<?php

namespace App\Filament\Resources\PricingRules;

use App\Domain\Pricing\PricingRules;
use App\Filament\Resources\PricingRules\Pages\ListPricingRules;
use App\Filament\Support\ArchiveUi;
use App\Filament\Support\Erp;
use App\Filament\Support\IndustryScope;
use App\Filament\Support\SimpleCatalogResource;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\PricingRule;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Pricing & tax → Pricing rules: selling prices worked out from the latest cost (App\Domain\Pricing\PricingRules), for
 * the whole company, a category or one product, on a price list. For people who set prices.
 */
class PricingRuleResource extends SimpleCatalogResource
{
    /** Follows the industry in view (owner 2026-11-09: every industry independent). */
    public static function getEloquentQuery(): Builder
    {
        return IndustryScope::product(parent::getEloquentQuery());
    }

    protected static ?string $model = PricingRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Pricing & tax';

    protected static ?string $navigationLabel = 'Pricing rules';

    protected static ?string $modelLabel = 'pricing rule';

    protected static ?int $navigationSort = 2;

    protected static string $viewPermission = 'pricing.manage';

    protected static string $managePermission = 'pricing.manage';

    public static function form(Schema $schema): Schema
    {
        $valueLabel = fn (Get $get) => match ($get('method')) {
            'multiply' => 'Multiply the cost by', 'markup' => 'Markup (% of the cost)', 'margin' => 'Margin (% of the selling price)', default => 'Value',
        };

        return $schema->components([
            Radio::make('scope')->label('Applies to')->options(PricingRule::SCOPES)->default('company')->live()->required(),
            Select::make('category_id')->label('Category')->options(fn () => Category::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable()
                ->visible(fn (Get $get) => $get('scope') === 'category')->required(fn (Get $get) => $get('scope') === 'category'),
            Select::make('product_id')->label('Product')->searchable()
                ->getSearchResultsUsing(fn (string $search) => Product::where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"))->limit(30)->pluck('name', 'id'))
                ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name)
                ->visible(fn (Get $get) => $get('scope') === 'product')->required(fn (Get $get) => $get('scope') === 'product'),
            Select::make('price_list_id')->label('Price list it sets')->options(fn () => PriceList::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id'))
                ->default(fn () => PriceList::where('is_default', true)->value('id'))->required(),
            Select::make('method')->label('How the price is worked out')->options(PricingRule::METHODS)->default('multiply')->live()->required()->selectablePlaceholder(false),
            Grid::make(2)->schema([
                TextInput::make('value')->label($valueLabel)->numeric()->default('1.40')
                    ->visible(fn (Get $get) => in_array($get('method'), ['multiply', 'markup', 'margin'], true))->live(onBlur: true),
                TextInput::make('amount')->label('Add to the cost')->numeric()->prefix('GHS')->visible(fn (Get $get) => $get('method') === 'plus')->live(onBlur: true),
            ]),
            Text::make(fn (Get $get) => self::example($get))->visible(fn (Get $get) => $get('method') !== 'manual'),
            Grid::make(2)->schema([
                Select::make('tax_mode')->label('The result is')->options(PricingRule::TAX_MODES)->default('inclusive')->selectablePlaceholder(false)
                    ->helperText('Shelf price: VAT and levies are inside the price. Before tax: they are added on top for taxable items.'),
                Select::make('round_minor')->label('Rounding')->options(PricingRule::ROUNDING)->default(10)->selectablePlaceholder(false),
                Select::make('round_mode')->label('Round')->options(['nearest' => 'To the nearest', 'up' => 'Always up'])->default('nearest')->selectablePlaceholder(false),
                Select::make('on_change')->label('When a delivery changes the cost')->options(PricingRule::ON_CHANGE)->default('rise_auto')->selectablePlaceholder(false)
                    ->helperText('Prices waiting for approval are under Pricing & tax → Prices to review.'),
            ])->visible(fn (Get $get) => $get('method') !== 'manual'),
            Toggle::make('is_active')->label('In use')->default(true),
        ]);
    }

    /** "A cost of 10.00 gives 14.00." for the rule being typed. */
    private static function example(Get $get): string
    {
        $v = (float) ($get('value') ?: 0);
        $price = match ($get('method')) {
            'multiply' => 10 * $v,
            'markup' => 10 * (1 + $v / 100),
            'margin' => $v < 100 ? 10 / (1 - $v / 100) : null,
            'plus' => 10 + (float) ($get('amount') ?: 0),
            default => null,
        };

        return $price === null ? '' : 'Example: a cost of GHS 10.00 gives GHS '.number_format($price, 2).' (before rounding).';
    }

    public static function table(Table $table): Table
    {
        $save = fn (?PricingRule $record, array $data): Model => Erp::run(fn ($actor) => app(PricingRules::class)->saveRule($actor, $record ?? new PricingRule, $data), 'Rule saved');
        $fill = fn (array $data) => $data + [
            'value' => match ($data['method'] ?? '') {
                'multiply' => ($data['value_bp'] ?? 0) / 10000, 'markup', 'margin' => ($data['value_bp'] ?? 0) / 100, default => null
            },
            'amount' => isset($data['amount_minor']) ? $data['amount_minor'] / 100 : null,
        ];

        return ArchiveUi::apply($table
            ->columns([
                TextColumn::make('scope')->label('Applies to')->formatStateUsing(fn (PricingRule $r) => match ($r->scope) {
                    'category' => 'Category: '.$r->category?->name, 'product' => 'Product: '.$r->product?->name, default => 'Whole company'
                }),
                TextColumn::make('priceList.name')->label('Price list'),
                TextColumn::make('method')->label('Rule')->formatStateUsing(fn (PricingRule $r) => $r->describe())->weight('bold'),
                TextColumn::make('tax_mode')->label('Gives')->formatStateUsing(fn ($state) => PricingRule::TAX_MODES[$state] ?? $state),
                TextColumn::make('round_minor')->label('Rounding')->formatStateUsing(fn ($state, PricingRule $r) => (PricingRule::ROUNDING[$state] ?? $state).($r->round_mode === 'up' ? ', up' : '')),
                TextColumn::make('on_change')->label('When the cost changes')->formatStateUsing(fn ($state) => PricingRule::ON_CHANGE[$state] ?? $state)->wrap(),
                IconColumn::make('is_active')->boolean()->label('In use'),
            ])
            ->defaultSort('scope')
            ->emptyStateHeading('No pricing rules: prices are typed by hand')
            ->emptyStateDescription('Add a rule for the whole company (e.g. cost × 1.40), then exceptions for categories or products.')
            ->pushHeaderActions([
                CreateAction::make()->label('New rule')->using(fn (array $data) => $save(null, $data)),
                Action::make('applyAll')->label('Apply the rules to all products now')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->requiresConfirmation()->modalDescription('Every product with a rule gets the price its latest cost gives. Rises (and new prices) are set at once where the rule says so; the rest wait under Prices to review.')
                    ->action(function () {
                        $r = Erp::run(fn ($actor) => app(PricingRules::class)->followAll($actor));
                        Notification::make()->success()->title("{$r['applied']} price(s) set, {$r['suggested']} waiting for review")->send();
                    }),
            ])
            ->recordActions([EditAction::make()->mutateRecordDataUsing($fill)->using(fn (PricingRule $record, array $data) => $save($record, $data))]));
    }

    public static function getPages(): array
    {
        return ['index' => ListPricingRules::route('/')];
    }
}
