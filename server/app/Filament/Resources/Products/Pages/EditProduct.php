<?php

namespace App\Filament\Resources\Products\Pages;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\ProductWriter;
use App\Domain\Catalog\CatalogService;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Erp;
use App\Models\Uom;
use App\Support\Qty;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + ProductResource::toFormData($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            // A wrong base unit (Pieces instead of Pack) after stock has moved: renamed, quantities stay.
            Action::make('correctUnit')->label('Correct the unit')->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
                ->visible(fn () => Erp::canAnywhere('catalog.manage') && $this->record->hasMoved())
                ->modalDescription(fn () => 'The unit is renamed, the quantities stay: '.Qty::format((int) $this->record->stockLevels->sum(fn ($l) => Qty::toMilli((string) $l->on_hand)))
                    .' '.$this->record->baseUom?->code.' become the same number in the new unit. Prices and barcodes stay. To sell in packs as well as pieces, add Pack under Other units instead.')
                ->schema([
                    Select::make('uom_id')->label('Correct unit')->required()->searchable()
                        ->options(fn () => Uom::whereKeyNot($this->record->base_uom_id)->orderBy('code')->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->code} · {$u->name}"])),
                    TextInput::make('reason')->label('Reason')->required()->maxLength(255)->placeholder('e.g. counted and sold in packs, not pieces'),
                ])
                ->action(function (array $data) {
                    Erp::run(fn ($actor) => app(CatalogService::class)->correctBaseUnit($actor, $this->record, $data['uom_id'], $data['reason']), 'Unit corrected');
                    $this->redirect(ProductResource::getUrl('edit', ['record' => $this->record]));
                }),
            // Draft an online-shop description with the AI; the person edits it before saving.
            Action::make('describe')->label('Write description (AI)')->icon(Heroicon::OutlinedSparkles)->color('gray')
                ->visible(fn () => Erp::canAnywhere('catalog.manage'))
                ->modalDescription(fn () => app(AiGateway::class)->unavailableReason(Erp::user()->tenant_id) ?? 'A draft from the product details. Edit it as you like; nothing is saved until you press Save.')
                ->fillForm(fn () => ['tone' => 'friendly', 'description' => $this->record->description])
                ->schema([
                    Select::make('tone')->options(['friendly' => 'Friendly', 'professional' => 'Professional', 'short' => 'Very short'])->label('Style'),
                    Textarea::make('description')->rows(6)->maxLength(2000)
                        ->hintAction(Action::make('draft')->label('Draft with AI')->icon(Heroicon::OutlinedSparkles)
                            ->action(function ($set, $get) {
                                $text = Erp::run(fn ($actor) => app(ProductWriter::class)->draft($actor, $this->record, (string) ($get('tone') ?: 'friendly')));
                                if ($text) {
                                    $set('description', $text);
                                }
                            })),
                ])
                ->action(function (array $data) {
                    if (Erp::run(fn ($actor) => app(CatalogService::class)->saveProduct($actor, $this->record, ['description' => $data['description'] ?? null], 'Description written with AI help'), 'Description saved')) {
                        $this->fillForm();
                    }
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! Erp::can('pricing.manage')) {
            unset($data['prices']); // never wipe prices someone can't see
        }

        return Erp::run(fn ($actor) => app(CatalogService::class)->saveProduct($actor, $record, $data, $data['reason'] ?? null));
    }
}
