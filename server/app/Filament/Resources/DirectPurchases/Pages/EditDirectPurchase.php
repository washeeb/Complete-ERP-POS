<?php

namespace App\Filament\Resources\DirectPurchases\Pages;

use App\Domain\Purchasing\DirectPurchases;
use App\Filament\Resources\DirectPurchases\DirectPurchaseResource;
use App\Filament\Support\Erp;
use App\Models\DirectPurchaseLine;
use App\Support\Money;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDirectPurchase extends EditRecord
{
    protected static string $resource = DirectPurchaseResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $cur = $this->record->currency_code;
        $plain = fn (?int $v) => $v === null ? null : number_format($v / (10 ** Money::exponent($cur)), Money::exponent($cur), '.', '');

        return $data + ['printed_total' => $plain($this->record->printed_total_minor), 'lines' => $this->record->lines->map(fn (DirectPurchaseLine $l) => ['product_uom_id' => $l->product_uom_id,
            'qty' => (float) $l->qty, 'unit_price' => $plain($l->unit_price_minor), 'tax_group_id' => $l->tax_group_id, 'batch_no' => $l->batch_no, 'expires_on' => $l->expires_on?->toDateString(),
            'supplier_sku' => $l->supplier_sku, 'sell_uom_id' => $l->sell_uom_id, 'new_price' => $plain($l->new_price_minor)])->all()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(DirectPurchases::class)->save($actor, $record, $data));
    }

    protected function getRedirectUrl(): string
    {
        return DirectPurchaseResource::getUrl('view', ['record' => $this->record]);
    }
}
