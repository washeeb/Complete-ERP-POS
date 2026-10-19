<?php

namespace App\Filament\Resources\Brands;

use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Support\SimpleCatalogResource;
use App\Models\Brand;
use App\Models\Product;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class BrandResource extends SimpleCatalogResource
{
    protected static ?string $model = Brand::class;

    protected static bool $deletable = true;

    protected static ?string $movesProducts = 'brand_id';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(120)]);
    }

    public static function table(Table $table): Table
    {
        return static::crudTable($table->columns([TextColumn::make('name')->searchable()->sortable(), TextColumn::make('products_count')->label('Products')->state(fn ($record) => Product::where('brand_id', $record->id)->count())])->defaultSort('name'));
    }

    public static function getPages(): array
    {
        return ['index' => ListBrands::route('/')];
    }
}
