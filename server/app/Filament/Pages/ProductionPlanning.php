<?php

namespace App\Filament\Pages;

use App\Domain\Manufacturing\MrpPlanner;
use App\Domain\Manufacturing\WorkOrderService;
use App\Domain\Modules\ModuleService;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Filament\Support\Fields;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** MRP: what to make and what to buy for a warehouse, level by level down the bills of materials, and the load on work centres. */
class ProductionPlanning extends Page
{
    use ExportsPage {
        getHeaderActions as exportActions;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Manufacturing';

    protected static ?string $navigationLabel = 'Production planning (MRP)';

    protected static ?string $title = 'Production planning (MRP)';

    protected static ?string $slug = 'production-planning';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.production-planning';

    #[Url]
    public string $warehouse = '';

    /** @var array{make: list<array<string, mixed>>, buy: list<array<string, mixed>>, load: list<array<string, mixed>>}|null */
    private ?array $planCache = null;

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('stock.view') && ModuleService::enabledAnywhere('manufacturing');
    }

    public function mount(): void
    {
        $this->warehouse = $this->warehouse ?: (string) array_key_first($this->warehouses());
    }

    public function warehouses(): array
    {
        return Fields::warehouses('stock.view');
    }

    public function plan(): array
    {
        if (! $this->warehouse || ! array_key_exists($this->warehouse, $this->warehouses())) {
            return ['make' => [], 'buy' => [], 'load' => []];
        }

        return $this->planCache ??= app(MrpPlanner::class)->plan($this->warehouse);
    }

    public function qty(float $v): string
    {
        return MrpPlanner::qty($v);
    }

    public function canMake(): bool
    {
        return $this->warehouse !== '' && Erp::canAnywhere('manufacturing.produce');
    }

    /** Raise a draft work order for one suggestion. */
    public function makeOne(string $productId): void
    {
        $row = collect($this->plan()['make'])->firstWhere('product_id', $productId);
        if ($row && Erp::run(fn ($actor) => app(WorkOrderService::class)->create($actor, ['warehouse_id' => $this->warehouse, 'product_id' => $productId,
            'qty' => Qty::format((int) round($row['suggested'] * 1000)), 'note' => 'Raised from production planning']))) {
            Notification::make()->title("Draft work order raised for {$row['name']}")->success()->send();
            $this->planCache = null;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('makeAll')->label('Raise all work orders')->icon(Heroicon::OutlinedWrenchScrewdriver)->requiresConfirmation()
                ->modalDescription('A draft work order for every product suggested under "Make". They do nothing until released.')
                ->visible(fn () => $this->canMake() && $this->plan()['make'] !== [])
                ->action(function () {
                    foreach ($this->plan()['make'] as $row) {
                        $this->makeOne($row['product_id']);
                    }
                }),
            ...$this->exportActions(),
        ];
    }

    protected function exportFilters(): array
    {
        return ['Warehouse: '.($this->warehouses()[$this->warehouse] ?? '—')];
    }
}
