<?php

namespace App\Filament\Resources\LegalEntities;

use App\Domain\Org\OrgService;
use App\Filament\Resources\LegalEntities\Pages\ListLegalEntities;
use App\Filament\Support\Erp;
use App\Models\LegalEntity;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Company → Legal entities: the registered companies of the group. An industry registered as its own company keeps
 * separate books: give it its legal entity under Company → Industries.
 */
class LegalEntityResource extends Resource
{
    protected static ?string $model = LegalEntity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Company';

    protected static ?string $navigationLabel = 'Legal entities';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Erp::can('setup.company.manage') || Erp::canAnywhere('finance.view');
    }

    public static function canCreate(): bool
    {
        return Erp::can('setup.company.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::can('setup.company.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // its books and receipts point at it; switch it off instead
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Registered name')->required()->maxLength(150)->columnSpanFull(),
            TextInput::make('registration_no')->label('Registration number')->maxLength(40),
            TextInput::make('tin')->label('TIN')->maxLength(15)->helperText('Printed on receipts and invoices of its branches.'),
            TextInput::make('vat_no')->label('VAT number')->maxLength(30),
            TextInput::make('address')->maxLength(255),
            Toggle::make('is_primary')->label('Head office company')
                ->helperText('Lines without a branch, and branches without a company of their own, are booked here.'),
            Toggle::make('is_active')->label('In use')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $save = fn (?LegalEntity $record, array $data): Model => Erp::run(fn ($actor) => app(OrgService::class)->saveLegalEntity($actor, $record ?? new LegalEntity, $data), 'Saved');

        return $table
            ->columns([
                TextColumn::make('name')->label('Registered name')->searchable(),
                TextColumn::make('tin')->label('TIN')->placeholder('—'),
                TextColumn::make('vat_no')->label('VAT number')->placeholder('—'),
                IconColumn::make('is_primary')->label('Head office')->boolean()->falseIcon(null),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->defaultSort('name')
            ->headerActions([CreateAction::make()->label('New legal entity')->using(fn (array $data) => $save(null, $data))->visible(fn () => Erp::can('setup.company.manage'))])
            ->recordActions([EditAction::make()->using(fn (LegalEntity $record, array $data) => $save($record, $data))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLegalEntities::route('/')];
    }
}
