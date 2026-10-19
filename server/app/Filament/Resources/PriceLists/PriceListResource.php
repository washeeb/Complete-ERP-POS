<?php

namespace App\Filament\Resources\PriceLists;

use App\Domain\Catalog\CatalogService;
use App\Filament\Resources\PriceLists\Pages\ListPriceLists;
use App\Filament\Support\Erp;
use App\Filament\Support\GlobalSearch;
use App\Filament\Support\SimpleCatalogResource;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\PriceList;
use App\Support\IndustryContext;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PriceListResource extends SimpleCatalogResource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Code' => $record->code]);
    }

    protected static ?string $model = PriceList::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Pricing & tax';

    protected static ?int $navigationSort = 1;

    protected static string $managePermission = 'pricing.manage';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(20)->regex('/^[A-Za-z0-9_]{1,20}$/')->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(80),
            Toggle::make('prices_include_tax')->label('Prices include tax')->default(true)
                ->helperText('Retail shelf prices normally include tax. Cannot change once the list has prices.'),
            Toggle::make('is_default')->label('Default list (used when the till starts a sale)'),
            Toggle::make('pos_selectable')->label('Cashier can switch to it at the till')->default(true),
            Select::make('industry_id')->label('Industry')->options(fn () => Industry::licensed()->orderBy('sort')->pluck('name', 'id'))
                ->default(fn () => IndustryContext::selected())->placeholder('All industries (shared)')->visible(fn () => Industry::exists()),
            Select::make('branch_id')->label('Only at branch')->placeholder('All branches')
                ->options(fn () => Branch::orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"])),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $save = fn (?PriceList $record, array $data): Model => Erp::run(fn ($actor) => app(CatalogService::class)
            ->savePriceList($actor, $record ?? new PriceList, $data));

        return $table
            ->modifyQueryUsing(fn ($query) => $query->when(IndustryContext::selected(), fn ($q, $id) => $q->where(fn ($w) => $w->whereNull('industry_id')->orWhere('industry_id', $id))))
            ->columns([
                TextColumn::make('code'),
                TextColumn::make('name'),
                TextColumn::make('currency_code')->label('Currency'),
                IconColumn::make('prices_include_tax')->boolean()->label('Incl. tax'),
                IconColumn::make('is_default')->boolean()->label('Default'),
                IconColumn::make('pos_selectable')->boolean()->label('At till'),
                TextColumn::make('industry.name')->label('Industry')->placeholder('Shared')->visible(fn () => Industry::exists()),
                TextColumn::make('branch.code')->label('Branch')->placeholder('All'),
                TextColumn::make('prices_count')->counts('prices')->label('Prices'),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->pushHeaderActions([CreateAction::make()->using(fn (array $data) => $save(null, $data))])
            ->recordActions([EditAction::make()->using(fn (PriceList $record, array $data) => $save($record, $data))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPriceLists::route('/')];
    }
}
