<?php

namespace App\Filament\Resources\AssetClasses;

use App\Domain\Assets\AssetService;
use App\Filament\Resources\AssetClasses\Pages\ListAssetClasses;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\AssetClass;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Kinds of fixed asset: useful life and accounts. Reached from Fixed assets. */
class AssetClassResource extends Resource
{
    protected static ?string $model = AssetClass::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'kind of asset';

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('assets.manage') || Erp::canAnywhere('finance.view')) && config('erp.node.role') !== 'branch';
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('assets.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('assets.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $acc = fn (string $type) => fn () => FinanceUi::accounts(withControl: false, types: [$type]);

        return $schema->columns(2)->components([
            TextInput::make('code')->required()->maxLength(20),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('life_months')->label('Useful life (months)')->required()->numeric()->minValue(1),
            Select::make('cost_account_id')->label('Asset account (cost)')->required()->options($acc('asset')),
            Select::make('accumulated_account_id')->label('Accumulated depreciation account')->required()->options($acc('asset')),
            Select::make('expense_account_id')->label('Depreciation expense account')->required()->options($acc('expense')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code'),
            TextColumn::make('name'),
            TextColumn::make('life_months')->label('Life (months)'),
            TextColumn::make('costAccount.name')->label('Asset account'),
        ])->recordActions([EditAction::make()->using(fn (AssetClass $record, array $data) => Erp::run(fn ($actor) => app(AssetService::class)->saveClass($actor, $record, $data)))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAssetClasses::route('/')];
    }
}
