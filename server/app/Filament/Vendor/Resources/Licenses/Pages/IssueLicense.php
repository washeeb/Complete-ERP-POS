<?php

namespace App\Filament\Vendor\Resources\Licenses\Pages;

use App\Domain\Licensing\Vendor\VendorLicensing;
use App\Filament\Vendor\Resources\Licenses\LicenseResource;
use App\Filament\Vendor\VendorUi;
use App\Models\Vendor\VendorLicense;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

/** Issue a licence, or (with ?replaces=) a new key for the same installation with a changed package. */
class IssueLicense extends CreateRecord
{
    protected static string $resource = LicenseResource::class;

    protected static ?string $title = 'Issue a licence';

    #[Url]
    public ?string $replaces = null;

    protected function afterFill(): void
    {
        if ($this->replaces && ($old = VendorLicense::find($this->replaces))) {
            $p = $old->payload;
            $this->form->fill(['customer_id' => $old->customer_id, 'kind' => $old->kind === 'trial' ? 'paid' : $old->kind, 'site' => $old->site, 'replaces' => $old->id,
                'plan_id' => $old->plan_id, 'addons' => array_map(fn ($a) => ['addon_id' => $a['addon_id'], 'qty' => $a['qty']], $old->addons ?? []),
                'starts_on' => now()->toDateString(), 'expires_on' => $old->expires_on?->toDateString(), 'grace_days' => $p['grace_days'] ?? 14,
                'checkin_days' => $p['checkin_days'] ?? 30, 'invoice' => false, 'due_days' => 14, 'notes' => 'Package changed']);
        }
    }

    public function getSubheading(): ?string
    {
        return $this->replaces ? 'A new key for the same installation replaces the current one; the customer receives it at the next check-in (or pastes it).' : null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return VendorUi::run(fn ($actor) => app(VendorLicensing::class)->issue($actor, LicenseResource::issueData($data)), 'Licence issued');
    }

    protected function getRedirectUrl(): string
    {
        return LicenseResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
