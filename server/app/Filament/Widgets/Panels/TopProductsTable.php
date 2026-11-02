<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\DB;

/** Dashboard: the ten products that brought in most this month (posted sales, before discounts are split out). */
class TopProductsTable extends TableWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected static ?string $heading = 'Best sellers this month';

    public function table(Table $table): Table
    {
        return $table
            ->records(function () {
                $branches = self::branches('sales.view');

                return DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
                    ->where('s.tenant_id', self::tenantId())->where('s.status', 'posted')->whereNotNull('l.product_id')
                    ->when($branches !== null, fn ($q) => $q->whereIn('s.branch_id', $branches))
                    ->where('s.occurred_at', '>=', self::today()->startOfMonth()->utc())
                    ->groupBy('l.product_id')->selectRaw('l.product_id, MAX(l.name) AS name, SUM(l.qty * l.factor) AS qty, SUM(l.line_total_minor) AS total')
                    ->orderByDesc('total')->limit(10)->get()
                    ->mapWithKeys(fn ($r) => [$r->product_id => ['key' => $r->product_id, 'name' => $r->name, 'qty' => (float) $r->qty, 'total' => (int) $r->total]])->all();
            })
            ->columns([
                TextColumn::make('name')->label('Product')->wrap(),
                TextColumn::make('qty')->label('Units')->alignEnd()->numeric(decimalPlaces: 0),
                TextColumn::make('total')->label('Sales')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
            ])
            ->paginated(false)
            ->emptyStateHeading('No sales this month yet');
    }

    public static function canView(): bool
    {
        return Erp::canAnywhere('sales.view');
    }
}
