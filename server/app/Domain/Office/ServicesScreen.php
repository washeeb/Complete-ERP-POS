<?php

namespace App\Domain\Office;

use App\Domain\Services\AppointmentService;
use App\Domain\Services\CommissionService;
use App\Domain\Services\MembershipService;
use App\Domain\Services\PassService;
use App\Filament\Pages\CommissionReport;
use App\Filament\Pages\StaffCalendar;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\LicenceKeys\LicenceKeyResource;
use App\Filament\Resources\MembershipPlans\MembershipPlanResource;
use App\Filament\Resources\Memberships\MembershipResource;
use App\Filament\Resources\ServicePasses\ServicePassResource;
use App\Filament\Support\Erp;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Commission;
use App\Models\Employee;
use App\Models\LicenceKey;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServicePass;
use App\Models\ServicePassUse;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Services in the new back office (part 26 of the rewrite), as the current pages: Staff calendar, Appointments, Prepaid
 * passes, Memberships, Membership plans, Commissions and Licence keys. Same queries (the resources' and pages') and
 * rules: AppointmentService, MembershipService, PassService, LicenceKeyService and CommissionService (ServicesController).
 */
final class ServicesScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

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

    private function tz(?string $branchId = null): string
    {
        $branch = $branchId ? Branch::find($branchId) : null;

        return $branch?->timezone ?: (Tenant::find($this->user->tenant_id)?->timezone ?: 'UTC');
    }

    // -------------------------------------------------------------------------------------------- Appointments and the calendar

    public static function canAppointments(): bool
    {
        return AppointmentResource::canViewAny();
    }

    /** @return array<string, string> branches the person books appointments at */
    public function bookingBranches(): array
    {
        $ids = Erp::branchIds('services.appointments');

        return Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->pluck('name', 'id')->all();
    }

    /** What the booking form offers (the staff come per branch). */
    public function bookingForm(?string $branchId = null): array
    {
        $branches = $this->bookingBranches();
        $branch = $branchId && isset($branches[$branchId]) ? $branchId : (count($branches) === 1 ? (string) array_key_first($branches) : '');

        return ['branches' => self::choices($branches), 'branch' => $branch,
            'services' => self::choices(Product::where('is_active', true)->whereNotNull('service_minutes')->orderBy('name')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->name} ({$p->service_minutes} min)"])->all()),
            'staff' => $branch ? $this->staff($branch) : []];
    }

    public function staff(string $branchId): array
    {
        return isset($this->bookingBranches()[$branchId]) ? self::choices(AppointmentService::staff($branchId)->pluck('name', 'id')->all()) : [];
    }

    public function appointmentsQuery(): Builder
    {
        return AppointmentResource::getEloquentQuery();
    }

    public const TONE = ['done' => 'success', 'arrived' => 'warning', 'no_show' => 'danger', 'cancelled' => 'subtle'];

    private function appointment(Appointment $a, string $tz): array
    {
        return ['id' => $a->id, 'number' => $a->number, 'starts_at' => $a->starts_at?->copy()->setTimezone($tz)->format('D j M, H:i'), 'starts_iso' => $a->starts_at?->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'ends' => $a->ends_at?->copy()->setTimezone($tz)->format('H:i'), 'time' => $a->starts_at?->copy()->setTimezone($tz)->format('H:i'),
            'customer' => $a->customer_name, 'phone' => $a->phone, 'service' => $a->product?->name, 'with' => $a->employee?->name, 'employee_id' => $a->employee_id, 'branch_id' => $a->branch_id,
            'note' => $a->note, 'status' => $a->status, 'status_label' => Appointment::STATUSES[$a->status] ?? $a->status, 'tone' => self::TONE[$a->status] ?? 'informative',
            'can_arrive' => $a->status === 'booked', 'can_no_show' => $a->status === 'booked' && $a->starts_at->isPast(), 'can_change' => $a->status === 'booked',
            'can_cancel' => in_array($a->status, Appointment::OPEN, true)];
    }

    /** @param  array<string, mixed>  $p  q (customer or number), status, with, upcoming (1 by default), page, per */
    public function appointments(array $p): array
    {
        $q = $this->appointmentsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('customer_name', 'ilike', "%{$s}%")->orWhere('number', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%"));
        }
        if (array_key_exists($p['status'] ?? '', Appointment::STATUSES)) {
            $q->where('status', $p['status']);
        }
        if (($p['with'] ?? '') !== '') {
            $q->where('employee_id', $p['with']);
        }
        $upcoming = (string) ($p['upcoming'] ?? '1') !== '0';
        if ($upcoming) {
            $q->where('starts_at', '>=', now()->startOfDay());
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $tz = $this->tz();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'upcoming' => $upcoming, 'statuses' => self::choices(Appointment::STATUSES),
            'staff' => self::choices(Employee::where('takes_appointments', true)->orderBy('name')->pluck('name', 'id')->all()), 'can_book' => AppointmentResource::canCreate(),
            'rows' => $q->orderBy('starts_at')->forPage($page, $per)->get()->map(fn (Appointment $a) => $this->appointment($a, $tz))->all()];
    }

    public static function canCalendar(): bool
    {
        return StaffCalendar::canAccess();
    }

    /** The day's appointments as a grid: one column per staff member, one row per time slot (as the current page). */
    public function calendar(array $p): array
    {
        $branches = $this->bookingBranches();
        $branch = isset($branches[$p['branch'] ?? '']) ? (string) $p['branch'] : (string) (array_key_first($branches) ?? '');
        $tz = $this->tz($branch);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['date'] ?? '')) ? (string) $p['date'] : now()->setTimezone($tz)->toDateString();
        $out = ['branch' => $branch, 'date' => $date, 'branches' => self::choices($branches), 'staff' => [], 'slots' => [], 'cells' => (object) []];
        if ($branch === '') {
            return $out;
        }
        $settings = app(Settings::class);
        $tenant = $this->user->tenant_id;
        $step = max(5, (int) $settings->get('services.slot_minutes', $tenant));
        $start = Carbon::parse("{$date} ".$settings->get('services.day_start', $tenant), $tz);
        $end = Carbon::parse("{$date} ".$settings->get('services.day_end', $tenant), $tz);
        $list = AppointmentService::day($branch, $date, $tz);
        foreach ($list as $a) { // widen the day to show bookings outside the usual hours
            $start = $start->min($a->starts_at->copy()->setTimezone($tz));
            $end = $end->max($a->ends_at->copy()->setTimezone($tz));
        }
        $slots = [];
        for ($t = $start->copy(); $t->lt($end) && count($slots) < 300; $t->addMinutes($step)) {
            $slots[] = $t->format('H:i');
        }
        $cells = [];
        foreach ($list as $a) {
            $local = $a->starts_at->copy()->setTimezone($tz);
            $slot = $start->copy()->addMinutes(intdiv((int) $start->diffInMinutes($local), $step) * $step)->format('H:i');
            $cells[$a->employee_id][$slot][] = $this->appointment($a, $tz);
        }

        return ['staff' => AppointmentService::staff($branch)->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'job' => $e->job_title])->values()->all(), 'slots' => $slots,
            'cells' => (object) $cells, 'book_at' => Carbon::parse($date.' '.now($tz)->addHour()->format('H:00'), $tz)->format('Y-m-d\TH:i')] + $out;
    }

    // -------------------------------------------------------------------------------------------- Prepaid passes

    public static function canPasses(): bool
    {
        return ServicePassResource::canViewAny();
    }

    public function passesQuery(): Builder
    {
        return ServicePassResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, status (active by default; all), source, page, per */
    public function passes(array $p): array
    {
        $q = $this->passesQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")));
        }
        $status = (string) ($p['status'] ?? 'active');
        if (array_key_exists($status, ServicePass::STATUSES)) {
            $q->where('status', $status);
        }
        if (in_array($p['source'] ?? '', ['pack', 'membership'], true)) {
            $q->where('source', $p['source']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'status' => $status, 'statuses' => self::choices(ServicePass::STATUSES),
            'rows' => $q->orderByDesc('created_at')->forPage($page, $per)->get()->map(fn (ServicePass $r) => ['id' => $r->id, 'number' => $r->number, 'customer' => $r->customer?->name,
                'phone' => $r->customer?->phone, 'name' => $r->name, 'left' => collect(PassService::remaining($r))->map(fn ($n, $i) => ($n === null ? 'Unlimited' : $n).' '.$r->items[$i]['label'])->implode(' · '),
                'expires' => $r->expires_on?->toDateString(), 'status' => $r->status === 'active' && $r->expires_on?->isPast() ? 'Expired' : (ServicePass::STATUSES[$r->status] ?? $r->status),
                'tone' => $r->status === 'active' && ! $r->expires_on?->isPast() ? 'success' : 'subtle', 'void_reason' => $r->void_reason, 'can_void' => $r->status === 'active'])->all()];
    }

    public function passHistory(ServicePass $r): array
    {
        return ['title' => "{$r->number} · {$r->name}", 'rows' => ServicePassUse::where('service_pass_id', $r->id)->orderBy('occurred_at')->get()->map(fn ($u) => ['id' => $u->id,
            'when' => $u->occurred_at?->format('j M Y H:i'), 'item' => $r->items[$u->item]['label'] ?? '', 'sessions' => $u->sessions > 0 ? '−'.$u->sessions : '+'.(-$u->sessions),
            'by' => $u->by_name])->all()];
    }

    // -------------------------------------------------------------------------------------------- Memberships and plans

    public static function canMemberships(): bool
    {
        return MembershipResource::canViewAny();
    }

    public function membershipsQuery(): Builder
    {
        return MembershipResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, status (active by default; all), page, per */
    public function memberships(array $p): array
    {
        $q = $this->membershipsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")));
        }
        $status = (string) ($p['status'] ?? 'active');
        if (array_key_exists($status, Membership::STATUSES)) {
            $q->where('status', $status);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $ids = Erp::branchIds('services.memberships');
        $hq = config('erp.node.role') !== 'branch';

        return $this->m() + ['total' => $total, 'page' => $page, 'per' => $per, 'status' => $status, 'statuses' => self::choices(Membership::STATUSES), 'can_create' => MembershipResource::canCreate(),
            'plans' => self::choices(MembershipPlan::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->name} · ".Money::display((int) $p->price_minor, $this->m()['currency'])
                .' '.strtolower(MembershipService::INTERVALS[$p->interval_months] ?? '')])->all()),
            'branches' => self::choices(Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->pluck('name', 'id')->all()), 'today' => now()->toDateString(),
            'rows' => $q->orderBy('next_bill_on')->forPage($page, $per)->get()->map(fn (Membership $r) => ['id' => $r->id, 'number' => $r->number, 'customer' => $r->customer?->name,
                'phone' => $r->customer?->phone, 'plan' => $r->plan?->name, 'price' => (int) $r->price_minor, 'since' => $r->starts_on?->toDateString(), 'next' => $r->next_bill_on?->toDateString(),
                'ends' => $r->ends_on?->toDateString(), 'status' => $r->status, 'status_label' => Membership::STATUSES[$r->status] ?? $r->status,
                'tone' => ['active' => 'success', 'paused' => 'warning'][$r->status] ?? 'subtle', 'can_bill' => $r->status === 'active' && $r->next_bill_on->lte(now()) && $hq,
                'can_pause' => $r->status === 'active', 'can_resume' => $r->status === 'paused', 'can_cancel' => $r->status !== 'cancelled'])->all()];
    }

    public static function canPlans(): bool
    {
        return MembershipPlanResource::canViewAny();
    }

    public function plans(array $p): array
    {
        $q = MembershipPlanResource::getEloquentQuery();
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('archived_at'), 'both' => null, default => $q->whereNull('archived_at')
        };
        $archive = new CompanyScreen($this->user);
        $e = $this->m()['exponent'];

        return $this->m() + ['can_create' => MembershipPlanResource::canCreate(), 'intervals' => self::choices(MembershipService::INTERVALS),
            'products' => self::choices(Product::where('is_active', true)->where('track_stock', false)->orderBy('name')->pluck('name', 'id')->all()),
            'services' => self::choices(Product::where('is_active', true)->whereNotNull('service_minutes')->orderBy('name')->pluck('name', 'id')->all()),
            'rows' => $q->orderBy('code')->get()->map(fn (MembershipPlan $r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'price' => (int) $r->price_minor,
                'interval' => MembershipService::INTERVALS[$r->interval_months] ?? (string) $r->interval_months,
                'includes' => collect($r->items)->map(fn ($i) => ($i['sessions'] ?? 'Unlimited').' × '.$i['label'])->implode(', ') ?: '—', 'active' => (bool) $r->is_active,
                'values' => ['name' => $r->name, 'price' => number_format($r->price_minor / (10 ** $e), $e, '.', ''), 'interval_months' => (string) $r->interval_months,
                    'product_id' => (string) $r->product_id, 'description' => (string) $r->description, 'is_active' => (bool) $r->is_active,
                    'items' => array_map(fn ($i) => ['product_ids' => array_values($i['product_ids'] ?? []), 'sessions' => $i['sessions'] === null ? '' : (string) $i['sessions'],
                        'label' => (string) ($i['label'] ?? '')], (array) $r->items)]] + $archive->archiveOf($r))->all()];
    }

    // -------------------------------------------------------------------------------------------- Commissions

    public static function canCommissions(): bool
    {
        return CommissionReport::canAccess();
    }

    /** @param  array<string, mixed>  $p  from, to, employee */
    public function commissions(array $p): array
    {
        $date = fn ($v, $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : $d;
        $from = $date($p['from'] ?? '', now()->startOfMonth()->toDateString());
        $to = $date($p['to'] ?? '', now()->toDateString());
        $tz = $this->tz();
        $ids = Erp::branchIds('services.commissions');
        $employee = (string) ($p['employee'] ?? '');
        $lines = $employee === '' ? [] : Commission::where('employee_id', $employee)->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))
            ->where('occurred_at', '>=', Carbon::parse($from, $tz)->startOfDay()->utc())->where('occurred_at', '<', Carbon::parse($to, $tz)->addDay()->startOfDay()->utc())
            ->join('sale_lines as l', 'l.id', '=', 'commissions.sale_line_id')->join('sales as s', 's.id', '=', 'commissions.sale_id')
            ->orderBy('commissions.occurred_at')->limit(1000)->get(['commissions.*', 'l.name', 'l.qty', 's.receipt_no'])
            ->map(fn ($l) => ['id' => $l->id, 'when' => $l->occurred_at?->setTimezone($tz)->format('j M H:i'), 'receipt' => $l->receipt_no, 'item' => $l->name,
                'value' => (int) $l->base_minor, 'rate' => number_format($l->rate_bp / 100, 2).' %', 'amount' => (int) $l->amount_minor])->all();

        return $this->m() + ['from' => $from, 'to' => $to, 'employee' => $employee,
            'rows' => collect(CommissionService::summary($from, $to, $tz, $ids))->map(fn ($r) => ['id' => $r->employee_id, 'name' => $r->name, 'number' => $r->number, 'lines' => (int) $r->lines,
                'value' => (int) $r->base_minor, 'amount' => (int) $r->amount_minor])->values()->all(), 'lines' => $lines];
    }

    // -------------------------------------------------------------------------------------------- Licence keys

    public static function canKeys(): bool
    {
        return LicenceKeyResource::canViewAny();
    }

    public function keysQuery(): Builder
    {
        return LicenceKeyResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q (last characters), status, product, page, per */
    public function keys(array $p): array
    {
        $q = $this->keysQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where('hint', 'ilike', "%{$s}%");
        }
        if (array_key_exists($p['status'] ?? '', LicenceKey::STATUSES)) {
            $q->where('status', $p['status']);
        }
        if (($p['product'] ?? '') !== '') {
            $q->where('product_id', $p['product']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $ids = Erp::branchIds('services.licence_keys');
        $products = Product::where('licence_keys', true)->orderBy('name')->pluck('name', 'id')->all();
        $rows = $q->orderByDesc('created_at')->forPage($page, $per)->get();
        $names = Product::whereIn('id', $rows->pluck('product_id'))->pluck('name', 'id');
        $codes = Branch::pluck('code', 'id');
        $receipts = Sale::whereIn('id', $rows->pluck('sale_id')->filter())->pluck('receipt_no', 'id');

        return ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::choices(LicenceKey::STATUSES), 'products' => self::choices($products),
            'branches' => self::choices(Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->pluck('name', 'id')->all()),
            'rows' => $rows->map(fn (LicenceKey $k) => ['id' => $k->id, 'product' => $names[$k->product_id] ?? '—', 'branch' => $codes[$k->branch_id] ?? '—', 'hint' => '…'.$k->hint,
                'status' => $k->status, 'status_label' => LicenceKey::STATUSES[$k->status] ?? $k->status, 'tone' => ['available' => 'success', 'sold' => 'informative'][$k->status] ?? 'subtle',
                'sold_at' => $k->sold_at?->format('j M Y H:i'), 'receipt' => $k->sale_id ? ($receipts[$k->sale_id] ?? null) : null, 'can_void' => $k->status === 'available'])->all()];
    }
}
