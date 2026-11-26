<?php

namespace App\Domain\Office;

use App\Domain\Manufacturing\BomService;
use App\Domain\Manufacturing\MrpPlanner;
use App\Filament\Pages\ProductionPlanning;
use App\Filament\Resources\Boms\BomResource;
use App\Filament\Resources\WorkCentres\WorkCentreResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Bom;
use App\Models\BomLine;
use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\Product;
use App\Models\QcInspection;
use App\Models\User;
use App\Models\WorkCentre;
use App\Models\WorkOrder;
use App\Models\WorkOrderCost;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Builder;

/**
 * Manufacturing in the new back office (part 35 of the rewrite), as the current pages: Work orders (list, new, one work
 * order), Bills of materials (list, one BOM, its form), Production planning (MRP) and Work centres. Same queries and
 * rules: the BomService, WorkOrderService and MrpPlanner (ManufacturingController).
 */
final class ManufacturingScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const ORDER_TONE = ['done' => 'success', 'in_progress' => 'warning', 'released' => 'informative', 'cancelled' => 'subtle', 'draft' => 'subtle'];

    public const BOM_TONE = ['active' => 'success', 'archived' => 'subtle', 'draft' => 'warning'];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    private function m(): array
    {
        $c = $this->user->tenant?->currency_code ?? 'GHS';

        return ['currency' => $c, 'exponent' => Money::exponent($c)];
    }

    private static function num(mixed $v): string
    {
        $v = (string) ($v ?? '');

        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    /** A table as the current page shows it: headings, rows and which columns are numbers. */
    private static function table(array $t): array
    {
        return ['headings' => $t[0], 'rows' => $t[1], 'numeric' => $t[2] ?? []];
    }

    public static function canView(): bool
    {
        return WorkOrderResource::canViewAny();
    }

    public static function canSetup(): bool
    {
        return Erp::canAnywhere('manufacturing.setup');
    }

    public static function canProduce(): bool
    {
        return Erp::canAnywhere('manufacturing.produce');
    }

    // -------------------------------------------------------------------------------------------- Work orders

    public function ordersQuery(): Builder
    {
        return WorkOrderResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, open (1 by default; all), status, page, per */
    public function orders(array $p): array
    {
        $q = $this->ordersQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('work_orders.number', 'ilike', "%{$s}%")->orWhereHas('product', fn ($x) => $x->where('name', 'ilike', "%{$s}%")));
        }
        $open = ($p['open'] ?? '1') !== '0';
        if (isset(WorkOrder::STATUSES[$p['status'] ?? ''])) {
            $q->where('work_orders.status', $p['status']);
        } elseif ($open) {
            $q->whereIn('work_orders.status', WorkOrder::OPEN);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'open' => $open, 'statuses' => self::choices(WorkOrder::STATUSES), 'can_create' => self::canProduce(),
            'rows' => $q->orderByDesc('work_orders.number')->forPage($page, $per)->get()->map(fn (WorkOrder $o) => ['id' => $o->id, 'number' => $o->number,
                'product' => $o->product?->name, 'warehouse' => $o->warehouse?->code, 'planned' => Fields::qty($o->qty_planned), 'done' => Fields::qty($o->qty_done),
                'due' => $o->due_on?->toDateString(), 'status' => $o->status, 'status_label' => WorkOrder::STATUSES[$o->status] ?? $o->status,
                'tone' => self::ORDER_TONE[$o->status] ?? 'subtle'])->all()];
    }

    /** What "New work order" offers: warehouses, products with a BOM in use, and the product chosen already when it comes from a BOM. */
    public function orderForm(?string $product): array
    {
        $products = Product::whereIn('id', Bom::where('status', 'active')->select('product_id'))->orderBy('name')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} · {$p->name}"])->all();

        return ['warehouses' => self::choices(Fields::warehouses('manufacturing.produce')), 'products' => self::choices($products),
            'values' => ['warehouse_id' => '', 'product_id' => isset($products[$product ?? '']) ? $product : '', 'qty' => '', 'due_on' => '', 'customer_order_id' => '',
                'customer_order' => null, 'note' => '']];
    }

    public function customerOrders(string $q): array
    {
        return CustomerOrder::whereIn('status', ['confirmed', 'on_hold', 'partially_delivered'])->where('number', 'ilike', "%{$q}%")->limit(20)->get()
            ->map(fn ($o) => ['id' => $o->id, 'label' => $o->number])->all();
    }

    public function order(WorkOrder $o): array
    {
        $produce = Erp::can('manufacturing.produce', $o->warehouse);
        $running = in_array($o->status, ['released', 'in_progress'], true) && $produce;
        $m = fn ($v) => (int) $v;
        $bom = Bom::find($o->bom_id);
        $materials = $o->materials()->with('product')->where('kind', 'material')->get();

        return $this->m() + ['id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'status_label' => WorkOrder::STATUSES[$o->status] ?? $o->status,
            'tone' => self::ORDER_TONE[$o->status] ?? 'subtle', 'product' => $o->product?->name, 'sku' => $o->product?->sku, 'warehouse' => $o->warehouse?->name,
            'planned' => Fields::qty($o->qty_planned), 'done' => Fields::qty($o->qty_done), 'scrapped' => Fields::qty($o->qty_scrapped),
            'qc_required' => (bool) $o->qc_required, 'awaiting_qc' => Fields::qty($o->qty_awaiting_qc), 'due' => $o->due_on?->toDateString(),
            'bom' => $bom ? ['label' => "{$bom->code} rev {$bom->revision}", 'open' => "/office/manufacturing/boms/{$bom->id}"] : null,
            'customer_order' => CustomerOrder::whereKey($o->customer_order_id)->value('number'),
            'parent' => $o->parent_work_order_id ? ['number' => WorkOrder::whereKey($o->parent_work_order_id)->value('number'), 'open' => "/office/manufacturing/orders/{$o->parent_work_order_id}"] : null,
            'raised_by' => $o->created_by_name, 'released_by' => $o->released_by_name, 'released_at' => $o->released_at?->format('j M Y H:i'),
            'closed_by' => $o->closed_by_name, 'close_reason' => $o->close_reason, 'note' => $o->note,
            'cost' => ['material' => $m($o->material_minor), 'labour' => $m($o->labour_minor), 'by_product' => $m($o->by_product_minor), 'output' => $m($o->output_minor),
                'wip' => $o->wipMinor(), 'variance' => $m($o->variance_minor),
                'per_unit' => Qty::toMilli((string) $o->qty_done) > 0 ? (int) round(($o->material_minor + $o->labour_minor - $o->by_product_minor) * 1000 / Qty::toMilli((string) $o->qty_done)) : null],
            'materials' => self::table(WorkOrderResource::materialRows($o)), 'operations' => self::table(WorkOrderResource::operationRows($o)),
            'costs' => WorkOrderCost::where('work_order_id', $o->id)->orderBy('occurred_at')->get()->map(fn (WorkOrderCost $c) => ['id' => $c->id, 'when' => $c->occurred_at->format('j M H:i'),
                'what' => $c->kind === 'labour' ? $c->note : 'Left in work in progress at finish', 'minutes' => $c->minutes === null ? '' : self::num($c->minutes),
                'amount' => (int) $c->amount_minor, 'by' => $c->actor_name])->all(),
            'checks' => $o->qc_required ? QcInspection::where('work_order_id', $o->id)->orderBy('created_at')->get()->map(fn (QcInspection $c) => ['id' => $c->id, 'number' => $c->number,
                'when' => $c->created_at->format('j M H:i'), 'passed' => Fields::qty($c->qty_passed), 'failed' => Fields::qty($c->qty_failed),
                'checks' => collect($c->checks)->map(fn ($k) => "{$k['check']}: ".($k['result'] === 'pass' ? '✓' : '✗').($k['value'] ? " {$k['value']}" : ''))->implode('; '),
                'note' => $c->note, 'by' => $c->inspector_name])->all() : [],
            'batches' => (bool) $o->product?->track_batches,
            'issue_lines' => $materials->map(fn ($l) => ['id' => $l->id, 'item' => "{$l->product->sku} · {$l->product->name}", 'planned' => Fields::qty($l->planned_qty),
                'issued' => Fields::qty($l->issued_qty), 'issued_milli' => Qty::toMilli((string) $l->issued_qty)])->values()->all(),
            'steps' => collect($o->operations ?? [])->map(fn ($op) => ['id' => (string) $op['seq'], 'label' => "{$op['seq']} · {$op['name']}"])->values()->all(),
            'actions' => ['release' => $o->status === 'draft' && $produce, 'output' => $running,
                'inspect' => Qty::toMilli((string) $o->qty_awaiting_qc) > 0 && Erp::can('manufacturing.qc', $o->warehouse), 'issue' => $running,
                'time' => $running && (bool) $o->operations, 'return' => $running, 'finish' => $running,
                'cancel' => in_array($o->status, ['draft', 'released'], true) && $produce]];
    }

    // -------------------------------------------------------------------------------------------- Bills of materials

    public function bomsQuery(): Builder
    {
        return BomResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, status, page, per */
    public function boms(array $p): array
    {
        $q = $this->bomsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('boms.code', 'ilike', "%{$s}%")->orWhereHas('product', fn ($x) => $x->where('name', 'ilike', "%{$s}%")));
        }
        if (isset(Bom::STATUSES[$p['status'] ?? ''])) {
            $q->where('boms.status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::choices(Bom::STATUSES), 'can_create' => BomResource::canCreate(),
            'rows' => $q->orderBy('boms.code')->orderByDesc('boms.revision')->forPage($page, $per)->get()->map(fn (Bom $b) => ['id' => $b->id, 'code' => "{$b->code} rev {$b->revision}",
                'product' => $b->product?->name, 'batch' => Fields::qty($b->output_qty), 'lines' => (int) $b->lines_count, 'qc' => (bool) $b->qc_required, 'status' => $b->status,
                'status_label' => Bom::STATUSES[$b->status] ?? $b->status, 'tone' => self::BOM_TONE[$b->status] ?? 'subtle'])->all()];
    }

    public function bom(Bom $b): array
    {
        $e = app(BomService::class)->estimate($b);
        $setup = self::canSetup();

        return $this->m() + ['id' => $b->id, 'code' => "{$b->code} rev {$b->revision}", 'status' => $b->status, 'status_label' => Bom::STATUSES[$b->status] ?? $b->status,
            'tone' => self::BOM_TONE[$b->status] ?? 'subtle', 'product' => $b->product?->name, 'sku' => $b->product?->sku, 'batch' => Fields::qty($b->output_qty),
            'qc_required' => (bool) $b->qc_required, 'note' => $b->note,
            'estimate' => ['per_unit' => (int) $e['per_unit'], 'material' => (int) $e['material'], 'labour' => (int) $e['labour'], 'by_product' => (int) $e['by_product']],
            'lines' => self::table([['Type', 'SKU', 'Item', 'Quantity', 'Unit', 'Expected loss', 'Issued'],
                $b->lines()->with('product', 'unit.uom')->get()->map(fn (BomLine $l) => [BomLine::KINDS[$l->kind], $l->product->sku,
                    $l->product->name.(BomService::activeFor($l->product_id) ? ' (made here)' : ''), Qty::format(Qty::toMilli((string) $l->qty)), $l->unit->uom->code,
                    $l->kind === 'material' ? ($l->scrap_bp / 100).' %' : '', $l->kind === 'material' ? ($l->backflush ? 'Backflushed' : 'By hand') : 'With output'])->all(), [3]]),
            'operations' => self::table([['Step', 'Operation', 'Work centre', 'Set-up min', 'Run min', 'Time'],
                array_map(fn ($op) => [$op['seq'], $op['name'], WorkCentre::whereKey($op['work_centre_id'])->value('name'), $op['setup_minutes'], $op['run_minutes'],
                    $op['backflush'] ? 'Backflushed' : 'Recorded'], $b->operations ?? []), [3, 4]]),
            'warehouses' => self::choices(Fields::warehouses('manufacturing.produce')),
            'actions' => ['edit' => $b->status === 'draft' && $setup, 'activate' => $b->status === 'draft' && $setup, 'revise' => $b->status !== 'draft' && $setup,
                'make' => $b->status === 'active' && self::canProduce(), 'archive' => $b->status !== 'archived' && $setup]];
    }

    /** The BOM form: a new one, or a draft's values to change. */
    public function bomForm(?Bom $b): array
    {
        return ['id' => $b?->id, 'code' => $b ? "{$b->code} rev {$b->revision}" : null, 'kinds' => self::choices(BomLine::KINDS),
            'work_centres' => self::choices(WorkCentre::where('is_active', true)->whereNull('archived_at')->orderBy('code')->pluck('name', 'id')->all()),
            'values' => ['product_id' => (string) $b?->product_id, 'product' => $b?->product ? "{$b->product->sku} · {$b->product->name}" : null,
                'output_qty' => $b ? self::num($b->output_qty) : '1', 'qc_required' => (bool) $b?->qc_required, 'note' => (string) $b?->note,
                'lines' => $b ? $b->lines()->with('product', 'unit.uom')->get()->map(fn (BomLine $l) => ['kind' => $l->kind, 'product_uom_id' => $l->product_uom_id,
                    'unit' => Fields::unitLabel($l->unit), 'qty' => self::num($l->qty), 'scrap_pct' => self::num($l->scrap_bp / 100), 'backflush' => (bool) $l->backflush])->all()
                    : [['kind' => 'material', 'product_uom_id' => '', 'unit' => null, 'qty' => '', 'scrap_pct' => '0', 'backflush' => true]],
                'operations' => array_map(fn ($op) => ['name' => $op['name'], 'work_centre_id' => $op['work_centre_id'], 'setup_minutes' => self::num($op['setup_minutes']),
                    'run_minutes' => self::num($op['run_minutes']), 'backflush' => (bool) $op['backflush']], $b?->operations ?? [])]];
    }

    public function products(string $q): array
    {
        return Product::where('is_active', true)->where('track_stock', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%"))
            ->orderBy('name')->limit(30)->get()->map(fn ($p) => ['id' => $p->id, 'label' => "{$p->sku} · {$p->name}"])->all();
    }

    // -------------------------------------------------------------------------------------------- Production planning

    public function planning(?string $warehouse): array
    {
        $page = new ProductionPlanning;
        $page->warehouse = is_string($warehouse) ? $warehouse : '';
        $page->mount();
        $plan = $page->plan();
        $q = fn ($v) => MrpPlanner::qty((float) $v);

        return ['warehouses' => self::choices($page->warehouses()), 'warehouse' => $page->warehouse, 'can_make' => $page->canMake(),
            'make' => array_map(fn ($r) => ['product_id' => $r['product_id'], 'sku' => $r['sku'], 'name' => $r['name'], 'level' => $r['level'], 'needed' => $q($r['demand']),
                'on_hand' => $q($r['on_hand']), 'coming' => $q($r['coming']), 'suggested' => $q($r['suggested']), 'bom' => $r['bom']], $plan['make']),
            'buy' => array_map(fn ($r) => ['sku' => $r['sku'], 'name' => $r['name'], 'needed' => $q($r['demand']), 'on_hand' => $q($r['on_hand']), 'on_order' => $q($r['on_order']),
                'suggested' => $q($r['suggested'])], $plan['buy']),
            'load' => array_map(fn ($r) => ['work_centre' => "{$r['code']} · {$r['name']}", 'hours' => $r['hours'], 'per_day' => $r['per_day'], 'days' => $r['days'] ?? null], $plan['load'])];
    }

    // -------------------------------------------------------------------------------------------- Work centres

    /** @param  array<string, mixed>  $p  show */
    public function centres(array $p): array
    {
        $q = WorkCentreResource::getEloquentQuery();
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('archived_at'), 'both' => null, default => $q->whereNull('archived_at')
        };
        $archive = new CompanyScreen($this->user);
        $e = $this->m()['exponent'];

        return $this->m() + ['can_create' => WorkCentreResource::canCreate(), 'branches' => self::choices(Branch::choices(null)),
            'rows' => $q->orderBy('code')->get()->map(fn (WorkCentre $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'branch' => Branch::whereKey($c->branch_id)->value('code'),
                'rate' => (int) $c->hourly_rate_minor, 'hours' => self::num($c->hours_per_day), 'can_edit' => WorkCentreResource::canCreate(),
                'values' => ['name' => $c->name, 'branch_id' => $c->branch_id, 'hourly_rate' => number_format($c->hourly_rate_minor / (10 ** $e), $e, '.', ''),
                    'hours_per_day' => self::num($c->hours_per_day)]] + $archive->archiveOf($c))->all()];
    }
}
