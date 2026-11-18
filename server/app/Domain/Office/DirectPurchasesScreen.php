<?php

namespace App\Domain\Office;

use App\Domain\Ai\AiGateway;
use App\Domain\Purchasing\DirectPurchases;
use App\Domain\Support\Actor;
use App\Filament\Resources\DirectPurchases\DirectPurchaseResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\DirectPurchase;
use App\Models\DirectPurchaseLine;
use App\Models\Industry;
use App\Models\PriceList;
use App\Models\ProductUom;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Models\User;
use App\Support\IndustryContext;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Direct purchases in the new back office (release 5 of the rewrite): goods that come with a supplier's invoice and no
 * purchase order, paid on delivery, on credit or on consignment. The list with the current screen's search, filters
 * and columns; one purchase with the checks before posting, its lines, and Send for approval, Post, Cancel, the invoice
 * pages and labels; and the form (with the invoice scanned by the AI, and a product added on the spot). Same query
 * (DirectPurchaseResource: the industry in view), same rules: DirectPurchases (DirectPurchasesController).
 */
final class DirectPurchasesScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['created_at', 'invoice_date', 'number', 'total_minor'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return DirectPurchaseResource::canViewAny();
    }

    public static function canCreate(): bool
    {
        return DirectPurchaseResource::canCreate();
    }

    public function filtered(array $p): Builder
    {
        $q = DirectPurchaseResource::getEloquentQuery()->with('supplier');
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('direct_purchases.number', 'ilike', "%{$search}%")->orWhere('direct_purchases.invoice_no', 'ilike', "%{$search}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'ilike', "%{$search}%")));
        }
        if (isset(DirectPurchase::STATUSES[$p['status'] ?? ''])) {
            $q->where('direct_purchases.status', $p['status']);
        }
        if (isset(DirectPurchase::SETTLEMENTS[$p['settlement'] ?? ''])) {
            $q->where('direct_purchases.settlement', $p['settlement']);
        }

        return $q;
    }

    /** @return array{0: string, 1: string} */
    public function order(array $p): array
    {
        return [in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'created_at', ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
    }

    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->order($p);

        return $this->filtered($p)->orderBy("direct_purchases.{$sort}", $dir)->orderBy('direct_purchases.id', $dir);
    }

    public function rowOf(DirectPurchase $d): array
    {
        return [
            'id' => $d->id, 'number' => $d->number, 'invoice_date' => $d->invoice_date?->toDateString(), 'supplier' => $d->supplier?->name, 'invoice_no' => $d->invoice_no,
            'settlement' => $d->settlement, 'currency' => $d->currency_code, 'total' => (int) $d->total_minor, 'status' => $d->status, 'by' => $d->created_by_name,
        ];
    }

    /** @param  array<string, mixed>  $p  q, status, settlement, sort, dir, page, per */
    public function list(array $p): array
    {
        $t = (clone $this->filtered($p))->toBase()->reorder()->selectRaw("COUNT(*) AS n, COALESCE(SUM(total_minor) FILTER (WHERE status = 'posted'), 0) AS posted, "
            ."COUNT(*) FILTER (WHERE status = 'submitted') AS waiting, COUNT(*) FILTER (WHERE status = 'draft') AS drafts")->first();
        [$sort, $dir] = $this->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'total' => (int) $t->n, 'posted' => (int) $t->posted, 'waiting' => (int) $t->waiting, 'drafts' => (int) $t->drafts,
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (DirectPurchase $d) => $this->rowOf($d))->all(),
        ];
    }

    public function options(): array
    {
        return ['statuses' => self::choices(DirectPurchase::STATUSES), 'settlements' => self::choices(DirectPurchase::SETTLEMENTS), 'per' => self::PER_PAGE,
            'can_create' => self::canCreate()];
    }

    /** One purchase: what the current page shows, the checks before posting, and what may be done now. */
    public function show(DirectPurchase $d): array
    {
        $d->loadMissing(['supplier', 'warehouse', 'goodsReceipt', 'supplierBill', 'supplierPayment', 'lines.product', 'lines.unit.uom', 'lines.sellUnit.uom']);
        $cur = $d->currency_code;
        $svc = app(DirectPurchases::class);
        $actor = Actor::user($this->user, 'web');
        $why = $d->isOpen() ? $svc->whyNotPost($actor, $d) : null;

        return [
            'currency' => $cur, 'exponent' => Money::exponent($cur),
            'id' => $d->id, 'number' => $d->number, 'status' => $d->status, 'status_label' => DirectPurchase::STATUSES[$d->status] ?? $d->status,
            'supplier' => ['name' => $d->supplier?->name, 'open' => SuppliersScreen::canView() && $d->supplier_id ? "/office/suppliers/{$d->supplier_id}" : null],
            'invoice_no' => $d->invoice_no, 'invoice_date' => $d->invoice_date?->toDateString(), 'due_on' => $d->due_on?->toDateString(),
            'settlement' => $d->settlement, 'settlement_label' => DirectPurchase::SETTLEMENTS[$d->settlement] ?? $d->settlement,
            'net' => (int) $d->net_minor, 'tax' => (int) $d->tax_minor, 'total' => (int) $d->total_minor,
            'printed_total' => $d->printed_total_minor === null ? null : (int) $d->printed_total_minor, 'prices_include_tax' => (bool) $d->prices_include_tax,
            'warehouse' => $d->warehouse ? "{$d->warehouse->code} · {$d->warehouse->name}" : null,
            'method' => $d->method ? (SupplierPayment::METHODS[$d->method] ?? $d->method) : null, 'reference' => $d->reference,
            'created_by' => $d->created_by_name, 'posted_by' => $d->posted_by_name, 'reason' => $d->status === 'cancelled' ? $d->reason : null, 'note' => $d->note,
            'goods_receipt' => $d->goods_receipt_id ? ['number' => $d->goodsReceipt?->number,
                'open' => GoodsReceiptsScreen::canView() ? "/office/goods-received/{$d->goods_receipt_id}" : null] : null,
            'bill' => $d->supplierBill?->number, 'payment' => $d->supplierPayment?->number,
            'warnings' => $d->isOpen() ? $svc->warnings($d) : [],
            'lines' => $d->lines->map(fn (DirectPurchaseLine $l) => [
                'product' => $l->product?->name, 'qty' => rtrim(rtrim((string) $l->qty, '0'), '.').' '.$l->unit?->uom?->code, 'unit_price' => (int) $l->unit_price_minor,
                'batch' => $l->batch_no, 'expires_on' => $l->expires_on?->toDateString(), 'net' => (int) $l->net_minor, 'tax' => (int) $l->tax_minor,
                'new_price' => $l->new_price_minor === null ? null : (int) $l->new_price_minor, 'sell_unit' => $l->sellUnit?->uom?->code, 'margin' => DirectPurchases::margin($l),
            ])->values()->all(),
            'pages' => count((array) ($d->attachments ?? [])),
            'product_ids' => $d->lines->pluck('product_id')->unique()->values()->all(),
            'actions' => [
                'edit' => DirectPurchaseResource::canEdit($d), 'correcting' => $d->status === 'submitted',
                'submit' => $d->status === 'draft' && $why !== null && Erp::canAnywhere('purchasing.direct'),
                'post' => $d->isOpen() && $why === null ? ['what' => ['paid' => 'Receives the goods, enters the bill and records the payment of ', 'credit' => 'Receives the goods and enters the bill for ',
                    'consignment' => 'Receives the goods at no cost, on consignment worth '][$d->settlement].Money::display((int) $d->total_minor, $cur)
                    .'. New selling prices apply straight away. A posted purchase cannot be edited.'] : null,
                'why_not' => $d->isOpen() ? $why : null,
                'cancel' => $d->isOpen(),
                'labels' => $d->status === 'posted' && Erp::canAnywhere('catalog.view') && ProductsScreen::canView(),
            ],
        ];
    }

    /** The form: the current form's fields, with what each list offers. */
    public function form(?DirectPurchase $d): array
    {
        $cur = $d?->currency_code ?: $this->currency();
        $plain = fn (?int $v) => $v === null ? '' : number_format($v / (10 ** Money::exponent($cur)), Money::exponent($cur), '.', '');
        $d?->loadMissing('lines.unit.uom', 'lines.unit.product');
        $warehouses = Fields::warehouses('purchasing.direct');
        $industries = IndustryContext::choices($this->user);

        return [
            'id' => $d?->id, 'number' => $d?->number, 'currency' => $cur, 'correcting' => $d?->status === 'submitted',
            'values' => [
                'supplier_id' => (string) ($d?->supplier_id ?? ''), 'invoice_no' => $d?->invoice_no ?? '', 'invoice_date' => $d?->invoice_date?->toDateString() ?? now()->toDateString(),
                'warehouse_id' => (string) ($d?->warehouse_id ?? array_key_first($warehouses) ?? ''), 'prices_include_tax' => (bool) ($d?->prices_include_tax ?? false),
                'printed_total' => $plain($d?->printed_total_minor), 'attachments' => array_values((array) ($d?->attachments ?? [])),
                'settlement' => $d?->settlement ?? 'paid', 'method' => $d?->method ?? 'momo', 'account_id' => (string) ($d?->account_id ?? ''), 'reference' => $d?->reference ?? '',
                'due_on' => $d?->due_on?->toDateString() ?? '', 'price_list_id' => (string) ($d?->price_list_id ?? PriceList::where('is_default', true)->value('id') ?? ''),
                'note' => $d?->note ?? '',
                'lines' => $d ? $d->lines->map(fn (DirectPurchaseLine $l) => self::lineValues($l->unit, [
                    'qty' => rtrim(rtrim((string) $l->qty, '0'), '.'), 'unit_price' => $plain($l->unit_price_minor), 'tax_group_id' => (string) ($l->tax_group_id ?? ''),
                    'batch_no' => $l->batch_no ?? '', 'expires_on' => $l->expires_on?->toDateString() ?? '', 'supplier_sku' => $l->supplier_sku ?? '',
                    'sell_uom_id' => (string) ($l->sell_uom_id ?? ''), 'new_price' => $plain($l->new_price_minor)]))->values()->all() : [],
            ],
            'choices' => [
                'suppliers' => self::choices(Supplier::where(fn ($q) => $q->where('is_active', true)->when($d, fn ($x) => $x->orWhere('id', $d->supplier_id)))->orderBy('name')->pluck('name', 'id')->all()),
                'warehouses' => self::choices($warehouses), 'settlements' => self::choices(DirectPurchase::SETTLEMENTS), 'methods' => self::choices(SupplierPayment::METHODS),
                'accounts' => self::choices(FinanceUi::moneyAccounts()), 'tax_groups' => self::choices(TaxGroup::orderBy('name')->pluck('name', 'id')->all()),
                'price_lists' => self::choices(PriceList::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all()),
                'uoms' => self::choices(Uom::orderBy('name')->pluck('name', 'id')->all()),
                'industries' => Industry::licensed()->exists() ? self::choices($industries) : null,
            ],
            'short_dated_months' => (int) app(Settings::class)->get('purchasing.short_dated_months', $this->user->tenant_id),
            'can_add_product' => Erp::canAnywhere('catalog.manage'),
            'ai_unavailable' => app(AiGateway::class)->unavailableReason($this->user->tenant_id, 'vision'),
        ];
    }

    /** A line as the form shows it: the unit bought, its label and factor, the units it may be sold in, and the values. */
    public static function lineValues(?ProductUom $u, array $values): array
    {
        return ['product_uom_id' => (string) ($u?->id ?? ''), 'label' => $u ? Fields::unitLabel($u, true) : null, 'read_as' => null,
            'batched' => (bool) $u?->product?->track_batches, 'factor' => $u ? (float) $u->factor : 1.0, 'sell_units' => $u ? self::sellUnits($u) : []] + $values
            + ['qty' => '', 'unit_price' => '', 'tax_group_id' => '', 'batch_no' => '', 'expires_on' => '', 'supplier_sku' => '', 'sell_uom_id' => '', 'new_price' => ''];
    }

    /** @return list<array{id: string, label: string, factor: float}> the units a product is sold in */
    public static function sellUnits(ProductUom $bought): array
    {
        return ProductUom::with('uom')->where('product_id', $bought->product_id)->where('is_sellable', true)->get()
            ->map(fn (ProductUom $u) => ['id' => $u->id, 'label' => $u->uom->name.((float) $u->factor != 1 ? ' × '.rtrim(rtrim((string) $u->factor, '0'), '.') : ''),
                'factor' => (float) $u->factor])->values()->all();
    }

    /** The form's values → DirectPurchases::save's data (as the current form sends it). */
    public function toDomain(array $v): array
    {
        $blank = fn ($x) => is_string($x) && trim($x) === '' ? null : $x;
        $data = array_map($blank, array_intersect_key($v, array_flip(['supplier_id', 'invoice_no', 'invoice_date', 'warehouse_id', 'printed_total', 'settlement', 'method',
            'account_id', 'reference', 'due_on', 'price_list_id', 'note'])));
        $data['prices_include_tax'] = (bool) ($v['prices_include_tax'] ?? false);
        $data['attachments'] = array_values(array_filter((array) ($v['attachments'] ?? []), fn ($p) => is_string($p) && str_starts_with($p, 'direct-purchases/')));
        $data['lines'] = array_map(fn ($l) => array_map($blank, array_intersect_key((array) $l, array_flip(['product_uom_id', 'qty', 'unit_price', 'tax_group_id', 'batch_no',
            'expires_on', 'supplier_sku', 'sell_uom_id', 'new_price']))), array_values((array) ($v['lines'] ?? [])));

        return $data;
    }

    /** @param  array<string, string>  $map */
    public static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }
}
