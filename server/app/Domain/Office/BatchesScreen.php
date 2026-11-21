<?php

namespace App\Domain\Office;

use App\Domain\Grocery\MarkdownService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Support\Actor;
use App\Filament\Pages\BatchesExpiry;
use App\Filament\Pages\ExpiryReturns;
use App\Filament\Pages\GroceryMarkdowns;
use App\Filament\Pages\ImportData;
use App\Filament\Pages\PharmacyMarkdowns;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\Markdown;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Batches & expiry in the new back office (release 7 of the rewrite), as the current page (whose queries it uses):
 * for one store, the batches expiring soon with the suggested markdown (Apply, then print the stickers), the markdowns
 * on the shelf now (Withdraw), what has expired, every batch on hand, Correct expiry, and Add or move a batch (a draft
 * stock adjustment, posted as usual). With a kind (grocery or pharmacy, part 13) it is that industry's Near-expiry
 * markdowns page: the same work for the stores that run it, without the full batch list, for the people that page lets in.
 */
final class BatchesScreen
{
    /** The industries' markdown pages (Grocery → Markdowns, Pharmacy → Near-expiry markdowns). */
    public const KINDS = ['grocery' => GroceryMarkdowns::class, 'pharmacy' => PharmacyMarkdowns::class];

    public function __construct(private readonly User $user, private readonly ?string $kind = null) {}

    public static function canView(?string $kind = null): bool
    {
        return $kind === null ? BatchesExpiry::canAccess() : (isset(self::KINDS[$kind]) && self::KINDS[$kind]::canAccess());
    }

    private function page(?string $warehouse): BatchesExpiry
    {
        $class = $this->kind === null ? BatchesExpiry::class : self::KINDS[$this->kind];
        $page = new $class;
        $page->warehouse = (string) $warehouse;
        $page->updatedWarehouse();

        return $page;
    }

    private static function day(?string $d, string $format = 'j M Y'): ?string
    {
        return $d ? Carbon::parse($d)->format($format) : null;
    }

    private static function batch(object $b): array
    {
        return ['batch_id' => $b->batch_id, 'product' => $b->name, 'sku' => $b->sku, 'batch_no' => $b->batch_no, 'expires_on' => $b->expires_on,
            'expires' => self::day($b->expires_on), 'on_hand' => Fields::qty((string) $b->on_hand)];
    }

    public function show(?string $warehouse): array
    {
        $page = $this->page($warehouse);
        $w = Warehouse::find($page->warehouse);
        $markDown = $page->canMarkDown();
        $markdowns = $markDown ? $page->markdowns() : collect();

        return [
            'kind' => $this->kind, 'warehouse' => $page->warehouse, 'warehouses' => PurchaseOrdersScreen::choices($page->warehouses()),
            'can_mark_down' => $markDown, 'can_correct' => $page->canCorrect(), 'can_add' => $w && Erp::can('stock.adjust', $w),
            'suggestions' => $markDown ? array_map(fn ($s) => ['batch_id' => $s->batch_id, 'product' => $s->name, 'sku' => $s->sku, 'batch_no' => $s->batch_no,
                'expires' => self::day($s->expires_on, 'j M'), 'days_left' => $s->days_left, 'on_hand' => Fields::qty((string) $s->on_hand),
                'percent' => (string) ($s->suggested_bp / 100)], $page->suggestions()) : [],
            'markdowns' => $markdowns->map(fn (Markdown $m) => ['id' => $m->id, 'product' => $m->product?->name, 'batch_no' => $m->batch?->batch_no, 'percent' => $m->percent_bp / 100,
                'price' => FinanceUi::money((int) $m->price_minor), 'until' => $m->valid_until?->format('j M'), 'code' => $m->code])->all(),
            'labels' => $markdowns->isNotEmpty() ? route('print.markdown-labels', ['ids' => $markdowns->pluck('id')->implode(',')], false) : null,
            'expired' => $page->expired()->map(fn ($b) => self::batch($b))->all(),
            'batches' => $page->markdownsOnly ? [] : $page->batches()->map(fn ($b) => self::batch($b))->all(),
            'expiry_returns' => ExpiryReturns::canAccess() ? ExpiryReturns::getUrl(isAbsolute: false) : null,
            'sheet' => ImportData::getUrl(['type' => 'opening_batches'], isAbsolute: false),
        ];
    }

    /** Products with batches, for Add or move a batch (by name or SKU). */
    public function products(string $q): array
    {
        $q = trim($q);

        return Product::where('track_batches', true)->where('is_active', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%")))
            ->orderBy('name')->limit(30)->get()->map(fn ($p) => ['id' => $p->id, 'label' => "{$p->sku} · {$p->name}"])->all();
    }

    /** A product's batches with stock in the store, to move stock from. */
    public function productBatches(string $warehouse, string $product): array
    {
        return DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
            ->where('l.warehouse_id', $warehouse)->where('l.product_id', $product)->where('l.on_hand', '>', 0)->orderBy('b.batch_no')
            ->get(['b.id', 'b.batch_no', 'b.expires_on', 'l.on_hand'])
            ->map(fn ($b) => ['id' => $b->id, 'label' => $b->batch_no.($b->expires_on ? ' · expires '.Carbon::parse($b->expires_on)->format('j M Y') : '').' · '.Fields::qty((string) $b->on_hand).' on hand'])
            ->all();
    }

    public function apply(Actor $actor, string $warehouse, string $batch, string $percent): Markdown
    {
        return app(MarkdownService::class)->apply($actor, Warehouse::findOrFail($warehouse), $batch, (int) round((float) $percent * 100));
    }

    public function withdraw(Actor $actor, string $id): Markdown
    {
        return app(MarkdownService::class)->withdraw($actor, Markdown::findOrFail($id), 'Withdrawn from the shelf');
    }

    public function correct(Actor $actor, string $batch, ?string $expiresOn, string $reason): StockBatch
    {
        return app(InventoryService::class)->correctBatch($actor, StockBatch::findOrFail($batch), $expiresOn, $reason);
    }

    /**
     * Add or move a batch: a draft stock adjustment for the store — stock of a batch the system does not have ("found"),
     * or stock under the wrong batch number moved to the right one ("move") — to check and post as usual.
     *
     * @param  array{kind: string, product_id: string, from_batch_id?: ?string, batch_no: string, expires_on: string, qty: string, unit_cost?: ?string, note?: ?string}  $d
     */
    public function addBatch(Actor $actor, string $warehouse, array $d): StockAdjustment
    {
        $svc = app(StockAdjustmentService::class);
        $move = $d['kind'] === 'move';
        $a = $svc->create($actor, Warehouse::findOrFail($warehouse), ['reason_code' => $move ? 'correction' : 'found',
            'note' => $move ? 'Stock moved to the right batch' : 'Batch added from Batches & expiry']);
        if ($move) {
            $svc->addLine($actor, $a, ['product_id' => $d['product_id'], 'mode' => 'remove', 'qty' => (string) $d['qty'],
                'batch_no' => StockBatch::findOrFail($d['from_batch_id'])->batch_no, 'note' => $d['note'] ?? null]);
        }
        $svc->addLine($actor, $a, ['product_id' => $d['product_id'], 'mode' => 'add', 'qty' => (string) $d['qty'], 'batch_no' => mb_strtoupper(trim($d['batch_no'])),
            'expires_on' => $d['expires_on'], 'unit_cost' => $move ? null : ($d['unit_cost'] ?? null), 'note' => $d['note'] ?? null]);

        return $a;
    }
}
