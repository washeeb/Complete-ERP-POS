<?php

namespace App\Filament\Vendor\Resources\Checkins;

use App\Filament\Vendor\Resources\Checkins\Pages\ListCheckins;
use App\Filament\Vendor\Resources\Licenses\LicenseResource;
use App\Models\Vendor\VendorCheckin;
use App\Models\Vendor\VendorLicense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Every daily check-in from customer installations: who, from where, which build, what they use, what was answered. */
class CheckinResource extends Resource
{
    protected static ?string $model = VendorCheckin::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Licences';

    protected static ?string $modelLabel = 'check-in';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $customer = fn (VendorCheckin $r) => VendorLicense::with('client')->find($r->license_id)?->client?->name ?? 'unknown licence';

        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M Y H:i')->sortable(),
                TextColumn::make('license_id')->label('Customer')->formatStateUsing(fn ($state, VendorCheckin $r) => $customer($r))
                    ->description(fn (VendorCheckin $r) => strtoupper(substr(str_replace('-', '', $r->license_id), 0, 8)))
                    ->url(fn (VendorCheckin $r) => VendorLicense::find($r->license_id) ? LicenseResource::getUrl('view', ['record' => $r->license_id]) : null),
                TextColumn::make('ip')->label('From')->placeholder('—'),
                TextColumn::make('build_id')->label('Build')->placeholder('dev')->description(fn (VendorCheckin $r) => $r->report['version'] ?? null),
                TextColumn::make('counts')->label('Branches / devices / users')->state(fn (VendorCheckin $r) => ($c = $r->report['counts'] ?? null)
                    ? ($c['branches'] ?? '?').' / '.($c['devices'] ?? '?').' / '.($c['users'] ?? '?') : '—'),
                IconColumn::make('tampered')->label('Modified')->boolean()->trueColor('danger')->falseColor('success'),
                TextColumn::make('answer')->badge()->color(fn ($state) => $state === 'active' ? 'success' : 'danger'),
            ])
            ->filters([
                SelectFilter::make('answer')->options(['active' => 'Active', 'revoked' => 'Refused (revoked or other installation)', 'unknown_license' => 'Unknown licence']),
                TernaryFilter::make('tampered')->label('Modified software'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCheckins::route('/')];
    }
}
