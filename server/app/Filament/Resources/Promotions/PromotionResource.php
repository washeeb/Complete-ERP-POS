<?php

namespace App\Filament\Resources\Promotions;

use App\Domain\Pricing\PromotionEngine;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Promotions\Pages\CreatePromotion;
use App\Filament\Resources\Promotions\Pages\EditPromotion;
use App\Filament\Resources\Promotions\Pages\ListPromotions;
use App\Filament\Support\Erp;
use App\Filament\Support\GlobalSearch;
use App\Models\Branch;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Promotion;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Promotions and coupons (head office). The till and the server run the same engine. */
class PromotionResource extends Resource
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

    protected static ?string $model = Promotion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Pricing & tax';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('promotions.manage') || Erp::canAnywhere('catalog.view');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('promotions.manage') && config('erp.node.role') !== 'branch';
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate();
    }

    public static function canDelete(Model $record): bool
    {
        return false; // switch off instead: sales name the promotions they used
    }

    /** Form ⇄ model: percentages and money are entered as people write them. */
    public static function toForm(Promotion $p): array
    {
        $cur = CustomerResource::currency();

        return ['percent' => $p->value_bp ? rtrim(rtrim(number_format($p->value_bp / 100, 2, '.', ''), '0'), '.') : null,
            'amount' => $p->value_minor ? Money::format($p->value_minor, $cur) : null,
            'min_spend' => $p->min_spend_minor ? Money::format($p->min_spend_minor, $cur) : null];
    }

    public static function toDomain(array $d): array
    {
        $cur = CustomerResource::currency();
        $d['value_bp'] = isset($d['percent']) && $d['percent'] !== '' ? (int) round((float) $d['percent'] * 100) : ($d['type'] === 'buy_x_get_y' ? 10000 : 0);
        $d['value_minor'] = isset($d['amount']) && $d['amount'] !== '' ? Money::toMinor((string) $d['amount'], $cur) : 0;
        $d['min_spend_minor'] = isset($d['min_spend']) && $d['min_spend'] !== '' ? Money::toMinor((string) $d['min_spend'], $cur) : 0;
        foreach (['buy_qty', 'get_qty', 'bundle_qty'] as $f) {
            $d[$f] = (int) ($d[$f] ?? 0);
        }
        unset($d['percent'], $d['amount'], $d['min_spend']);

        return $d;
    }

    public static function form(Schema $schema): Schema
    {
        $is = fn (string ...$types) => fn (Get $get) => in_array($get('type'), $types, true);

        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255)->helperText('Shown on the receipt.')->columnSpan(2),
                Toggle::make('is_active')->label('Running')->default(true)->inline(false),
                Select::make('type')->options(PromotionEngine::TYPES)->required()->live()->columnSpan(2),
                TextInput::make('coupon_code')->label('Coupon code')->maxLength(30)->visible($is('coupon'))->required($is('coupon')),
                TextInput::make('percent')->label(fn (Get $get) => $get('type') === 'buy_x_get_y' ? 'Discount on the extra items (%)' : '% off')
                    ->numeric()->minValue(0.01)->maxValue(100)->visible($is('percent', 'buy_x_get_y', 'threshold', 'coupon'))
                    ->helperText(fn (Get $get) => $get('type') === 'buy_x_get_y' ? '100 = free (e.g. buy 2 get 1 free).' : null),
                TextInput::make('amount')->label(fn (Get $get) => match ($get('type')) {
                    'amount_off' => 'Amount off each item', 'bundle' => 'Bundle price', default => 'Amount off the basket'
                })->numeric()->minValue(0.01)->visible($is('amount_off', 'bundle', 'threshold', 'coupon'))
                    ->helperText(fn (Get $get) => in_array($get('type'), ['threshold', 'coupon'], true) ? 'Give a % or an amount, not both.' : null),
                TextInput::make('buy_qty')->label('Buy')->numeric()->minValue(1)->visible($is('buy_x_get_y')),
                TextInput::make('get_qty')->label('Get')->numeric()->minValue(1)->visible($is('buy_x_get_y')),
                TextInput::make('bundle_qty')->label('Items in the bundle')->numeric()->minValue(2)->visible($is('bundle')),
                TextInput::make('min_spend')->label('Minimum spend')->numeric()->minValue(0)->visible($is('threshold', 'coupon')),
            ]),
            Section::make('What it applies to')->description('Leave both empty for everything. Several products together make a mix & match.')->columns(2)->schema([
                Select::make('product_ids')->label('Products')->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Product::where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('sku', 'ilike', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} · {$p->name}"]))
                    ->getOptionLabelsUsing(fn (array $values) => Product::whereIn('id', $values)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} · {$p->name}"])->all()),
                Select::make('category_ids')->label('Categories')->multiple()->options(fn () => Category::orderBy('name')->pluck('name', 'id')),
            ]),
            Section::make('When and where')->description('Leave empty for always / everywhere. Happy hour uses the branch’s local time.')->columns(3)->schema([
                DateTimePicker::make('starts_at')->label('From'),
                DateTimePicker::make('ends_at')->label('Until'),
                CheckboxList::make('days')->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'])->columns(7)->columnSpanFull(),
                TimePicker::make('time_from')->label('Happy hour from')->seconds(false),
                TimePicker::make('time_to')->label('to')->seconds(false),
                Select::make('branch_ids')->label('Branches')->multiple()->options(fn () => Branch::orderBy('code')->pluck('name', 'id')),
                Select::make('price_list_ids')->label('Price lists')->multiple()->options(fn () => PriceList::orderBy('name')->pluck('name', 'id')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (Promotion $record) => $record->coupon_code ? "Code {$record->coupon_code}" : null),
                TextColumn::make('type')->badge()->formatStateUsing(fn ($state) => PromotionEngine::TYPES[$state] ?? $state),
                TextColumn::make('value')->label('Offer')->state(fn (Promotion $record) => self::describe($record)),
                TextColumn::make('window')->label('When')->state(fn (Promotion $record) => trim(implode(' ', array_filter([
                    $record->starts_at ? 'from '.$record->starts_at->format('j M') : null, $record->ends_at ? 'until '.$record->ends_at->format('j M') : null,
                    $record->time_from ? substr($record->time_from, 0, 5).'–'.substr((string) $record->time_to, 0, 5) : null]))) ?: 'always'),
                IconColumn::make('is_active')->boolean()->label('Running'),
            ])
            ->filters([SelectFilter::make('type')->options(PromotionEngine::TYPES), TernaryFilter::make('is_active')->label('Running')->default(true)])
            ->recordActions([EditAction::make()]);
    }

    public static function describe(Promotion $p): string
    {
        $m = fn ($v) => CustomerResource::money($v);
        $pct = fn () => rtrim(rtrim(number_format($p->value_bp / 100, 2), '0'), '.').' %';

        return match ($p->type) {
            'percent' => $pct().' off',
            'amount_off' => $m($p->value_minor).' off each',
            'buy_x_get_y' => "Buy {$p->buy_qty} get {$p->get_qty} ".($p->value_bp >= 10000 ? 'free' : $pct().' off'),
            'bundle' => "{$p->bundle_qty} for ".$m($p->value_minor),
            default => ($p->value_bp ? $pct() : $m($p->value_minor)).' off'.($p->min_spend_minor ? ' over '.$m($p->min_spend_minor) : ''),
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListPromotions::route('/'), 'create' => CreatePromotion::route('/create'), 'edit' => EditPromotion::route('/{record}/edit')];
    }
}
