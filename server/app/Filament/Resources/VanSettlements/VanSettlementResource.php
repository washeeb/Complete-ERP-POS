<?php

namespace App\Filament\Resources\VanSettlements;

use App\Domain\Distribution\VanSettlementService;
use App\Domain\Modules\ModuleService;
use App\Filament\Resources\VanSettlements\Pages\ListVanSettlements;
use App\Filament\Resources\VanSettlements\Pages\ViewVanSettlement;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\User;
use App\Models\VanSettlement;
use App\Support\Exports\Exporter;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** End-of-day van settlements: stock counted on the van against the ledger, unloading, and the day's cash. */
class VanSettlementResource extends Resource
{
    protected static ?string $model = VanSettlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Distribution';

    protected static ?string $navigationLabel = 'Van settlements';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.view') && ModuleService::enabledAnywhere('distribution');
    }

    public static function canCreate(): bool
    {
        return false; // started from the van (Distribution → Vans → Start end-of-day settlement)
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('stock.view');

        return parent::getEloquentQuery()->with('van')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function infolist(Schema $schema): Schema
    {
        $m = fn ($v) => $v === null ? '—' : FinanceUi::money((int) $v);

        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Settlement'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => VanSettlement::STATUSES[$state] ?? $state),
                TextEntry::make('van.name')->label('Van')->belowContent(fn (VanSettlement $r) => $r->van?->vehicle_reg),
                TextEntry::make('business_date')->label('Day')->date('D j M Y'),
                TextEntry::make('rep_user_id')->label('Rep')->formatStateUsing(fn ($state) => User::whereKey($state)->value('name'))->placeholder('—'),
                TextEntry::make('sales_minor')->label('Sales')->formatStateUsing(fn ($state) => $m($state)),
                TextEntry::make('cash_expected_minor')->label('Cash expected')->formatStateUsing(fn ($state) => $m($state))->placeholder('Shift not closed yet'),
                TextEntry::make('cash_counted_minor')->label('Cash handed in')->formatStateUsing(fn ($state) => $m($state))
                    ->belowContent(fn (VanSettlement $r) => $r->cash_counted_minor === null ? null : 'Over / short: '.FinanceUi::money($r->cash_counted_minor - (int) $r->cash_expected_minor)),
                TextEntry::make('stock_variance_minor')->label('Stock difference (value)')->formatStateUsing(fn ($state, VanSettlement $r) => $r->status === 'posted' ? $m($state) : '—'),
                TextEntry::make('started_by_name')->label('Started by'),
                TextEntry::make('submitted_by_name')->label('Submitted by')->placeholder('—'),
                TextEntry::make('posted_by_name')->label('Settled by')->placeholder('—'),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Stock on the van')->schema([
                TextEntry::make('lines_table')->hiddenLabel()->html()->state(fn (VanSettlement $r) => new HtmlString(self::linesHtml($r))),
            ]),
        ]);
    }

    /** @return array{headings: list<string>, rows: list<list<string>>} */
    public static function lineRows(VanSettlement $r): array
    {
        $q = fn ($v) => $v === null ? '' : Qty::format(Qty::toMilli((string) $v));
        $rows = $r->lines()->with('product')->get()->sortBy(fn ($l) => $l->product->name)->map(fn ($l) => [$l->product->sku, $l->product->name, $q($l->expected_qty),
            $q($l->counted_qty), $l->counted_qty === null ? '' : Qty::format(Qty::toMilli((string) $l->counted_qty) - Qty::toMilli((string) $l->expected_qty)), $q($l->unload_qty),
            $l->counted_qty === null ? '' : Qty::format(Qty::toMilli((string) $l->counted_qty) - Qty::toMilli((string) $l->unload_qty))])->values()->all();

        return ['headings' => ['SKU', 'Product', 'Should be on van', 'Counted', 'Difference', 'Unload to warehouse', 'Stays on van'], 'rows' => $rows];
    }

    private static function linesHtml(VanSettlement $r): string
    {
        $t = self::lineRows($r);
        $line = 'border-top:1px solid color-mix(in srgb, currentColor 8%, transparent)';

        return '<table style="width:100%;font-size:.85rem;border-collapse:collapse"><tr>'.collect($t['headings'])->map(fn ($h) => '<th style="text-align:left;padding:4px 6px">'.e($h).'</th>')->implode('')
            .'</tr>'.collect($t['rows'])->map(fn ($row) => '<tr>'.collect($row)->map(fn ($v) => '<td style="padding:4px 6px;'.$line.'">'.e($v).'</td>')->implode('').'</tr>')->implode('').'</table>';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('business_date', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('business_date')->label('Day')->date('j M Y'),
                TextColumn::make('van.code')->label('Van'),
                TextColumn::make('sales_minor')->label('Sales')->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('cash')->label('Cash over / short')->state(fn (VanSettlement $r) => $r->cash_counted_minor === null ? '—' : FinanceUi::money($r->cash_counted_minor - (int) $r->cash_expected_minor)),
                TextColumn::make('stock_variance_minor')->label('Stock difference')->formatStateUsing(fn ($state, VanSettlement $r) => $r->status === 'posted' ? FinanceUi::money((int) $state) : '—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => VanSettlement::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['posted' => 'success', 'submitted' => 'warning', 'cancelled' => 'gray'][$state] ?? 'info'),
            ])
            ->filters([SelectFilter::make('status')->options(VanSettlement::STATUSES)]);
    }

    public static function settlementActions(): array
    {
        $svc = fn () => app(VanSettlementService::class);

        return [
            Action::make('count')->label('Enter counts')->icon(Heroicon::OutlinedPencilSquare)->color('primary')->modalWidth('5xl')
                ->visible(fn (VanSettlement $record) => $record->status === 'counting')
                ->fillForm(fn (VanSettlement $record) => ['lines' => $record->lines()->with('product')->get()->sortBy(fn ($l) => $l->product->name)->map(fn ($l) => [
                    'product_id' => $l->product_id, 'item' => "{$l->product->sku} · {$l->product->name} (should be ".Qty::format(Qty::toMilli((string) $l->expected_qty)).')',
                    'counted' => $l->counted_qty === null ? null : rtrim(rtrim((string) $l->counted_qty, '0'), '.'), 'unload' => rtrim(rtrim((string) $l->unload_qty, '0'), '.') ?: '0'])->values()->all()])
                ->schema([
                    Repeater::make('lines')->hiddenLabel()->columns(4)->addable(false)->deletable(false)->reorderable(false)->schema([
                        Hidden::make('product_id'),
                        TextInput::make('item')->disabled()->dehydrated(false)->columnSpan(2),
                        TextInput::make('counted')->label('Counted on van')->numeric()->minValue(0),
                        TextInput::make('unload')->label('Unload')->numeric()->minValue(0)->default('0'),
                    ]),
                ])
                ->action(fn (VanSettlement $record, array $data) => Erp::run(fn ($actor) => $svc()->count($actor, $record,
                    collect($data['lines'])->mapWithKeys(fn ($l) => [$l['product_id'] => ['counted' => $l['counted'] ?? null, 'unload' => $l['unload'] ?? '0']])->all()), 'Counts saved')),
            Action::make('submit')->label('Submit')->icon(Heroicon::OutlinedPaperAirplane)->color('warning')
                ->visible(fn (VanSettlement $record) => $record->status === 'counting')
                ->schema([Textarea::make('note')->label('Note (e.g. why something is short)')])
                ->action(fn (VanSettlement $record, array $data) => Erp::run(fn ($actor) => $svc()->submit($actor, $record, $data['note'] ?? null), 'Submitted')),
            Action::make('post')->label('Approve and settle')->icon(Heroicon::OutlinedCheckCircle)->color('success')->requiresConfirmation()
                ->modalDescription('Shortages and surpluses are booked on the van, and the unloaded stock goes back to the warehouse.')
                ->visible(fn (VanSettlement $record) => $record->status === 'submitted')
                ->action(fn (VanSettlement $record) => Erp::run(fn ($actor) => $svc()->post($actor, $record), 'Settled')),
            Action::make('cancel')->color('danger')->visible(fn (VanSettlement $record) => in_array($record->status, ['counting', 'submitted'], true))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (VanSettlement $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
            Action::make('sheet')->label('Settlement sheet (PDF)')->icon(Heroicon::OutlinedDocumentArrowDown)->color('gray')
                ->action(function (VanSettlement $record) {
                    $t = self::lineRows($record);
                    $sheet = ['title' => "Van settlement {$record->number}", 'headings' => $t['headings'], 'rows' => $t['rows'],
                        'note' => sprintf('%s %s · %s · Sales %s · Cash expected %s · handed in %s. Rep: ______________  Storekeeper: ______________  Supervisor: ______________',
                            $record->van->code, $record->van->vehicle_reg, $record->business_date->format('j M Y'), FinanceUi::money($record->sales_minor),
                            $record->cash_expected_minor === null ? '—' : FinanceUi::money($record->cash_expected_minor), $record->cash_counted_minor === null ? '—' : FinanceUi::money($record->cash_counted_minor))];

                    return Erp::run(fn ($actor) => app(Exporter::class)->pdf($actor, $sheet['title'], [$sheet], [], 'portrait'));
                }),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListVanSettlements::route('/'), 'view' => ViewVanSettlement::route('/{record}')];
    }
}
