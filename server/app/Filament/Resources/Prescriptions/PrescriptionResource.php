<?php

namespace App\Filament\Resources\Prescriptions;

use App\Domain\Modules\ModuleService;
use App\Filament\Resources\Prescriptions\Pages\ListPrescriptions;
use App\Filament\Resources\Prescriptions\Pages\ViewPrescription;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\Erp;
use App\Models\Prescription;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Prescriptions dispensed at the tills (created with the sale; never edited). */
class PrescriptionResource extends Resource
{
    protected static ?string $model = Prescription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Pharmacy';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('pharmacy.dispense') || Erp::canAnywhere('pharmacy.manage')) && ModuleService::enabledAnywhere('pharmacy');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Branches where the person dispenses or manages the pharmacy (null = every branch). */
    public static function branchIds(): ?array
    {
        $ids = [];
        foreach (['pharmacy.dispense', 'pharmacy.manage'] as $p) {
            if (! Erp::canAnywhere($p)) {
                continue;
            }
            $b = Erp::branchIds($p);
            if ($b === null) {
                return null;
            }
            $ids = array_merge($ids, $b);
        }

        return array_values(array_unique($ids));
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = self::branchIds();

        return parent::getEloquentQuery()->with('customer', 'sale')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('sale.occurred_at')->label('Dispensed')->dateTime(),
                TextEntry::make('customer.name')->label('Patient'),
                TextEntry::make('sale.receipt_no')->label('Receipt')
                    ->url(fn (Prescription $r) => $r->sale_id && SaleResource::canViewAny() ? SaleResource::getUrl('view', ['record' => $r->sale_id]) : null),
                TextEntry::make('prescriber_name')->label('Prescriber'),
                TextEntry::make('prescriber_reg_no')->label('Prescriber reg. no.')->placeholder('—'),
                TextEntry::make('facility')->placeholder('—'),
                TextEntry::make('reference')->label('Prescription ref.')->placeholder('—'),
                TextEntry::make('issued_on')->label('Written on')->date(),
                TextEntry::make('pharmacist_name')->label('Pharmacist')
                    ->belowContent(fn (Prescription $r) => $r->approved_offline ? 'Approved on the till while offline' : null),
            ]),
            Section::make('Medicines')->schema([
                TextEntry::make('medicines')->hiddenLabel()->html()->state(fn (Prescription $r) => '<ul>'.($r->sale?->lines()->where('prescription_id', $r->id)->get() ?? collect())
                    ->map(fn ($l) => '<li>'.e(rtrim(rtrim((string) $l->qty, '0'), '.').' '.$l->uom_code.' × '.$l->name).'</li>')->implode('').'</ul>'),
            ]),
            Section::make('Warnings shown to the pharmacist')->schema([
                TextEntry::make('warning_list')->hiddenLabel()->html()->state(fn (Prescription $r) => $r->warnings
                    ? '<ul>'.collect($r->warnings)->map(fn ($w) => '<li><b>'.e(ucfirst($w['severity'])).'</b> · '.e($w['message']).'</li>')->implode('').'</ul>' : 'None'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('created_at')->label('Dispensed')->dateTime()->sortable(),
                TextColumn::make('customer.name')->label('Patient')->searchable(),
                TextColumn::make('prescriber_name')->label('Prescriber')->searchable(),
                TextColumn::make('pharmacist_name')->label('Pharmacist'),
                TextColumn::make('warning_count')->label('Warnings')->state(fn (Prescription $r) => count($r->warnings ?? []) ?: null)->placeholder('—'),
                IconColumn::make('approved_offline')->label('Offline')->boolean(),
            ])
            ->filters([TernaryFilter::make('approved_offline')->label('Approved offline')])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPrescriptions::route('/'), 'view' => ViewPrescription::route('/{record}')];
    }
}
