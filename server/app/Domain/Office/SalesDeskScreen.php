<?php

namespace App\Domain\Office;

use App\Domain\Messaging\PhonePush;
use App\Domain\Pos\OfficeReturns;
use App\Domain\Pos\ReturnService;
use App\Domain\Pos\TillApprovals;
use App\Domain\Support\Actor;
use App\Filament\Pages\TillRequests;
use App\Filament\Resources\OfficeReturns\OfficeReturnResource;
use App\Filament\Resources\ReviewItems\ReviewItemResource;
use App\Filament\Resources\StoredValues\StoredValueResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\Branch;
use App\Models\OfficeReturn;
use App\Models\ReviewItem;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StoredValue;
use App\Models\TillApproval;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The rest of Sales in the new back office (part 16 of the rewrite), as the current pages: Till requests (what tills ask
 * a manager for: approve or decline from anywhere, notifications on this phone), Back-office returns (entered from a
 * receipt, approved by someone else; started from the sale's page), the Review queue (offline and sync situations a
 * person must decide) and Gift cards & credit (balances, history, selling a gift card). Through TillApprovals,
 * OfficeReturns, ReviewQueue and StoredValues (SalesDeskController).
 */
final class SalesDeskScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    /** How a gift card is paid for (as the current form). */
    public const PAID_BY = ['cash' => 'Cash', 'momo' => 'Mobile money', 'card' => 'Card', 'bank' => 'Bank transfer', 'free' => 'Given free (promotion)'];

    public function __construct(private readonly User $user) {}

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    private function money(): array
    {
        $c = $this->currency();

        return ['currency' => $c, 'exponent' => Money::exponent($c)];
    }

    /** @param  array<string, string>  $map */
    public static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    // -------------------------------------------------------------------------------------------- Till requests

    public static function canTill(): bool
    {
        return TillRequests::canAccess();
    }

    /** @return list<string> branches where this person approves something at the tills (as the current page) */
    public function tillBranches(): array
    {
        $perms = array_values(array_unique(array_map(fn ($k) => $k[1], TillApproval::KINDS)));
        $authz = app(Authorizer::class);

        return Branch::all()->filter(fn (Branch $b) => collect($perms)->contains(fn ($p) => $authz->allows($this->user, $p, $b)))->modelKeys();
    }

    /** @param  array<string, mixed>  $p  show (pending · answered · all), id (one request, from a notification), page, per */
    public function tillRequests(array $p): array
    {
        $svc = app(TillApprovals::class);
        $one = is_string($p['id'] ?? null) && preg_match('/^[0-9a-f-]{36}$/', $p['id']) ? $p['id'] : null;
        $show = in_array($p['show'] ?? null, ['pending', 'answered', 'all'], true) ? $p['show'] : ($one ? 'all' : 'pending');
        $q = TillApproval::with(['till', 'branch'])->whereIn('branch_id', $this->tillBranches())->when($one, fn ($q) => $q->whereKey($one));
        match ($show) {
            'pending' => $q->where('status', 'pending')->where('expires_at', '>', now()),
            'answered' => $q->whereNot('status', 'pending'),
            default => null,
        };
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $m = $this->money();
        $rows = $q->orderByDesc('created_at')->forPage($page, $per)->get()->map(function (TillApproval $r) use ($svc, $m) {
            $status = $r->status === 'pending' && $r->expires_at->isPast() ? 'expired' : $r->status;

            return ['id' => $r->id, 'asked' => $r->created_at?->toIso8601String(), 'expires_at' => $r->status === 'pending' ? $r->expires_at?->toIso8601String() : null,
                'till' => $r->till?->name, 'branch' => $r->branch?->name, 'cashier' => $r->cashier_name, 'what' => $r->label(), 'summary' => $r->summary, 'reason' => $r->reason,
                'items' => collect($r->details ?? [])->map(fn ($d) => trim(($d['qty'] ?? '').' × '.($d['name'] ?? ''), ' ×')
                    .(isset($d['amount_minor']) ? ' '.Money::display((int) $d['amount_minor'], $m['currency']) : ''))->implode(' · ') ?: null,
                'amount' => $r->kind === 'discount' ? sprintf('%.2f %%', $r->limit_amount / 100) : ($r->amount_minor ? Money::display((int) $r->amount_minor, $m['currency']) : null),
                'status' => $status, 'status_label' => TillApproval::STATUSES[$status] ?? $status,
                'answered_by' => $r->decided_by_name ? $r->decided_by_name.($r->decided_via === 'till' ? ' at the till' : '').($r->decision_note ? ': '.$r->decision_note : '') : null,
                'can_decide' => $r->isOpen() && $svc->mayDecide($this->user, $r)];
        })->all();
        $push = app(PhonePush::class);

        return ['show' => $show, 'one' => $one, 'total' => $total, 'page' => $page, 'per' => $per, 'rows' => $rows,
            'push' => ['key' => $push->publicKey($this->user->tenant_id), 'has_device' => PhonePush::hasDevice($this->user)]];
    }

    // -------------------------------------------------------------------------------------------- Back-office returns

    public static function canReturns(): bool
    {
        return OfficeReturnResource::canViewAny();
    }

    /** @return list<string>|null the branches where the person enters or approves returns (null: every branch) */
    public static function returnBranches(): ?array
    {
        return CashScreen::scope('returns.office', 'returns.office.approve');
    }

    public function returnsQuery(): Builder
    {
        $ids = self::returnBranches();

        return OfficeReturnResource::getEloquentQuery()->when($ids !== null, fn ($q) => $q->whereIn('office_returns.branch_id', $ids));
    }

    /** @param  array<string, mixed>  $p  q (number or receipt), status, page, per */
    public function returns(array $p): array
    {
        $q = $this->returnsQuery()->with('original');
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('office_returns.number', 'ilike', "%{$search}%")->orWhereHas('original', fn ($s) => $s->where('receipt_no', 'ilike', "%{$search}%")));
        }
        if (array_key_exists($p['status'] ?? '', OfficeReturn::STATUSES)) {
            $q->where('office_returns.status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $waiting = (clone $q)->reorder()->where('office_returns.status', 'submitted')->count();

        return $this->money() + ['total' => $total, 'waiting' => $waiting, 'page' => $page, 'per' => $per,
            'statuses' => self::choices(OfficeReturn::STATUSES),
            'rows' => $q->orderByDesc('office_returns.created_at')->forPage($page, $per)->get()->map(fn (OfficeReturn $r) => ['id' => $r->id, 'number' => $r->number,
                'entered' => $r->created_at?->toIso8601String(), 'receipt' => $r->original?->receipt_no, 'refund' => (int) $r->total_minor,
                'refund_as' => OfficeReturn::REFUNDS[$r->refund_method] ?? $r->refund_method, 'reason' => ReturnService::REASONS[$r->reason_code] ?? $r->reason_code,
                'status' => $r->status, 'status_label' => OfficeReturn::STATUSES[$r->status] ?? $r->status, 'by' => $r->created_by_name])->all()];
    }

    public function returnDoc(OfficeReturn $r): array
    {
        $r->loadMissing(['original', 'sale', 'account', 'warehouse']);
        $names = SaleLine::whereIn('id', collect($r->lines)->pluck('line_id'))->get()->keyBy('id');
        $why = $r->status === 'submitted' ? app(OfficeReturns::class)->whyNotApprove(Actor::user($this->user, 'web'), $r) : 'done';

        return ['currency' => $r->currency_code, 'exponent' => Money::exponent($r->currency_code), 'id' => $r->id, 'number' => $r->number, 'status' => $r->status,
            'status_label' => OfficeReturn::STATUSES[$r->status] ?? $r->status, 'receipt' => $r->original?->receipt_no,
            'original' => $r->original_sale_id && SalesScreen::canView() ? "/office/sales/{$r->original_sale_id}" : null,
            'refund' => (int) $r->total_minor, 'refund_as' => OfficeReturn::REFUNDS[$r->refund_method] ?? $r->refund_method, 'paid_from' => $r->account?->name,
            'reason' => ReturnService::REASONS[$r->reason_code] ?? $r->reason_code, 'details' => $r->reason, 'reference' => $r->reference,
            'goes_to' => $r->warehouse?->name, 'entered_by' => $r->created_by_name, 'approved_by' => $r->posted_by_name, 'return_no' => $r->sale?->receipt_no,
            'cancel_reason' => $r->cancel_reason,
            'lines' => collect($r->lines)->map(fn ($l) => ['item' => $names[$l['line_id']]->name ?? '—',
                'qty' => rtrim(rtrim((string) $l['qty'], '0'), '.').' '.($names[$l['line_id']]->uom_code ?? ''), 'condition' => ! empty($l['damaged']) ? 'Damaged' : 'Good'])->values()->all(),
            'actions' => ['approve' => $r->status === 'submitted' && $why === null, 'why_not' => $r->status === 'submitted' ? $why : null,
                'cancel' => $r->status === 'submitted', 'slip' => $r->sale_id ? "/office-api/returns/{$r->sale_id}/slip" : null]];
    }

    /** "Return goods" on a sale's page: what can still come back, and the form's lists. Null when it may not be started here. */
    public function returnForm(Sale $sale): ?array
    {
        if ($sale->kind !== 'sale' || $sale->status !== 'posted' || ! Erp::canAnywhere('returns.office')) {
            return null;
        }
        $lines = collect(app(ReturnService::class)->returnable($sale->load('lines', 'payments'))['lines'])->filter(fn ($l) => (float) $l['returnable_qty'] > 0)
            ->map(fn ($l) => ['line_id' => $l['id'], 'name' => $l['name'], 'can' => rtrim(rtrim($l['returnable_qty'], '0'), '.').' '.$l['uom']])->values()->all();

        return ['lines' => $lines, 'warehouse_id' => (string) $sale->warehouse_id, 'warehouses' => self::choices(Fields::warehouses('returns.office')),
            'reasons' => self::choices(ReturnService::REASONS), 'refunds' => self::choices(OfficeReturn::REFUNDS), 'accounts' => self::choices(FinanceUi::moneyAccounts())];
    }

    // -------------------------------------------------------------------------------------------- Review queue

    public static function canReview(): bool
    {
        return ReviewItemResource::canViewAny();
    }

    /** @param  array<string, mixed>  $p  status (open · resolved · all), type, page, per */
    public function review(array $p): array
    {
        $status = in_array($p['status'] ?? null, ['open', 'resolved', 'all'], true) ? $p['status'] : 'open';
        $q = ReviewItemResource::getEloquentQuery()->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when(array_key_exists($p['type'] ?? '', ReviewItem::TYPES), fn ($q) => $q->where('type', $p['type']));
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['status' => $status, 'total' => $total, 'page' => $page, 'per' => $per, 'types' => self::choices(ReviewItem::TYPES),
            'rows' => $q->orderByDesc('created_at')->forPage($page, $per)->get()->map(fn (ReviewItem $r) => ['id' => $r->id, 'at' => $r->created_at?->toIso8601String(),
                'type' => $r->type, 'type_label' => ReviewItem::TYPES[$r->type] ?? $r->type, 'mild' => in_array($r->type, ['negative_stock', 'offline_price'], true),
                'branch' => $r->branch?->code, 'summary' => $r->summary, 'status' => $r->status, 'resolution' => $r->resolution,
                'resolved' => $r->resolved_by_name ? "{$r->resolved_by_name}, {$r->resolved_at?->format('j M Y H:i')}" : null,
                'sale' => $r->subject_type === 'sale' && $r->subject_id ? "/office/sales/{$r->subject_id}" : null])->all()];
    }

    // -------------------------------------------------------------------------------------------- Gift cards & credit

    public static function canCards(): bool
    {
        return StoredValueResource::canViewAny();
    }

    /** @param  array<string, mixed>  $p  q (card number or customer), kind, page, per */
    public function cards(array $p): array
    {
        $q = StoredValueResource::getEloquentQuery()->with('customer');
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('stored_values.code', 'ilike', "%{$search}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%")));
        }
        if (array_key_exists($p['kind'] ?? '', StoredValue::KINDS)) {
            $q->where('stored_values.kind', $p['kind']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $cards = $q->orderByDesc('stored_values.created_at')->forPage($page, $per)->get();
        $balances = DB::table('stored_value_balances')->whereIn('stored_value_id', $cards->modelKeys())->pluck('balance_minor', 'stored_value_id');

        return $this->money() + ['total' => $total, 'page' => $page, 'per' => $per, 'kinds' => self::choices(StoredValue::KINDS),
            'rows' => $cards->map(fn (StoredValue $s) => ['id' => $s->id, 'code' => $s->code, 'kind' => StoredValue::KINDS[$s->kind] ?? $s->kind, 'customer' => $s->customer?->name,
                'balance' => (int) ($balances[$s->id] ?? 0), 'last_used' => $s->last_used_at?->toIso8601String(), 'status' => $s->status])->all(),
            'paid_by' => self::choices(self::PAID_BY),
            'can_sell' => Erp::canAnywhere('giftcards.manage')];
    }

    /** A card's history: each sale, top-up, use and refund, oldest first. */
    public function cardHistory(StoredValue $s): array
    {
        return DB::table('stored_value_ledger')->where('stored_value_id', $s->id)->orderBy('occurred_at')->get()
            ->map(fn ($e) => ['at' => substr((string) $e->occurred_at, 0, 16), 'type' => $e->type, 'doc' => $e->doc_number, 'amount' => (int) $e->amount_minor])->all();
    }
}
