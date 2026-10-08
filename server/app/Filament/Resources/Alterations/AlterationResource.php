<?php

namespace App\Filament\Resources\Alterations;

use App\Domain\Fashion\AlterationService;
use App\Domain\Modules\ModuleService;
use App\Filament\Resources\Alterations\Pages\ListAlterations;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Alteration;
use App\Models\Branch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Alteration jobs: garments in for tailoring, their agreed charge, due date and progress. */
class AlterationResource extends Resource
{
    protected static ?string $model = Alteration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScissors;

    protected static string|UnitEnum|null $navigationGroup = 'Fashion';

    protected static ?string $navigationLabel = 'Alterations';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('fashion.alterations') && ModuleService::enabledAnywhere('fashion');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('fashion.alterations');
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
        $ids = Erp::branchIds('fashion.alterations');

        return parent::getEloquentQuery()->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('branch_id')->label('Branch')->required()
                ->options(fn () => Branch::when(Erp::branchIds('fashion.alterations') !== null, fn ($q) => $q->whereIn('id', Erp::branchIds('fashion.alterations')))->orderBy('code')->pluck('name', 'id')),
            TextInput::make('customer_name')->label('Customer')->required()->maxLength(120),
            TextInput::make('phone')->tel()->maxLength(20),
            TextInput::make('garment')->required()->maxLength(160),
            TextInput::make('charge')->label('Charge')->numeric()->minValue(0)->default('0')->helperText('0 = free (e.g. with the purchase).'),
            DatePicker::make('due_on')->label('Ready by'),
            Textarea::make('work')->label('Work to do')->required()->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $svc = fn () => app(AlterationService::class);

        return $table->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('customer_name')->label('Customer')->searchable()->description(fn (Alteration $r) => $r->phone),
                TextColumn::make('garment')->description(fn (Alteration $r) => $r->work)->wrap(),
                TextColumn::make('charge_minor')->label('Charge')->formatStateUsing(fn ($state) => $state ? FinanceUi::money((int) $state) : 'Free'),
                TextColumn::make('due_on')->label('Ready by')->date()->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Alteration::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['ready' => 'success', 'collected' => 'gray', 'cancelled' => 'gray'][$state] ?? 'info'),
            ])
            ->filters([SelectFilter::make('status')->options(Alteration::STATUSES)])
            ->recordActions([
                Action::make('progress')->label('Update')->visible(fn (Alteration $r) => isset(AlterationService::TRANSITIONS[$r->status]))
                    ->schema(fn (Alteration $r) => [
                        Select::make('status')->required()->options(collect(AlterationService::TRANSITIONS[$r->status] ?? [])->mapWithKeys(fn ($s) => [$s => Alteration::STATUSES[$s]])->all()),
                        TextInput::make('tailor')->maxLength(120)->default(fn () => $r->tailor_name),
                    ])
                    ->action(fn (Alteration $r, array $data) => Erp::run(fn ($actor) => $svc()->setStatus($actor, $r, $data['status'], $data['tailor'] ?? null), 'Updated')),
                Action::make('handOver')->label('Hand over')->color('success')->requiresConfirmation()
                    ->visible(fn (Alteration $r) => $r->status === 'ready' && $r->charge_minor === 0)
                    ->action(fn (Alteration $r) => Erp::run(fn ($actor) => $svc()->handOver($actor, $r), 'Handed over')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAlterations::route('/')];
    }
}
