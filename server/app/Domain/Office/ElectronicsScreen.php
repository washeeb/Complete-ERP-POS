<?php

namespace App\Domain\Office;

use App\Domain\Electronics\RepairService;
use App\Domain\Inventory\SerialRegistry;
use App\Filament\Resources\RepairJobs\RepairJobResource;
use App\Filament\Resources\SerialNumbers\SerialNumberResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\RepairJob;
use App\Models\RepairJobLine;
use App\Models\Sale;
use App\Models\SerialNumber;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Electronics in the new back office (part 36 of the rewrite), as the current pages: Repairs (list, new, one repair job)
 * and Serial numbers (list, one number with its history, registering numbers for stock on hand). Same queries and rules:
 * the RepairService and InventoryService (ElectronicsController).
 */
final class ElectronicsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const REPAIR_TONE = ['ready' => 'success', 'collected' => 'subtle', 'at_supplier' => 'warning', 'cancelled' => 'subtle'];

    public const SERIAL_TONE = ['in_stock' => 'success', 'sold' => 'informative', 'in_transit' => 'warning'];

    public function __construct(private readonly User $user) {}

    private static function pairs(array $map): array
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

    private static function num(string $v): string
    {
        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    // -------------------------------------------------------------------------------------------- Repairs

    public function repairsQuery(): Builder
    {
        return RepairJobResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, status, page, per */
    public function repairs(array $p): array
    {
        $q = $this->repairsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhere('customer_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")
                ->orWhere('device', 'ilike', "%{$s}%")->orWhere('serial', 'ilike', "%{$s}%"));
        }
        if (isset(RepairJob::STATUSES[$p['status'] ?? ''])) {
            $q->where('status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $ids = Erp::branchIds('electronics.repairs');

        return ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::pairs(RepairJob::STATUSES), 'can_create' => RepairJobResource::canCreate(),
            'branches' => self::pairs(Branch::when($ids !== null, fn ($b) => $b->whereIn('id', $ids))->whereNull('archived_at')->orderBy('code')->pluck('name', 'id')->all()),
            'rows' => $q->orderByDesc('received_at')->forPage($page, $per)->get()->map(fn (RepairJob $j) => ['id' => $j->id, 'number' => $j->number,
                'received' => $j->received_at?->format('j M H:i'), 'customer' => $j->customer_name, 'phone' => $j->phone, 'device' => $j->device, 'serial' => $j->serial,
                'status' => $j->status, 'status_label' => RepairJob::STATUSES[$j->status] ?? $j->status, 'tone' => self::REPAIR_TONE[$j->status] ?? 'informative',
                'warranty' => (bool) $j->in_warranty, 'promised' => $j->promised_on?->toDateString()])->all()];
    }

    public function repair(RepairJob $j): array
    {
        $j->loadMissing('lines');
        $open = in_array($j->status, RepairJob::OPEN, true);
        $charge = (int) $j->lines->sum(fn (RepairJobLine $l) => (int) round($l->unit_price_minor * (float) $l->qty));
        $units = ProductUom::with('uom', 'product')->whereIn('id', $j->lines->pluck('product_uom_id'))->get()->keyBy('id');

        return $this->m() + ['id' => $j->id, 'number' => $j->number, 'status' => $j->status, 'status_label' => RepairJob::STATUSES[$j->status] ?? $j->status,
            'tone' => self::REPAIR_TONE[$j->status] ?? 'informative', 'received' => $j->received_at?->format('j M Y H:i'), 'customer' => $j->customer_name, 'phone' => $j->phone,
            'device' => $j->device, 'serial' => $j->serial, 'serial_open' => $j->serial_number_id ? "/office/electronics/serials/{$j->serial_number_id}" : null,
            'fault' => $j->fault, 'accessories' => $j->accessories, 'promised' => $j->promised_on?->toDateString(), 'warranty' => (bool) $j->in_warranty,
            'supplier' => Supplier::whereKey($j->supplier_id)->value('name'), 'rma' => $j->rma_reference,
            'lines' => $j->lines->map(fn (RepairJobLine $l) => ['id' => $l->id, 'name' => $l->name, 'qty' => Fields::qty($l->qty), 'price' => (int) $l->unit_price_minor,
                'amount' => (int) round($l->unit_price_minor * (float) $l->qty)])->values()->all(),
            'charge' => $charge,
            'line_values' => $j->lines->map(fn (RepairJobLine $l) => ['product_uom_id' => $l->product_uom_id,
                'unit' => ($u = $units[$l->product_uom_id] ?? null) ? Fields::unitLabel($u) : $l->name, 'qty' => self::num((string) $l->qty)])->values()->all(),
            'log' => collect($j->log ?? [])->map(fn ($e) => ['when' => Carbon::parse($e['at'])->format('j M H:i'), 'status' => RepairJob::STATUSES[$e['status']] ?? $e['status'],
                'by' => $e['by'], 'note' => $e['note'] ?? null])->reverse()->values()->all(),
            'next' => self::pairs(collect(RepairService::TRANSITIONS[$j->status] ?? [])->mapWithKeys(fn ($s) => [$s => RepairJob::STATUSES[$s]])->all()),
            'warehouses' => self::pairs(Warehouse::where('branch_id', $j->branch_id)->orderBy('code')->pluck('name', 'id')->all()),
            'actions' => ['progress' => isset(RepairService::TRANSITIONS[$j->status]), 'lines' => $open, 'hand_over' => $j->status === 'ready' && $j->lines->sum('unit_price_minor') == 0]];
    }

    public function suppliers(string $q): array
    {
        return Supplier::where('name', 'ilike', "%{$q}%")->orderBy('name')->limit(20)->get()->map(fn ($s) => ['id' => $s->id, 'label' => $s->name])->all();
    }

    // -------------------------------------------------------------------------------------------- Serial numbers

    public function serialsQuery(): Builder
    {
        return SerialNumberResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, status, page, per */
    public function serials(array $p): array
    {
        $q = $this->serialsQuery()->with('product');
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('serial', 'ilike', "%{$s}%")->orWhereHas('product', fn ($x) => $x->where('name', 'ilike', "%{$s}%")));
        }
        if (isset(SerialNumber::STATUSES[$p['status'] ?? ''])) {
            $q->where('status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $adjust = Erp::canAnywhere('stock.adjust');
        $wh = Warehouse::pluck('code', 'id');

        return ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::pairs(SerialNumber::STATUSES), 'can_register' => $adjust,
            'gaps' => $adjust ? count(SerialRegistry::check($this->user->tenant_id)) : 0,
            'warehouses' => $adjust ? self::pairs(Warehouse::orderBy('code')->pluck('name', 'id')->all()) : [],
            'rows' => $q->orderByDesc('updated_at')->forPage($page, $per)->get()->map(fn (SerialNumber $n) => ['id' => $n->id, 'serial' => $n->serial,
                'product' => $n->product?->name, 'status' => $n->status, 'status_label' => SerialNumber::STATUSES[$n->status] ?? $n->status,
                'tone' => self::SERIAL_TONE[$n->status] ?? 'subtle', 'where' => $n->warehouse_id ? ($wh[$n->warehouse_id] ?? null) : null,
                'warranty' => $n->warranty_until?->toDateString()])->all()];
    }

    public function serial(SerialNumber $n): array
    {
        return ['id' => $n->id, 'serial' => $n->serial, 'product' => $n->product?->name, 'sku' => $n->product?->sku, 'status' => $n->status,
            'status_label' => SerialNumber::STATUSES[$n->status] ?? $n->status, 'tone' => self::SERIAL_TONE[$n->status] ?? 'subtle',
            'where' => $n->warehouse_id ? Warehouse::whereKey($n->warehouse_id)->value('name') : null, 'sold' => $n->sold_at?->format('j M Y H:i'),
            'receipt' => $n->sale_id ? Sale::whereKey($n->sale_id)->value('receipt_no') : null, 'sale_open' => $n->sale_id ? "/office/sales/{$n->sale_id}" : null,
            'customer' => $n->customer_id ? Customer::whereKey($n->customer_id)->value('name') : null, 'warranty' => $n->warranty_until?->toDateString(),
            'in_warranty' => $n->warranty_until ? $n->inWarranty() : null,
            'history' => DB::table('stock_movement_serials as l')->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
                ->where('l.serial_number_id', $n->id)->orderBy('m.occurred_at')->get(['m.id', 'm.occurred_at', 'm.type', 'm.reason', 'm.actor_name', 'w.code'])
                ->map(fn ($m) => ['id' => $m->id, 'when' => Carbon::parse($m->occurred_at)->format('j M Y H:i'), 'what' => str_replace('_', ' ', $m->type),
                    'where' => $m->code, 'reason' => $m->reason, 'by' => $m->actor_name])->all()];
    }

    /** Products tracked by serial number, for registering numbers. */
    public function serialProducts(string $q): array
    {
        return Product::where('track_serials', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%"))->orderBy('name')->limit(30)
            ->get()->map(fn ($p) => ['id' => $p->id, 'label' => "{$p->sku} · {$p->name}"])->all();
    }
}
