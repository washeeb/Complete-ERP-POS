<?php

namespace App\Filament\Resources\SalesChannels\Pages;

use App\Domain\Channels\ChannelAdmin;
use App\Filament\Resources\SalesChannels\SalesChannelResource;
use App\Filament\Support\Erp;
use App\Models\SalesChannel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSalesChannel extends CreateRecord
{
    protected static string $resource = SalesChannelResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $secrets = $data['secrets'][$data['type']] ?? [];

        return Erp::run(fn ($actor) => app(ChannelAdmin::class)->save($actor, new SalesChannel, $data, $secrets));
    }
}
