<?php

namespace App\Domain\Manufacturing;

use App\Domain\Modules\ModuleService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Bom;
use App\Models\BomLine;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\WorkCentre;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * Work centres and bills of materials (BOMs).
 *
 * A BOM says what one batch of a product uses (materials, with an expected loss), what else comes out of it (by-products)
 * and the operations that make it (the routing: work centre, set-up minutes and run minutes per batch). A material that
 * has its own BOM is a sub-assembly, which makes the BOM multi-level.
 *
 * BOMs are drafted, then put **in use**; one is in use per product at a time. A BOM in use never changes: a new revision
 * is drafted and replaces it when put in use, so open work orders (which copy the BOM when released) and history stay true.
 */
final class BomService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly ModuleService $modules,
    ) {}

    /** @param array{code?: string, name?: string, branch_id?: string, hourly_rate?: string|int|null, hours_per_day?: string|float|null, is_active?: bool} $data */
    public function saveWorkCentre(Actor $actor, WorkCentre $centre, array $data): WorkCentre
    {
        $branch = Branch::findOrFail($data['branch_id'] ?? $centre->branch_id);
        $this->requireSetup($actor, $branch);
        $centre->fill(array_intersect_key($data, array_flip(['code', 'name', 'is_active'])));
        $centre->branch_id = $branch->id;
        $centre->code = strtoupper(trim((string) $centre->code));
        $centre->name = trim((string) $centre->name);
        if (! preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $centre->code) || $centre->name === '') {
            throw new DomainException('Enter a work centre code (letters, digits, dash) and a name.');
        }
        if (WorkCentre::where('code', $centre->code)->when($centre->exists, fn ($q) => $q->whereKeyNot($centre->id))->exists()) {
            throw new DomainException("Work centre {$centre->code} already exists.");
        }
        if (array_key_exists('hourly_rate', $data)) {
            $centre->hourly_rate_minor = Money::toMinor((string) ($data['hourly_rate'] ?: '0'), $actor->user->tenant->currency_code);
        }
        if (array_key_exists('hours_per_day', $data)) {
            $centre->hours_per_day = (string) ($data['hours_per_day'] ?: '0');
        }
        if ($centre->hourly_rate_minor < 0 || (float) $centre->hours_per_day < 0 || (float) $centre->hours_per_day > 24) {
            throw new DomainException('The hourly cost cannot be negative, and a day has at most 24 hours.');
        }
        $centre->tenant_id = $actor->tenantId;

        return $this->recorder->save($actor, $centre, $centre->exists ? 'work_centre.updated' : 'work_centre.created', branchId: $branch->id);
    }

    /**
     * Save a draft BOM.
     *
     * @param array{code?: string, product_id?: string, output_qty?: string, qc_required?: bool, note?: ?string,
     *     lines?: list<array{kind?: string, product_uom_id: string, qty: string, scrap_pct?: string|int|null, backflush?: bool}>,
     *     operations?: list<array{name: string, work_centre_id: string, setup_minutes?: string|int|null, run_minutes?: string|int|null, backflush?: bool}>} $data
     */
    public function save(Actor $actor, Bom $bom, array $data): Bom
    {
        $this->requireSetup($actor);
        if ($bom->exists && $bom->status !== 'draft') {
            throw new DomainException("{$bom->code} rev {$bom->revision} is in use or replaced and cannot change. Start a new revision.");
        }
        $product = Product::find($data['product_id'] ?? $bom->product_id) ?? throw new DomainException('Choose the product this BOM makes.');
        if (! $product->track_stock) {
            throw new DomainException("{$product->name} is not stock-tracked, so it cannot be made.");
        }
        $code = strtoupper(trim((string) ($data['code'] ?? $bom->code)));
        if (! preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,29}$/', $code)) {
            throw new DomainException('Enter a BOM code (letters, digits, dash, dot).');
        }
        if (Bom::where('code', $code)->where('product_id', '<>', $product->id)->exists()) {
            throw new DomainException("BOM code {$code} is used for another product.");
        }
        $output = Qty::toMilli((string) ($data['output_qty'] ?? $bom->output_qty ?? '1'));
        if ($output <= 0) {
            throw new DomainException('A batch must make more than zero.');
        }
        $lines = $this->lines($product, $data['lines'] ?? null, $bom);
        $operations = array_key_exists('operations', $data) ? $this->operations($data['operations'] ?? []) : ($bom->operations ?? []);

        return $this->recorder->transaction(function () use ($actor, $bom, $data, $product, $code, $output, $lines, $operations) {
            $new = ! $bom->exists;
            $bom->forceFill(['tenant_id' => $actor->tenantId, 'code' => $code, 'product_id' => $product->id, 'output_qty' => Qty::format($output),
                'qc_required' => (bool) ($data['qc_required'] ?? $bom->qc_required ?? false), 'note' => array_key_exists('note', $data) ? $data['note'] : $bom->note,
                'operations' => $operations]);
            if ($new) {
                $bom->forceFill(['status' => 'draft', 'revision' => (int) (Bom::where('code', $code)->max('revision') ?? 0) + 1]);
                $bom->save(); // the lines need its id; the audited save below records it
            }
            if ($lines !== null) {
                $bom->lines()->delete();
                foreach ($lines as $i => $l) {
                    BomLine::create($l + ['bom_id' => $bom->id, 'line_no' => $i + 1]);
                }
                $bom->updated_at = now(); // the lines changed: the snapshot goes out again
            }

            return $this->recorder->save($actor, $bom->load('lines'), $new ? 'bom.created' : 'bom.updated');
        });
    }

    /** Put a draft BOM in use; the product's BOM in use until now is replaced. */
    public function activate(Actor $actor, Bom $bom): Bom
    {
        $this->requireSetup($actor);
        if ($bom->status !== 'draft') {
            throw new DomainException('Only a draft BOM can be put in use.');
        }
        if (! $bom->lines()->where('kind', 'material')->exists()) {
            throw new DomainException('Add at least one material first.');
        }
        $this->assertNoCycle($bom->product_id, $bom->lines()->where('kind', 'material')->pluck('product_id')->all());

        return $this->recorder->transaction(function () use ($actor, $bom) {
            foreach (Bom::where('product_id', $bom->product_id)->where('status', 'active')->lockForUpdate()->get() as $old) {
                $old->status = 'archived';
                $this->recorder->save($actor, $old, 'bom.archived', "Replaced by rev {$bom->revision}");
            }
            $bom->status = 'active';

            return $this->recorder->save($actor, $bom, 'bom.activated');
        });
    }

    /** A new draft revision, copied from this BOM, to change what is in use. */
    public function revise(Actor $actor, Bom $bom): Bom
    {
        $this->requireSetup($actor);
        if (Bom::where('code', $bom->code)->where('status', 'draft')->exists()) {
            throw new DomainException("A draft revision of {$bom->code} already exists; edit that one.");
        }

        return $this->save($actor, new Bom, ['code' => $bom->code, 'product_id' => $bom->product_id, 'output_qty' => (string) $bom->output_qty,
            'qc_required' => $bom->qc_required, 'note' => $bom->note, 'operations' => $bom->operations ?? [],
            'lines' => $bom->lines->map(fn (BomLine $l) => ['kind' => $l->kind, 'product_uom_id' => $l->product_uom_id, 'qty' => (string) $l->qty,
                'scrap_pct' => $l->scrap_bp / 100, 'backflush' => $l->backflush])->all()]);
    }

    public function archive(Actor $actor, Bom $bom, string $reason): Bom
    {
        $this->requireSetup($actor);
        if ($bom->status === 'archived' || trim($reason) === '') {
            throw new DomainException(trim($reason) === '' ? 'Give the reason.' : 'That BOM is already replaced.');
        }
        $bom->status = 'archived';

        return $this->recorder->save($actor, $bom, 'bom.archived', $reason);
    }

    /** The BOM in use for a product, if any. */
    public static function activeFor(string $productId): ?Bom
    {
        return Bom::where('product_id', $productId)->where('status', 'active')->first();
    }

    /**
     * Estimated cost of one base unit of the BOM's product: materials at today's average cost in a warehouse (sub-assemblies
     * without stock are costed from their own BOM), plus operations at their work centres' rates, less by-products.
     *
     * @return array{material: int, labour: int, by_product: int, per_unit: int} minor units per base unit
     */
    public function estimate(Bom $bom, ?string $warehouseId = null, int $depth = 0): array
    {
        $out = (float) $bom->output_qty ?: 1.0;
        $material = 0.0;
        $byProduct = 0.0;
        foreach ($bom->lines()->with('unit')->get() as $l) {
            $base = (float) $l->qty * (float) $l->unit->factor * ($l->kind === 'material' ? 1 + $l->scrap_bp / 10000 : 1);
            $cost = $this->unitCost($l->product_id, $warehouseId, $depth);
            $l->kind === 'material' ? $material += $base * $cost : $byProduct += $base * $cost;
        }
        $labour = 0.0;
        $rates = WorkCentre::whereIn('id', array_column($bom->operations ?? [], 'work_centre_id'))->pluck('hourly_rate_minor', 'id');
        foreach ($bom->operations ?? [] as $op) {
            $labour += ((float) $op['setup_minutes'] + (float) $op['run_minutes']) / 60 * (int) ($rates[$op['work_centre_id']] ?? 0);
        }

        return ['material' => (int) round($material / $out), 'labour' => (int) round($labour / $out), 'by_product' => (int) round($byProduct / $out),
            'per_unit' => (int) round(($material + $labour - $byProduct) / $out)];
    }

    private function unitCost(string $productId, ?string $warehouseId, int $depth): float
    {
        $avg = DB::table('stock_levels')->where('product_id', $productId)->whereNotNull('avg_cost_minor')
            ->when($warehouseId, fn ($q) => $q->orderByRaw('CASE WHEN warehouse_id = ? THEN 0 ELSE 1 END', [$warehouseId]))->value('avg_cost_minor');
        if ($avg !== null) {
            return (float) $avg;
        }
        $sub = $depth < 10 ? self::activeFor($productId) : null;

        return $sub ? (float) $this->estimate($sub, $warehouseId, $depth + 1)['per_unit'] : 0.0;
    }

    /** @return list<array<string, mixed>>|null */
    private function lines(Product $product, ?array $rows, Bom $bom): ?array
    {
        if ($rows === null) {
            return $bom->exists ? null : throw new DomainException('Add the materials.');
        }
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $unit = ProductUom::with('product', 'uom')->find($r['product_uom_id'] ?? null) ?? throw new DomainException('Choose the item and unit on every line.');
            $item = $unit->product;
            $kind = ($r['kind'] ?? 'material') === 'by_product' ? 'by_product' : 'material';
            $qty = Qty::toMilli((string) ($r['qty'] ?? '0'));
            if ($qty <= 0) {
                throw new DomainException("{$item->name}: enter a quantity above zero.");
            }
            if ($item->id === $product->id) {
                throw new DomainException("{$product->name} cannot be a material of itself.");
            }
            if (! $item->track_stock) {
                throw new DomainException("{$item->name} is not stock-tracked, so it cannot be used or made in production.");
            }
            if ($item->track_serials) {
                throw new DomainException("{$item->name} has serial numbers; serial-numbered items cannot be in a BOM yet.");
            }
            if (isset($seen[$kind.$item->id])) {
                throw new DomainException("{$item->name} is on the BOM twice; put it on one line.");
            }
            $seen[$kind.$item->id] = true;
            $scrap = (int) round((float) ($r['scrap_pct'] ?? 0) * 100);
            if ($scrap < 0 || $scrap > 5000) {
                throw new DomainException("{$item->name}: expected loss must be between 0 and 50 %.");
            }
            $out[] = ['kind' => $kind, 'product_id' => $item->id, 'product_uom_id' => $unit->id, 'qty' => Qty::format($qty),
                'scrap_bp' => $kind === 'material' ? $scrap : 0, 'backflush' => $kind === 'material' ? (bool) ($r['backflush'] ?? true) : true];
        }
        if (! array_filter($out, fn ($l) => $l['kind'] === 'material')) {
            throw new DomainException('Add at least one material.');
        }
        $this->assertNoCycle($product->id, array_column(array_filter($out, fn ($l) => $l['kind'] === 'material'), 'product_id'));

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function operations(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $centre = WorkCentre::find($r['work_centre_id'] ?? null);
            if ($name === '' || ! $centre) {
                throw new DomainException('Give every operation a name and a work centre.');
            }
            $setup = round((float) ($r['setup_minutes'] ?? 0), 2);
            $run = round((float) ($r['run_minutes'] ?? 0), 2);
            if ($setup < 0 || $run < 0 || $setup + $run <= 0) {
                throw new DomainException("{$name}: enter the set-up and/or run minutes.");
            }
            $out[] = ['seq' => ($i + 1) * 10, 'name' => mb_substr($name, 0, 120), 'work_centre_id' => $centre->id, 'setup_minutes' => $setup,
                'run_minutes' => $run, 'backflush' => (bool) ($r['backflush'] ?? true)];
        }

        return $out;
    }

    /** A product may not (at any depth) be made from itself. */
    private function assertNoCycle(string $productId, array $materialIds): void
    {
        $stack = array_map(fn ($id) => [$id, 1], $materialIds);
        $seen = [];
        while ($stack) {
            [$id, $depth] = array_pop($stack);
            if ($id === $productId) {
                $name = Product::whereKey($productId)->value('name');
                throw new DomainException("{$name} would end up being made from itself (through a sub-assembly).");
            }
            if (isset($seen[$id]) || $depth > 20) {
                continue;
            }
            $seen[$id] = true;
            $bom = self::activeFor($id);
            foreach ($bom ? $bom->lines()->where('kind', 'material')->pluck('product_id') : [] as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }
    }

    private function requireSetup(Actor $actor, ?Branch $branch = null): void
    {
        if (! $actor->user || ! ($branch ? $this->authz->allows($actor->user, 'manufacturing.setup', $branch) : $this->authz->allowsAnywhere($actor->user, 'manufacturing.setup'))) {
            throw new AccessDenied;
        }
        if ($branch && ! $this->modules->isEnabled($branch, 'manufacturing')) {
            throw new DomainException('Switch on Manufacturing for this branch first (System → Modules).', 'module_disabled');
        }
    }
}
