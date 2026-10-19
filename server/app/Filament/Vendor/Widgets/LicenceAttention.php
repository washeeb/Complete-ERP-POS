<?php

namespace App\Filament\Vendor\Widgets;

use App\Domain\Licensing\Vendor\VendorLicensing;
use App\Filament\Vendor\Resources\Licenses\LicenseResource;
use App\Models\Vendor\VendorLicense;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Licences that need someone: ending soon or ended, silent installations, modified software, more machines or more use than licensed. */
class LicenceAttention extends TableWidget
{
    protected static ?string $heading = 'Needs attention';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->records(function () {
                $rows = [];
                foreach (VendorLicense::with('client')->whereNull('revoked_at')->whereNull('superseded_by')->get() as $l) {
                    $h = VendorLicensing::health($l);
                    $why = array_values(array_filter([
                        in_array($l->status(), ['expiring', 'grace', 'expired'], true) ? VendorLicense::STATUSES[$l->status()].' ('.$l->expires_on?->format('j M Y').')' : null,
                        $h['tampered'] ? 'Modified software' : null,
                        $h['machines'] > 1 ? "Used on {$h['machines']} machines" : null,
                        $h['over'] ? 'Over the licence: '.implode(', ', $h['over']) : null,
                        ($l->payload['checkin_days'] ?? 30) && $h['days_silent'] !== null && $h['days_silent'] > 7 ? "No check-in for {$h['days_silent']} days" : null,
                        ($l->payload['checkin_days'] ?? 30) && ! $h['last'] && $l->created_at?->lt(now()->subDays(3)) ? 'Never checked in' : null,
                    ]));
                    if ($why) {
                        $rows[$l->id] = ['id' => $l->id, 'customer' => $l->client?->name ?? ($l->payload['customer'] ?? '?'), 'short' => $l->short(), 'why' => implode(' · ', $why)];
                    }
                }

                return collect($rows);
            })
            ->columns([
                TextColumn::make('customer')->description(fn (array $record) => $record['short']),
                TextColumn::make('why')->label('Why')->wrap()->color('danger'),
            ])
            ->recordUrl(fn (array $record) => LicenseResource::getUrl('view', ['record' => $record['id']]))
            ->emptyStateHeading('All good')->emptyStateDescription('No licence is ending, silent, modified or over its limits.');
    }
}
