<?php

namespace App\Filament\Vendor\Resources\Licenses\Pages;

use App\Domain\Licensing\Vendor\VendorLicensing;
use App\Domain\Messaging\Messenger;
use App\Filament\Vendor\Resources\Invoices\InvoiceResource;
use App\Filament\Vendor\Resources\Licenses\LicenseResource;
use App\Filament\Vendor\VendorUi;
use App\Models\Tenant;
use App\Models\Vendor\VendorLicense;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewLicense extends ViewRecord
{
    protected static string $resource = LicenseResource::class;

    protected function getHeaderActions(): array
    {
        $current = fn (VendorLicense $record) => ! $record->revoked_at && ! $record->superseded_by;

        return [
            Action::make('renew')->label('Renew')->icon(Heroicon::OutlinedArrowPath)->color('success')->visible($current)
                ->modalDescription('Same installation and package, for the next period. The new key reaches the customer at their next check-in.')
                ->schema([
                    DatePicker::make('expires_on')->label('New end date')->helperText('Empty: one plan length after the current end'),
                    TextInput::make('price')->label('Price for the period')->numeric()->minValue(0)
                        ->default(fn (VendorLicense $record) => $record->price_minor / 100),
                    Toggle::make('invoice')->label('Create the invoice')->default(true),
                    VendorUi::confirmPassword(),
                ])
                ->action(function (VendorLicense $record, array $data) {
                    $new = VendorUi::run(fn ($actor) => app(VendorLicensing::class)->renew($actor, $record, ['expires_on' => $data['expires_on'] ?? null,
                        'price_minor' => ($data['price'] ?? '') === '' ? null : VendorUi::toMinor($data['price'], $record->currency ?? 'GHS'), 'invoice' => (bool) $data['invoice']]), 'Renewed');
                    $this->redirect(LicenseResource::getUrl('view', ['record' => $new]));
                }),
            Action::make('change')->label('Change package')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('gray')->visible($current)
                ->url(fn (VendorLicense $record) => LicenseResource::getUrl('create', ['replaces' => $record->id])),
            Action::make('send')->label('Send key by e-mail')->icon(Heroicon::OutlinedEnvelope)->color('gray')
                ->visible(fn (VendorLicense $record) => $current($record) && $record->client?->email)
                ->requiresConfirmation()->modalDescription(fn (VendorLicense $record) => "To {$record->client->email}.")
                ->action(fn (VendorLicense $record) => VendorUi::run(fn ($actor) => app(Messenger::class)->send(Tenant::orderBy('created_at')->value('id'), 'email',
                    $record->client->email, "Dear {$record->client->name},\n\nYour SES Labs licence key ({$record->short()}, valid until {$record->expires_on?->format('j M Y')}):\n\n"
                    .$record->license_key."\n\nSign in as the owner, open Settings → Licence, click Enter licence key and paste it.\n\nSyncLytics", 'Your SES Labs licence key',
                    'licence '.$record->short(), $actor->name), 'Sent')),
            ActionGroup::make([
                Action::make('certificate')->label('Licence certificate')->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (VendorLicense $record) => route('vendor.licenses.certificate', $record), true),
                Action::make('invoices')->label('Invoices')->icon(Heroicon::OutlinedBanknotes)
                    ->url(fn (VendorLicense $record) => InvoiceResource::getUrl('index', ['filters' => ['license_id' => ['value' => $record->id]]])),
            ])->label('More')->button()->color('gray'),
            Action::make('revoke')->label('Revoke')->icon(Heroicon::OutlinedNoSymbol)->color('danger')->visible(fn (VendorLicense $record) => ! $record->revoked_at)
                ->modalDescription('The installation becomes read-only at its next check-in. Use it for unpaid or stolen copies; it cannot be undone (issue a new licence instead).')
                ->schema([Textarea::make('reason')->label('Reason (the customer sees it)')->required()->maxLength(255), VendorUi::confirmPassword()])
                ->action(fn (VendorLicense $record, array $data) => VendorUi::run(fn ($actor) => app(VendorLicensing::class)->revoke($actor, $record, $data['reason']), 'Revoked')),
        ];
    }
}
