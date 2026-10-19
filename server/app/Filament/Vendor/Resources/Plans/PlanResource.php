<?php

namespace App\Filament\Vendor\Resources\Plans;

use App\Domain\Licensing\Vendor\VendorLicensing;
use App\Filament\Vendor\Resources\Plans\Pages\CreatePlan;
use App\Filament\Vendor\Resources\Plans\Pages\EditPlan;
use App\Filament\Vendor\Resources\Plans\Pages\ListPlans;
use App\Filament\Vendor\VendorUi;
use App\Models\Vendor\VendorPlan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Licence plans: default limits, industry modules, features, length and price. */
class PlanResource extends Resource
{
    protected static ?string $model = VendorPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Set-up';

    protected static ?string $modelLabel = 'plan';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        $isPlan = VendorPlan::class === VendorPlan::class;

        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema(array_values(array_filter([
                TextInput::make('code')->required()->maxLength(20)->unique(ignoreRecord: true),
                TextInput::make('name')->required()->maxLength(80),
                Toggle::make('is_active')->label('Offered')->default(true)->inline(false),
                $isPlan ? Textarea::make('description')->maxLength(500)->columnSpanFull() : null,
                TextInput::make('price')->label('Price per licence period')->numeric()->minValue(0)->required()
                    ->formatStateUsing(fn (?VendorPlan $record) => $record ? $record->price_minor / 100 : null)->dehydrated(false),
                Select::make('currency')->options(['GHS' => 'GHS', 'USD' => 'USD'])->default('GHS')->required(),
                $isPlan ? TextInput::make('months')->label('Licence length (months)')->numeric()->minValue(1)->maxValue(120)->default(12)->required() : null,
            ]))),
            Section::make($isPlan ? 'Limits (empty = unlimited)' : 'Adds per unit')->columns(3)->schema([
                TextInput::make('branches')->numeric()->minValue(0),
                TextInput::make('devices')->label('Tills and devices')->numeric()->minValue(0),
                TextInput::make('users')->label('Active users')->numeric()->minValue(0),
            ]),
            Section::make('Industry modules and features')->columns(2)->schema([
                Toggle::make('all_modules')->label($isPlan ? 'Every industry module' : 'No modules')->live()->dehydrated(false)
                    ->formatStateUsing(fn (?VendorPlan $record) => $record === null ? $isPlan : $record->modules === null),
                CheckboxList::make('modules')->options(fn () => VendorLicensing::modules())->columns(3)->hidden(fn (Get $get) => $get('all_modules')),
                Toggle::make('all_features')->label($isPlan ? 'Every feature' : 'No features')->live()->dehydrated(false)
                    ->formatStateUsing(fn (?VendorPlan $record) => $record === null ? $isPlan : $record->features === null),
                CheckboxList::make('features')->options(VendorLicensing::FEATURES)->hidden(fn (Get $get) => $get('all_features')),
            ]),
        ]);
    }

    /** Form data → columns (price in minor units; "every" = null for plans, none = null for add-ons). */
    public static function prepare(array $data, array $raw): array
    {
        $data['price_minor'] = VendorUi::toMinor($raw['price'] ?? 0, $data['currency'] ?? 'GHS');
        foreach (['modules' => 'all_modules', 'features' => 'all_features'] as $k => $all) {
            $data[$k] = ! empty($raw[$all]) ? null : array_values($data[$k] ?? []);
        }
        foreach (['branches', 'devices', 'users'] as $k) {
            $data[$k] = ($data[$k] ?? '') === '' ? null : (int) $data[$k];
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('price_minor')
            ->columns([
                TextColumn::make('name')->description(fn (VendorPlan $r) => $r->code)->searchable(),
                TextColumn::make('price_minor')->label('Price')->formatStateUsing(fn ($state, VendorPlan $r) => VendorUi::money((int) $state, $r->currency)),
                TextColumn::make('limits')->label('Branches / devices / users')->state(fn (VendorPlan $r) => collect(['branches', 'devices', 'users'])
                    ->map(fn ($k) => $r->{$k} === null ? '∞' : $r->{$k})->implode(' / ')),
                TextColumn::make('modules')->label('Modules')->state(fn (VendorPlan $r) => $r->modules === null ? 'all' : (implode(', ', $r->modules) ?: 'none'))->wrap(),
                TextColumn::make('features')->label('Features')->state(fn (VendorPlan $r) => $r->features === null ? 'all' : (implode(', ', $r->features) ?: 'none')),
                IconColumn::make('is_active')->label('Offered')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPlans::route('/'), 'create' => CreatePlan::route('/create'), 'edit' => EditPlan::route('/{record}/edit')];
    }
}
