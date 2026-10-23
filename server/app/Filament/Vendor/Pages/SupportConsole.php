<?php

namespace App\Filament\Vendor\Pages;

use App\Filament\Vendor\Resources\Licenses\LicenseResource;
use App\Models\Vendor\VendorLicense;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Support console (owner request 2026-10-23): every client installation on one page, from its hourly health report —
 * the server's checks (backups, restore check, disk, memory, scheduler…), tills with problems, open problem reports and
 * whether the owner has let support in. Worst first, so the client who needs a call is at the top.
 */
class SupportConsole extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Licences';

    protected static ?string $navigationLabel = 'Support console';

    protected static ?string $title = 'Support console';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.table-page';

    /** A report older than this means the server (or its internet, or its scheduler) is down. */
    public const SILENT_HOURS = 3;

    /** 'fail', 'silent', 'warning', 'ok' or 'none' (never reported: an older version, or sharing switched off). */
    public static function state(VendorLicense $l): string
    {
        if (! $l->health_at) {
            return 'none';
        }
        if ($l->health_at->lt(now()->subHours(self::SILENT_HOURS))) {
            return 'silent';
        }
        $statuses = collect($l->health['checks'] ?? [])->pluck('status');

        return $statuses->contains('fail') ? 'fail' : ($statuses->contains('warning') || ($l->health['devices']['with_problems'] ?? 0) > 0 ? 'warning' : 'ok');
    }

    private const LABELS = ['fail' => 'Needs attention', 'silent' => 'Not reporting', 'warning' => 'Warning', 'ok' => 'OK', 'none' => 'No reports'];

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => VendorLicense::query()->with('client')->whereNull('revoked_at')->whereNull('superseded_by')
                ->orderByRaw('health_at is null')->orderBy('health_at')) // never reported and longest silent first
            ->columns([
                TextColumn::make('client.name')->label('Client')->default(fn (VendorLicense $r) => $r->payload['customer'] ?? '?')->searchable()
                    ->description(fn (VendorLicense $r) => $r->short()),
                TextColumn::make('state')->label('Now')->badge()->state(fn (VendorLicense $r) => self::LABELS[self::state($r)])
                    ->color(fn (VendorLicense $r) => match (self::state($r)) {
                        'fail', 'silent' => 'danger', 'warning' => 'warning', 'ok' => 'success', default => 'gray'
                    }),
                TextColumn::make('problems')->label('What')->wrap()->state(function (VendorLicense $r) {
                    $checks = collect($r->health['checks'] ?? [])->where('status', '!=', 'ok');
                    $out = $checks->sortBy(fn ($c) => $c['status'] === 'fail' ? 0 : 1)->map(fn ($c) => $c['check'].': '.$c['detail'])->take(3)->all();
                    if ($n = $r->health['devices']['with_problems'] ?? 0) {
                        $out[] = "{$n} till(s) with problems";
                    }

                    return $out ? implode(' · ', $out) : null;
                })->placeholder('—'),
                TextColumn::make('backups')->label('Backups')->wrap()->toggleable()
                    ->state(fn (VendorLicense $r) => collect($r->health['checks'] ?? [])->firstWhere('check', 'Backups')['detail'] ?? null)->placeholder('—'),
                TextColumn::make('reports')->label('Open problems')->alignCenter()
                    ->state(fn (VendorLicense $r) => count($r->health['support']['open_reports'] ?? []) ?: null)->placeholder('—'),
                TextColumn::make('access')->label('Support access')->badge()->color('warning')
                    ->state(fn (VendorLicense $r) => match (true) {
                        in_array('control', $r->health['support']['access_open'] ?? [], true) => 'See and control',
                        in_array('view', $r->health['support']['access_open'] ?? [], true) => 'See only',
                        default => null,
                    })->placeholder('—'),
                TextColumn::make('health.version')->label('Version')->placeholder('—')->toggleable(),
                TextColumn::make('health_at')->label('Last report')->since()->placeholder('Never'),
            ])
            ->filters([
                Filter::make('attention')->label('Needs attention or not reporting')
                    ->query(fn (Builder $q) => $q->where(fn ($w) => $w->where('health_at', '<', now()->subHours(self::SILENT_HOURS))
                        ->orWhereRaw("exists (select 1 from jsonb_array_elements(coalesce(health->'checks', '[]'::jsonb)) c where c->>'status' = 'fail')"))),
            ])
            ->recordActions([
                Action::make('details')->label('Details')->icon(Heroicon::OutlinedListBullet)->color('gray')->visible(fn (VendorLicense $r) => $r->health_at !== null)
                    ->modalHeading(fn (VendorLicense $r) => ($r->client?->name ?? 'Client').' — report '.$r->health_at?->diffForHumans())
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (VendorLicense $r) => new HtmlString(self::details($r))),
                Action::make('licence')->label('Licence')->icon(Heroicon::OutlinedKey)->color('gray')
                    ->url(fn (VendorLicense $r) => LicenseResource::getUrl('view', ['record' => $r->id])),
            ])
            ->emptyStateHeading('No client licences yet');
    }

    private static function details(VendorLicense $l): string
    {
        $h = $l->health ?? [];
        $mark = ['ok' => '✓', 'warning' => '!', 'fail' => '✗'];
        $rows = collect($h['checks'] ?? [])->map(fn ($c) => '<tr><td style="padding:2px 8px 2px 0">'.($mark[$c['status']] ?? '').'</td><td style="padding:2px 8px 2px 0;white-space:nowrap"><strong>'
            .e($c['check']).'</strong></td><td>'.e($c['detail'] ?? '').'</td></tr>')->implode('');
        $devices = collect($h['devices']['problems'] ?? [])->map(fn ($p) => '<li>'.e($p).'</li>')->implode('');
        $reports = collect($h['support']['open_reports'] ?? [])->map(fn ($p) => '<li><strong>'.e($p['number']).'</strong> '.e($p['summary'] ?? '').'</li>')->implode('');

        return '<table style="font-size:.9rem">'.$rows.'</table>'
            .'<p style="margin-top:.75rem"><strong>Tills:</strong> '.(int) ($h['devices']['active'] ?? 0).' active, '.(int) ($h['devices']['with_problems'] ?? 0).' with problems</p>'
            .($devices ? '<ul style="list-style:disc;padding-left:1.25rem">'.$devices.'</ul>' : '')
            .($reports ? '<p style="margin-top:.75rem"><strong>Open problem reports</strong></p><ul style="list-style:disc;padding-left:1.25rem">'.$reports.'</ul>' : '');
    }
}
