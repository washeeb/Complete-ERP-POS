<?php

namespace App\Livewire;

use App\Filament\Support\Erp;
use App\Support\IndustryContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "All industries / Pharmacy / Grocery …" at the top of the back office: every screen then shows only that industry's
 * branches, stock, prices, purchasing, sales and reports. Shown to people who can see more than one industry.
 */
class IndustrySwitcher extends Component
{
    public ?string $industry = null;

    public function mount(): void
    {
        $this->industry = IndustryContext::selected();
    }

    public function updatedIndustry(?string $value): void
    {
        IndustryContext::set($value ?: null);
        $this->redirect(request()->header('Referer') ?: url('/admin'), navigate: false);
    }

    public function render(): View
    {
        $user = Erp::user();
        $options = $user ? IndustryContext::options($user) : collect();

        return view('livewire.industry-switcher', ['options' => $options, 'show' => $options->count() > 1 || ($options->count() === 1 && $this->allVisible())]);
    }

    /** People with company-wide reach also have "All industries" (and head office), so one industry is still a choice. */
    private function allVisible(): bool
    {
        return Erp::branchIds('sales.view') === null || Erp::user()?->security_level >= 5;
    }
}
