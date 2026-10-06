<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\JournalService;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\Erp;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListJournalEntries extends ListRecords
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('post')->label('Post now')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => Erp::canAnywhere('finance.journal') || Erp::canAnywhere('finance.close'))
                ->action(function () {
                    $r = app(AutoPoster::class)->run(Erp::user()->tenant_id);
                    $n = array_sum($r['posted']);
                    $r['errors'] ? Notification::make()->danger()->title('Some records could not be posted')->body(implode(' ', $r['errors']))->persistent()->send()
                        : Notification::make()->success()->title($n ? "{$n} record(s) posted" : 'Everything is already posted')->send();
                }),
            Action::make('opening')->label('Opening balances')->icon(Heroicon::OutlinedArrowDownOnSquare)->color('gray')
                ->visible(fn () => Erp::canAnywhere('finance.journal'))->modalWidth('5xl')
                ->modalDescription('Balances from your old books on the go-live date (not customers, suppliers or stock — enter those on their own screens). Any difference goes to “Opening balances”.')
                ->schema(JournalEntryResource::journalForm(opening: true))
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(JournalService::class)->opening($actor, $data['date'], JournalEntryResource::toLines($data)), 'Opening balances posted')),
            Action::make('journal')->label('New journal')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => Erp::canAnywhere('finance.journal'))->modalWidth('5xl')
                ->schema(JournalEntryResource::journalForm())
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(JournalService::class)->manual($actor, $data['date'], $data['memo'], JournalEntryResource::toLines($data)), 'Journal posted')),
        ];
    }
}
