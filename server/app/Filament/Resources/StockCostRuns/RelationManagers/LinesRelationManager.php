<?php

namespace App\Filament\Resources\StockCostRuns\RelationManagers;

use App\Filament\Support\FinanceUi;
use App\Models\StockCostRun;
use App\Models\StockCostRunLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The run's preview, product by product, with what it could not cost and why (Export gives the whole run). */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Products';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $money = fn ($state) => $state === null ? '—' : FinanceUi::money((int) $state);
        $qty = fn ($state) => rtrim(rtrim(number_format((float) $state, 3), '0'), '.');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('product', 'warehouse'))
            ->columns([
                TextColumn::make('product.name')->label('Product')->searchable()->limit(40),
                TextColumn::make('product.sku')->label('SKU')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('warehouse.name')->label('Store')->placeholder('—'),
                TextColumn::make('kind')->label('What happens')->badge()->formatStateUsing(fn ($state) => StockCostRunLine::KINDS[$state] ?? $state)
                    ->color(fn ($state) => ['skipped' => 'warning', 'overwrite' => 'danger', 'top_up' => 'info'][$state] ?? 'success'),
                TextColumn::make('skip_reason')->label('Why')->placeholder('—')->limit(40),
                TextColumn::make('on_hand')->label('On hand')->alignEnd()->formatStateUsing($qty),
                TextColumn::make('price_minor')->label('Price used')->alignEnd()->formatStateUsing(fn ($state) => $state === null ? '—' : FinanceUi::money((int) round((float) $state))),
                TextColumn::make('factor')->label('Factor')->state(fn (StockCostRunLine $l) => $l->value_bp ? StockCostRun::factorLabel($this->getOwnerRecord()->method, $l->value_bp) : '—'),
                TextColumn::make('old_avg_minor')->label('Old cost')->alignEnd()->formatStateUsing(fn ($state) => $state === null ? '—' : FinanceUi::money((int) round((float) $state)))->placeholder('—'),
                TextColumn::make('new_cost_minor')->label('New cost')->alignEnd()->formatStateUsing($money)->placeholder('—'),
                TextColumn::make('stock_value_minor')->label('Stock value +')->alignEnd()->formatStateUsing($money)->sortable(),
                TextColumn::make('sold_qty')->label('Sold at no cost')->alignEnd()->formatStateUsing($qty)->toggleable(),
                TextColumn::make('catch_up_minor')->label('Cost of past sales')->alignEnd()->formatStateUsing($money)->sortable(),
            ])
            ->filters([SelectFilter::make('kind')->label('What happens')->options(StockCostRunLine::KINDS)])
            ->defaultSort('stock_value_minor', 'desc')
            ->emptyStateHeading('Nothing to do')
            ->emptyStateDescription('Every product chosen already has a cost.');
    }
}
