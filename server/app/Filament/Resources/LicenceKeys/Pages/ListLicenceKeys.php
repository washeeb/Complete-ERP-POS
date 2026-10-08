<?php

namespace App\Filament\Resources\LicenceKeys\Pages;

use App\Domain\Services\LicenceKeyService;
use App\Filament\Resources\LicenceKeys\LicenceKeyResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Product;
use App\Models\SaleLine;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListLicenceKeys extends ListRecords
{
    protected static string $resource = LicenceKeyResource::class;

    protected function getHeaderActions(): array
    {
        $ids = Erp::branchIds('services.licence_keys');

        return [
            Action::make('load')->label('Load keys')->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    Select::make('product_id')->label('Product')->required()->options(fn () => Product::where('licence_keys', true)->orderBy('name')->pluck('name', 'id'))
                        ->helperText('Products with "Hands out a licence key" switched on (Product → Services).'),
                    Select::make('branch_id')->label('Sold at (branch)')->required()
                        ->options(fn () => Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->pluck('name', 'id')),
                    Textarea::make('keys')->label('Keys, one per line')->required()->rows(8),
                ])
                ->action(function (array $data) {
                    $n = Erp::run(fn ($actor) => app(LicenceKeyService::class)->load($actor, Product::findOrFail($data['product_id']), Branch::findOrFail($data['branch_id']), $data['keys']));
                    if ($n !== null) {
                        Notification::make()->success()->title("{$n} key(s) loaded")->body($n === 0 ? 'They were all loaded before.' : null)->send();
                    }
                }),
            // Sales made offline while a product was out of keys: hand the missing keys out now (the receipt can be reprinted).
            Action::make('owed')->label('Hand out keys still owed')->color('gray')->requiresConfirmation()
                ->modalDescription('For sales made while a product had no keys left, take keys from the pool now. Reprint the receipt or tell the customer the key.')
                ->action(function () {
                    $owed = DB::table('sale_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('p.licence_keys', true)->where('l.qty', '>', 0)
                        ->whereRaw('(SELECT COUNT(*) FROM licence_keys k WHERE k.sale_line_id = l.id) < FLOOR(l.qty)')->pluck('l.id');
                    $n = 0;
                    foreach ($owed as $lineId) {
                        $n += (int) Erp::run(fn ($actor) => app(LicenceKeyService::class)->handOutLater($actor, SaleLine::findOrFail($lineId)));
                    }
                    Notification::make()->success()->title("{$n} key(s) handed out")->send();
                }),
        ];
    }
}
