<?php

namespace App\Filament\Pages;

use App\Domain\Modules\ModuleService;
use App\Domain\Services\AppointmentService;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Support\Erp;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Tenant;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/** The day's appointments as a grid: one column per staff member, one row per time slot. */
class StaffCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static ?string $navigationLabel = 'Staff calendar';

    protected static ?string $title = 'Staff calendar';

    protected static ?string $slug = 'staff-calendar';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.staff-calendar';

    #[Url]
    public string $date = '';

    #[Url]
    public string $branchId = '';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('services.appointments') && ModuleService::enabledAnywhere('services');
    }

    public function mount(): void
    {
        $this->branchId = $this->branchId ?: (string) (array_key_first($this->branches()) ?? '');
        $this->date = $this->date ?: now()->setTimezone($this->tz())->toDateString();
    }

    public function branches(): array
    {
        $ids = Erp::branchIds('services.appointments');

        return Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->pluck('name', 'id')->all();
    }

    public function shift(int $days): void
    {
        $this->date = Carbon::parse($this->date)->addDays($days)->toDateString();
    }

    /** @return array{staff: Collection, slots: list<string>, cells: array<string, array<string, list<Appointment>>>} */
    public function grid(): array
    {
        if (! $this->branchId || ! isset($this->branches()[$this->branchId])) {
            return ['staff' => collect(), 'slots' => [], 'cells' => []];
        }
        $tz = $this->tz();
        $settings = app(Settings::class);
        $tenant = Erp::user()->tenant_id;
        $step = max(5, (int) $settings->get('services.slot_minutes', $tenant));
        $start = Carbon::parse("{$this->date} ".$settings->get('services.day_start', $tenant), $tz);
        $end = Carbon::parse("{$this->date} ".$settings->get('services.day_end', $tenant), $tz);
        $appointments = AppointmentService::day($this->branchId, $this->date, $tz);
        // Widen the day to show bookings outside the usual hours.
        foreach ($appointments as $a) {
            $start = $start->min($a->starts_at->copy()->setTimezone($tz));
            $end = $end->max($a->ends_at->copy()->setTimezone($tz));
        }
        $slots = [];
        for ($t = $start->copy(); $t->lt($end) && count($slots) < 300; $t->addMinutes($step)) {
            $slots[] = $t->format('H:i');
        }
        $cells = [];
        foreach ($appointments as $a) {
            $local = $a->starts_at->copy()->setTimezone($tz);
            $slot = $start->copy()->addMinutes(intdiv((int) $start->diffInMinutes($local), $step) * $step)->format('H:i');
            $cells[$a->employee_id][$slot][] = $a;
        }

        return ['staff' => AppointmentService::staff($this->branchId), 'slots' => $slots, 'cells' => $cells];
    }

    public function tz(): string
    {
        $branch = $this->branchId ? Branch::find($this->branchId) : null;

        return $branch?->timezone ?: (Tenant::find(Erp::user()->tenant_id)?->timezone ?: 'UTC');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('book')->label('Book appointment')->schema(AppointmentResource::bookingFields())->modalWidth('4xl')
            ->fillForm(fn () => ['branch_id' => $this->branchId ?: null, 'starts_at' => Carbon::parse($this->date.' '.now($this->tz())->addHour()->format('H:00'), $this->tz())])
            ->action(fn (array $data) => Erp::run(fn ($actor) => app(AppointmentService::class)->book($actor, new Appointment, array_filter($data, fn ($v) => $v !== null && $v !== '')), 'Booked'))];
    }
}
