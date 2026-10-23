<?php

namespace App\Filament\Resources\RefillReminders\Pages;

use App\Filament\Resources\RefillReminders\RefillReminderResource;
use Filament\Resources\Pages\ListRecords;

class ListRefillReminders extends ListRecords
{
    protected static string $resource = RefillReminderResource::class;

    public function getSubheading(): ?string
    {
        return 'Made from sales of medicines with “Used a day” to named customers; sent before the medicine runs out to those who agreed to WhatsApp or SMS. Switch on under System → Settings → Refill reminders.';
    }
}
