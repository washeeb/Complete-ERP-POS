<?php

namespace App\Domain\Office;

use App\Domain\Customers\CustomerLedger;
use App\Domain\Customers\CustomerService;
use App\Domain\Customers\Loyalty;
use App\Domain\Modules\ModuleService;
use App\Domain\Org\ArchiveService;
use App\Domain\Sales\ReceivablesService;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\CrmUi;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\CrmActivity;
use App\Models\Customer;
use App\Models\PatientProfile;
use App\Models\PriceList;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\IndustryContext;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customers in the new back office (release 4 of the rewrite): the list with the current screen's search (code, name,
 * phone — a number typed as 024… also finds +233…), filters and columns, one customer on a page of their own (details,
 * account, what is still owed) with every action of the current page, and the add / edit form. Same query
 * (CustomerResource), same rules: every change goes through CustomerService, ReceivablesService, PharmacyService,
 * Crm and ArchiveService (CustomersController).
 */
final class CustomersScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['name', 'code', 'balance'];

    public const CONSENT_SOURCES = ['in person' => 'In person', 'paper form' => 'Paper form', 'phone call' => 'Phone call', 'online' => 'Online'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return CustomerResource::canViewAny();
    }

    public static function canManage(): bool
    {
        return CustomerResource::canCreate();
    }

    /** Credit limits and terms: head office only, with its own permission (as the current "Credit" button). */
    public static function canCredit(): bool
    {
        return Erp::can('customers.credit.manage') && config('erp.node.role') !== 'branch';
    }

    public static function query(): Builder
    {
        return CustomerResource::getEloquentQuery();
    }

    /** The customers the list's search and filters pick. */
    public function filtered(array $p): Builder
    {
        $q = self::query();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $phone = CustomerService::normalisePhone($search);
            $q->where(fn (Builder $w) => $w->where('customers.code', 'ilike', "%{$search}%")->orWhere('customers.name', 'ilike', "%{$search}%")
                ->orWhere('customers.phone', 'ilike', "%{$search}%")->orWhere('customers.contact_name', 'ilike', "%{$search}%")
                ->when($phone && strlen($phone) >= 8, fn ($x) => $x->orWhere('customers.phone', $phone)));
        }
        if (isset(Customer::TYPES[$p['type'] ?? ''])) {
            $q->where('customers.type', $p['type']);
        }
        if (($p['owing'] ?? null) === '1') {
            $q->whereHas('balance', fn ($b) => $b->where('balance_minor', '>', 0));
        }
        if (($p['credit'] ?? null) === '1') {
            $q->whereNotNull('customers.credit_limit_minor');
        }
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('customers.archived_at'),
            'all' => $q,
            default => $q->whereNull('customers.archived_at'),
        };

        return $q;
    }

    /** The filtered customers in the list's order, with their balance. */
    public function sorted(array $p): Builder
    {
        $sort = in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'name';
        $dir = ($p['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $q = $this->filtered($p)->select('customers.*')
            ->selectRaw('(SELECT balance_minor FROM customer_balances WHERE customer_balances.customer_id = customers.id) AS balance_sum');

        return ($sort === 'balance' ? $q->orderByRaw("COALESCE((SELECT balance_minor FROM customer_balances WHERE customer_balances.customer_id = customers.id), 0) {$dir}")
            : $q->orderBy("customers.{$sort}", $dir))->orderBy('customers.id', $dir);
    }

    /** @param  array<string, mixed>  $p  q, type, owing (1), credit (1), show (''|archived|all), sort, dir, page, per */
    public function list(array $p): array
    {
        $q = $this->filtered($p);
        $totals = (clone $q)->toBase()->reorder()
            ->leftJoin('customer_balances', 'customer_balances.customer_id', '=', 'customers.id')
            ->selectRaw('COUNT(*) AS n, COUNT(*) FILTER (WHERE customer_balances.balance_minor > 0) AS owing, '
                .'COALESCE(SUM(customer_balances.balance_minor) FILTER (WHERE customer_balances.balance_minor > 0), 0) AS owed, '
                .'COUNT(*) FILTER (WHERE customers.credit_limit_minor IS NOT NULL) AS credit, COUNT(*) FILTER (WHERE customers.credit_hold) AS hold')
            ->first();
        $sort = in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'name';
        $dir = ($p['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'total' => (int) $totals->n, 'owing' => (int) $totals->owing, 'owed' => (int) $totals->owed, 'credit' => (int) $totals->credit, 'hold' => (int) $totals->hold,
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (Customer $c) => $this->rowOf($c))->all(),
        ];
    }

    public function rowOf(Customer $c): array
    {
        return [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'contact' => $c->contact_name, 'phone' => $c->phone, 'type' => $c->type,
            'price_list' => $c->priceList?->name, 'balance' => $c->balance_sum === null ? null : (int) $c->balance_sum,
            'credit_limit' => $c->credit_limit_minor, 'hold' => (bool) $c->credit_hold, 'archived' => $c->archived_at !== null,
        ];
    }

    public function options(): array
    {
        $manage = self::canManage();

        return [
            'types' => self::choices(Customer::TYPES),
            'per' => self::PER_PAGE,
            'can_create' => $manage,
            'import' => $manage ? '/office/import?type=customers' : null,
        ];
    }

    /** One customer: what the current page shows (details, account, open items) and what may be done here. */
    public function show(Customer $c): array
    {
        $c->loadMissing(['balance', 'priceList', 'homeBranch', 'industry']);
        $currency = $this->currency();
        $m = fn (?int $v) => $v === null ? null : Money::display($v, $currency);
        $ledger = app(CustomerLedger::class);
        $consent = collect(Customer::CONSENT_CHANNELS)->filter(fn ($l, $ch) => $c->hasConsent($ch))->values()->implode(', ');
        $pharmacy = Erp::canAnywhere('pharmacy.patients') && ModuleService::enabledAnywhere('pharmacy');
        $patient = $pharmacy ? PatientProfile::find($c->id) : null;
        $credit = self::canCredit();
        $archive = app(ArchiveService::class);
        $mayArchive = $archive->allowed($this->user, $c);
        $collect = Erp::canAnywhere('receivables.collect');
        $crm = CrmUi::canManage();

        $facts = array_filter([
            'Code' => $c->code, 'Type' => Customer::TYPES[$c->type] ?? $c->type, 'Phone' => $c->phone ?: '—', 'Email' => $c->email ?: '—',
            'Contact person' => $c->contact_name ?: null, 'TIN / Ghana Card' => $c->tax_no ?: '—', 'Price list' => $c->priceList?->name ?? 'Company default',
            'Home branch' => $c->homeBranch?->name ?? '—', 'Industry' => count(IndustryContext::choices($this->user)) > 1 ? ($c->industry?->name ?? '—') : null,
            'Loyalty points' => number_format(app(Loyalty::class)->balance($c->id)), 'Consent' => $consent ?: 'none',
            'Tags' => $c->tags ? implode(', ', (array) $c->tags) : null, 'Address' => $c->address ?: null,
        ], fn ($v) => $v !== null);

        $open = array_map(fn ($r) => [
            'number' => $r->doc_number, 'due' => $r->due_on, 'overdue' => $r->due_on !== null && $r->due_on < now()->toDateString(),
            'amount' => (int) $r->amount_minor, 'open' => (int) $r->open_minor,
            'url' => match ($r->doc_type) {
                'invoice' => "/office/invoices/{$r->doc_id}", 'sale' => "/office/sales/{$r->doc_id}", default => null
            },
        ], $ledger->openItems($c->id));

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'archived' => $c->archived_at !== null, 'hold' => (bool) $c->credit_hold,
            'archive_note' => $c->archived_at ? trim('Archived '.$c->archived_at->format('j M Y').(($by = User::find($c->archived_by)?->name) ? " by {$by}" : '')
                .($c->archive_reason ? ": {$c->archive_reason}" : '')) : null,
            'facts' => $facts, 'notes' => $c->notes,
            'account' => [
                'balance' => $c->balanceMinor(), 'overdue' => $ledger->overdueMinor($c->id), 'limit' => $c->credit_limit_minor,
                'available' => app(CustomerService::class)->availableCredit($c), 'terms' => $c->payment_terms_days ? "{$c->payment_terms_days} days" : 'Cash',
            ],
            'open_items' => $open,
            'patient' => $patient ? array_filter([
                'Date of birth' => $patient->date_of_birth?->format('j M Y'), 'Sex' => $patient->sex ? ucfirst($patient->sex) : null,
                'Allergies' => $patient->allergies ? implode(', ', (array) $patient->allergies) : null, 'Conditions / notes' => $patient->conditions,
                'NHIS no.' => $patient->nhis_no, 'NHIS card expires' => $patient->nhis_expires_on?->format('j M Y'),
            ]) : null,
            'actions' => [
                'edit' => self::canManage(),
                'pay' => $collect ? [
                    'branches' => self::choices(Branch::choices()), 'default_branch' => Branch::where('is_hq', true)->value('id'),
                    'methods' => self::choices(ReceivablesService::METHODS), 'providers' => self::choices(SalePayment::MOMO_PROVIDERS),
                ] : null,
                'statement' => Erp::canAnywhere('receivables.view') ? route('print.statement', ['customer' => $c->id], false) : null,
                'credit' => $credit ? ['limit' => $c->credit_limit_minor === null ? '' : Money::format($c->credit_limit_minor, $currency),
                    'terms' => (string) ($c->payment_terms_days ?? 0), 'hold' => (bool) $c->credit_hold] : null,
                'opening' => $credit && $c->balance === null,
                'patient' => $pharmacy ? [
                    'date_of_birth' => $patient?->date_of_birth?->toDateString() ?? '', 'sex' => $patient?->sex ?? '', 'allergies' => array_values((array) ($patient?->allergies ?? [])),
                    'conditions' => $patient?->conditions ?? '', 'nhis_no' => $patient?->nhis_no ?? '', 'nhis_expires_on' => $patient?->nhis_expires_on?->toDateString() ?? '',
                ] : null,
                'follow_up' => $crm ? ['types' => self::choices(CrmActivity::TYPES), 'people' => self::choices(CrmUi::people())] : null,
                'new_deal' => $crm ? '/office/crm/deals/new?'.http_build_query(['customer' => $c->id]) : null,
                'archive' => $mayArchive && ! $c->archived_at ? ['blockers' => array_values($archive->blockers($c))] : null,
                'restore' => $mayArchive && (bool) $c->archived_at,
            ],
        ];
    }

    /** The add / edit form: the current form's fields, with what each list offers. */
    public function form(?Customer $c): array
    {
        $industries = IndustryContext::choices($this->user);
        unset($industries[IndustryContext::SHARED]); // each industry has its own customers
        $consents = collect(Customer::CONSENT_CHANNELS)->mapWithKeys(fn ($l, $ch) => [$ch => $c ? $c->hasConsent($ch) : false])->all();

        return [
            'id' => $c?->id, 'code' => $c?->code, 'name' => $c?->name,
            'values' => [
                'type' => $c?->type ?? 'individual', 'name' => $c?->name ?? '', 'industry_id' => $c ? (string) $c->industry_id : (string) IndustryContext::defaultId(),
                'phone' => $c?->phone ?? '', 'email' => $c?->email ?? '', 'contact_name' => $c?->contact_name ?? '', 'tax_no' => $c?->tax_no ?? '',
                'price_list_id' => (string) ($c?->price_list_id ?? ''), 'home_branch_id' => (string) ($c?->home_branch_id ?? ''), 'address' => $c?->address ?? '',
                'tags' => array_values((array) ($c?->tags ?? [])), 'notes' => $c?->notes ?? '', 'consents' => $consents, 'consent_source' => 'in person',
                'credit_limit' => '', 'payment_terms_days' => '0',
            ],
            'choices' => [
                'types' => self::choices(Customer::TYPES), 'industries' => self::choices($industries),
                'price_lists' => self::choices(PriceList::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                'branches' => self::choices(Branch::choices($c?->home_branch_id)), 'consent_sources' => self::choices(self::CONSENT_SOURCES),
                'consent_channels' => self::choices(Customer::CONSENT_CHANNELS),
            ],
            'sections' => ['industry' => count($industries) > 1, 'credit' => ! $c && self::canCredit()],
        ];
    }

    /** The form's values → CustomerService's data (as CustomerResource::toDomain). */
    public function toDomain(array $v, bool $new): array
    {
        $data = array_intersect_key($v, array_flip(['type', 'name', 'industry_id', 'phone', 'email', 'contact_name', 'tax_no', 'price_list_id', 'home_branch_id',
            'address', 'tags', 'notes']));
        $data = array_map(fn ($x) => $x === '' ? null : $x, $data);
        if (! $this->form(null)['sections']['industry']) {
            unset($data['industry_id']); // not asked: the service gives the home branch's or the person's own industry
        }
        $data['tags'] = array_values(array_filter(array_map('trim', (array) ($v['tags'] ?? [])), fn ($t) => $t !== ''));
        $data['consents'] = array_map(fn ($x) => (bool) $x, array_intersect_key((array) ($v['consents'] ?? []), Customer::CONSENT_CHANNELS));
        if ($new && self::canCredit() && (($v['credit_limit'] ?? '') !== '' || (int) ($v['payment_terms_days'] ?? 0) > 0)) {
            $data['credit_limit_minor'] = ($v['credit_limit'] ?? '') === '' ? null : Money::toMinor((string) $v['credit_limit'], $this->currency());
            $data['payment_terms_days'] = (int) ($v['payment_terms_days'] ?? 0);
        }

        return $data;
    }

    public function minor(string $amount): int
    {
        return Money::toMinor($amount, $this->currency());
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
