<?php

namespace App\Filament\Support;

use App\Domain\Catalog\Labels;
use App\Models\PriceList;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Print labels" wherever products are chosen (the products list, a product, a delivery): the kind of label, the sheet or
 * label printer, the price list, copies, labels to leave empty on a used sheet, and — for people who may edit products —
 * giving products without a barcode an in-store one first. The labels open in a new tab, ready to print.
 */
final class LabelsAction
{
    /** For a page about one record: $products returns the product ids (and, optionally, copies per product). */
    public static function make(Closure $products): Action
    {
        return Action::make('labels')->label('Print labels')->icon(Heroicon::OutlinedQrCode)->color('gray')
            ->visible(fn () => Erp::canAnywhere('catalog.view'))
            ->schema(self::schema())
            ->action(fn (array $data, $record) => self::run($products($record), $data));
    }

    /** For a list: the ticked products. */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('labels')->label('Print labels')->icon(Heroicon::OutlinedQrCode)
            ->visible(fn () => Erp::canAnywhere('catalog.view'))
            ->schema(self::schema())
            ->action(fn (Collection $records, array $data) => self::run($records->pluck('id')->all(), $data))
            ->deselectRecordsAfterCompletion();
    }

    private static function schema(): array
    {
        return [
            Select::make('kind')->label('Label')->required()->default('shelf')->options(Labels::KINDS),
            Select::make('layout')->label('Print on')->required()->default('a4_24')->live()
                ->options(collect(Labels::LAYOUTS)->map(fn ($l) => $l[0])->all()),
            Select::make('price_list_id')->label('Prices from')->options(fn () => PriceList::where('is_active', true)->orderByDesc('is_default')->pluck('name', 'id'))
                ->default(fn () => PriceList::where('is_default', true)->value('id')),
            TextInput::make('copies')->label('Labels per product')->numeric()->minValue(1)->maxValue(500)->default(1)->required(),
            TextInput::make('skip')->label('Leave empty at the start (a sheet already partly used)')->numeric()->minValue(0)->maxValue(64)->default(0)
                ->visible(fn (Get $get) => (Labels::LAYOUTS[$get('layout')][2] ?? 0) > 0),
            Checkbox::make('give')->label('Give products without a barcode an in-store barcode first')->default(true)
                ->visible(fn () => Erp::canAnywhere('catalog.manage')),
        ];
    }

    private static function run(array $productIds, array $data): void
    {
        $given = ! empty($data['give']) ? Erp::run(fn ($actor) => app(Labels::class)->giveBarcodes($actor, $productIds)) : 0;
        $url = Erp::run(fn ($actor) => app(Labels::class)->prepare($actor, $productIds, ['kind' => $data['kind'], 'layout' => $data['layout'],
            'price_list_id' => $data['price_list_id'] ?? null, 'copies' => (int) $data['copies'], 'skip' => (int) ($data['skip'] ?? 0)]));
        Notification::make()->success()->title('Labels ready')
            ->body(count($productIds).' product(s)'.($given ? "; {$given} given an in-store barcode" : '').'.')
            ->actions([Action::make('open')->label('Open the labels')->button()->url($url, shouldOpenInNewTab: true)])->persistent()->send();
    }
}
