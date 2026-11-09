<?php

namespace App\Filament\Resources\StockCostRuns;

use App\Domain\Finance\GeneralLedger;
use App\Domain\Inventory\StockCostRuns;
use App\Domain\Support\DomainException;
use App\Filament\Resources\StockCostRuns\Pages\CreateStockCostRun;
use App\Filament\Resources\StockCostRuns\Pages\ListStockCostRuns;
use App\Filament\Resources\StockCostRuns\Pages\ViewStockCostRun;
use App\Filament\Resources\StockCostRuns\RelationManagers\LinesRelationManager;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\Brand;
use App\Models\Category;
use App\Models\GoLivePlan;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\StockCostRun;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Stock → Cost runs: products loaded without a cost get one worked out from their selling price, for all products or a
 * selection. The run's page is the preview; nothing moves until it is posted.
 */
class StockCostRunResource extends Resource
{
    protected static ?string $model = StockCostRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Cost runs';

    protected static ?string $modelLabel = 'cost run';

    protected static ?int $navigationSort = 60;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.cost.manage') || Erp::canAnywhere('stock.cost.approve');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('stock.cost.manage') && config('erp.node.role') !== 'branch';
    }

    public static function canEdit(Model $record): bool
    {
        return false; // change the choices by cancelling and preparing another
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** The go-live date when that month is still open, otherwise today. */
    private static function defaultDate(): string
    {
        $date = GoLivePlan::query()->value('go_live_on');
        if ($date) {
            try {
                app(GeneralLedger::class)->postingDate(Erp::actor()->tenantId, Carbon::parse($date)->toDateString());

                return Carbon::parse($date)->toDateString();
            } catch (DomainException) {
                // that month is closed
            }
        }

        return now()->toDateString();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('How to work out the cost')->columns(2)->schema([
                Radio::make('method')->label('Method')->options(StockCostRun::METHODS)->default('divide')->required()->live()->columnSpanFull(),
                TextInput::make('value')->label(fn (Get $get) => $get('method') === 'margin' ? 'Profit, % of the selling price' : 'Factor')->required()->numeric()->default('1.4')
                    ->helperText(fn (Get $get) => $get('method') === 'margin' ? 'A GH₵ 14.00 item at 40% costs GH₵ 8.40.' : 'A GH₵ 14.00 item at 1.4 costs GH₵ 10.00.'),
                Select::make('price_list_id')->label('Selling prices from')->required()->selectablePlaceholder(false)
                    ->options(fn () => PriceList::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => PriceList::where('is_default', true)->value('id')),
                Radio::make('tax_mode')->label('VAT and levies')->options(StockCostRun::TAX_MODES)->default('shelf')->required()->columnSpanFull()
                    ->helperText('Use the price the customer pays if that is the price you multiply your cost by. Remove them first if you add your profit before tax.'),
                Repeater::make('overrides')->label('A different factor for some categories or brands (optional)')->columns(3)->defaultItems(0)->addActionLabel('Add a different factor')->columnSpanFull()
                    ->schema([
                        Select::make('kind')->label('For')->options(['category' => 'Category', 'brand' => 'Brand'])->default('category')->required()->live(),
                        Select::make('id')->label('Which')->required()->searchable()
                            ->options(fn (Get $get) => $get('kind') === 'brand' ? Brand::orderBy('name')->pluck('name', 'id') : Category::orderBy('name')->pluck('name', 'id')),
                        TextInput::make('value')->label('Factor or %')->required()->numeric(),
                    ]),
            ]),
            Section::make('Which products')->columns(2)->description('Leave everything empty for all products in stock.')->schema([
                Select::make('product_ids')->label('Only these products')->multiple()->searchable()->columnSpanFull()
                    ->getSearchResultsUsing(fn (string $search) => Product::where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"))->limit(30)->pluck('name', 'id'))
                    ->getOptionLabelsUsing(fn (array $values) => Product::whereIn('id', $values)->pluck('name', 'id'))
                    ->default(fn () => session()->pull('stock_cost_run.products', [])),
                Select::make('category_ids')->label('Categories')->multiple()->searchable()->options(fn () => Category::orderBy('name')->pluck('name', 'id')),
                Select::make('brand_ids')->label('Brands')->multiple()->searchable()->options(fn () => Brand::orderBy('name')->pluck('name', 'id')),
                Select::make('supplier_ids')->label('Suppliers')->multiple()->searchable()->options(fn () => Supplier::orderBy('name')->pluck('name', 'id')),
                Select::make('warehouse_ids')->label('Stores')->multiple()->options(fn () => Fields::warehouses('stock.cost.manage')),
                Toggle::make('overwrite')->label('Also replace costs products already have')->default(false)->columnSpanFull()
                    ->helperText('Off: only products with no cost are filled in, and real costs from deliveries stay. On: their real cost is lost from the average. '
                        .'To re-cost products you chose, switch this on.'),
            ]),
            Section::make('The books')->columns(2)->schema([
                DatePicker::make('effective_on')->label('Date in the books')->required()->default(fn () => self::defaultDate())->helperText('Usually the go-live date. It must be in an open month.'),
                Toggle::make('catch_up')->label('Add the cost of past sales')->default(true)->helperText('Sales made with no cost stay as they are; one entry per branch adds their estimated cost, so profit is realistic.'),
                Select::make('account_id')->label('Against account (optional)')->searchable()->columnSpanFull()->visible(fn () => Erp::canAnywhere('finance.view'))
                    ->options(fn () => FinanceUi::accounts(false))
                    ->helperText('Empty: stock that had no cost goes against Opening balances (to clear); a replaced cost goes to Stock losses and count differences.'),
                Textarea::make('note')->label('Note')->rows(2)->maxLength(255)->columnSpanFull()->placeholder('e.g. Owner\'s usual mark-up of 1.4; products were loaded without costs'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state) => FinanceUi::money((int) $state);

        return $schema->components([
            Section::make(fn (StockCostRun $r) => $r->status === 'posted' || $r->status === 'reversed' ? 'What it did' : 'Preview: nothing has changed yet')->columns(4)
                // Say plainly why a run has nothing to do, and what to press (owner 2026-11-09).
                ->description(fn (StockCostRun $r) => $r->isOpen() && (int) $r->lines_count === 0 && (int) $r->already_costed_count > 0 && ! $r->overwrite
                    ? "Nothing to do: the {$r->already_costed_count} product line(s) chosen already have a cost, and this run only fills in missing costs. To give them a new cost, press \"Replace their costs too\"."
                    : null)->schema([
                        TextEntry::make('stock_value_minor')->label('Added to stock value')->formatStateUsing($money)->weight('bold'),
                        TextEntry::make('catch_up_minor')->label('Cost of past sales')->formatStateUsing($money),
                        TextEntry::make('lines_count')->label('Lines')->helperText('A product in each store'),
                        TextEntry::make('skipped_count')->label('Skipped')->helperText('Listed below, with the reason'),
                        TextEntry::make('already_costed_count')->label('Already had a cost')->helperText('Left as they are'),
                        TextEntry::make('factor')->label('Factor')->state(fn (StockCostRun $r) => StockCostRun::factorLabel($r->method, $r->value_bp)
                            .collect($r->overrides ?? [])->map(fn ($o) => ', '.$o['name'].' '.StockCostRun::factorLabel($r->method, $o['value_bp']))->implode('')),
                        TextEntry::make('tax_mode')->label('VAT and levies')->formatStateUsing(fn ($state) => StockCostRun::TAX_MODES[$state] ?? $state),
                        TextEntry::make('priceList.name')->label('Prices from'),
                        TextEntry::make('effective_on')->label('Date in the books')->date('j M Y'),
                        TextEntry::make('account.name')->label('Against account')->placeholder('The usual accounts'),
                        TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => StockCostRun::STATUSES[$state] ?? $state),
                        TextEntry::make('created_by_name')->label('Prepared by'),
                        TextEntry::make('posted_by_name')->label('Posted by')->placeholder('—'),
                        TextEntry::make('reason')->label('Reason')->placeholder('—'),
                        TextEntry::make('note')->label('Note')->placeholder('—')->columnSpan(2),
                    ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => FinanceUi::money((int) $state);

        return $table
            ->columns([
                TextColumn::make('number')->label('No.')->searchable()->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => StockCostRun::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['posted' => 'success', 'submitted' => 'warning', 'reversed' => 'gray', 'cancelled' => 'gray'][$state] ?? 'info'),
                TextColumn::make('factor')->label('Factor')->state(fn (StockCostRun $r) => StockCostRun::factorLabel($r->method, $r->value_bp)),
                TextColumn::make('effective_on')->label('Date in the books')->date('j M Y')->sortable(),
                TextColumn::make('stock_value_minor')->label('Stock value')->alignEnd()->formatStateUsing($money),
                TextColumn::make('catch_up_minor')->label('Past sales')->alignEnd()->formatStateUsing($money),
                TextColumn::make('lines_count')->label('Lines')->alignEnd(),
                TextColumn::make('skipped_count')->label('Skipped')->alignEnd(),
                TextColumn::make('created_by_name')->label('By'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** @return list<Action> */
    public static function runActions(): array
    {
        $svc = fn () => app(StockCostRuns::class);
        $reason = [Textarea::make('reason')->label('Reason')->required()->maxLength(255)];

        return [
            Action::make('recalculate')->label('Work it out again')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn (StockCostRun $record) => $record->isOpen() && Erp::canAnywhere('stock.cost.manage'))
                ->action(fn (StockCostRun $record) => Erp::run(fn ($actor) => $svc()->recalculate($actor, $record), 'Worked out again')),
            // Nothing to do because the products already had a cost: replace theirs too, without starting again.
            Action::make('replaceExisting')->label('Replace their costs too')->icon(Heroicon::OutlinedArrowsRightLeft)->color('warning')
                ->visible(fn (StockCostRun $record) => $record->isOpen() && ! $record->overwrite && $record->already_costed_count > 0 && Erp::canAnywhere('stock.cost.manage'))
                ->requiresConfirmation()->modalHeading('Replace the costs these products already have?')
                ->modalDescription(fn (StockCostRun $record) => "The {$record->already_costed_count} product line(s) that already had a cost get the cost worked out from the price. "
                    .'Their real cost from deliveries is lost from the average, and the difference goes to Stock losses when posted. Nothing changes until it is posted.')
                ->action(fn (StockCostRun $record) => Erp::run(fn ($actor) => $svc()->replaceExisting($actor, $record), 'Worked out again, replacing their costs')),
            Action::make('submit')->label('Send for approval')->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (StockCostRun $record) => $record->status === 'draft' && Erp::canAnywhere('stock.cost.manage') && $svc()->twoPeople($record->tenant_id))
                ->requiresConfirmation()->action(fn (StockCostRun $record) => Erp::run(fn ($actor) => $svc()->submit($actor, $record), 'Sent for approval')),
            Action::make('post')->label('Approve and post')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (StockCostRun $record) => $record->isOpen() && Erp::canAnywhere('stock.cost.approve') && ! $svc()->whyNotPost(Erp::actor(), $record))
                ->requiresConfirmation()->modalHeading('Post this cost run?')
                ->modalDescription(fn (StockCostRun $record) => 'Adds '.FinanceUi::money($record->stock_value_minor).' to the stock value and '.FinanceUi::money($record->catch_up_minor)
                    .' to cost of past sales, as worked out again now. Best done when the tills are quiet: it can take a few minutes. Selling prices do not change.')
                ->action(fn (StockCostRun $record) => Erp::run(fn ($actor) => $svc()->post($actor, $record), 'Cost run posted')),
            Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedXMark)->color('gray')
                ->visible(fn (StockCostRun $record) => $record->isOpen() && Erp::canAnywhere('stock.cost.manage'))
                ->schema($reason)->action(fn (StockCostRun $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
            Action::make('undo')->label('Undo this run')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
                ->visible(fn (StockCostRun $record) => $record->status === 'posted' && ! $record->reverses_id && Erp::canAnywhere('stock.cost.approve'))
                ->modalDescription('Puts the earlier costs back with an equal and opposite run. Products that had a delivery since keep their cost, and sales made since keep the cost they were sold at.')
                ->schema($reason)->action(fn (StockCostRun $record, array $data) => Erp::run(fn ($actor) => $svc()->undo($actor, $record, $data['reason']), 'Cost run undone')),
        ];
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListStockCostRuns::route('/'), 'create' => CreateStockCostRun::route('/create'), 'view' => ViewStockCostRun::route('/{record}')];
    }
}
