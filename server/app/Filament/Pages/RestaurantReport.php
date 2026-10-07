<?php

namespace App\Filament\Pages;

use App\Domain\Modules\ModuleService;
use App\Filament\Support\Erp;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/** Tips owed per person (paid with payroll) and voided items, for a period. */
class RestaurantReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?string $navigationLabel = 'Tips & voids';

    protected static ?string $title = 'Tips & voids';

    protected static ?string $slug = 'restaurant-report';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.restaurant-report';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('restaurant.manage') && ModuleService::enabledAnywhere('restaurant');
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    private function branches(): ?array
    {
        return Erp::branchIds('restaurant.manage');
    }

    public function tips()
    {
        return DB::table('sales as s')->leftJoin('users as u', 'u.id', '=', 's.tip_user_id')
            ->where('s.tip_minor', '>', 0)->where('s.status', 'posted')->whereBetween('s.occurred_at', [$this->from.' 00:00:00', $this->to.' 23:59:59.999999'])
            ->when($this->branches() !== null, fn ($q) => $q->whereIn('s.branch_id', $this->branches()))
            ->groupBy('u.name')->orderBy('u.name')->selectRaw('u.name, COUNT(*) AS bills, SUM(s.tip_minor) AS tips')->get();
    }

    public function voids()
    {
        return DB::table('dining_order_items as i')->join('dining_orders as o', 'o.id', '=', 'i.dining_order_id')
            ->where('i.status', 'void')->whereBetween('i.added_at', [$this->from.' 00:00:00', $this->to.' 23:59:59.999999'])
            ->when($this->branches() !== null, fn ($q) => $q->whereIn('o.branch_id', $this->branches()))
            ->orderByDesc('i.added_at')->limit(500)
            ->get(['o.number', 'i.added_at', 'i.name', 'i.qty', 'i.fired_at', 'i.void_reason', 'i.voided_by_name', 'i.added_by_name']);
    }
}
