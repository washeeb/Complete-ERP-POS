<?php

namespace App\Filament\Resources\Reservations;

use App\Domain\Modules\ModuleService;
use App\Domain\Restaurant\ReservationService;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Reservation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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

/** Table bookings: who, when, which table, and the deposit (taken at the till; kept here after a no-show). */
class ReservationResource extends Resource
{
    protected static ?string $model = Reservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?string $navigationLabel = 'Bookings';

    protected static ?string $modelLabel = 'booking';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 0;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('restaurant.serve') || Erp::canAnywhere('restaurant.manage')) && ModuleService::enabledAnywhere('restaurant');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('restaurant.serve');
    }

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'booked' && Erp::can('restaurant.serve', Branch::find($record->branch_id));
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('restaurant.serve');

        return parent::getEloquentQuery()->with(['table', 'branch'])->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('branch_id')->label('Branch')->required()->disabledOn('edit')->live()
                ->options(fn () => Branch::when(Erp::branchIds('restaurant.serve') !== null, fn ($q) => $q->whereIn('id', Erp::branchIds('restaurant.serve')))->orderBy('code')->pluck('name', 'id')),
            TextInput::make('guest_name')->label('Name')->required()->maxLength(120),
            TextInput::make('phone')->tel()->maxLength(20)->helperText('For the SMS confirmation and reminder.'),
            TextInput::make('party_size')->label('Guests')->numeric()->required()->minValue(1)->maxValue(200)->default(2),
            DateTimePicker::make('starts_at')->label('Date and time')->required()->seconds(false)->minutesStep(15),
            TextInput::make('duration_minutes')->label('Minutes')->numeric()->default(90)->minValue(15)->maxValue(600),
            Select::make('dining_table_id')->label('Table')->placeholder('Choose on arrival')
                ->options(fn (Get $get) => DiningTable::where('branch_id', $get('branch_id'))->where('is_active', true)->orderBy('code')->get()
                    ->mapWithKeys(fn ($t) => [$t->id => "{$t->code} ({$t->seats} seats)"])->all()),
            TextInput::make('note')->maxLength(255)->columnSpan(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')->label('When')->dateTime('D j M, H:i')->sortable(),
                TextColumn::make('guest_name')->label('Name')->searchable()->description(fn (Reservation $r) => $r->phone),
                TextColumn::make('party_size')->label('Guests'),
                TextColumn::make('table.code')->label('Table')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Reservation::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['booked' => 'info', 'seated' => 'success', 'no_show' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('deposit')->label('Deposit')->state(fn (Reservation $r) => $r->deposit_minor ? FinanceUi::money($r->deposit_minor).' · '.$r->deposit_status : null)->placeholder('—'),
                TextColumn::make('number')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('upcoming')->label('From today')->default()->query(fn ($query) => $query->where('starts_at', '>=', now()->startOfDay())),
                SelectFilter::make('status')->options(Reservation::STATUSES),
            ])
            ->recordActions([
                EditAction::make()->using(fn (Reservation $record, array $data) => Erp::run(fn ($actor) => app(ReservationService::class)->save($actor, $record, $data))),
                Action::make('no_show')->label('No-show')->color('danger')->requiresConfirmation()
                    ->visible(fn (Reservation $r) => $r->status === 'booked' && $r->starts_at->lt(now()))
                    ->action(fn (Reservation $r) => Erp::run(fn ($actor) => app(ReservationService::class)->noShow($actor, $r), 'Marked as no-show')),
                Action::make('cancel')->label('Cancel')->color('gray')->visible(fn (Reservation $r) => $r->status === 'booked')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (Reservation $r, array $data) => Erp::run(fn ($actor) => app(ReservationService::class)->cancel($actor, $r, $data['reason']), 'Booking cancelled')),
                Action::make('forfeit')->label('Keep deposit')->color('warning')
                    ->visible(fn (Reservation $r) => in_array($r->status, ['no_show', 'cancelled'], true) && $r->depositLeft() > 0 && Erp::can('restaurant.manage', Branch::find($r->branch_id)))
                    ->modalDescription('The deposit left becomes income (Other income). To give it back instead, refund it at a till.')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (Reservation $r, array $data) => Erp::run(fn ($actor) => app(ReservationService::class)->forfeitDeposit($actor, $r, $data['reason']), 'Deposit kept')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReservations::route('/')];
    }
}
