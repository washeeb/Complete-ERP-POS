<?php

namespace App\Filament\Resources\DrugInteractions\Pages;

use App\Domain\Pharmacy\InteractionStarter;
use App\Domain\Pharmacy\PharmacyService;
use App\Filament\Resources\DrugInteractions\DrugInteractionResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Erp;
use App\Models\DrugInteraction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ListDrugInteractions extends ListRecords
{
    protected static string $resource = DrugInteractionResource::class;

    /** Warnings only work for medicines with a generic name or active ingredients: say how many have neither. */
    public function getSubheading(): string|Htmlable|null
    {
        $n = PharmacyService::unchecked();

        return $n ? new HtmlString(e("{$n} medicine(s) have neither a generic name nor active ingredients, so allergies and interactions cannot be checked for them. ")
            .'<a class="underline" href="'.e(ProductResource::getUrl('index')).'">Add them on the products</a>.') : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('starter')->label('Add the starter list')->icon(Heroicon::OutlinedListBullet)->color('gray')
                ->visible(fn () => Erp::canAnywhere('pharmacy.manage'))
                ->requiresConfirmation()->modalHeading('Add the starter list of interactions?')
                ->modalDescription(count(InteractionStarter::ROWS).' well-known interactions between common medicines (warfarin, methotrexate, sildenafil and nitrates, statins and some antibiotics, antacids and iron with ciprofloxacin or doxycycline, the pill with rifampicin…). '
                    .'Pairs already in your table are left as they are. It is not a complete reference: the superintendent pharmacist should read it, change or delete rows, and add others.')
                ->action(function () {
                    $n = Erp::run(fn ($actor) => app(PharmacyService::class)->addStarter($actor));
                    if ($n !== null) {
                        Notification::make()->title($n ? "{$n} interaction(s) added" : 'Every pair of the starter list is already in the table')->success()->send();
                    }
                }),
            CreateAction::make()->using(fn (array $data) => Erp::run(fn ($actor) => app(PharmacyService::class)->saveInteraction($actor, new DrugInteraction, $data))),
        ];
    }
}
