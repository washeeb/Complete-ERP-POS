<?php

namespace App\Filament\Pages;

use App\Domain\Grocery\MarkdownService;
use App\Domain\Inventory\StockBatches;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Models\Markdown;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/** Batches on hand by expiry; near-expiry markdowns to apply; expired stock to write off; stickers to print. */
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
        $this->warehouse = $this->warehouse ?: (string) array_key_first($this->warehouses());
    }

    public function canMarkDown(): bool
    {
        return ($w = Warehouse::find($this->warehouse)) && Erp::can('grocery.markdowns', $w);
    }

    public function suggestions(): array
    {
        $rows = $this->warehouse ? app(MarkdownService::class)->suggestions(Erp::user()->tenant_id, [$this->warehouse]) : [];
        foreach ($rows as $r) {
            $this->percent[$r->batch_id] ??= (string) ($r->suggested_bp / 100);
        }

        return $rows;
    }

    public function expired()
    {
        return DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.warehouse_id', $this->warehouse)->where('l.on_hand', '>', 0)->where('b.expires_on', '<', StockBatches::today())
            ->orderBy('b.expires_on')->get(['l.batch_id', 'b.batch_no', 'b.expires_on', 'l.on_hand', 'p.name', 'p.sku']);
    }

    public function batches()
    {
        return DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.warehouse_id', $this->warehouse)->where('l.on_hand', '<>', 0)
            ->orderByRaw('b.expires_on ASC NULLS LAST')->orderBy('p.name')->limit(500)->get(['b.batch_no', 'b.expires_on', 'l.on_hand', 'p.name', 'p.sku']);
    }

    public function markdowns()
    {
        return Markdown::with('product', 'batch')->where('warehouse_id', $this->warehouse)->where('status', 'active')->orderBy('valid_until')->get();
    }

    public function apply(string $batchId): void
    {
        $this->attempt(fn ($actor) => app(MarkdownService::class)->apply($actor, Warehouse::findOrFail($this->warehouse), $batchId,
            (int) round((float) ($this->percent[$batchId] ?? 0) * 100)), 'Markdown applied — print the stickers');
    }

    public function writeOff(string $batchId): void
    {
        $this->attempt(fn ($actor) => app(MarkdownService::class)->writeOffExpired($actor, Warehouse::findOrFail($this->warehouse), $batchId), 'Written off as expired');
    }

    public function withdraw(string $id): void
    {
        $this->attempt(fn ($actor) => app(MarkdownService::class)->withdraw($actor, Markdown::findOrFail($id), 'Withdrawn from the shelf'), 'Markdown withdrawn');
    }

    private function attempt(callable $call, string $ok): void
    {
        try {
            $call(Erp::actor());
        } catch (DomainException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title($ok)->send();
    }
}
