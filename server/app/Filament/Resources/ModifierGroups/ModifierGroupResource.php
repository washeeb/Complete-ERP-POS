<?php

namespace App\Filament\Resources\ModifierGroups;

use App\Domain\Modules\ModuleService;
use App\Domain\Restaurant\RestaurantSetup;
use App\Filament\Resources\ModifierGroups\Pages\ListModifierGroups;
use App\Filament\Support\Erp;
use App\Models\ModifierGroup;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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

/** Choices offered with dishes: cooking, sides, extras. A priced extra is charged as its own product. */
class ModifierGroupResource extends Resource
{
    protected static ?string $model = ModifierGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?string $navigationLabel = 'Modifiers';

    protected static ?string $modelLabel = 'modifier group';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('restaurant.manage') && ModuleService::enabledAnywhere('restaurant') && config('erp.node.role') !== 'branch';
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('restaurant.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('restaurant.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // switch it off instead; past bills keep the choices they were sold with
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            TextInput::make('name')->required()->maxLength(255)->columnSpan(2)->placeholder('Spice level, Sides, Extras…'),
            TextInput::make('min_choices')->label('Choose at least')->numeric()->required()->default(0)->minValue(0)->helperText('1 = the waiter must choose'),
            TextInput::make('max_choices')->label('Choose at most')->numeric()->required()->default(1)->minValue(1),
            Toggle::make('is_active')->label('Offered')->default(true)->inline(false),
            Repeater::make('options')->label('Choices')->columnSpanFull()->columns(2)->minItems(1)->defaultItems(2)
                ->schema([
                    Hidden::make('id'),
                    TextInput::make('name')->required()->maxLength(120),
                    Select::make('product_id')->label('Charged as (optional)')->placeholder('Free')->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Product::where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"))
                            ->limit(30)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} · {$p->name}"])->all())
                        ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? "{$p->sku} · {$p->name}" : null),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->modifyQueryUsing(fn ($query) => $query->with('options'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('rule')->label('Choose')->state(fn (ModifierGroup $r) => $r->min_choices === $r->max_choices ? (string) $r->min_choices : "{$r->min_choices}–{$r->max_choices}"),
                TextColumn::make('choices')->state(fn (ModifierGroup $r) => $r->options->pluck('name')->implode(', '))->wrap(),
                IconColumn::make('is_active')->label('Offered')->boolean(),
            ])
            ->recordActions([EditAction::make()
                ->mutateRecordDataUsing(fn (array $data, ModifierGroup $record) => $data + ['options' => $record->options->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'product_id' => $o->product_id])->all()])
                ->using(fn (ModifierGroup $record, array $data) => Erp::run(fn ($actor) => app(RestaurantSetup::class)->saveModifierGroup($actor, $record, $data)))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListModifierGroups::route('/')];
    }
}
