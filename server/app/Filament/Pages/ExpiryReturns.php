<?php

namespace App\Filament\Pages;

use App\Domain\Grocery\MarkdownService;
use App\Domain\Inventory\ExpiryService;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\PagedRecords;
use App\Filament\Support\QuickCreate;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Expired and near-expiry stock in one list: tick the batches, then dispose of them, send them back to the supplier
 * or mark them down. Disposals and returns wait for a second person to approve them.
 */
class ExpiryReturns extends Page implements HasTable
{
    use InteractsWithTable;
    use PagedRecords;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxXMark;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Expiry & returns';

    protected static ?string $title = 'Expiry & returns';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('stock.expiry');
    }

    public function getSubheading(): ?string
    {
        return 'Tick the batches, then choose Dispose, Return to supplier or Mark down (one warehouse at a time). A disposal or return waits for someone else with '
            .'"approve stock adjustments" to approve it; the stock leaves only then.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('register')->label('Disposal register (print)')->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->schema([
                    Select::make('warehouse')->label('Store')->required()->options(fn () => Fields::warehouses('stock.expiry'))
                        ->default(fn () => array_key_first(Fields::warehouses('stock.expiry'))),
                    DatePicker::make('from')->required()->default(now()->startOfMonth()),
                    DatePicker::make('to')->required()->default(now()),
                ])
                ->action(fn (array $data) => $this->redirect(route('print.disposal-register', ['warehouse' => $data['warehouse'], 'from' => $data['from'], 'to' => $data['to']]))),
            Action::make('returns')->label('Returns to suppliers')->icon(Heroicon::OutlinedTruck)->color('gray')->url(SupplierReturnResource::getUrl('index')),
        ];
    }

    private function alertDays(): int
    {
        return (int) app(Settings::class)->get('stock.expiry_alert_days', Erp::user()->tenant_id);
    }

    /** Every batch for the chosen filters and search (shown a page at a time by PagedRecords). */
    protected function allRows(?array $filters, ?string $search): Collection
    {
        $warehouses = array_keys(Fields::warehouses('stock.expiry'));
        $wh = $filters['warehouse_id']['value'] ?? null;
        $status = $filters['status']['value'] ?? 'soon';
        $rows = app(ExpiryService::class)->batches(Erp::user()->tenant_id, [
            'warehouse_ids' => $wh && in_array($wh, $warehouses, true) ? [$wh] : $warehouses,
            'expired' => $status === 'expired',
            'within_days' => match ($status) {
                'soon' => $this->alertDays(), '30' => 30, default => null
            },
            'controlled' => (bool) ($filters['controlled']['isActive'] ?? false),
            'supplier_id' => $filters['supplier_id']['value'] ?? null,
            'returnable' => (bool) ($filters['returnable']['isActive'] ?? false),
        ]);
        $term = mb_strtolower(trim((string) $search));

        return collect($rows)->when($term !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower("{$r['name']} {$r['sku']} {$r['batch_no']}"), $term)))
            ->keyBy('key');

    }

    public function table(Table $table): Table
    {
        return $this->paged($table)
            ->columns([
                TextColumn::make('name')->label('Product')->searchable()->description(fn (array $record) => $record['sku'].($record['controlled'] ? ' · controlled' : '')),
                TextColumn::make('batch_no')->label('Batch'),
                TextColumn::make('expires_on')->label('Expires')->date('j M Y'),
                TextColumn::make('days_left')->label('Days left')->badge()
                    ->formatStateUsing(fn (int $state) => $state < 0 ? 'expired '.abs($state).' d ago' : ($state === 0 ? 'today' : "{$state} d"))
                    ->color(fn (int $state) => $state < 0 ? 'danger' : ($state <= 30 ? 'warning' : 'gray')),
                TextColumn::make('qty')->label('On hand')->formatStateUsing(fn ($state, array $record) => Fields::qty($state, $record['unit'])),
                TextColumn::make('value_minor')->label('Value (cost)')->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('warehouse')->label('Store'),
                TextColumn::make('supplier')->label('Supplier')->placeholder('—')
                    ->description(fn (array $record) => $record['returnable'] ? 'can still go back' : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('Show')->default('soon')->selectablePlaceholder(false)
                    ->options(fn () => ['expired' => 'Expired', '30' => 'Expired or expiring within 30 days', 'soon' => 'Expired or expiring within '.$this->alertDays().' days',
                        'all' => 'Every batch with an expiry date']),
                SelectFilter::make('warehouse_id')->label('Store')->options(fn () => Fields::warehouses('stock.expiry')),
                SelectFilter::make('supplier_id')->label('Supplier')->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('controlled')->label('Controlled drugs only'),
                Filter::make('returnable')->label('Only what the supplier still takes back'),
            ])
            ->toolbarActions([
                BulkAction::make('dispose')->label('Dispose')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->modalHeading('Dispose of the ticked batches')
                    ->modalDescription('The whole batch is removed once someone else approves it. Keep the packs aside, marked "not for sale", until then.')
                    ->schema(fn () => $this->disposalForm())
                    ->action(function (Collection $records, array $data) {
                        $a = Erp::run(fn ($actor) => app(ExpiryService::class)->dispose($actor, $records->pluck('key')->all(), [
                            'reason' => $data['reason'] ?? 'expired', 'method' => $data['method'] ?? null, 'disposed_on' => $data['disposed_on'] ?? null,
                            'witness' => $data['witness'] ?? null, 'certificate_path' => $data['certificate_path'] ?? null, 'note' => $data['note'] ?? null]));
                        Notification::make()->success()->title("Disposal {$a->number} prepared")->body('It now waits for approval by someone else.')->send();
                        $this->redirect(StockAdjustmentResource::getUrl('view', ['record' => $a]));
                    }),
                BulkAction::make('return')->label('Return to supplier')->icon(Heroicon::OutlinedTruck)->color('warning')
                    ->modalHeading('Return the ticked batches to a supplier')
                    ->schema([
                        QuickCreate::on(Select::make('supplier_id')->label('Supplier')->required()->searchable()
                            ->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn () => collect($this->ticked())->pluck('supplier_id')->filter()->first()), 'supplier'),
                        Select::make('settlement')->label('The supplier gives')->required()->options(SupplierReturn::SETTLEMENTS)->default('credit_note'),
                        Select::make('reason')->label('Reason')->required()->options(SupplierReturn::REASONS)
                            ->default(fn () => collect($this->ticked())->every(fn ($r) => $r['days_left'] < 0) ? 'expired' : 'near_expiry'),
                        Textarea::make('note')->label('Note (e.g. the supplier rep\'s name, collection date)')->maxLength(500),
                    ])
                    ->action(function (Collection $records, array $data) {
                        $r = Erp::run(fn ($actor) => app(ExpiryService::class)->prepareReturn($actor, $records->pluck('key')->all(), $data['supplier_id'],
                            $data['settlement'], $data['reason'], $data['note'] ?? null));
                        Notification::make()->success()->title("Return {$r->number} prepared")->body('It now waits for approval by someone else.')->send();
                        $this->redirect(SupplierReturnResource::getUrl('view', ['record' => $r]));
                    }),
                BulkAction::make('markdown')->label('Mark down')->icon(Heroicon::OutlinedReceiptPercent)
                    ->visible(fn () => Erp::canAnywhere('grocery.markdowns'))
                    ->modalHeading('Mark down the ticked batches')->modalDescription('Batches that have already expired are skipped. Print the stickers afterwards from Batches & expiry.')
                    ->schema([TextInput::make('percent')->label('% off')->numeric()->minValue(1)->maxValue(90)->required()->default(30)])
                    ->action(function (Collection $records, array $data) {
                        $done = Erp::run(function ($actor) use ($records, $data) {
                            $n = 0;
                            foreach ($records->filter(fn ($r) => $r['days_left'] >= 0) as $r) {
                                app(MarkdownService::class)->apply($actor, Warehouse::findOrFail($r['warehouse_id']), $r['batch_id'], (int) round((float) $data['percent'] * 100));
                                $n++;
                            }

                            return $n;
                        });
                        Notification::make()->success()->title("{$done} batch(es) marked down")->body('Print the stickers from Stock → Batches & expiry.')->send();
                    }),
            ])
            ->emptyStateHeading('Nothing here')
            ->emptyStateDescription('No batch in stock matches: nothing expired or expiring in this period. Batches need an expiry date (received with one, or on the stock sheet).');
    }

    /** @return list<array<string, mixed>> the ticked batches (to suggest the supplier and reason) */
    private function ticked(): array
    {
        $keys = array_map('strval', $this->selectedTableRecords);
        $warehouses = array_unique(array_map(fn ($k) => explode('|', $k)[0], $keys));

        return $keys ? array_values(array_filter(app(ExpiryService::class)->batches(Erp::user()->tenant_id, ['warehouse_ids' => $warehouses]),
            fn ($r) => in_array($r['key'], $keys, true))) : [];
    }

    /** What the company asks to be recorded (Settings → Stock → evidence); controlled drugs always need a witness. */
    private function disposalForm(): array
    {
        $evidence = (string) app(Settings::class)->get('stock.disposal_evidence', Erp::user()->tenant_id);

        return array_values(array_filter([
            Select::make('reason')->label('Reason')->required()->options(['expired' => 'Expired', 'damaged' => 'Damaged'])->default('expired'),
            $evidence !== 'reason' ? Select::make('method')->label('How it is disposed of')->required()->options(ExpiryService::DISPOSAL_METHODS) : null,
            DatePicker::make('disposed_on')->label('Disposed of on')->default(now())->required(),
            TextInput::make('witness')->label('Witness (full name and role)')->maxLength(120)->required($evidence !== 'reason')
                ->helperText('Required for controlled drugs, whatever the setting.'),
            $evidence === 'method_witness_certificate' ? FileUpload::make('certificate_path')->label('Disposal certificate (photo or PDF)')->required()
                ->disk('local')->directory('disposal-certificates')->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])->maxSize(5120) : null,
            Textarea::make('note')->label('Note')->maxLength(500),
        ]));
    }
}
