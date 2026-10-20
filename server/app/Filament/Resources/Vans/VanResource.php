<?php

namespace App\Filament\Resources\Vans;

use App\Domain\Distribution\VanService;
use App\Domain\Distribution\VanSettlementService;
use App\Domain\Modules\ModuleService;
use App\Filament\Resources\Vans\Pages\ListVans;
use App\Filament\Resources\VanSettlements\VanSettlementResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** Vans: warehouses on wheels with their own till; loaded from a home warehouse and settled each evening. */
class VanResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static ?string $slug = 'vans';

    protected static ?string $modelLabel = 'van';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Distribution';

    protected static ?string $navigationLabel = 'Vans';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.view') && ModuleService::enabledAnywhere('distribution');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('setup.company.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('setup.company.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('stock.view');

        return parent::getEloquentQuery()->where('is_van', true)->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('branch_id')->label('Branch')->required()->live()->disabledOn('edit')
                ->options(fn () => Branch::orderBy('code')->pluck('name', 'id')),
            TextInput::make('code')->label('Van code')->required()->maxLength(10)->disabledOn('edit')->helperText('Also the till code on receipts, e.g. V01.'),
            TextInput::make('name')->required()->maxLength(80)->placeholder('Kumasi van 1'),
            TextInput::make('vehicle_reg')->label('Registration')->maxLength(20)->placeholder('GR 1234-24'),
            Select::make('rep_user_id')->label('Rep')->searchable()->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
            Select::make('home_warehouse_id')->label('Loaded from (warehouse)')->required()
                ->options(fn (Get $get) => Warehouse::where('is_van', false)->when($get('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))->orderBy('code')->pluck('name', 'id')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('vehicle_reg')->label('Registration')->placeholder('—'),
                TextColumn::make('rep_user_id')->label('Rep')->formatStateUsing(fn ($state) => User::whereKey($state)->value('name'))->placeholder('—'),
                TextColumn::make('home_warehouse_id')->label('Loaded from')->formatStateUsing(fn ($state) => Warehouse::whereKey($state)->value('code')),
                TextColumn::make('stock')->label('Stock on board')->state(fn (Warehouse $r) => number_format((float) DB::table('stock_levels')->where('warehouse_id', $r->id)->sum('on_hand'), 0).' units'),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::moveAction('load', 'Load van', false),
                    self::moveAction('unload', 'Unload to warehouse', true),
                    Action::make('settle')->label('Start end-of-day settlement')->icon(Heroicon::OutlinedClipboardDocumentCheck)
                        ->visible(fn () => Erp::canAnywhere('stock.transfer'))
                        ->action(function (Warehouse $record, $livewire) {
                            $s = Erp::run(fn ($actor) => app(VanSettlementService::class)->start($actor, $record), 'Settlement started');
                            $livewire->redirect(VanSettlementResource::getUrl('view', ['record' => $s]));
                        }),
                ])->label('Van')->button()->color('gray'),
            ]);
    }

    private static function moveAction(string $name, string $label, bool $unload): Action
    {
        return Action::make($name)->label($label)->icon($unload ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedArrowUpOnSquareStack)
            ->visible(fn () => Erp::canAnywhere('stock.transfer'))
            ->modalDescription($unload ? 'Stock goes from the van back to its warehouse (a transfer, received at once).' : 'Stock goes from the warehouse onto the van (a transfer, received at once — both are at the depot).')
            ->schema([
                Repeater::make('lines')->itemNumbers()->hiddenLabel()->columns(3)->minItems(1)->addActionLabel('Add product')->schema([
                    Fields::productUnit()->columnSpan(2),
                    TextInput::make('qty')->label('Quantity')->required()->numeric()->minValue(0.001),
                ]),
            ])
            ->action(fn (Warehouse $record, array $data) => Erp::run(fn ($actor) => app(VanService::class)->load($actor, $record, array_values($data['lines']), $unload),
                $unload ? 'Unloaded' : 'Van loaded'));
    }

    public static function getPages(): array
    {
        return ['index' => ListVans::route('/'), 'edit' => Pages\EditVan::route('/{record}/edit')];
    }
}
