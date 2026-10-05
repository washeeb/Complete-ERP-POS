<?php

namespace App\Filament\Resources\Brands;

use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Support\SimpleCatalogResource;
use App\Models\Brand;
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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(120)]);
    }

    public static function table(Table $table): Table
    {
        return static::crudTable($table->columns([TextColumn::make('name')->searchable()->sortable()])->defaultSort('name'));
    }

    public static function getPages(): array
    {
        return ['index' => ListBrands::route('/')];
    }
}
