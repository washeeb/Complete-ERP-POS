<?php

namespace App\Filament\Resources\Appointments;

use App\Domain\Modules\ModuleService;
use App\Domain\Services\AppointmentService;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Support\Erp;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Appointments: services booked with a staff member; arrivals, no-shows and cancellations. Paid at the till. */
class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static ?string $navigationLabel = 'Appointments';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('services.appointments') && ModuleService::enabledAnywhere('services');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('services.appointments');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('services.appointments');

        return parent::getEloquentQuery()->with('employee:id,name', 'product:id,name')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components(self::bookingFields());
    }

    /** The booking form (also used by the calendar page). */
    public static function bookingFields(): array
    {
        $ids = Erp::branchIds('services.appointments');

        return [
            Select::make('branch_id')->label('Branch')->required()->live()
                ->options(fn () => Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->pluck('name', 'id'))
                ->default(fn () => $ids !== null && count($ids) === 1 ? $ids[0] : null),
            Select::make('product_id')->label('Service')->required()->searchable()
                ->options(fn () => Product::where('is_active', true)->whereNotNull('service_minutes')->orderBy('name')->get()
                    ->mapWithKeys(fn ($p) => [$p->id => "{$p->name} ({$p->service_minutes} min)"])),
            Select::make('employee_id')->label('With')->required()
                ->options(fn (Get $get) => $get('branch_id') ? AppointmentService::staff($get('branch_id'))->pluck('name', 'id') : []),
            DateTimePicker::make('starts_at')->label('Starts')->required()->seconds(false)->minutesStep(5),
            Select::make('customer_id')->label('Customer (if known)')->searchable()
                ->getSearchResultsUsing(fn (string $search) => Customer::where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                    ->orderBy('name')->limit(20)->get()->mapWithKeys(fn ($c) => [$c->id => trim("{$c->name} {$c->phone}")]))
                ->getOptionLabelUsing(fn ($value) => Customer::whereKey($value)->value('name')),
            TextInput::make('customer_name')->label('Name (if not a customer)')->maxLength(120),
            TextInput::make('phone')->tel()->maxLength(20)->helperText('For the SMS confirmation and reminder.'),
            TextInput::make('note')->maxLength(255)->columnSpan(2),
        ];
    }

    public static function table(Table $table): Table
    {
        $svc = fn () => app(AppointmentService::class);

        return $table->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')->label('When')->dateTime('D j M, H:i')->sortable(),
                TextColumn::make('customer_name')->label('Customer')->searchable()->description(fn (Appointment $r) => $r->phone),
                TextColumn::make('product.name')->label('Service'),
                TextColumn::make('employee.name')->label('With'),
                TextColumn::make('number')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Appointment::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['done' => 'success', 'arrived' => 'warning', 'no_show' => 'danger', 'cancelled' => 'gray'][$state] ?? 'info'),
            ])
            ->filters([
                Filter::make('upcoming')->label('From today')->default()->query(fn (Builder $query) => $query->where('starts_at', '>=', now()->startOfDay())),
                SelectFilter::make('status')->options(Appointment::STATUSES),
                SelectFilter::make('employee_id')->label('With')->options(fn () => Employee::where('takes_appointments', true)->orderBy('name')->pluck('name', 'id')),
            ])
            ->recordActions([
                Action::make('arrived')->label('Arrived')->color('warning')->visible(fn (Appointment $r) => $r->status === 'booked')
                    ->action(fn (Appointment $r) => Erp::run(fn ($actor) => $svc()->setStatus($actor, $r, 'arrived'), 'Marked arrived')),
                Action::make('noShow')->label('No-show')->color('danger')->requiresConfirmation()->visible(fn (Appointment $r) => $r->status === 'booked' && $r->starts_at->isPast())
                    ->action(fn (Appointment $r) => Erp::run(fn ($actor) => $svc()->setStatus($actor, $r, 'no_show'), 'Marked no-show')),
                Action::make('move')->label('Change')->visible(fn (Appointment $r) => $r->status === 'booked')
                    ->schema(fn (Appointment $r) => [
                        DateTimePicker::make('starts_at')->label('Starts')->required()->seconds(false)->minutesStep(5)->default($r->starts_at),
                        Select::make('employee_id')->label('With')->required()->default($r->employee_id)->options(AppointmentService::staff($r->branch_id)->pluck('name', 'id')),
                    ])
                    ->action(fn (Appointment $r, array $data) => Erp::run(fn ($actor) => $svc()->book($actor, $r, $data), 'Appointment changed')),
                Action::make('cancel')->label('Cancel')->color('gray')->visible(fn (Appointment $r) => in_array($r->status, Appointment::OPEN, true))
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (Appointment $r, array $data) => Erp::run(fn ($actor) => $svc()->setStatus($actor, $r, 'cancelled', $data['reason']), 'Cancelled')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAppointments::route('/')];
    }
}
