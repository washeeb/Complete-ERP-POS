<?php

namespace App\Domain\Office;

use App\Domain\Payables\SupplierLedger;
use App\Domain\Payables\WithholdingTax;
use App\Filament\Pages\AgedPayables;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Supplier payments and Aged payables in the new back office (release 6 of the rewrite): the payments list with the
 * current screen's search, method filter and columns (what it settles, the withholding tax kept back and its
 * certificate, what was paid out), what is owed to each supplier by how late, and Pay a supplier on both — the
 * withholding tax worked out as the amount is typed — through SupplierPaymentService (SupplierPaymentsController).
 */
final class SupplierPaymentsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['paid_on', 'number', 'amount_minor'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return SupplierPaymentResource::canViewAny();
    }

    public static function canAging(): bool
    {
        return AgedPayables::canAccess();
    }

    public static function canPay(): bool
    {
        return Erp::canAnywhere('payables.pay') && config('erp.node.role') !== 'branch';
    }

    public function filtered(array $p): Builder
    {
        $q = SupplierPaymentResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('supplier_payments.number', 'ilike', "%{$search}%")->orWhere('supplier_payments.reference', 'ilike', "%{$search}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'ilike', "%{$search}%")));
        }
        if (isset(SupplierPayment::METHODS[$p['method'] ?? ''])) {
            $q->where('supplier_payments.method', $p['method']);
        }
        if (filled($p['supplier'] ?? null)) {
            $q->where('supplier_payments.supplier_id', (string) $p['supplier']);
        }

        return $q;
    }

    /** @return array{0: string, 1: string} */
    public function order(array $p): array
    {
        return [in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'paid_on', ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
    }

    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->order($p);

        return $this->filtered($p)->orderBy("supplier_payments.{$sort}", $dir)->orderBy('supplier_payments.id', $dir);
    }

    public function rowOf(SupplierPayment $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'paid_on' => $r->paid_on?->toDateString(), 'supplier' => $r->supplier?->name,
            'supplier_open' => SuppliersScreen::canView() && $r->supplier_id ? "/office/suppliers/{$r->supplier_id}" : null,
            'method' => $r->method, 'from' => $r->account?->name, 'reference' => $r->reference, 'amount' => (int) $r->amount_minor, 'wht' => (int) $r->wht_minor,
            'certificate_no' => $r->wht_certificate_no, 'certificate' => $r->wht_minor > 0 ? route('print.wht', $r->id, false) : null,
            'paid' => $r->cashMinor(), 'by' => $r->paid_by_name,
        ];
    }

    /** @param  array<string, mixed>  $p  q, method, supplier, sort, dir, page, per */
    public function list(array $p): array
    {
        $t = (clone $this->filtered($p))->toBase()->reorder()->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS settled, COALESCE(SUM(wht_minor), 0) AS wht')->first();
        [$sort, $dir] = $this->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency), 'total' => (int) $t->n, 'settled' => (int) $t->settled, 'wht' => (int) $t->wht,
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (SupplierPayment $r) => $this->rowOf($r))->all(),
        ];
    }

    public function options(): array
    {
        return [
            'methods' => PurchaseOrdersScreen::choices(SupplierPayment::METHODS),
            'suppliers' => PurchaseOrdersScreen::choices(Supplier::orderBy('name')->pluck('name', 'id')->all()),
            'per' => self::PER_PAGE, 'pay' => self::canPay() ? $this->payForm() : null, 'aging' => self::canAging(),
        ];
    }

    /** What Pay a supplier offers, as the current dialog. */
    public function payForm(): array
    {
        return [
            'suppliers' => PurchaseOrdersScreen::choices(Supplier::orderBy('name')->pluck('name', 'id')->all()),
            'accounts' => PurchaseOrdersScreen::choices(FinanceUi::moneyAccounts()), 'account' => (string) Account::where('system_key', 'bank')->value('id'),
            'methods' => PurchaseOrdersScreen::choices(SupplierPayment::METHODS), 'branches' => PurchaseOrdersScreen::choices(FinanceUi::branches()),
            'branch' => (string) FinanceUi::hq(), 'wht' => app(WithholdingTax::class)->enabled($this->user->tenant_id),
        ];
    }

    /** "GHS 30.00 (3 % of GHS 1,000.00 before VAT). Pay the supplier GHS 1,120.00." — as the current dialog works it out. */
    public function whtPreview(?string $supplierId, string $amount, bool $withhold, ?string $override): string
    {
        $supplier = $supplierId ? Supplier::find($supplierId) : null;
        if (! $supplier || ! is_numeric($amount) || (float) $amount <= 0) {
            return 'Choose the supplier and the amount.';
        }
        $gross = FinanceUi::toMinor($amount);
        if (! $withhold) {
            return 'None kept back. Pay '.FinanceUi::money($gross).'.';
        }
        $w = app(WithholdingTax::class)->compute($supplier, $gross, app(SupplierLedger::class)->plan($supplier->id, $gross));
        $given = filled($override) && is_numeric($override);
        $wht = $given ? FinanceUi::toMinor((string) $override) : $w['wht'];

        return ($w['why'] && ! $given ? $w['why'].' ' : FinanceUi::money($wht).($w['rate_bp'] ? ' ('.($w['rate_bp'] / 100).' % of '.FinanceUi::money($w['base']).' before VAT)' : '').'. ')
            .'Pay the supplier '.FinanceUi::money($gross - $wht).'.';
    }

    /** Aged payables: what is owed to each supplier, by how late (days past due), the largest first. */
    public function aging(): array
    {
        $aging = app(SupplierLedger::class)->aging();
        $names = Supplier::whereIn('id', array_keys($aging))->get(['id', 'code', 'name'])->keyBy('id');
        $currency = $this->currency();
        $rows = collect($aging)->map(fn ($b, $id) => ['id' => $id, 'name' => $names[$id]->name ?? '?', 'code' => $names[$id]->code ?? '',
            'open' => SuppliersScreen::canView() ? "/office/suppliers/{$id}" : null,
            'current' => (int) $b['current'], 'd1_30' => (int) $b['d1_30'], 'd31_60' => (int) $b['d31_60'], 'd61_90' => (int) $b['d61_90'],
            'd90_plus' => (int) $b['d90_plus'], 'total' => (int) $b['total']])->sortByDesc('total')->values();

        return ['currency' => $currency, 'exponent' => Money::exponent($currency), 'rows' => $rows->all(),
            'totals' => collect(['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'total'])->mapWithKeys(fn ($k) => [$k => (int) $rows->sum($k)])->all(),
            'pay' => self::canPay() ? $this->payForm() : null];
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }
}
