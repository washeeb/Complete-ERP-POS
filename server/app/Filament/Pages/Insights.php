<?php

namespace App\Filament\Pages;

use App\Domain\Analytics\CashFlowForecaster;
use App\Domain\Analytics\CustomerInsights;
use App\Domain\Analytics\DemandForecaster;
use App\Domain\Analytics\LossPrevention;
use App\Domain\Analytics\PricingAdvisor;
use App\Domain\Analytics\StockHealth;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Built-in analytics (no AI model needed): demand forecast and reorder, stock health, customers, pricing, cash flow, loss prevention. */
class Insights extends Page
{
    use ExportsPage;

    public const TABS = ['forecast' => 'Forecast & reorder', 'stock' => 'Stock health', 'customers' => 'Customers', 'pricing' => 'Pricing',
        'cash' => 'Cash flow', 'loss' => 'Loss prevention'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?string $navigationLabel = 'Insights';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.insights';

    #[Url]
    public string $tab = 'forecast';

    #[Url]
    public string $warehouse = '';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('insights.view');
    }

    public function mount(): void
    {
        if (! array_key_exists($this->tab, $this->tabs())) {
            $this->tab = 'forecast';
        }
        $this->warehouse = $this->warehouse ?: (string) array_key_first($this->warehouses());
    }

    public function getTitle(): string
    {
        return 'Insights — '.(self::TABS[$this->tab] ?? '');
    }

    public function tabs(): array
    {
        $tabs = self::TABS;
        if (! Erp::canAnywhere('insights.loss_prevention')) {
            unset($tabs['loss']);
        }
        if (Erp::branchIds('insights.view') !== null) { // company-wide money and customer figures need a company-wide grant
            unset($tabs['cash'], $tabs['customers'], $tabs['pricing']);
        }

        return $tabs;
    }

    public function warehouses(): array
    {
        return Fields::warehouses('insights.view');
    }

    public function money(int|float|null $minor): string
    {
        return FinanceUi::money((int) round((float) $minor));
    }

    public function q(float|int|string|null $v): string
    {
        return Fields::qty((string) $v);
    }

    private function tenantId(): string
    {
        return Erp::user()->tenant_id;
    }

    private function scope(): array
    {
        return array_key_exists($this->warehouse, $this->warehouses()) ? [$this->warehouse] : [];
    }

    public function forecast(): array
    {
        return $this->scope() ? app(DemandForecaster::class)->forWarehouse($this->warehouse) : [];
    }

    public function stock(): array
    {
        return $this->scope() ? app(StockHealth::class)->report($this->tenantId(), $this->scope()) : ['dead' => [], 'overstock' => [], 'expiry' => [], 'transfers' => []];
    }

    public function transfers(): array
    {
        return app(StockHealth::class)->report($this->tenantId(), array_keys($this->warehouses()))['transfers'];
    }

    public function customers(): array
    {
        $c = app(CustomerInsights::class);

        return ['segments' => $c->segments($this->tenantId()), 'churn' => array_slice($c->churnRisk($this->tenantId()), 0, 50), 'baskets' => $c->baskets($this->tenantId())];
    }

    public function pricing(): array
    {
        $p = app(PricingAdvisor::class);

        return ['margins' => $p->margins($this->tenantId()), 'markdowns' => $this->scope() ? $p->markdowns($this->tenantId(), $this->scope()) : [],
            'promotions' => $p->promotionUplift($this->tenantId())];
    }

    public function cash(): array
    {
        return app(CashFlowForecaster::class)->forecast($this->tenantId());
    }

    public function loss(): array
    {
        if (! Erp::canAnywhere('insights.loss_prevention')) {
            return [];
        }
        $today = CarbonImmutable::now()->startOfDay();

        return app(LossPrevention::class)->cashiers($this->tenantId(), $today->subDays(30), $today->addDay(), Erp::branchIds('insights.loss_prevention'));
    }

    protected function exportFilters(): array
    {
        return ['View: '.(self::TABS[$this->tab] ?? ''), 'Warehouse: '.($this->warehouses()[$this->warehouse] ?? '—')];
    }
}
