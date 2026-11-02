<?php

namespace App\Filament\Resources\PricingRules\Pages;

use App\Filament\Resources\PricingRules\PricingRuleResource;
use Filament\Resources\Pages\ListRecords;

class ListPricingRules extends ListRecords
{
    protected static string $resource = PricingRuleResource::class;

    public function getSubheading(): ?string
    {
        return 'Selling prices worked out from the latest cost, with freight. The most specific rule wins: a product\'s, then its category\'s, then the company\'s.';
    }
}
