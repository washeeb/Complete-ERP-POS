<?php

namespace App\Filament\Resources\AccessRequests\Pages;

use App\Filament\Resources\AccessRequests\AccessRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListAccessRequests extends ListRecords
{
    protected static string $resource = AccessRequestResource::class;

    protected static ?string $title = 'Access requests';

    protected ?string $subheading = 'Changes IT Admin asked for: a new user, access, a role, a till PIN or a two-step sign-in reset. They happen only when the owner or SyncLytics support approves, and lapse after 72 hours. Taking access away is done at once.';
}
