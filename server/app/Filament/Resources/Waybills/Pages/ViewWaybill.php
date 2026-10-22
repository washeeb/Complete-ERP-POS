<?php

namespace App\Filament\Resources\Waybills\Pages;

use App\Domain\Logistics\WaybillService;
use App\Filament\Resources\Waybills\WaybillResource;
use App\Filament\Support\Erp;
use App\Filament\Support\ReloadsAfterAction;
use App\Models\Waybill;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewWaybill extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = WaybillResource::class;

    protected function getHeaderActions(): array
    {
        $svc = fn () => app(WaybillService::class);

        return [
            Action::make('print')->label('Print waybill')->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->url(fn (Waybill $record) => route('print.waybill', $record), true),
            Action::make('dispatch')->label('Dispatch')->icon(Heroicon::OutlinedTruck)->color('warning')
                ->visible(fn (Waybill $record) => $record->status === 'prepared')
                ->fillForm(fn (Waybill $record) => $record->only(['transporter', 'driver_name', 'driver_phone', 'vehicle_no']))
                ->schema([
                    TextInput::make('driver_name')->label('Driver')->maxLength(120),
                    TextInput::make('driver_phone')->label('Driver\'s phone')->tel()->maxLength(30),
                    TextInput::make('vehicle_no')->label('Vehicle registration')->maxLength(30),
                    TextInput::make('transporter')->label('Transport company')->maxLength(120),
                ])
                ->action(fn (Waybill $record, array $data) => Erp::run(fn ($actor) => $svc()->dispatch($actor, $record, $data), 'On the way')),
            Action::make('deliver')->label('Mark delivered')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (Waybill $record) => in_array($record->status, ['prepared', 'dispatched'], true))
                ->modalDescription('From the signed copy brought back. The receiver can also confirm by scanning the QR code on the printed waybill.')
                ->schema([
                    TextInput::make('received_by')->label('Received by')->required()->maxLength(120),
                    TextInput::make('phone')->label('Their phone')->tel()->maxLength(30),
                    FileUpload::make('pod')->label('Signed waybill or photo')->disk('local')->directory('waybill-pod')->visibility('private')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])->maxSize(5120),
                    Textarea::make('note')->label('Missing or damaged?')->maxLength(500),
                ])
                ->action(fn (Waybill $record, array $data) => Erp::run(fn ($actor) => $svc()->deliver($actor, $record, $data['received_by'], $data['pod'] ?? null,
                    $data['note'] ?? null, $data['phone'] ?? null), 'Delivered')),
            Action::make('cancel')->label('Cancel')->color('danger')->visible(fn (Waybill $record) => in_array($record->status, ['prepared', 'dispatched'], true))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (Waybill $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
        ];
    }
}
