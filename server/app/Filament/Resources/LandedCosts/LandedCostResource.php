<?php

namespace App\Filament\Resources\LandedCosts;

use App\Domain\Purchasing\LandedCostService;
use App\Filament\Resources\LandedCosts\Pages\ListLandedCosts;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\IndustryScope;
use App\Filament\Support\MiniTable;
use App\Models\GoodsReceipt;
use App\Models\LandedCost;
use App\Models\LandedCostLine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Freight, import duty, clearing and insurance spread over deliveries, so stock costs what it really cost to bring in.
 * The bill for the charge goes to "Freight, duty and clearing to spread"; this moves it into stock and cost of sales.
 */
class LandedCostResource extends Resource
{
    protected static ?string $model = LandedCost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Landed costs';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('payables.view') || Erp::canAnywhere('purchasing.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false; // posted once; a correction is another landed cost
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function spreadAction(): Action
    {
        return Action::make('spread')->label('Spread freight and duty')->icon(Heroicon::OutlinedTruck)->color('success')
            ->visible(fn () => Erp::canAnywhere('payables.manage') && config('erp.node.role') !== 'branch')
            ->modalDescription('First enter the freight, customs or clearing bill as a supplier bill, with an expense line to "Freight, duty and clearing to spread". '
                .'Then spread it here over the deliveries it belongs to. What is still in stock costs more; what was already sold goes to cost of sales.')
            ->schema([
                Select::make('receipts')->label('Deliveries (goods received)')->multiple()->required()->searchable()
                    ->options(fn () => GoodsReceipt::with('supplier')->where('status', 'posted')->where('posted_at', '>=', now()->subMonths(6))->orderByDesc('posted_at')->limit(300)->get()
                        ->mapWithKeys(fn (GoodsReceipt $g) => [$g->id => "{$g->number} · ".($g->supplier?->name ?? '—').' · '.$g->posted_at?->format('j M').' · '.FinanceUi::money((int) $g->net_minor)])),
                Select::make('method')->label('Spread')->options(LandedCost::METHODS)->default('value')->required()->selectablePlaceholder(false)
                    ->helperText('By value suits most goods (a dearer item carries more of the freight). By quantity suits bulky goods of similar size.'),
                Repeater::make('charges')->label('Charges')->required()->minItems(1)->defaultItems(1)->columns(3)->addActionLabel('Add a charge')->schema([
                    Select::make('kind')->label('Kind')->options(LandedCost::KINDS)->default('freight')->required(),
                    TextInput::make('description')->label('Description')->required()->maxLength(255)->placeholder('e.g. Freight Kumasi–Tarkwa, waybill 4471'),
                    TextInput::make('amount')->label('Amount (before VAT)')->required()->numeric()->helperText('Negative to correct an earlier one.'),
                ]),
                TextInput::make('note')->label('Note')->maxLength(255),
            ])
            ->action(fn (array $data) => Erp::run(fn ($actor) => app(LandedCostService::class)->post($actor, $data['receipts'],
                array_map(fn ($c) => ['kind' => $c['kind'], 'description' => $c['description'], 'amount_minor' => FinanceUi::toMinor($c['amount'])], $data['charges']),
                $data['method'], $data['note'] ?? null), 'Landed cost posted'));
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => FinanceUi::money((int) $state);

        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('posted_at')->label('Date')->date()->sortable(),
                TextColumn::make('charges_list')->label('Charges')->state(fn (LandedCost $r) => $r->charges->pluck('description')->implode('; '))->wrap(),
                TextColumn::make('method')->formatStateUsing(fn ($state) => $state === 'value' ? 'By value' : 'By quantity'),
                TextColumn::make('total_minor')->label('Total')->alignEnd()->formatStateUsing($money),
                TextColumn::make('to_stock_minor')->label('Into stock')->alignEnd()->formatStateUsing($money),
                TextColumn::make('to_cogs_minor')->label('Cost of sales')->alignEnd()->formatStateUsing($money)
                    ->tooltip('The share for goods already sold when the charge came in.'),
                TextColumn::make('created_by_name')->label('By'),
            ])
            ->recordActions([
                Action::make('lines')->label('Per product')->icon(Heroicon::OutlinedListBullet)->color('gray')
                    ->modalHeading(fn (LandedCost $r) => "{$r->number}: per product")->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (LandedCost $r) => MiniTable::html(['Product', 'Share', 'Into stock', 'Cost of sales', 'Cost per unit before → after'],
                        $r->lines()->with('product')->get()->map(fn (LandedCostLine $l) => [$l->product?->name ?? '—', FinanceUi::money($l->allocated_minor),
                            FinanceUi::money($l->to_stock_minor), FinanceUi::money($l->to_cogs_minor),
                            ($l->avg_before !== null ? number_format($l->avg_before / 100, 4) : '—').' → '.($l->avg_after !== null ? number_format($l->avg_after / 100, 4) : '—')])->all(),
                        [], 'Nothing.')),
            ])
            ->defaultSort('posted_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return IndustryScope::branches(parent::getEloquentQuery()->with('charges'));
    }

    public static function getPages(): array
    {
        return ['index' => ListLandedCosts::route('/')];
    }
}
