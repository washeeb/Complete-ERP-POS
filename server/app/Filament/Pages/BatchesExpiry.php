<?php

namespace App\Filament\Pages;

use App\Domain\Grocery\MarkdownService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Inventory\StockBatches;
use App\Domain\Support\DomainException;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Filament\Support\Fields;
use App\Models\Markdown;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Batches on hand by expiry; near-expiry markdowns to apply; expired stock to take off the shelf (disposed of from Expiry &
 * returns); stickers to print; correcting a batch's number or expiry, and adding or moving a batch (owner request 2026-10-22).
 */
class BatchesExpiry extends Page
{
    use ExportsPage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Batches & expiry';

    protected static ?string $title = 'Batches & expiry';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.batches-expiry';

    #[Url]
    public string $warehouse = '';

    /** Grocery → Markdowns shows the same page without the full batch list. */
    public bool $markdownsOnly = false;

    /** @var array<string, string> batch id → percent typed */
    public array $percent = [];

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('stock.view') && DB::table('products')->where('track_batches', true)->exists();
    }

    public function warehouses(): array
    {
        $ids = Erp::branchIds('stock.view');

        return Warehouse::when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all();
    }

    public function mount(): void
    {
        $this->updatedWarehouse();
    }

    /** Only a store the person may see (the address bar or a changed request cannot show another branch's batches). */
    public function updatedWarehouse(): void
    {
        $mine = $this->warehouses();
        $this->warehouse = array_key_exists($this->warehouse, $mine) ? $this->warehouse : (string) array_key_first($mine);
    }

    public function canMarkDown(): bool
    {
        return ($w = Warehouse::find($this->warehouse)) && Erp::can('grocery.markdowns', $w);
    }

    public function suggestions(): array
    {
        $rows = $this->warehouse !== '' ? app(MarkdownService::class)->suggestions(Erp::user()->tenant_id, [$this->warehouse]) : [];
        foreach ($rows as $r) {
            $this->percent[$r->batch_id] ??= (string) ($r->suggested_bp / 100);
        }

        return $rows;
    }

    public function expired()
    {
        if ($this->warehouse === '') {
            return collect(); // no store this person can see
        }

        return DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.warehouse_id', $this->warehouse)->where('l.on_hand', '>', 0)->where('b.expires_on', '<', StockBatches::today())
            ->orderBy('b.expires_on')->get(['l.batch_id', 'b.batch_no', 'b.expires_on', 'l.on_hand', 'p.name', 'p.sku']);
    }

    public function batches()
    {
        if ($this->warehouse === '') {
            return collect();
        }

        return DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.warehouse_id', $this->warehouse)->where('l.on_hand', '<>', 0)
            ->orderByRaw('b.expires_on ASC NULLS LAST')->orderBy('p.name')->limit(500)->get(['l.batch_id', 'b.batch_no', 'b.expires_on', 'l.on_hand', 'p.name', 'p.sku']);
    }

    public function markdowns()
    {
        if ($this->warehouse === '') {
            return collect();
        }

        return Markdown::with('product', 'batch')->where('warehouse_id', $this->warehouse)->where('status', 'active')->orderBy('valid_until')->get();
    }

    public function apply(string $batchId): void
    {
        $this->attempt(fn ($actor) => app(MarkdownService::class)->apply($actor, Warehouse::findOrFail($this->warehouse), $batchId,
            (int) round((float) ($this->percent[$batchId] ?? 0) * 100)), 'Markdown applied — print the stickers');
    }

    public function withdraw(string $id): void
    {
        $this->attempt(fn ($actor) => app(MarkdownService::class)->withdraw($actor, Markdown::findOrFail($id), 'Withdrawn from the shelf'), 'Markdown withdrawn');
    }

    public function canCorrect(): bool
    {
        return Erp::canAnywhere('stock.adjust');
    }

    /** Correct a batch's expiry date (or add a missing one). */
    public function correctAction(): Action
    {
        return Action::make('correct')->label('Correct expiry')->link()->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn () => $this->canCorrect())
            ->modalHeading(fn (array $arguments) => 'Expiry of batch '.(StockBatch::find($arguments['batch'] ?? null)?->batch_no ?? ''))
            ->modalDescription('For an expiry date typed wrongly, or missing. The stock stays in this batch. A wrong batch number: use Add or move a batch (top right) to move the stock to the right batch.')
            ->fillForm(fn (array $arguments) => ['expires_on' => StockBatch::find($arguments['batch'] ?? null)?->expires_on?->toDateString()])
            ->schema([
                DatePicker::make('expires_on')->label('Expiry date')->required(),
                TextInput::make('reason')->label('Why')->required()->maxLength(255)->placeholder('Date mistyped at receiving'),
            ])
            ->action(function (array $arguments, array $data) {
                $this->attempt(fn ($actor) => app(InventoryService::class)->correctBatch($actor, StockBatch::findOrFail($arguments['batch']), $data['expires_on'] ?? null, $data['reason']),
                    'Expiry date corrected');
            });
    }

    /**
     * A batch the system does not know (stock found, or entered without its batch), or stock recorded under the wrong
     * batch: a draft stock adjustment with the lines, opened for checking and posting under the usual approval rules.
     */
    public function addBatchAction(): Action
    {
        return Action::make('addBatch')->label('Add or move a batch')->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn () => ($w = Warehouse::find($this->warehouse)) && Erp::can('stock.adjust', $w))
            ->modalDescription('Makes a draft stock adjustment for this store. Check it and post it as usual (a manager approves it above the limit).')
            ->schema([
                Radio::make('kind')->label('What happened')->required()->default('found')->live()->options([
                    'found' => 'Stock of a batch the system does not have (found, or never entered)',
                    'move' => 'Stock recorded under the wrong batch number: move it to the right one',
                ]),
                Select::make('product_id')->label('Product')->required()->searchable()->live()
                    ->getSearchResultsUsing(fn (string $search) => Product::where('track_batches', true)->where('is_active', true)
                        ->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"))->orderBy('name')->limit(30)
                        ->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} · {$p->name}"])->all())
                    ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? "{$p->sku} · {$p->name}" : null),
                Select::make('from_batch_id')->label('Take it from batch')->required()->visible(fn (Get $get) => $get('kind') === 'move')
                    ->options(fn (Get $get) => DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
                        ->where('l.warehouse_id', $this->warehouse)->where('l.product_id', $get('product_id'))->where('l.on_hand', '>', 0)->orderBy('b.batch_no')
                        ->get(['b.id', 'b.batch_no', 'b.expires_on', 'l.on_hand'])
                        ->mapWithKeys(fn ($b) => [$b->id => "{$b->batch_no}".($b->expires_on ? ' · expires '.Carbon::parse($b->expires_on)->format('j M Y') : '').' · '.Fields::qty((string) $b->on_hand).' on hand'])->all()),
                TextInput::make('batch_no')->label(fn (Get $get) => $get('kind') === 'move' ? 'Right batch number' : 'Batch number')->required()->maxLength(60),
                DatePicker::make('expires_on')->label('Expiry date')->required()
                    ->helperText('For a batch the product already has, its date stays as it is; correct it with Correct on the list.'),
                TextInput::make('qty')->label('Quantity (base unit)')->numeric()->minValue(0.001)->required(),
                TextInput::make('unit_cost')->label('Cost per unit (optional)')->numeric()->minValue(0)->visible(fn (Get $get) => $get('kind') === 'found')
                    ->helperText('Leave empty to use the average cost.'),
                TextInput::make('note')->label('Note')->maxLength(255),
            ])
            ->action(function (array $data) {
                $a = null;
                $done = $this->attempt(function ($actor) use ($data, &$a) {
                    $svc = app(StockAdjustmentService::class);
                    $move = $data['kind'] === 'move';
                    DB::transaction(function () use ($svc, $actor, $data, $move, &$a) {
                        $a = $svc->create($actor, Warehouse::findOrFail($this->warehouse), ['reason_code' => $move ? 'correction' : 'found',
                            'note' => $move ? 'Stock moved to the right batch' : 'Batch added from Batches & expiry']);
                        if ($move) {
                            $svc->addLine($actor, $a, ['product_id' => $data['product_id'], 'mode' => 'remove', 'qty' => (string) $data['qty'],
                                'batch_no' => StockBatch::findOrFail($data['from_batch_id'])->batch_no, 'note' => $data['note'] ?? null]);
                        }
                        $svc->addLine($actor, $a, ['product_id' => $data['product_id'], 'mode' => 'add', 'qty' => (string) $data['qty'], 'batch_no' => mb_strtoupper(trim($data['batch_no'])),
                            'expires_on' => $data['expires_on'], 'unit_cost' => $move ? null : ($data['unit_cost'] ?? null), 'note' => $data['note'] ?? null]);
                    });
                }, 'Draft stock adjustment made — check and post it');
                if ($done && $a) {
                    $this->redirect(StockAdjustmentResource::getUrl('view', ['record' => $a]));
                }
            });
    }

    protected function getHeaderActions(): array
    {
        return [$this->addBatchAction()];
    }

    private function attempt(callable $call, string $ok): bool
    {
        try {
            $call(Erp::actor());
        } catch (DomainException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();

            return false;
        }
        Notification::make()->success()->title($ok)->send();

        return true;
    }
}
