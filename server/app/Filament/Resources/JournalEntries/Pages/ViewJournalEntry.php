<?php

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewJournalEntry extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = JournalEntryResource::class;

    /** The lines with their accounts and branches in one go, not one query per line. */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load('lines.account', 'lines.branch');
    }

    protected function getHeaderActions(): array
    {
        return [JournalEntryResource::reverseAction()];
    }
}
