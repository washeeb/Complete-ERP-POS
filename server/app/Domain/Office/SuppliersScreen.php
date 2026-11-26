<?php

namespace App\Domain\Office;

use App\Domain\Org\ArchiveService;
use App\Domain\Purchasing\SupplierMessages;
use App\Domain\Purchasing\SupplierOnboarding;
use App\Domain\Purchasing\SupplierPortal;
use App\Domain\Purchasing\SupplierScorecard;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\ProductUom;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Support\IndustryContext;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Suppliers in the new back office (release 5 of the rewrite): the list with the current screen's search, status
 * filter and columns (status with what is missing, scorecard grade, terms, products, what we owe), one supplier on a
 * page of its own (details, how they are paid, products supplied, documents) with every action of the current screen,
 * and the add / edit form with the products they supply. Same query (SupplierResource), same rules: every change goes
 * through SupplierService, SupplierOnboarding, SupplierPortal, SupplierPaymentService and ArchiveService.
 */
final class SuppliersScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['name', 'code', 'owed'];

    private const OWED = '(SELECT balance_minor FROM supplier_balances WHERE supplier_balances.supplier_id = suppliers.id)';

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return SupplierResource::canViewAny();
    }

    public static function canManage(): bool
    {
        return SupplierResource::canCreate();
    }

    public static function canApprove(): bool
    {
        return Erp::canAnywhere('suppliers.approve');
    }

    /** What we owe them: payables people at head office (as the current "We owe" column). */
    public static function seesOwed(): bool
    {
        return Erp::canAnywhere('payables.view') && config('erp.node.role') !== 'branch';
    }

    /** The opening balance: the same check as SupplierPaymentService::opening (finance.journal for the company). */
    public static function canOpening(): bool
    {
        return Erp::can('finance.journal') && config('erp.node.role') !== 'branch';
    }

    /** The suppliers the list's search and filters pick. */
    public function filtered(array $p): Builder
    {
        $q = SupplierResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('suppliers.code', 'ilike', "%{$search}%")->orWhere('suppliers.name', 'ilike', "%{$search}%")
                ->orWhere('suppliers.phone', 'ilike', "%{$search}%")->orWhere('suppliers.email', 'ilike', "%{$search}%")
                ->orWhere('suppliers.contact_name', 'ilike', "%{$search}%"));
        }
        if (isset(Supplier::STATUSES[$p['status'] ?? ''])) {
            $q->where('suppliers.status', $p['status']);
        }
        if (($p['unverified'] ?? '') === '1') { // bank or MoMo details nobody has checked yet
            $q->whereNull('suppliers.bank_verified_at')->where(fn (Builder $w) => collect(Supplier::BANK_FIELDS)
                ->each(fn ($f) => $w->orWhere(fn ($x) => $x->whereNotNull("suppliers.{$f}")->where("suppliers.{$f}", '!=', ''))));
        }
        if (($p['owed'] ?? '') === '1' && self::seesOwed()) {
            $q->whereRaw('COALESCE('.self::OWED.', 0) > 0');
        }
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('suppliers.archived_at'),
            'all' => $q,
            default => $q->whereNull('suppliers.archived_at'),
        };

        return $q;
    }

    /** @return array{0: string, 1: string} */
    public function order(array $p): array
    {
        $sort = in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'name';
        if ($sort === 'owed' && ! self::seesOwed()) {
            $sort = 'name';
        }

        return [$sort, ($p['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc'];
    }

    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->order($p);
        $q = $this->filtered($p)->select('suppliers.*')->selectRaw(self::OWED.' AS owed_minor')->withCount('products');

        return ($sort === 'owed' ? $q->orderByRaw('COALESCE('.self::OWED.", 0) {$dir}") : $q->orderBy("suppliers.{$sort}", $dir))->orderBy('suppliers.id', $dir);
    }

    public function rowOf(Supplier $s): array
    {
        $card = SupplierScorecard::for($s);

        return [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'contact' => $s->contact_name, 'phone' => $s->phone,
            'status' => $s->status, 'note' => $s->status === 'blocked' ? $s->blocked_reason : (($g = app(SupplierOnboarding::class)->gaps($s)) ? implode(', ', $g) : null),
            'score' => $card['grade'] ? "{$card['grade']} ({$card['score']})" : null,
            'terms' => $s->payment_terms_days ? "{$s->payment_terms_days} days" : 'Cash', 'products' => (int) ($s->products_count ?? 0),
            'owed' => self::seesOwed() ? (int) ($s->owed_minor ?? 0) : null, 'archived' => $s->archived_at !== null,
            'bank_unchecked' => $s->hasBankDetails() && ! $s->bank_verified_at,
        ];
    }

    /** @param  array<string, mixed>  $p  q, status, unverified, owed, show, sort, dir, page, per */
    public function list(array $p): array
    {
        $t = (clone $this->filtered($p))->toBase()->reorder()
            ->selectRaw("COUNT(*) AS n, COUNT(*) FILTER (WHERE suppliers.status = 'pending') AS pending, COUNT(*) FILTER (WHERE suppliers.status = 'blocked') AS blocked, "
                .'COALESCE(SUM(GREATEST(COALESCE('.self::OWED.', 0), 0)), 0) AS owed')->first();
        [$sort, $dir] = $this->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'total' => (int) $t->n, 'pending' => (int) $t->pending, 'blocked' => (int) $t->blocked, 'owed' => self::seesOwed() ? (int) $t->owed : null,
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (Supplier $s) => $this->rowOf($s))->all(),
        ];
    }

    public function options(): array
    {
        $manage = self::canManage();

        return [
            'statuses' => self::choices(Supplier::STATUSES), 'per' => self::PER_PAGE, 'can_create' => $manage, 'sees_owed' => self::seesOwed(),
            'import' => $manage ? '/office/import?type=suppliers' : null,
        ];
    }

    /** One supplier: what the current screen shows across its list, form and documents, and what may be done here. */
    public function show(Supplier $s): array
    {
        $s->loadMissing(['products.unit.uom', 'products.unit.product', 'documents']);
        $cur = $s->currency_code ?: $this->currency();
        $onboarding = app(SupplierOnboarding::class);
        $required = $onboarding->required($s->tenant_id);
        $manage = self::canManage();
        $approve = self::canApprove();
        $archive = app(ArchiveService::class);
        $mayArchive = $archive->allowed($this->user, $s);
        $portal = app(SupplierPortal::class);
        $bank = collect(['Bank' => $s->bank_name, 'Branch' => $s->bank_branch, 'Account name' => $s->bank_account_name,
            'Account number' => $s->bank_account_no, 'MoMo' => $s->momo_number])->filter(fn ($v) => filled($v));
        $owed = self::seesOwed() ? (int) DB::table('supplier_balances')->where('supplier_id', $s->id)->value('balance_minor') : null;

        return [
            'currency' => $cur, 'exponent' => Money::exponent($cur),
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'status' => $s->status, 'status_label' => Supplier::STATUSES[$s->status] ?? $s->status,
            'blocked_reason' => $s->blocked_reason, 'gaps' => $onboarding->gaps($s), 'approval_on' => $onboarding->approvalOn($s->tenant_id),
            'archived' => $s->archived_at !== null,
            'archive_note' => $s->archived_at ? trim('Archived '.$s->archived_at->format('j M Y').(($by = User::find($s->archived_by)?->name) ? " by {$by}" : '')
                .($s->archive_reason ? ": {$s->archive_reason}" : '')) : null,
            'facts' => array_filter([
                'Contact person' => $s->contact_name, 'Phone' => $s->phone, 'Email' => $s->email, 'TIN / VAT number' => $s->tax_no,
                'Payment terms' => $s->payment_terms_days ? "{$s->payment_terms_days} days" : 'Cash',
                'Delivery lead time' => $s->lead_time_days !== null ? "{$s->lead_time_days} days" : null,
                'Takes back expiring stock' => $s->return_days_before_expiry !== null ? "Up to {$s->return_days_before_expiry} days before expiry" : 'No',
                'They supply' => Supplier::SUPPLY_KINDS[$s->supply_kind ?? 'goods'] ?? $s->supply_kind,
                'Approved' => $s->approved_at ? "By {$s->approved_by_name}, {$s->approved_at->format('j M Y')}" : null,
                'Address' => $s->address,
            ], fn ($v) => filled($v)),
            'notes' => $s->notes, 'owed' => $owed,
            // Bank details are shown to the people who keep or check them; others only see whether they are on file.
            'bank' => [
                'details' => $manage || $approve ? $bank->all() : ($bank->isNotEmpty() ? ['On file' => 'Yes'] : []),
                'checked' => $s->bank_verified_at ? "By {$s->bank_verified_by_name}, {$s->bank_verified_at->format('j M Y')}" : null,
                'unchecked' => $bank->isNotEmpty() && ! $s->bank_verified_at,
            ],
            'products' => $s->products->sortBy(fn ($p) => $p->unit?->product?->name)->values()->map(fn ($p) => [
                'label' => $p->unit ? Fields::unitLabel($p->unit, true) : '—', 'sku' => $p->supplier_sku,
                'cost' => $p->last_cost_minor === null ? null : (int) $p->last_cost_minor, 'min' => Fields::qty($p->min_order_qty), 'multiple' => Fields::qty($p->order_multiple),
                'preferred' => (bool) $p->is_preferred])->all(),
            'documents' => [
                'required' => array_map(fn ($k) => SupplierDocument::KINDS[$k], $required),
                'rows' => $s->documents->sortBy('kind')->values()->map(fn (SupplierDocument $d) => [
                    'id' => $d->id, 'kind' => $d->label(), 'required' => in_array($d->kind, $required, true), 'number' => $d->number,
                    'expires_on' => $d->expires_on?->toDateString(), 'expired' => $d->expired(), 'soon' => ! $d->expired() && $d->expires_on && $d->expires_on->lte(now()->addDays(30)),
                    'by' => $d->uploaded_by_name, 'checked_by' => $d->verified_by_name, 'file' => (bool) $d->file_path,
                    'can_verify' => ! $d->verified_at && $approve && $d->uploaded_by !== $this->user->id, 'can_remove' => $manage])->all(),
                'kinds' => self::choices(SupplierDocument::KINDS), 'can_add' => $manage,
            ],
            'scorecard' => SupplierScorecard::for($s),
            'actions' => [
                'edit' => $manage,
                'approve' => $s->status === 'pending' && $approve,
                'block' => $s->status !== 'blocked' && $approve,
                'unblock' => $s->status === 'blocked' && $approve,
                'verify_bank' => $bank->isNotEmpty() && ! $s->bank_verified_at && $approve
                    ? ['confirm' => $bank->map(fn ($v, $k) => $k === 'MoMo' ? "MoMo {$v}" : $v)->implode(' · ')] : null,
                'portal' => $s->status !== 'blocked' && Erp::canAnywhere('purchasing.manage') && $portal->enabled($s->tenant_id) ? [
                    'channels' => self::choices(app(SupplierMessages::class)->channels($s->tenant_id) + ['none' => 'Do not send; just show me the link']),
                    'email' => $s->email, 'phone' => $s->phone, 'has_link' => (bool) $s->portal_token,
                ] : null,
                'opening' => self::canOpening() && ! DB::table('supplier_ledger')->where('supplier_id', $s->id)->exists(),
                'archive' => $mayArchive && ! $s->archived_at ? ['blockers' => array_values($archive->blockers($s))] : null,
                'restore' => $mayArchive && (bool) $s->archived_at,
            ],
        ];
    }

    /** The add / edit form: the current form's fields, with the products they supply. */
    public function form(?Supplier $s): array
    {
        $industries = IndustryContext::choices($this->user);
        if ($s?->exists && $s->industry_id === null) {
            $industries[IndustryContext::SHARED] = 'Shared by all industries';
        }
        $named = array_diff_key($industries, [IndustryContext::SHARED => true]);
        $cur = $s?->currency_code ?: $this->currency();
        $s?->loadMissing('products.unit.uom', 'products.unit.product');

        return [
            'id' => $s?->id, 'code' => $s?->code, 'name' => $s?->name, 'currency' => $cur,
            'values' => [
                'name' => $s?->name ?? '', 'industry_id' => $s ? ($s->industry_id ?? IndustryContext::SHARED) : (string) IndustryContext::defaultId(),
                'contact_name' => $s?->contact_name ?? '', 'phone' => $s?->phone ?? '', 'email' => $s?->email ?? '', 'tax_no' => $s?->tax_no ?? '',
                'payment_terms_days' => (string) ($s?->payment_terms_days ?? 0), 'lead_time_days' => $s?->lead_time_days === null ? '' : (string) $s->lead_time_days,
                'return_days_before_expiry' => $s?->return_days_before_expiry === null ? '' : (string) $s->return_days_before_expiry,
                'address' => $s?->address ?? '', 'notes' => $s?->notes ?? '', 'supply_kind' => $s?->supply_kind ?? 'goods',
                'bank_name' => $s?->bank_name ?? '', 'bank_branch' => $s?->bank_branch ?? '', 'bank_account_name' => $s?->bank_account_name ?? '',
                'bank_account_no' => $s?->bank_account_no ?? '', 'momo_number' => $s?->momo_number ?? '',
                'products' => $s ? $s->products->map(fn ($p) => [
                    'product_uom_id' => $p->product_uom_id, 'label' => $p->unit ? Fields::unitLabel($p->unit, true) : null, 'supplier_sku' => $p->supplier_sku ?? '',
                    'last_cost' => $p->last_cost_minor === null ? '' : Money::format($p->last_cost_minor, $cur), 'min_order_qty' => Fields::qty($p->min_order_qty),
                    'order_multiple' => Fields::qty($p->order_multiple), 'is_preferred' => (bool) $p->is_preferred])->values()->all() : [],
            ],
            'choices' => ['industries' => self::choices($industries), 'supply_kinds' => self::choices(Supplier::SUPPLY_KINDS)],
            'sections' => ['industry' => count($named) > 1 || isset($industries[IndustryContext::SHARED])],
            'bank_checked' => $s?->bank_verified_at ? "By {$s->bank_verified_by_name}, {$s->bank_verified_at->format('j M Y')}" : null,
        ];
    }

    /** The form's values → SupplierService's data (as the current form sends it). */
    public function toDomain(array $v): array
    {
        $data = array_intersect_key($v, array_flip(['name', 'industry_id', 'contact_name', 'phone', 'email', 'tax_no', 'payment_terms_days', 'lead_time_days',
            'return_days_before_expiry', 'address', 'notes', 'supply_kind', ...Supplier::BANK_FIELDS]));
        $data = array_map(fn ($x) => is_string($x) && trim($x) === '' ? null : $x, $data);
        if (! $this->form(null)['sections']['industry']) {
            unset($data['industry_id']); // not asked: the service gives the person's own industry
        }
        $data['payment_terms_days'] = (int) ($data['payment_terms_days'] ?? 0);
        foreach (['lead_time_days', 'return_days_before_expiry'] as $k) {
            $data[$k] = $data[$k] === null ? null : (int) $data[$k];
        }
        $data['products'] = array_map(fn ($r) => [
            'product_uom_id' => $r['product_uom_id'] ?? null, 'supplier_sku' => filled($r['supplier_sku'] ?? null) ? trim($r['supplier_sku']) : null,
            'last_cost' => (string) ($r['last_cost'] ?? ''), 'min_order_qty' => (string) ($r['min_order_qty'] ?? '0'),
            'order_multiple' => (string) ($r['order_multiple'] ?? '0'), 'is_preferred' => (bool) ($r['is_preferred'] ?? false),
        ], array_values((array) ($v['products'] ?? [])));

        return $data;
    }

    /** Product units a supplier may sell (as the current form's product field: buying units, any active product). */
    public function units(string $q): array
    {
        if (mb_strlen($q) < 2) {
            return [];
        }

        return ProductUom::with('product', 'uom')
            ->whereHas('product', fn (Builder $w) => IndustryContext::productScope($w)->whereNull('products.archived_at'))
            ->where(fn ($w) => $w->whereHas('product', fn (Builder $x) => $x->where('is_active', true)->where(fn ($y) => $y->where('name', 'ilike', "%{$q}%")
                ->orWhere('sku', 'ilike', "%{$q}%")))->orWhereHas('barcodes', fn ($b) => $b->where('code', $q)))
            ->where('is_purchasable', true)
            ->limit(30)->get()
            ->sortBy(fn ($u) => [$u->product->name, $u->product->buying_uom_id === $u->uom_id ? 0 : 1, -(float) $u->factor])->values()
            ->map(fn (ProductUom $u) => ['id' => $u->id, 'product_id' => $u->product_id, 'label' => Fields::unitLabel($u, true), 'batches' => false, 'serialised' => false])->all();
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
