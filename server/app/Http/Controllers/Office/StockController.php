<?php

namespace App\Http\Controllers\Office;

use App\Domain\Imports\Importers\StockRows;
use App\Domain\Inventory\InventoryService;
use App\Domain\Office\StockScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Support\Fields;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Support\Exports\Exporter;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Stock on hand and the stock ledger in the new back office (App\Domain\Office\StockScreen), with the current screens' rules. */
class StockController extends Controller
{
    public function levels(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new StockScreen($request->user()))->levels($request->query()));
    }

    public function levelsOptions(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new StockScreen($request->user()))->levelsOptions());
    }

    public function movements(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new StockScreen($request->user()))->movements($request->query()));
    }

    public function movementsOptions(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new StockScreen($request->user()))->movementsOptions());
    }

    /** Products for the adjustment form, with their units. */
    public function find(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json(['results' => (new StockScreen($request->user()))->find(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    /**
     * Stock sheet (before a count): a warehouse's stock standing to print or fill in Excel, with or without the system
     * quantities (a blind count), as on the current screen.
     */
    public function sheet(Request $request, Exporter $exporter): StreamedResponse
    {
        $this->allowed();
        $d = $request->validate(['warehouse' => ['required', 'uuid'], 'category' => ['nullable', 'uuid'], 'show_qty' => ['required', 'boolean'],
            'format' => ['required', Rule::in(['pdf', 'csv'])], 'zero' => ['required', 'boolean']]);
        abort_unless(isset(Fields::warehouses('stock.view')[$d['warehouse']]), 403, 'You cannot see this warehouse.');
        $warehouse = Warehouse::findOrFail($d['warehouse']);
        $show = (bool) $d['show_qty'];
        $rows = [];
        foreach (StockRows::standing($warehouse, $d['category'] ?? null, (bool) $d['zero']) as $s) {
            $p = $s['product'];
            $rows[] = [$p->sku, $s['barcode'], $p->name, $p->category, $p->unit, $show ? $s['on_hand'] : '', '', ''];
        }
        $sheet = ['title' => "Stock sheet {$warehouse->code}", 'headings' => ['SKU', 'Barcode', 'Product', 'Category', 'Unit', $show ? 'System qty' : 'System qty (hidden)', 'Counted', 'Difference'],
            'rows' => $rows, 'note' => "{$warehouse->code} · {$warehouse->name} · stock standing at ".now()->format('j M Y H:i').' · Counted by: ______________  Checked by: ______________', 'numeric' => [5, 6, 7]];
        $actor = Actor::user($request->user(), 'web');

        return $d['format'] === 'csv' ? $exporter->csv($actor, $sheet['title'], [$sheet], [$warehouse->code]) : $exporter->pdf($actor, $sheet['title'], [$sheet], [$warehouse->code], 'portrait');
    }

    /** Opening stock or an adjustment: one row in the stock ledger with the person's name and reason; nothing is overwritten. */
    public function adjust(Request $request, InventoryService $inventory): JsonResponse
    {
        $this->allowed();
        $d = $request->validate([
            'type' => ['required', Rule::in(['opening', 'adjustment'])], 'warehouse_id' => ['required', 'uuid'], 'product_id' => ['required', 'uuid'],
            'product_uom_id' => ['required', 'uuid'], 'qty' => ['required', 'string', 'max:20'],
            'reason' => ['required_if:type,adjustment', 'nullable', Rule::in(array_keys(InventoryService::ADJUSTMENT_REASONS))],
            'note' => ['nullable', 'string', 'max:200'], 'batch_no' => ['nullable', 'string', 'max:60'], 'expires_on' => ['nullable', 'date'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        $product = Product::findOrFail($d['product_id']);
        $unit = ProductUom::where('product_id', $product->id)->findOrFail($d['product_uom_id']);
        $warehouse = Warehouse::findOrFail($d['warehouse_id']);
        try {
            $m = DB::transaction(fn () => $inventory->adjust(Actor::user($request->user(), 'web'), $warehouse, $product, $unit, (string) $d['qty'], $d['type'],
                $d['reason'] ?? '', $d['note'] ?? null, isset($d['unit_cost']) ? (string) $d['unit_cost'] : null, $d['batch_no'] ?? null, $d['expires_on'] ?? null));
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You cannot change stock in this warehouse.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $m->id, 'qty' => $m->qty_base]);
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(StockScreen::canView(), 403, 'You cannot see stock.');
    }
}
