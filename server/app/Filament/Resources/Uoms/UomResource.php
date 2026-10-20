<?php

namespace App\Filament\Resources\Uoms;

use App\Filament\Resources\Uoms\Pages\ListUoms;
use App\Filament\Support\GlobalSearch;
use App\Filament\Support\SimpleCatalogResource;
use App\Models\ProductUom;
use App\Models\Uom;
use App\Domain\Catalog\CatalogService;
use App\Filament\Support\Erp;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class UomResource extends SimpleCatalogResource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Code' => $record->code]);
    }

    protected static ?string $model = Uom::class;

    protected static bool $deletable = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Units of measure';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(10)->regex('/^[A-Za-z0-9]{1,10}$/')
                ->helperText('Short code printed on receipts, e.g. PC, CTN, KG, TAB. Changing it changes it on every product, the tills and new documents; receipts and documents already made keep the old code.'),
            TextInput::make('name')->required()->maxLength(60),
            Select::make('decimals')->label('Quantity decimals')->required()->default(0)
                ->options([0 => '0 — whole units only', 1 => '1', 2 => '2', 3 => '3 — e.g. 1.250 kg'])
                ->helperText('How precisely this unit can be sold or counted.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return static::crudTable($table->columns([
            TextColumn::make('code')->sortable()->searchable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('decimals')->label('Decimals'),
            TextColumn::make('products_count')->label('Products')->state(fn ($record) => ProductUom::where('uom_id', $record->id)->distinct()->count('product_id')),
            TextColumn::make('merged')->label('Merged into')->state(fn (Uom $record) => $record->merged_into_id ? Uom::find($record->merged_into_id)?->code : null)
                ->placeholder('—')->badge()->color('gray'),
        ])->defaultSort('code'))->pushRecordActions([
            // Two codes for the same thing (PCS and PC): move every product to the right one.
            Action::make('merge')->label('Merge into…')->icon(Heroicon::OutlinedArrowsPointingIn)->color('gray')
                ->visible(fn (Uom $record) => Erp::can('catalog.manage') && ! $record->merged_into_id)
                ->modalDescription(fn (Uom $record) => "Every product using {$record->code} moves to the unit you choose, with its pack size, prices and barcodes. "
                    .'Past documents keep what they showed. '.$record->code.' is then no longer offered.')
                ->schema([Select::make('to')->label('Merge into')->required()->searchable()
                    ->options(fn (Uom $record) => Uom::whereKeyNot($record->id)->whereNull('merged_into_id')->orderBy('code')->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->code} · {$u->name}"]))])
                ->action(function (Uom $record, array $data) {
                    $n = Erp::run(fn ($actor) => app(CatalogService::class)->mergeUom($actor, $record, Uom::findOrFail($data['to'])));
                    Notification::make()->success()->title("{$record->code} merged: {$n} product(s) moved")->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUoms::route('/')];
    }
}
