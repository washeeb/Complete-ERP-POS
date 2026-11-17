<?php

namespace App\Http\Controllers\Office;

use App\Domain\Imports\ImportService;
use App\Domain\Inventory\StockCountService;
use App\Domain\Office\CountsScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Filament\Support\Fields;
use App\Http\Controllers\Controller;
use App\Models\Bin;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockCount;
use App\Models\Warehouse;
use App\Support\Exports\Exporter;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Stock counts in the new back office (App\Domain\Office\CountsScreen), through StockCountService. */
class CountsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new CountsScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new CountsScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new CountsScreen($request->user()))->show($this->find($id)));
    }

    /** Start a count of a warehouse (or one bin of it), blind or with the expected figures shown. */
    public function start(Request $request, StockCountService $svc): JsonResponse
    {
        $this->allowed();
        $d = $request->validate(['warehouse_id' => ['required', 'uuid'], 'bin_id' => ['nullable', 'uuid'], 'blind' => ['boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        abort_unless(isset(Fields::warehouses('stock.count')[$d['warehouse_id']]), 403, 'You cannot count in this warehouse.');
        $warehouse = Warehouse::findOrFail($d['warehouse_id']);
        $bin = filled($d['bin_id'] ?? null) ? Bin::where('warehouse_id', $warehouse->id)->findOrFail($d['bin_id']) : null;

        return $this->act($request, fn (Actor $actor) => ['id' => $svc->start($actor, $warehouse, $bin, (bool) ($d['blind'] ?? true), null, $d['note'] ?? null)->id]);
    }

    /** Counted quantities: several products at once, each in the unit it was counted in. */
    public function record(Request $request, string $id, StockCountService $svc): JsonResponse
    {
        $this->allowed();
        $d = $request->validate(['bin_id' => ['nullable', 'uuid'], 'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_uom_id' => ['required', 'uuid'], 'items.*.qty' => ['required', 'numeric', 'min:0']],
            ['items.*.product_uom_id.required' => 'Choose the product on every line.', 'items.*.qty.required' => 'Type the counted quantity on every line.']);
        $count = $this->find($id);

        return $this->act($request, function (Actor $actor) use ($svc, $count, $d) {
            foreach (array_values($d['items']) as $i => $item) {
                $unit = ProductUom::findOrFail($item['product_uom_id']);
                try {
                    $svc->record($actor, $count, Product::findOrFail($unit->product_id), $unit, (string) $item['qty'], $d['bin_id'] ?? null);
                } catch (DomainException $e) {
                    throw new DomainException('Line '.($i + 1).': '.$e->getMessage(), $e->errorCode);
                }
            }

            return (new CountsScreen($actor->user))->show($count->fresh());
        });
    }

    /** Submit for approval, approve and post (the differences into stock), or cancel with a reason. */
    public function action(Request $request, string $id, StockCountService $svc): JsonResponse
    {
        $this->allowed();
        $d = $request->validate(['action' => ['required', Rule::in(['submit', 'post', 'cancel'])], 'reason' => ['required_if:action,cancel', 'nullable', 'string', 'max:255']]);
        $count = $this->find($id);

        return $this->act($request, function (Actor $actor) use ($svc, $count, $d) {
            $posted = null;
            match ($d['action']) {
                'submit' => $svc->submit($actor, $count),
                'post' => $posted = $svc->post($actor, $count),
                default => $svc->cancel($actor, $count, $d['reason']),
            };

            return (new CountsScreen($actor->user))->show($count->fresh()) + ['posted_lines' => $posted['lines'] ?? null];
        });
    }

    /** The count sheet: PDF to count on paper, or CSV to fill in and import (expected figures only on a count that shows them). */
    public function sheet(Request $request, string $id, Exporter $exporter): StreamedResponse
    {
        $this->allowed();
        $count = $this->find($id);
        $actor = Actor::user($request->user(), 'web');
        $i = ImportService::importer('stock_count');
        $sheet = ['title' => "Count sheet {$count->number}", 'headings' => $i->headings(), 'rows' => iterator_to_array($i->exportRows($actor, [], $count), false),
            'note' => $count->warehouse->code.' · '.$count->warehouse->name.($count->blind ? ' · Blind count: expected quantities are not shown.' : '')
                .' · Counted by: ____________________  Checked by: ____________________'];

        return $request->query('format') === 'csv' ? $exporter->csv($actor, $sheet['title'], [$sheet]) : $exporter->pdf($actor, $sheet['title'], [$sheet]);
    }

    private function find(string $id): StockCount
    {
        return StockCountResource::getEloquentQuery()->findOrFail($id);
    }

    private function act(Request $request, Closure $fn): JsonResponse
    {
        $actor = Actor::user($request->user(), 'web');
        try {
            return response()->json(DB::transaction(fn () => $fn($actor)));
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(CountsScreen::canView(), 403, 'You cannot see stock counts.');
    }
}
