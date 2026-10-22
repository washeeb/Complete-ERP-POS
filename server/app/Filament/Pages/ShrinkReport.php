<?php

namespace App\Filament\Pages;

use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/** Stock lost — damaged, expired, spoiled, stolen, count shortages — at cost, by reason and by product. */
class ShrinkReport extends Page
{
    use ExportsPage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Shrink';

    protected static ?string $title = 'Shrink and write-offs';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.shrink-report';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $warehouse = '';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('stock.view');
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
        $this->updatedWarehouse();
    }

    /** Only a store the person may see; empty = all of them. */
    public function updatedWarehouse(): void
    {
        if ($this->warehouse && ! array_key_exists($this->warehouse, $this->warehouses())) {
            $this->warehouse = '';
        }
    }

    public function warehouses(): array
    {
        $ids = Erp::branchIds('stock.view');

        return Warehouse::when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all();
    }

    private function base(?string $from = null)
    {
        $mine = array_keys($this->warehouses());
        $ids = $this->warehouse && in_array($this->warehouse, $mine, true) ? [$this->warehouse] : $mine;

        return DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->whereIn('m.type', ['adjustment', 'count'])->where('m.qty_base', '<', 0)->whereIn('m.warehouse_id', $ids)
            ->whereBetween('m.occurred_at', [($from ?? $this->from).' 00:00:00', $this->to.' 23:59:59']);
    }

    private const VALUE = '-SUM(ROUND(m.qty_entered * COALESCE(m.unit_cost_minor, 0)))::bigint';

    /** The last six months to the end date, month by month: is it getting better or worse? */
    public function byMonth()
    {
        $from = Carbon::parse($this->to)->startOfMonth()->subMonths(5)->toDateString();

        return $this->base($from)->selectRaw("to_char(date_trunc('month', m.occurred_at), 'Mon YYYY') AS month, date_trunc('month', m.occurred_at) AS m0, ".self::VALUE.' AS value')
            ->groupByRaw('1, 2')->orderBy('m0')->get();
    }

    public function byCategory()
    {
        return $this->base()->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->selectRaw("COALESCE(c.name, 'No category') AS category, ".self::VALUE.' AS value')->groupByRaw('1')->orderByDesc('value')->limit(15)->get();
    }

    /** Who recorded the losses (adjustments and count shortages are posted by a person). */
    public function byPerson()
    {
        return $this->base()->selectRaw('m.actor_name AS person, COUNT(*) AS lines, '.self::VALUE.' AS value')->groupBy('m.actor_name')->orderByDesc('value')->limit(15)->get();
    }

    /** Products lost three times or more in the period: worth a closer look (where they are kept, who handles them). */
    public function repeated()
    {
        return $this->base()->selectRaw('p.sku, p.name, COUNT(*) AS times, '.self::VALUE.' AS value')->groupBy('p.sku', 'p.name')
            ->havingRaw('COUNT(*) >= 3')->orderByDesc('times')->limit(20)->get();
    }

    public function byReason()
    {
        return $this->base()->selectRaw("CASE WHEN m.type = 'count' THEN 'Stock count shortage' ELSE split_part(COALESCE(m.reason, 'Other'), ':', 1) END AS reason,
            COUNT(*) AS lines, -SUM(ROUND(m.qty_entered * COALESCE(m.unit_cost_minor, 0)))::bigint AS value")->groupByRaw('1')->orderByDesc('value')->get();
    }

    public function byProduct()
    {
        return $this->base()->selectRaw('p.sku, p.name, -SUM(m.qty_base) AS qty, -SUM(ROUND(m.qty_entered * COALESCE(m.unit_cost_minor, 0)))::bigint AS value')
            ->groupBy('p.sku', 'p.name')->orderByDesc('value')->limit(25)->get();
    }
}
