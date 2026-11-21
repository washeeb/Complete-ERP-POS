<?php

namespace App\Domain\Office;

use App\Domain\Inventory\CycleCounts;
use App\Domain\Inventory\StockBatches;
use App\Domain\Inventory\StockChecks;
use App\Domain\Logistics\WaybillService;
use App\Domain\Org\ArchiveService;
use App\Domain\Purchasing\ReorderService;
use App\Filament\Pages\BatchesExpiry;
use App\Filament\Pages\ExpiryReturns;
use App\Filament\Pages\MyDay;
use App\Filament\Pages\ReorderSuggestions;
use App\Filament\Resources\Bins\BinResource;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use App\Filament\Resources\Waybills\WaybillResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\Bin;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\ProductUom;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Waybill;
use App\Support\Codes;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The rest of the Stock floor in the new back office (part 17 of the rewrite), as the current pages: Returns to suppliers
 * (approve and send, the supplier's settlement, cancel, the return note), Waybills (prepare one for a delivery, transfer,
 * return or goods received; dispatch, mark delivered with the signed copy, cancel, print), Bins (shelf locations, what
 * they hold, moving stock between them, archive) and My day (today's cycle count, what needs doing in a store, checks).
 * Through ExpiryService, WaybillService, BinService, ArchiveService and CycleCounts (StockFloorController).
 */
final class StockFloorScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    /** @param  array<string, string>  $map */
    public static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    private function money(): array
    {
        $c = $this->user->tenant?->currency_code ?? 'GHS';

        return ['currency' => $c, 'exponent' => Money::exponent($c)];
    }

    // -------------------------------------------------------------------------------------------- Returns to suppliers

    public static function canReturns(): bool
    {
        return SupplierReturnResource::canViewAny();
    }

    public function returnsQuery(): Builder
    {
        return SupplierReturnResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q (number or supplier), status, settlement, supplier, page, per */
    public function returns(array $p): array
    {
        $q = $this->returnsQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('supplier_returns.number', 'ilike', "%{$search}%")->orWhereHas('supplier', fn ($s) => $s->where('name', 'ilike', "%{$search}%")));
        }
        foreach (['status' => SupplierReturn::STATUSES, 'settlement' => SupplierReturn::SETTLEMENTS] as $k => $allowed) {
            if (array_key_exists($p[$k] ?? '', $allowed)) {
                $q->where("supplier_returns.{$k}", $p[$k]);
            }
        }
        if (filled($p['supplier'] ?? null)) {
            $q->where('supplier_returns.supplier_id', (string) $p['supplier']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $waiting = (clone $q)->reorder()->where('supplier_returns.status', 'submitted')->count();

        return $this->money() + ['total' => $total, 'waiting' => $waiting, 'page' => $page, 'per' => $per,
            'statuses' => self::choices(SupplierReturn::STATUSES), 'settlements' => self::choices(SupplierReturn::SETTLEMENTS),
            'suppliers' => self::choices(Supplier::orderBy('name')->pluck('name', 'id')->all()),
            'rows' => $q->orderByDesc('supplier_returns.created_at')->forPage($page, $per)->get()->map(fn (SupplierReturn $r) => ['id' => $r->id, 'number' => $r->number,
                'date' => $r->created_at?->toDateString(), 'supplier' => $r->supplier?->name, 'store' => $r->warehouse?->code, 'reason' => SupplierReturn::REASONS[$r->reason] ?? $r->reason,
                'settlement' => SupplierReturn::SETTLEMENTS[$r->settlement] ?? $r->settlement,
                'settled' => $r->status === 'posted' ? ($r->settled_at ? 'settled: '.$r->settlement_ref : 'waiting for the supplier') : null,
                'lines' => (int) $r->lines_count, 'value' => (int) $r->total_minor, 'status' => $r->status, 'status_label' => SupplierReturn::STATUSES[$r->status] ?? $r->status])->all()];
    }

    public function returnDoc(SupplierReturn $r): array
    {
        $r->loadMissing(['supplier', 'warehouse']);
        $waybill = Waybill::where('source_id', $r->id)->where('status', '<>', 'cancelled')->first();
        $refLabel = match ($r->settlement) {
            'credit_note' => 'Supplier\'s credit note number', 'replacement' => 'Delivery note of the replacement stock',
            'refund' => 'Refund receipt or MoMo / bank reference', default => 'Supplier\'s acknowledgement or collection note'
        };

        return $this->money() + ['id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'status_label' => SupplierReturn::STATUSES[$r->status] ?? $r->status,
            'supplier' => $r->supplier?->name, 'supplier_open' => SuppliersScreen::canView() ? "/office/suppliers/{$r->supplier_id}" : null, 'store' => $r->warehouse?->name,
            'reason' => SupplierReturn::REASONS[$r->reason] ?? $r->reason, 'settlement' => $r->settlement, 'settlement_label' => SupplierReturn::SETTLEMENTS[$r->settlement] ?? $r->settlement,
            'value' => (int) $r->total_minor, 'prepared_by' => $r->prepared_by_name, 'prepared_at' => $r->created_at?->toIso8601String(),
            'approved_by' => $r->approved_by_name, 'approved_at' => $r->posted_at?->toIso8601String(), 'settlement_ref' => $r->settlement_ref,
            'settled' => $r->settled_at ? "{$r->settled_by_name}, {$r->settled_at->format('j M Y')}" : null,
            'refund' => $r->settlement === 'refund' && $r->refund_minor !== null ? (int) $r->refund_minor : null, 'note' => $r->note,
            'lines' => $r->lines()->with('product.baseUom')->get()->map(fn ($l) => ['no' => $l->line_no, 'sku' => $l->product?->sku, 'name' => $l->product?->name,
                'batch' => $l->batch_no, 'expires_on' => $l->expires_on?->toDateString(), 'qty' => Fields::qty(Qty::format(Qty::toMilli((string) $l->qty)), $l->product?->baseUom?->code),
                'value' => (int) $l->value_minor])->all(),
            'actions' => [
                'post' => $r->status === 'submitted', 'cancel' => $r->status === 'submitted',
                'settle' => $r->status === 'posted' && ! $r->settled_at ? ['label' => $refLabel, 'refund' => $r->settlement === 'refund' ? (int) $r->total_minor : null,
                    'accounts' => $r->settlement === 'refund' ? self::choices(FinanceUi::moneyAccounts()) : null,
                    'replacement' => $r->settlement === 'replacement'] : null,
                'waybill' => $waybill ? "/office/waybills/{$waybill->id}" : (WaybillResource::canCreate() ? "/office/waybills/new?kind=supplier_return&source={$r->id}" : null),
                'print' => route('print.supplier-return', $r->id, false)]];
    }

    // -------------------------------------------------------------------------------------------- Waybills

    public static function canWaybills(): bool
    {
        return WaybillResource::canViewAny();
    }

    public function waybillsQuery(): Builder
    {
        return WaybillResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, status, kind, page, per */
    public function waybills(array $p): array
    {
        $q = $this->waybillsQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%{$search}%")->orWhere('source_number', 'ilike', "%{$search}%")->orWhere('reference', 'ilike', "%{$search}%")
                ->orWhere('to_name', 'ilike', "%{$search}%")->orWhere('vehicle_no', 'ilike', "%{$search}%"));
        }
        foreach (['status' => Waybill::STATUSES, 'kind' => Waybill::KINDS] as $k => $allowed) {
            if (array_key_exists($p[$k] ?? '', $allowed)) {
                $q->where($k, $p[$k]);
            }
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::choices(Waybill::STATUSES), 'kinds' => self::choices(Waybill::KINDS),
            'can_create' => WaybillResource::canCreate(),
            'rows' => $q->orderByDesc('created_at')->forPage($page, $per)->get()->map(fn (Waybill $w) => ['id' => $w->id, 'number' => $w->number, 'date' => $w->created_at?->toDateString(),
                'kind' => Waybill::KINDS[$w->kind] ?? $w->kind, 'source' => $w->source_number, 'to' => $w->to_name, 'vehicle' => $w->vehicle_no, 'driver' => $w->driver_name,
                'status' => $w->status, 'status_label' => Waybill::STATUSES[$w->status] ?? $w->status])->all()];
    }

    public function waybill(Waybill $w): array
    {
        $open = in_array($w->status, ['prepared', 'dispatched'], true);

        return ['id' => $w->id, 'number' => $w->number, 'status' => $w->status, 'status_label' => Waybill::STATUSES[$w->status] ?? $w->status,
            'kind' => Waybill::KINDS[$w->kind] ?? $w->kind, 'source' => $w->source_number, 'reference' => $w->reference,
            'from' => $w->from_name, 'from_address' => $w->from_address, 'to' => $w->to_name, 'to_address' => trim(($w->to_address ?? '').' '.($w->to_phone ?? '')),
            'vehicle' => collect([$w->vehicle_no, $w->driver_name, $w->driver_phone, $w->transporter])->filter()->implode(' · ') ?: null,
            'packages' => ($w->packages ?? '—').' / '.($w->weight_kg ? "{$w->weight_kg} kg" : '—'),
            'prepared' => trim(($w->created_by_name ?? '').', '.$w->created_at?->format('j M Y H:i'), ', '),
            'dispatched' => $w->dispatched_at ? trim(($w->dispatched_by_name ?? '').', '.$w->dispatched_at->format('j M Y H:i'), ', ') : null,
            'received' => $w->delivered_at ? ($w->received_by_name ?? '').', '.$w->delivered_at->format('j M Y H:i').($w->pod_via === 'link' ? ' · confirmed by the receiver (QR)' : '') : null,
            'pod' => $w->pod_path ? route('print.waybill.pod', $w->id, false) : null, 'pod_note' => $w->pod_note, 'notes' => $w->notes,
            'cancel_reason' => $w->status === 'cancelled' ? $w->cancel_reason : null,
            'lines' => collect($w->lines)->map(fn ($l) => ['sku' => $l['sku'] ?? '', 'name' => $l['name'] ?? '', 'qty' => rtrim(rtrim((string) ($l['qty'] ?? ''), '0'), '.').' '.($l['unit'] ?? '')])->all(),
            'actions' => ['print' => route('print.waybill', $w->id, false), 'dispatch' => $w->status === 'prepared'
                ? ['transporter' => $w->transporter, 'driver_name' => $w->driver_name, 'driver_phone' => $w->driver_phone, 'vehicle_no' => $w->vehicle_no] : null,
                'deliver' => $open, 'cancel' => $open]];
    }

    /** Recent documents of a kind that can travel with a waybill, from branches where the person may make that waybill. */
    public function sources(string $kind): array
    {
        if (! isset(WaybillService::PERMISSIONS[$kind])) {
            return [];
        }
        $ids = Erp::branchIds(WaybillService::PERMISSIONS[$kind]);
        $in = fn ($q, string $col = 'branch_id') => $q->when($ids !== null, fn ($x) => $x->whereIn($col, $ids));
        $since = now()->subDays(120);

        return self::choices(match ($kind) {
            'delivery' => $in(Delivery::with('customer')->where('created_at', '>=', $since))->latest()->limit(300)->get()->mapWithKeys(fn ($d) => [$d->id => "{$d->number} · {$d->customer?->name}"])->all(),
            'transfer' => StockTransfer::with('toWarehouse')->where('created_at', '>=', $since)
                ->when($ids !== null, fn ($q) => $q->whereIn('from_warehouse_id', Warehouse::whereIn('branch_id', $ids)->pluck('id')))
                ->latest()->limit(300)->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->number} → {$t->toWarehouse?->name}"])->all(),
            'supplier_return' => $in(SupplierReturn::with('supplier')->where('status', '<>', 'cancelled')->where('created_at', '>=', $since))->latest()->limit(300)->get()
                ->mapWithKeys(fn ($r) => [$r->id => "{$r->number} · {$r->supplier?->name}"])->all(),
            'inbound' => $in(GoodsReceipt::with('supplier')->where('created_at', '>=', $since))->latest()->limit(300)->get()->mapWithKeys(fn ($g) => [$g->id => "{$g->number} · {$g->supplier?->name}"])->all(),
        });
    }

    /** The kinds of waybill this person may make. */
    public function waybillKinds(): array
    {
        return self::choices(array_filter(Waybill::KINDS, fn ($l, $k) => Erp::canAnywhere(WaybillService::PERMISSIONS[$k] ?? '-'), ARRAY_FILTER_USE_BOTH));
    }

    // -------------------------------------------------------------------------------------------- Bins

    public static function canBins(): bool
    {
        return BinResource::canViewAny();
    }

    /** @param  array<string, mixed>  $p  q, warehouse, archived (1 = show archived), page, per */
    public function bins(array $p): array
    {
        $q = BinResource::getEloquentQuery()->when(($p['archived'] ?? '') !== '1', fn ($q) => $q->whereNull('bins.archived_at'), fn ($q) => $q->whereNotNull('bins.archived_at'));
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
        }
        if (filled($p['warehouse'] ?? null)) {
            $q->where('warehouse_id', (string) $p['warehouse']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $bins = $q->orderBy('pick_order')->orderBy('code')->forPage($page, $per)->get();
        $holds = DB::table('stock_bin_levels')->join('products', 'products.id', '=', 'stock_bin_levels.product_id')->whereIn('bin_id', $bins->modelKeys())
            ->where('on_hand', '<>', 0)->orderBy('products.name')->get(['bin_id', 'products.id', 'products.name', 'on_hand'])->groupBy('bin_id');
        $archive = app(ArchiveService::class);
        $bwh = Warehouse::where('use_bins', true)->orderBy('code')->get();

        return ['total' => $total, 'page' => $page, 'per' => $per,
            'warehouses' => self::choices($bwh->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all()),
            'can_create' => BinResource::canCreate(),
            'rows' => $bins->map(fn (Bin $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'warehouse_id' => $b->warehouse_id, 'warehouse' => $b->warehouse?->code,
                'kind' => Bin::KINDS[$b->kind] ?? $b->kind, 'pick_order' => (int) $b->pick_order, 'active' => (bool) $b->is_active, 'archived' => (bool) $b->archived_at,
                'holds' => ($holds->get($b->id) ?? collect())->take(4)->map(fn ($r) => Fields::qty($r->on_hand).' '.$r->name)->implode(', ') ?: 'empty',
                'products' => self::choices(($holds->get($b->id) ?? collect())->filter(fn ($r) => (float) $r->on_hand > 0)->pluck('name', 'id')->all()),
                'can_edit' => BinResource::canEdit($b),
                'can_move' => Erp::can('stock.receive', $b->warehouse) || Erp::can('stock.transfer', $b->warehouse),
                'can_archive' => ! $b->archived_at && $archive->allowed($this->user, $b), 'can_restore' => (bool) $b->archived_at && $archive->allowed($this->user, $b)])->all()];
    }

    /** The next free code in a store ("A-01-03" after "A-01-02"), and the store's other bins to move stock to. */
    public function binHelp(Warehouse $w, ?string $except = null): array
    {
        return ['next_code' => Codes::after(Bin::where('warehouse_id', $w->id)->pluck('code')) ?? 'A-01-01',
            'bins' => self::choices(collect(Fields::bins($w->id))->except($except ?? '')->all())];
    }

    /** @return array<int, array{id: string, label: string}> a product's units, for moving stock */
    public function units(string $productId): array
    {
        return self::choices(ProductUom::with('uom')->where('product_id', $productId)->get()->mapWithKeys(fn ($u) => [$u->id => $u->uom->code])->all());
    }

    // -------------------------------------------------------------------------------------------- My day

    public static function canMyDay(): bool
    {
        return MyDay::canAccess();
    }

    /** @return array<string, string> the stores this person may see */
    public static function stores(): array
    {
        $ids = Erp::branchIds('stock.view');

        return Warehouse::when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all();
    }

    public function myDay(array $p): array
    {
        $stores = self::stores();
        $id = (string) ($p['warehouse'] ?? '');
        $id = array_key_exists($id, $stores) ? $id : (string) (array_key_first($stores) ?? '');
        $w = $id !== '' ? Warehouse::find($id) : null;
        if (! $w) {
            return ['warehouses' => self::choices($stores), 'warehouse' => null, 'cards' => [], 'cycle' => ['on' => false], 'checks' => null];
        }
        $today = StockBatches::today();
        $expiring = fn (int $days) => DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
            ->where('l.warehouse_id', $w->id)->where('l.on_hand', '>', 0)->where('b.expires_on', '>=', $today)->where('b.expires_on', '<', now()->addDays($days)->toDateString())->count();
        $expired = DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
            ->where('l.warehouse_id', $w->id)->where('l.on_hand', '>', 0)->where('b.expires_on', '<', $today)->count();
        $neverCounted = DB::table('stock_levels as sl')->join('products as p', 'p.id', '=', 'sl.product_id')->where('sl.warehouse_id', $w->id)
            ->where('sl.on_hand', '<>', 0)->where('p.track_stock', true)
            ->whereNotExists(fn ($q) => $q->from('stock_count_lines as l')->join('stock_counts as c', 'c.id', '=', 'l.stock_count_id')
                ->whereColumn('l.product_id', 'sl.product_id')->where('c.warehouse_id', $w->id)->where('c.status', 'posted')->whereNotNull('l.counted_qty'))->count();
        $expiryPage = ExpiryReturns::canAccess() ? '/office/expiry' : (BatchesExpiry::canAccess() ? '/office/batches' : null);
        $low = count(app(ReorderService::class)->suggestions($w));
        $e30 = $expiring(30);
        $card = fn (string $label, int $value, string $hint, ?string $url, string $tone) => compact('label', 'value', 'hint', 'url', 'tone');
        $cards = [
            $card('Low stock', $low, 'At or below the minimum (counting what is on order)', ReorderSuggestions::canAccess() ? "/office/what-to-order?warehouse={$w->id}" : null, $low ? 'warning' : 'ok'),
            $card('Expired, still in stock', $expired, 'Take them off the shelf', $expiryPage, $expired ? 'danger' : 'ok'),
            $card('Expiring in 30 days', $e30, 'Batches with stock', $expiryPage, $e30 ? 'warning' : 'ok'),
            $card('Expiring in 31–90 days', max(0, $expiring(90) - $e30), 'Plan returns or markdowns', $expiryPage, 'info'),
            $card('Negative stock', DB::table('stock_levels')->where('warehouse_id', $w->id)->where('on_hand', '<', 0)->count(), 'Sold more than the system had: count them',
                StockLevelResource::canViewAny() ? '/office/stock' : null, 'danger'),
            $card('Never counted here', $neverCounted, 'Products with stock, no count yet', null, 'info'),
            $card('Adjustments waiting for a manager', StockAdjustment::where('warehouse_id', $w->id)->where('status', 'submitted')->count(), 'Submitted, not yet posted',
                StockAdjustmentResource::canViewAny() ? '/office/adjustments' : null, 'info'),
            $card('Transfers on the way here', StockTransfer::where('to_warehouse_id', $w->id)->where('status', 'in_transit')->count(), 'Receive them when they arrive',
                StockTransferResource::canViewAny() ? '/office/transfers' : null, 'info'),
            $card('Orders to receive', PurchaseOrder::where('warehouse_id', $w->id)->whereIn('status', ['approved', 'partially_received'])->count(), 'Approved purchase orders',
                PurchaseOrdersScreen::canView() ? '/office/purchase-orders' : null, 'info'),
        ];
        $svc = app(CycleCounts::class);
        $cycle = ['on' => false];
        if ($svc->perDay($w->tenant_id) > 0) {
            $started = $svc->today($w);
            $cycle = ['on' => true, 'per_day' => $svc->perDay($w->tenant_id),
                'started' => $started ? ['number' => $started->number, 'status' => StockCount::STATUSES[$started->status] ?? $started->status, 'open' => "/office/counts/{$started->id}"] : null,
                'due' => $started ? [] : $svc->due($w, $svc->perDay($w->tenant_id))->map(fn ($d) => ['name' => $d->name, 'sku' => $d->sku, 'class' => $d->class,
                    'last_counted' => $d->last_counted ? substr((string) $d->last_counted, 0, 10) : null, 'often_wrong' => (bool) $d->often_wrong])->values()->all(),
                'wrong' => DB::table('products')->whereIn('id', $svc->oftenWrongIds($w))->orderBy('name')->limit(10)->pluck('name')->all(),
                'can_start' => Erp::can('stock.count', $w)];
        }
        $checks = app(StockChecks::class);
        $link = fn ($r) => match ($r->source_type) {
            'stock_adjustment' => "/office/adjustments/{$r->source_id}", 'stock_count' => "/office/counts/{$r->source_id}", default => null,
        };
        $larger = $checks->largerThanUsual($w->id);

        return ['warehouses' => self::choices($stores), 'warehouse' => $w->id, 'cards' => $cards, 'cycle' => $cycle, 'currency' => $this->money()['currency'],
            'exponent' => $this->money()['exponent'],
            'checks' => ['usual' => $larger->first()?->usual !== null ? (int) $larger->first()->usual : null,
                'larger' => $larger->map(fn ($r) => ['when' => substr((string) $r->when, 0, 10), 'name' => $r->name, 'sku' => $r->sku, 'reason' => $r->reason ?? 'Count shortage',
                    'by' => $r->by, 'value' => (int) $r->value, 'open' => $link($r)])->values()->all(),
                'again' => $checks->againAndAgain($w->id)->map(fn ($r) => ['name' => $r->name, 'sku' => $r->sku, 'times' => (int) $r->times, 'value' => (int) $r->value])->values()->all(),
                'duplicates' => $checks->possibleDuplicates($w->tenant_id)->map(fn ($r) => ['name' => $r->name, 'skus' => $r->skus])->values()->all()]];
    }
}
