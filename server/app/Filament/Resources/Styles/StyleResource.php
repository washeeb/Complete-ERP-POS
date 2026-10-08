<?php

namespace App\Filament\Resources\Styles;

use App\Domain\Fashion\FashionReports;
use App\Domain\Fashion\StyleService;
use App\Domain\Modules\ModuleService;
use App\Filament\Resources\Styles\Pages\ListStyles;
use App\Filament\Resources\Styles\Pages\ViewStyle;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Style;
use App\Models\TaxGroup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Fashion styles: one design in sizes and colours; each size × colour is its own product (variant). */
class StyleResource extends Resource
{
    protected static ?string $model = Style::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'Fashion';

    protected static ?string $navigationLabel = 'Styles';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('catalog.view') && ModuleService::enabledAnywhere('fashion');
    }

    public static function canCreate(): bool
    {
        return Erp::can('catalog.manage') && config('erp.node.role') !== 'branch';
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
            TextInput::make('code')->label('Style code')->required()->maxLength(30)->helperText('SKUs become CODE-COLOUR-SIZE.'),
            TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
            Select::make('category_id')->label('Category')->options(fn () => Category::orderBy('name')->pluck('name', 'id')),
            Select::make('brand_id')->label('Brand')->options(fn () => Brand::orderBy('name')->pluck('name', 'id')),
            Select::make('tax_group_id')->label('Tax')->required()->options(fn () => TaxGroup::orderBy('name')->pluck('name', 'id'))
                ->default(fn () => TaxGroup::where('code', 'STD')->value('id')),
            TextInput::make('season')->maxLength(30)->placeholder('SS26'),
            TextInput::make('collection')->maxLength(60),
            TextInput::make('price')->label('Price (default list)')->numeric()->minValue(0)->prefix(fn () => Erp::user()?->tenant->currency_code),
            TagsInput::make('sizes')->required()->placeholder('Add a size, then Enter')->helperText('In display order, e.g. S M L XL or 38 40 42.')->columnSpan(3),
            TagsInput::make('colours')->required()->placeholder('Add a colour, then Enter')->columnSpan(3),
            Textarea::make('description')->rows(2)->columnSpan(2),
            Toggle::make('is_active')->label('For sale')->default(true)->inline(false),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('code'),
                TextEntry::make('name'),
                TextEntry::make('season')->placeholder('—')->belowContent(fn (Style $r) => $r->collection),
                TextEntry::make('price_minor')->label('Price')->formatStateUsing(fn ($state) => $state === null ? '—' : FinanceUi::money((int) $state))->placeholder('—'),
            ]),
            Section::make('Sizes and colours')->description('On hand now (all warehouses) and sold in the last 30 days; each cell is its own product.')->schema([
                TextEntry::make('grid')->hiddenLabel()->html()->state(function (Style $r) {
                    $m = FashionReports::matrix($r, now()->subDays(30)->toDateString(), now()->toDateString());
                    $head = '<tr><th></th>'.collect($m['sizes'])->map(fn ($s) => '<th style="padding:4px 8px">'.e($s).'</th>')->implode('').'</tr>';
                    $rows = collect($m['colours'])->map(fn ($c) => '<tr><th style="text-align:left;padding:4px 8px">'.e($c).'</th>'.collect($m['sizes'])->map(function ($s) use ($m, $c) {
                        $cell = $m['cells'][$c][$s] ?? null;

                        return '<td style="padding:4px 8px;text-align:center">'.($cell ? '<span title="'.e($cell['sku']).'">'.e(Fields::qty($cell['on_hand'])).'</span> <small>('.e(Fields::qty($cell['sold'])).' sold)</small><br><small>'.e($cell['sku']).'</small>' : '<small>not created</small>').'</td>';
                    })->implode('').'</tr>')->implode('');

                    return '<table>'.$head.$rows.'</table>';
                }),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('season')->placeholder('—'),
                TextColumn::make('collection')->placeholder('—'),
                TextColumn::make('variants_count')->label('Products')->counts('variants'),
            ])
            ->filters([SelectFilter::make('season')->options(fn () => Style::whereNotNull('season')->distinct()->orderBy('season')->pluck('season', 'season'))])
            ->recordActions([ViewAction::make(), EditAction::make()->using(fn (Style $record, array $data) => Erp::run(fn ($actor) => app(StyleService::class)->save($actor, $record, $data)))
                ->mutateRecordDataUsing(fn (array $data, Style $record) => $data + ['price' => $record->price_minor === null ? null : $record->price_minor / 100])]);
    }

    /** @return list<Action> */
    public static function styleActions(): array
    {
        return [
            Action::make('generate')->label('Create sizes and colours')->icon(Heroicon::OutlinedSquares2x2)->color('success')->visible(fn () => self::canCreate())
                ->modalDescription('Creates the products missing from the grid (with SKU, barcode and the style price) and updates the name, category, brand and tax of the others.')
                ->action(fn (Style $record) => Erp::run(fn ($actor) => app(StyleService::class)->generate($actor, $record), 'Sizes and colours are up to date')),
            Action::make('price')->label('One price for all')->icon(Heroicon::OutlinedTag)->color('gray')->visible(fn () => self::canCreate() && Erp::can('pricing.manage'))
                ->schema([TextInput::make('price')->required()->numeric()->minValue(0)])
                ->action(fn (Style $record, array $data) => Erp::run(fn ($actor) => app(StyleService::class)->setPrice($actor, $record, (string) $data['price']), 'Price set on every size and colour')),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListStyles::route('/'), 'view' => ViewStyle::route('/{record}')];
    }
}
