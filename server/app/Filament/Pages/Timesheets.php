<?php

namespace App\Filament\Pages;

use App\Domain\Hr\AttendanceService;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollProfile;
use App\Models\Tenant;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/** Hours from clock-ins on the tills; managers add missing clock-outs or void mistakes (with a reason). */
class Timesheets extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'HR & payroll';

    protected static ?string $navigationLabel = 'Timesheets';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.timesheets';

    #[Url]
    public string $branch = '';

    #[Url]
    public string $month = '';

    #[Url]
    public string $person = '';

    public string $addKind = 'out';

    public string $addAt = '';

    public string $reason = '';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('attendance.manage') && config('erp.node.role') !== 'branch';
    }

    public function mount(): void
    {
        $ids = Erp::branchIds('attendance.manage');
        $this->branch = $this->branch ?: (string) ($ids === null ? FinanceUi::hq() : ($ids[0] ?? ''));
        $this->month = $this->month ?: now()->format('Y-m');
    }

    public function branches(): array
    {
        $ids = Erp::branchIds('attendance.manage');

        return Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"])->all();
    }

    private function range(): array
    {
        $start = Carbon::parse($this->month.'-01');

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    public function rows(): array
    {
        [$from, $to] = $this->range();
        $tz = Tenant::find(Erp::user()->tenant_id)->timezone ?: 'UTC';
        $hours = (int) (PayrollProfile::orderByDesc('effective_from')->first()?->rules['standard_day_hours'] ?? 8);
        $out = [];
        foreach (Employee::where('branch_id', $this->branch)->whereNotNull('user_id')->where('status', 'active')->orderBy('name')->get() as $e) {
            $s = AttendanceService::timesheet($e->user_id, $from, $to, $hours, $tz);
            $out[] = ['employee' => $e, 'days' => count($s['days']), 'hours' => $s['total_centi'] / 100, 'overtime' => $s['overtime_centi'] / 100, 'open' => $s['open']];
        }

        return $out;
    }

    public function events(): array
    {
        if (! $this->person) {
            return [];
        }
        [$from, $to] = $this->range();
        $tz = Tenant::find(Erp::user()->tenant_id)->timezone ?: 'UTC';
        $rows = DB::table('attendance_events')->where('user_id', $this->person)
            ->whereBetween('occurred_at', [Carbon::parse($from, $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()])->orderBy('occurred_at')->get();
        $voided = $rows->whereNotNull('voids_id')->pluck('voids_id')->flip();

        return $rows->map(fn ($r) => (object) ['id' => $r->id, 'kind' => $r->kind, 'at' => Carbon::parse($r->occurred_at, 'UTC')->setTimezone($tz)->format('D j M H:i'),
            'source' => $r->source, 'reason' => $r->reason, 'by' => $r->recorded_by_name, 'voided' => isset($voided[$r->id]), 'is_void' => (bool) $r->voids_id])->all();
    }

    public function personName(): ?string
    {
        return $this->person ? User::find($this->person)?->name : null;
    }

    public function addEvent(): void
    {
        $this->attempt(fn ($actor) => app(AttendanceService::class)->addManual($actor, User::findOrFail($this->person), Branch::findOrFail($this->branch), $this->addKind,
            Carbon::parse($this->addAt, Tenant::find(Erp::user()->tenant_id)->timezone ?: 'UTC')->utc()->toDateTimeString(), $this->reason), 'Added');
    }

    public function voidEvent(string $id): void
    {
        $this->attempt(fn ($actor) => app(AttendanceService::class)->void($actor, $id, $this->reason), 'Cancelled');
    }

    private function attempt(callable $call, string $ok): void
    {
        try {
            $call(Erp::actor());
        } catch (DomainException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();

            return;
        }
        $this->reason = '';
        Notification::make()->success()->title($ok)->send();
    }
}
