<?php

namespace App\Domain\Office;

use App\Domain\Pharmacy\NhisClaims;
use App\Domain\Pharmacy\PharmacyRules;
use App\Filament\Pages\MedicineSignoffs;
use App\Filament\Resources\NhisClaims\NhisClaimResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Branch;
use App\Models\NhisClaim;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * NHIS claims and medicines sold without a pharmacist, in the new back office (pharmacy, part 12 of the rewrite), as
 * the current screens: the claims (Prepare a claim, open one with its sales, Download the list, Mark as sent, Record
 * what the NHIA paid, Withdraw) through NhisClaims; and the sales to sign off (by status, branch and period, Sign off,
 * Download CSV) through PharmacySignoffs (PharmacyClaimsController). Who may see and do what is unchanged.
 */
final class NhisClaimsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    private function currency(): string
    {
        return FinanceUi::currency();
    }

    private static function pairs(array $a): array
    {
        return array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => (string) $v], array_keys($a), $a);
    }

    private static function paging(array $p, int $default = 25): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : $default, max(1, (int) ($p['page'] ?? 1))];
    }

    private function money(): array
    {
        return ['currency' => $this->currency(), 'exponent' => Money::exponent($this->currency())];
    }

    /** @param  array<string, mixed>  $p  q, status, dir, page, per */
    public function claims(array $p): array
    {
        [$per, $page] = self::paging($p);
        $q = NhisClaimResource::getEloquentQuery()->with('branch');
        if (($search = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%{$search}%")->orWhere('reference', 'ilike', "%{$search}%"));
        }
        if (array_key_exists((string) ($p['status'] ?? ''), NhisClaim::STATUSES)) {
            $q->where('status', $p['status']);
        }
        $dir = ($p['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
        $branches = NhisClaimResource::branches();

        return $this->money() + ['total' => (clone $q)->count(), 'page' => $page, 'per' => $per, 'dir' => $dir, 'statuses' => self::pairs(NhisClaim::STATUSES),
            'branches' => self::pairs($branches), 'default_branch' => count($branches) === 1 ? (string) array_key_first($branches) : null,
            'default_up_to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(), 'today' => now()->toDateString(),
            'rows' => $q->orderBy('created_at', $dir)->orderBy('id', $dir)->forPage($page, $per)->get()->map(fn (NhisClaim $c) => [
                'id' => $c->id, 'number' => $c->number, 'branch' => $c->branch?->name, 'period_to' => $c->period_to?->toDateString(),
                'claimed' => (int) $c->claimed_minor, 'paid' => $c->paid_minor === null ? null : (int) $c->paid_minor,
                'rejected' => $c->rejected_minor === null ? null : (int) $c->rejected_minor, 'status' => $c->status, 'status_label' => NhisClaim::STATUSES[$c->status] ?? $c->status,
            ])->all()];
    }

    /** What a claim for this branch up to this day would hold. */
    public function unclaimed(Branch $branch, string $upTo): array
    {
        $q = NhisClaims::unclaimed($branch, $upTo);

        return $this->money() + ['sales' => (clone $q)->distinct()->count('p.sale_id'), 'amount' => (int) (clone $q)->sum('p.amount_minor')];
    }

    /** @param  array<string, mixed>  $p  page, per (the claim's sales) */
    public function claim(NhisClaim $c, array $p): array
    {
        [$per, $page] = self::paging($p);
        $lines = $c->lines()->with('sale.customer', 'payment')->orderBy('created_at')->orderBy('id');
        $sales = SaleResource::canViewAny();

        return $this->money() + ['id' => $c->id, 'number' => $c->number, 'status' => $c->status, 'status_label' => NhisClaim::STATUSES[$c->status] ?? $c->status,
            'branch' => $c->branch?->name, 'period_from' => $c->period_from?->toDateString(), 'period_to' => $c->period_to?->toDateString(),
            'claimed' => (int) $c->claimed_minor, 'paid' => $c->paid_minor === null ? null : (int) $c->paid_minor, 'rejected' => $c->rejected_minor === null ? null : (int) $c->rejected_minor,
            'reference' => $c->reference, 'prepared_by' => $c->prepared_by_name, 'submitted_by' => $c->submitted_by_name, 'submitted_at' => $c->submitted_at?->toIso8601String(),
            'settled_by' => $c->settled_by_name, 'paid_on' => $c->paid_on?->toDateString(), 'paid_method' => $c->paid_method ? (NhisClaim::METHODS[$c->paid_method] ?? $c->paid_method) : null,
            'note' => $c->note, 'methods' => self::pairs(NhisClaim::METHODS), 'today' => now()->toDateString(),
            'download' => $c->status !== 'withdrawn' ? route('print.nhis-claim', ['claim' => $c->id], false) : null,
            'lines' => ['total' => (clone $lines)->count(), 'page' => $page, 'per' => $per,
                'rows' => $lines->forPage($page, $per)->get()->map(fn ($l) => ['id' => $l->id, 'at' => $l->sale?->occurred_at?->toIso8601String(),
                    'receipt' => $l->sale?->receipt_no, 'sale_id' => $sales ? $l->sale_id : null, 'patient' => $l->sale?->customer?->name,
                    'nhis_no' => $l->payment?->reference, 'amount' => (int) $l->amount_minor])->all()]];
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} the chosen days and their UTC bounds (default: the last 30 days) */
    private function period(array $p): array
    {
        $tz = $this->user->tenant?->timezone ?: 'UTC';
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $from = $date($p['from'] ?? null) ?? now($tz)->subDays(30)->toDateString();
        $to = $date($p['to'] ?? null) ?? now($tz)->toDateString();

        return [$from, $to, Carbon::parse($from, $tz)->startOfDay()->utc()->toDateTimeString(), Carbon::parse($to, $tz)->endOfDay()->utc()->toDateTimeString()];
    }

    /** @param  array<string, mixed>  $p  status (waiting | signed | all), branch, from, to */
    public function signoffRows(array $p): Collection
    {
        [, , $from, $to] = $this->period($p);
        $status = in_array($p['status'] ?? '', ['signed', 'all'], true) ? $p['status'] : 'waiting';
        $branch = (string) ($p['branch'] ?? '');

        return PharmacyRules::unapproved($this->user->tenant_id, PrescriptionResource::branchIds(), $from, $to)
            ->when($branch !== '', fn ($q) => $q->where('s.branch_id', $branch))
            ->when($status === 'waiting', fn ($q) => $q->whereNull('so.id'))->when($status === 'signed', fn ($q) => $q->whereNotNull('so.id'))
            ->orderByDesc('s.occurred_at')->limit(2000)->get();
    }

    /** @param  array<string, mixed>  $p  status, branch, from, to, page, per */
    public function signoffs(array $p): array
    {
        [$per, $page] = self::paging($p);
        [$from, $to] = $this->period($p);
        $rows = $this->signoffRows($p);
        $ids = PrescriptionResource::branchIds();
        $branches = Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->pluck('name', 'id');

        return ['total' => $rows->count(), 'page' => $page, 'per' => $per, 'from' => $from, 'to' => $to, 'branches' => self::pairs($branches->all()),
            'can_sign' => MedicineSignoffs::canAccess() && Erp::canAnywhere('pharmacy.dispense'), 'capped' => $rows->count() >= 2000,
            'rows' => $rows->forPage($page, $per)->map(fn ($r) => ['id' => $r->id, 'at' => Carbon::parse($r->occurred_at)->toIso8601String(), 'receipt' => $r->receipt_no,
                'branch' => $branches[$r->branch_id] ?? null, 'sold_by' => $r->cashier_name, 'medicines' => $r->medicines, 'controlled' => (bool) $r->controlled,
                'signed_by' => $r->signed_by_name, 'signed_at' => $r->signed_at ? Carbon::parse($r->signed_at)->toIso8601String() : null, 'note' => $r->note])->values()->all()];
    }
}
