<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Support\GlobalSearch;
use App\Filament\Support\SimpleCatalogResource;
use App\Models\Category;
use App\Models\Product;
use BackedEnum;
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

class CategoryResource extends SimpleCatalogResource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails([]);
    }

    protected static ?string $model = Category::class;

    protected static bool $deletable = true;

    protected static ?string $movesProducts = 'category_id';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120),
            Select::make('parent_id')->label('Inside category')
                ->options(fn (?Category $record) => Category::when($record, fn ($q) => $q->whereKeyNot($record->id))->orderBy('name')->pluck('name', 'id')),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return static::crudTable($table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('parent.name')->label('Inside'),
            TextColumn::make('products_count')->label('Products')->state(fn ($record) => Product::where('category_id', $record->id)->count()),
            IconColumn::make('is_active')->boolean()->label('Active'),
        ])->defaultSort('name'));
    }

    public static function getPages(): array
    {
        return ['index' => ListCategories::route('/')];
    }
}
