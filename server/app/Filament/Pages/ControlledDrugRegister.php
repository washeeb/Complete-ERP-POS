<?php

namespace App\Filament\Pages;

use App\Domain\Pharmacy\PharmacyReports;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Support\Erp;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Controlled-drug register (read from the stock ledger) and the NHIS claims export. */
class ControlledDrugRegister extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Pharmacy';

    protected static ?string $navigationLabel = 'Controlled-drug register';

    protected static ?string $title = 'Controlled-drug register';

    protected static ?string $slug = 'controlled-drug-register';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.controlled-drug-register';

    #[Url]
    public string $warehouse = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function canAccess(): bool
    {
        return PrescriptionResource::canViewAny();
    }

    public function mount(): void
    {
        $this->warehouse = $this->warehouse ?: (string) array_key_first($this->warehouses());
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    public function warehouses(): array
    {
        $ids = PrescriptionResource::branchIds();

        return Warehouse::when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all();
    }

    public function register(): array
    {
        if (! array_key_exists($this->warehouse, $this->warehouses()) || ! $this->from || ! $this->to || $this->from > $this->to) {
            return [];
        }

        return PharmacyReports::controlledRegister($this->warehouse, $this->from, $this->to);
    }

    public function canExportClaims(): bool
    {
        return Erp::canAnywhere('pharmacy.manage');
    }
}
