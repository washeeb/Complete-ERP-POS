<?php

namespace App\Filament\Resources\ReorderRules\Pages;

use App\Filament\Pages\ReorderSuggestions;
use App\Filament\Resources\ReorderRules\ReorderRuleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListReorderRules extends ListRecords
{
    protected static string $resource = ReorderRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('suggestions')->label('What to order')->icon(Heroicon::OutlinedLightBulb)->url(ReorderSuggestions::getUrl())
            ->visible(fn () => ReorderSuggestions::canAccess())];
    }
}
