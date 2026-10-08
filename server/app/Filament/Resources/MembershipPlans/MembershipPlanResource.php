<?php

namespace App\Filament\Resources\MembershipPlans;

use App\Domain\Modules\ModuleService;
use App\Domain\Services\MembershipService;
use App\Filament\Resources\MembershipPlans\Pages\ListMembershipPlans;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\MembershipPlan;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

/** Membership and subscription plans: price per period, how often, and the sessions included each period. */
class MembershipPlanResource extends Resource
{
    protected static ?string $model = MembershipPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static ?string $navigationLabel = 'Membership plans';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('services.memberships') && ModuleService::enabledAnywhere('services');
    }

    public static function canCreate(): bool
    {
        return Erp::can('services.memberships') && config('erp.node.role') !== 'branch'; // plans are head-office data
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('code')->required()->maxLength(30),
            TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
            TextInput::make('price')->label('Price per period')->required()->numeric()->minValue(0.01)->prefix(fn () => Erp::user()?->tenant->currency_code)
                ->helperText('Including tax when your default price list includes tax.'),
            Select::make('interval_months')->label('Billed')->required()->options(MembershipService::INTERVALS)->default(1),
            Select::make('product_id')->label('Invoiced as (product)')->required()->searchable()
                ->options(fn () => Product::where('is_active', true)->where('track_stock', false)->orderBy('name')->pluck('name', 'id'))
                ->helperText('A product without stock, e.g. "Gym membership". Its tax group taxes the invoice.'),
            Repeater::make('items')->label('Sessions included each period (optional)')->defaultItems(0)->columns(3)->reorderable(false)->columnSpanFull()
                ->helperText('Leave empty for a plain subscription. Sessions empty = unlimited (e.g. gym access); they do not carry over to the next period.')
                ->schema([
                    Select::make('product_ids')->label('Services')->multiple()->required()->searchable()
                        ->options(fn () => Product::where('is_active', true)->whereNotNull('service_minutes')->orderBy('name')->pluck('name', 'id')),
                    TextInput::make('sessions')->label('Sessions')->numeric()->minValue(1)->maxValue(1000),
                    TextInput::make('label')->label('Shown as (optional)')->maxLength(120),
                ]),
            Textarea::make('description')->rows(2)->columnSpan(2),
            Toggle::make('is_active')->label('Offered')->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('price_minor')->label('Price')->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('interval_months')->label('Billed')->formatStateUsing(fn ($state) => MembershipService::INTERVALS[$state] ?? $state),
                TextColumn::make('items')->label('Includes')->formatStateUsing(fn (MembershipPlan $record) => collect($record->items)
                    ->map(fn ($i) => ($i['sessions'] ?? 'Unlimited').' × '.$i['label'])->implode(', ') ?: '—'),
                IconColumn::make('is_active')->label('Offered')->boolean(),
            ])
            ->recordActions([EditAction::make()->mutateRecordDataUsing(fn (array $data, MembershipPlan $record) => $data + ['price' => $record->price_minor / 100])
                ->using(fn (MembershipPlan $record, array $data) => Erp::run(fn ($actor) => app(MembershipService::class)->savePlan($actor, $record, $data)))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMembershipPlans::route('/')];
    }
}
