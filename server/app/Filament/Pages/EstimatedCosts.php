<?php

namespace App\Filament\Pages;

use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\StockCostRunLine;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Cost runs → estimated against real costs: every product given an estimated cost, and what its first real delivery
 * after the run cost. The real factor (price ÷ real cost) shows whether the factor used was right; a product stays
 * "estimated" until a delivery arrives.
 */
class EstimatedCosts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Estimated costs';

    protected static ?string $title = 'Estimated against real costs';

    protected static ?string $slug = 'estimated-costs';

    protected static ?int $navigationSort = 61;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('stock.cost.manage') || Erp::canAnywhere('stock.cost.approve');
    }

    public function getSubheading(): ?string
    {
        $row = DB::query()->fromSub($this->withRealCost($this->baseQuery())->toBase(), 'x')
            ->selectRaw('COUNT(*) AS n, COUNT(real_cost) AS d, AVG(CASE WHEN real_cost > 0 THEN price / real_cost END) AS f')->first();
        $real = $row->d ? ' Real factor so far: '.number_format((float) $row->f, 2).' on '.$row->d.' product(s).' : ' No deliveries yet to compare with.';

        return $row->n.' product(s) carry an estimated cost until a delivery replaces it.'.$real;
    }

    private function baseQuery(): Builder
    {
        return StockCostRunLine::query()->join('stock_cost_runs as r', 'r.id', '=', 'stock_cost_run_lines.stock_cost_run_id')
            ->where('r.status', 'posted')->whereNull('r.reverses_id')->where('stock_cost_run_lines.kind', 'estimate');
    }

    /** The first delivery after the run, per base unit. */
    private function withRealCost(Builder $query): Builder
    {
        $real = DB::table('goods_receipt_lines as g')->join('goods_receipts as gr', 'gr.id', '=', 'g.goods_receipt_id')
            ->whereColumn('g.product_id', 'stock_cost_run_lines.product_id')->where('gr.status', 'posted')->whereColumn('gr.posted_at', '>', 'r.posted_at')
            ->where('g.qty', '>', 0)->orderBy('gr.posted_at')->limit(1)->selectRaw('g.net_minor / (g.qty * COALESCE(NULLIF(g.factor, 0), 1))');

        return $query->select('stock_cost_run_lines.*')->selectRaw('stock_cost_run_lines.price_minor AS price')->selectSub($real, 'real_cost');
    }

    public function table(Table $table): Table
    {
        $money = fn ($state) => $state === null ? '—' : FinanceUi::money((int) round((float) $state));

        return $table
            ->query(fn () => $this->withRealCost($this->baseQuery())->with('product', 'warehouse'))
            ->columns([
                TextColumn::make('product.name')->label('Product')->limit(40),
                TextColumn::make('warehouse.name')->label('Store'),
                TextColumn::make('price_minor')->label('Price used')->alignEnd()->formatStateUsing($money),
                TextColumn::make('new_cost_minor')->label('Estimated cost')->alignEnd()->formatStateUsing($money),
                TextColumn::make('real_cost')->label('Real cost')->alignEnd()->formatStateUsing($money)->placeholder('Still estimated'),
                TextColumn::make('difference')->label('Real against estimate')->alignEnd()->placeholder('—')
                    ->state(fn (StockCostRunLine $l) => $l->real_cost && $l->new_cost_minor ? number_format(((float) $l->real_cost / $l->new_cost_minor - 1) * 100, 1).'%' : null),
                TextColumn::make('real_factor')->label('Real factor')->alignEnd()->placeholder('—')
                    ->state(fn (StockCostRunLine $l) => (float) $l->real_cost > 0 ? number_format((float) $l->price_minor / (float) $l->real_cost, 2) : null),
            ])
            ->filters([
                TernaryFilter::make('delivered')->label('Real cost known')->trueLabel('Delivered since')->falseLabel('Still estimated')
                    ->queries(
                        true: fn (Builder $query) => $query->whereRaw('EXISTS (SELECT 1 FROM goods_receipt_lines g JOIN goods_receipts gr ON gr.id = g.goods_receipt_id WHERE g.product_id = stock_cost_run_lines.product_id AND gr.status = ? AND gr.posted_at > r.posted_at)', ['posted']),
                        false: fn (Builder $query) => $query->whereRaw('NOT EXISTS (SELECT 1 FROM goods_receipt_lines g JOIN goods_receipts gr ON gr.id = g.goods_receipt_id WHERE g.product_id = stock_cost_run_lines.product_id AND gr.status = ? AND gr.posted_at > r.posted_at)', ['posted']),
                    ),
            ])
            ->defaultSort('stock_cost_run_lines.created_at', 'desc')
            ->emptyStateHeading('No estimated costs')
            ->emptyStateDescription('Products given a cost by a cost run appear here.');
    }
}
