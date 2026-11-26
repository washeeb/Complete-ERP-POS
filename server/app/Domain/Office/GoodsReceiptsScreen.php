<?php

namespace App\Domain\Office;

use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\Waybills\WaybillResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\GoodsReceipt;
use App\Models\User;
use App\Models\Waybill;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Goods received notes in the new back office (release 5 of the rewrite): the list with the current screen's search
 * (number, order, supplier, their delivery note), status filter and columns, and one note on a page of its own with
 * Print, Accept or Refuse a held delivery, Reverse, labels for what came in and the supplier waybill. Same query
 * (GoodsReceiptResource: the branches the person may see), same rules: GoodsReceiptService (GoodsReceiptsController).
 */
final class GoodsReceiptsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['received_at', 'number', 'net_minor'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return GoodsReceiptResource::canViewAny();
    }

    public function filtered(array $p): Builder
    {
        $q = GoodsReceiptResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('goods_receipts.number', 'ilike', "%{$search}%")->orWhere('goods_receipts.supplier_ref', 'ilike', "%{$search}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'ilike', "%{$search}%"))
                ->orWhereHas('purchaseOrder', fn (Builder $o) => $o->where('number', 'ilike', "%{$search}%")));
        }
        if (isset(GoodsReceipt::STATUSES[$p['status'] ?? ''])) {
            $q->where('goods_receipts.status', $p['status']);
        }
        if (filled($p['warehouse'] ?? null)) {
            $q->where('goods_receipts.warehouse_id', (string) $p['warehouse']);
        }

        return $q;
    }

    /** @return array{0: string, 1: string} */
    public function order(array $p): array
    {
        return [in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'received_at', ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
    }

    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->order($p);

        return $this->filtered($p)->orderBy("goods_receipts.{$sort}", $dir)->orderBy('goods_receipts.id', $dir);
    }

    public function rowOf(GoodsReceipt $g): array
    {
        return [
            'id' => $g->id, 'number' => $g->number, 'order' => $g->purchaseOrder?->number, 'supplier' => $g->supplier?->name, 'ref' => $g->supplier_ref,
            'warehouse' => $g->warehouse?->code, 'currency' => $g->currency_code, 'value' => (int) $g->net_minor,
            'received_at' => $g->received_at?->toIso8601String(), 'received_by' => $g->received_by_name, 'status' => $g->status,
        ];
    }

    /** @param  array<string, mixed>  $p  q, status, warehouse, sort, dir, page, per */
    public function list(array $p): array
    {
        $t = (clone $this->filtered($p))->toBase()->reorder()->selectRaw("COUNT(*) AS n, COALESCE(SUM(net_minor) FILTER (WHERE status = 'posted'), 0) AS value, "
            ."COUNT(*) FILTER (WHERE status = 'held') AS held")->first();
        [$sort, $dir] = $this->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->user->tenant?->currency_code ?? 'GHS';

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'total' => (int) $t->n, 'value' => (int) $t->value, 'held' => (int) $t->held,
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (GoodsReceipt $g) => $this->rowOf($g))->all(),
        ];
    }

    public function options(): array
    {
        return [
            'statuses' => PurchaseOrdersScreen::choices(GoodsReceipt::STATUSES),
            'warehouses' => PurchaseOrdersScreen::choices(Fields::warehouses(['purchasing.view', 'stock.receive'])),
            'per' => self::PER_PAGE,
        ];
    }

    /** One note: what the current page shows, and what may be done with it now. */
    public function show(GoodsReceipt $g): array
    {
        $g->loadMissing(['lines.bin', 'supplier', 'warehouse', 'purchaseOrder']);
        $cur = $g->currency_code;
        $manager = Erp::can('stock.receive.approve', $g->warehouse);
        $waybill = Waybill::where('source_id', $g->id)->where('status', '<>', 'cancelled')->first();

        return [
            'currency' => $cur, 'exponent' => Money::exponent($cur),
            'id' => $g->id, 'number' => $g->number, 'status' => $g->status, 'status_label' => GoodsReceipt::STATUSES[$g->status] ?? $g->status,
            'order' => $g->purchase_order_id ? ['number' => $g->purchaseOrder?->number,
                'open' => PurchaseOrdersScreen::canView() ? "/office/purchase-orders/{$g->purchase_order_id}" : null] : null,
            'supplier' => ['name' => $g->supplier?->name, 'open' => SuppliersScreen::canView() && $g->supplier_id ? "/office/suppliers/{$g->supplier_id}" : null],
            'warehouse' => $g->warehouse ? "{$g->warehouse->code} · {$g->warehouse->name}" : null, 'ref' => $g->supplier_ref,
            'received_by' => $g->received_by_name, 'received_at' => $g->received_at?->toIso8601String(), 'approved_by' => $g->approved_by_name,
            'value' => (int) $g->net_minor, 'reversal' => $g->status === 'reversed' ? ['reason' => $g->reversal_reason, 'by' => $g->reversed_by_name] : null, 'note' => $g->note,
            'lines' => $g->lines->map(fn ($l) => ['sku' => $l->sku, 'name' => $l->name, 'qty' => Fields::qty($l->qty, $l->uom_code), 'cost' => (int) $l->unit_cost_minor,
                'value' => (int) $l->net_minor, 'bin' => $l->bin?->code, 'serials' => array_values((array) ($l->serials ?? []))])->values()->all(),
            'product_ids' => $g->lines->pluck('product_id')->unique()->values()->all(),
            'actions' => [
                'print' => route('print.goods-receipt', $g->id, false),
                'accept' => $g->status === 'held' && $manager,
                'refuse' => $g->status === 'held' && $manager,
                'reverse' => $g->status === 'posted' && $manager,
                'labels' => ProductsScreen::canView(),
                'waybill' => $waybill && WaybillResource::canViewAny()
                    ? ['label' => 'Supplier waybill', 'open' => '/office/waybills/'.($waybill instanceof Model ? $waybill->getKey() : $waybill)]
                    : (WaybillResource::canCreate() ? ['label' => 'Supplier waybill', 'open' => '/office/waybills/new?'.http_build_query(['kind' => 'inbound', 'source' => $g->id])] : null),
            ],
        ];
    }
}
