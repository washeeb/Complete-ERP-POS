<?php

namespace App\Filament\Resources\TaxRates;

use App\Filament\Resources\TaxRates\Pages\ListTaxRates;
use App\Filament\Support\SimpleCatalogResource;
use App\Models\TaxRate;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TaxRateResource extends SimpleCatalogResource
{
    protected static ?string $model = TaxRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Pricing & tax';

    protected static ?int $navigationSort = 3;

    protected static string $managePermission = 'pricing.manage';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(20)->regex('/^[A-Za-z0-9_]{1,20}$/')->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(80)->helperText('Printed on receipts'),
            TextInput::make('rate_bp')->label('Rate (basis points)')->required()->integer()->minValue(0)->maxValue(10000)
                ->helperText('1500 = 15.00%. A change affects new sales only; posted sales keep the tax they were charged.'),
            TextInput::make('sort')->integer()->default(0)->helperText('Order on receipts'),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return static::crudTable($table->columns([
            TextColumn::make('code')->sortable(),
            TextColumn::make('name'),
            TextColumn::make('rate_bp')->label('Rate')->formatStateUsing(fn ($state) => number_format($state / 100, 2).'%'),
            IconColumn::make('is_active')->boolean()->label('Active'),
        ])->defaultSort('sort'));
    }

    public static function getPages(): array
    {
        return ['index' => ListTaxRates::route('/')];
    }
}
