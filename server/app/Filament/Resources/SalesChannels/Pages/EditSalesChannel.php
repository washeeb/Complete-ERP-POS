<?php

namespace App\Filament\Resources\SalesChannels\Pages;

use App\Domain\Channels\ChannelAdmin;
use App\Filament\Resources\SalesChannels\SalesChannelResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSalesChannel extends EditRecord
{
    protected static string $resource = SalesChannelResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['credentials'], $data['webhook_secret']); // never sent to the browser

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $secrets = $data['secrets'][$record->type] ?? [];

        return Erp::run(fn ($actor) => app(ChannelAdmin::class)->save($actor, $record, $data, $secrets));
    }
}
