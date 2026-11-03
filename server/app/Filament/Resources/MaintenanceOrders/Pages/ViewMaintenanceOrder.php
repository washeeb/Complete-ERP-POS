<?php

namespace App\Filament\Resources\MaintenanceOrders\Pages;

use App\Filament\Resources\MaintenanceOrders\MaintenanceOrderResource;
use App\Filament\Support\FinanceUi;
use App\Models\MaintenanceOrder;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** A work order: what is to be done, the checklist, parts, time, cost and downtime. */
class ViewMaintenanceOrder extends ViewRecord
{
    protected static string $resource = MaintenanceOrderResource::class;

    public function getTitle(): string
    {
        return "{$this->record->number} · {$this->record->title}";
    }

    protected function getHeaderActions(): array
    {
        return MaintenanceOrderResource::orderActions();
    }

    public function infolist(Schema $schema): Schema
    {
        $money = fn (int $v) => FinanceUi::money($v);

        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('asset.name')->label('Equipment')->helperText(fn (MaintenanceOrder $o) => $o->asset?->owner === 'customer' ? 'Customer: '.$o->asset->customer?->name : $o->asset?->location),
                TextEntry::make('kind')->formatStateUsing(fn ($state) => MaintenanceOrder::KINDS[$state] ?? $state),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => MaintenanceOrder::STATUSES[$state] ?? $state),
                TextEntry::make('assignee.name')->label('Who')->placeholder('Nobody yet'),
                TextEntry::make('due_on')->label('By')->date('j M Y')->placeholder('—'),
                TextEntry::make('down')->label('Down for')->state(fn (MaintenanceOrder $o) => $o->down_from ? MaintenanceOrderResource::span($o->downtimeMinutes()).($o->down_until ? '' : ' so far') : 'Still working'),
                TextEntry::make('chargeable')->label('Paid by')->state(fn (MaintenanceOrder $o) => $o->asset?->owner !== 'customer' ? 'Us' : ($o->chargeable ? 'The customer' : 'Us (warranty or contract)')),
                TextEntry::make('invoice.number')->label('Invoice')->placeholder('—'),
                TextEntry::make('details')->placeholder('—')->columnSpanFull(),
                TextEntry::make('outcome')->label('What was done')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Cost')->columns(4)->schema([
                TextEntry::make('labour')->label('Time')->state(fn (MaintenanceOrder $o) => intdiv($o->labour_minutes, 60).' h '.($o->labour_minutes % 60).' min · '.$money($o->labourMinor())),
                TextEntry::make('parts_minor')->label('Parts')->formatStateUsing(fn ($state) => $money((int) $state)),
                TextEntry::make('outside_minor')->label('Outside technicians')->formatStateUsing(fn ($state) => $money((int) $state)),
                TextEntry::make('total')->label('Total')->weight('bold')->state(fn (MaintenanceOrder $o) => $money($o->totalMinor())),
            ]),
            Section::make('Checklist')->visible(fn (MaintenanceOrder $o) => (bool) $o->checklist)->schema([
                RepeatableEntry::make('checklist')->hiddenLabel()->columns(2)->schema([TextEntry::make('item')->hiddenLabel(),
                    TextEntry::make('done')->hiddenLabel()->formatStateUsing(fn ($state) => $state ? 'Done' : 'Not done')->color(fn ($state) => $state ? 'success' : 'gray')]),
            ]),
            Section::make('Parts used')->visible(fn (MaintenanceOrder $o) => $o->parts()->exists())->schema([
                RepeatableEntry::make('parts')->hiddenLabel()->columns(3)->schema([TextEntry::make('product.name')->hiddenLabel(),
                    TextEntry::make('qty')->hiddenLabel()->formatStateUsing(fn ($state) => rtrim(rtrim((string) $state, '0'), '.')),
                    TextEntry::make('cost_minor')->hiddenLabel()->formatStateUsing(fn ($state) => $money((int) $state))]),
            ]),
        ]);
    }
}
