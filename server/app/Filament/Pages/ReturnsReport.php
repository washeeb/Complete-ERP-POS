<?php

namespace App\Filament\Pages;

use App\Domain\Pos\ReturnService;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\ListFilters;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\Exports\Exporter;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Sales → Returns: every return from the tills and the back office, by cashier, product and reason, with who approved it.
 * Returns are a common way for money to leave, so whoever has far more than their share of them is named at the top.
 */
class ReturnsReport extends Page implements HasTable
{
    use InteractsWithTable;

    /** Named when one person has more than this share of the returns, and at least this many of them. */
    private const SHARE = 0.5;

    private const AT_LEAST = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Returns';

    protected static ?string $title = 'Returns';

    protected static ?string $slug = 'returns';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('sales.view');
    }

    private function base(): Builder
    {
        return SaleResource::getEloquentQuery()->where('kind', 'return');
    }

    public function getSubheading(): ?string
    {
        $last = $this->base()->where('occurred_at', '>=', now()->subDays(30));
        $n = (clone $last)->count();
        if ($n === 0) {
            return 'No returns in the last 30 days.';
        }
        $top = (clone $last)->toBase()->select('cashier_name', DB::raw('COUNT(*) AS n'))->groupBy('cashier_name')->orderByDesc('n')->first();
        $note = $top->n >= self::AT_LEAST && $top->n / $n > self::SHARE ? " Worth a look: {$top->cashier_name} did {$top->n} of them." : '';

        return "{$n} return(s) in the last 30 days, worth ".FinanceUi::money(-(int) (clone $last)->sum('total_minor')).'.'.$note;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->base()->with('payments', 'lines'))
            ->columns([
                TextColumn::make('receipt_no')->label('Return')->searchable()->sortable(),
                TextColumn::make('occurred_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('branch.code')->label('Branch'),
                TextColumn::make('cashier_name')->asPerson('cashier_id')->label('By')->sortable(),
                TextColumn::make('discount_approved_by_name')->label('Approved by')->placeholder('Not approved'),
                TextColumn::make('return_reason_code')->label('Reason')->placeholder('—')->formatStateUsing(fn ($state) => ReturnService::REASONS[$state] ?? $state),
                TextColumn::make('return_reason')->label('Details')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('items')->label('Items')->state(fn (Sale $r) => $r->lines->map(fn ($l) => rtrim(rtrim(ltrim((string) $l->qty, '-'), '0'), '.').' '.$l->uom_code.' '.$l->name)->implode(', '))->limit(50),
                TextColumn::make('to')->label('Went to')->state(fn (Sale $r) => $r->lines->contains('returned_to', 'quarantine') ? 'Quarantine' : 'Shelf')->toggleable(),
                TextColumn::make('total_minor')->label('Refund')->alignEnd()->sortable()->formatStateUsing(fn ($state, Sale $r) => Money::display(-(int) $state, $r->currency_code)),
                TextColumn::make('paid_by')->label('Refunded as')->state(fn (Sale $r) => $r->payments->pluck('method')->unique()->map(fn ($m) => SalePayment::METHODS[$m] ?? $m)->implode(' + ')),
            ])
            ->filters([
                ListFilters::dates('occurred_at', 'Date'),
                ListFilters::person(SaleResource::class, 'cashier_id', 'cashier_name', 'By'),
                SelectFilter::make('return_reason_code')->label('Reason')->multiple()->options(ReturnService::REASONS),
                SelectFilter::make('product')->label('Product')->searchable()->multiple()
                    ->options(fn () => DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->where('s.kind', 'return')->where('s.tenant_id', Erp::user()->tenant_id)->distinct()->orderBy('l.name')->pluck('l.name', 'l.product_id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn ($q, $v) => $q->whereIn('sales.id', DB::table('sale_lines')->whereIn('product_id', $v)->select('sale_id')))),
                SelectFilter::make('approval')->label('Approval')->options(['approved' => 'Approved', 'none' => 'Not approved'])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $v) => $v === 'approved' ? $q->whereNotNull('discount_approved_by_name') : $q->whereNull('discount_approved_by_name'))),
            ])
            ->recordActions([Action::make('slip')->label('Return slip')->icon(Heroicon::OutlinedPrinter)->color('gray')->action(fn (Sale $record) => self::slip($record))])
            ->defaultSort('occurred_at', 'desc');
    }

    /** A return slip to sign and file: what came back, the refund, who did it and who approved it. */
    public static function slip(Sale $sale)
    {
        $m = fn (int $v) => Money::display($v, $sale->currency_code);
        $sale->loadMissing('lines', 'payments', 'branch');
        $original = $sale->original_sale_id ? Sale::find($sale->original_sale_id) : null;

        return Erp::run(fn ($actor) => app(Exporter::class)->pdf($actor, "Return {$sale->receipt_no}", [
            ['title' => 'Goods returned', 'headings' => ['Item', 'Quantity', 'Went to', 'Refund'], 'numeric' => [3],
                'rows' => $sale->lines->map(fn ($l) => [$l->name, rtrim(rtrim(ltrim((string) $l->qty, '-'), '0'), '.').' '.$l->uom_code, $l->returned_to === 'quarantine' ? 'Quarantine' : 'Shelf', $m(-(int) $l->line_total_minor)])->all(),
                'foot' => ['Refund', '', '', $m(-(int) $sale->total_minor)]],
        ], [], 'portrait', [
            'branch' => $sale->branch?->name,
            'facts' => array_filter(['Original receipt' => $original?->receipt_no, 'Date' => $sale->occurred_at->format('j M Y, H:i'), 'Reason' => (ReturnService::REASONS[$sale->return_reason_code] ?? null) ?: $sale->return_reason,
                'Refunded as' => $sale->payments->pluck('method')->unique()->map(fn ($p) => SalePayment::METHODS[$p] ?? $p)->implode(' + '), 'Taken by' => $sale->cashier_name, 'Approved by' => $sale->discount_approved_by_name]),
            'sign' => ['Customer', "Taken by: {$sale->cashier_name}", 'Approved by'],
        ]));
    }
}
