<?php

namespace App\Http\Controllers\Office;

use App\Domain\Inventory\BinService;
use App\Domain\Inventory\CycleCounts;
use App\Domain\Inventory\ExpiryService;
use App\Domain\Logistics\WaybillService;
use App\Domain\Office\StockFloorScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\Actor;
use App\Filament\Resources\Bins\BinResource;
use App\Filament\Resources\Waybills\WaybillResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Bin;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use App\Models\Waybill;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The rest of the Stock floor in the new back office (App\Domain\Office\StockFloorScreen): Returns to suppliers,
 * Waybills, Bins and My day. Each record is found only within the person's branches; each step goes through the same
 * service as the current pages, which checks the person again.
 */
class StockFloorController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): StockFloorScreen
    {
        $this->allowed($can);

        return new StockFloorScreen($r->user());
    }

    // -------------------------------------------------------------------------------------------- Returns to suppliers

    public function returns(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, StockFloorScreen::canReturns())->returns($request->query()));
    }

    public function returnShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, StockFloorScreen::canReturns());

        return response()->json($screen->returnDoc($screen->returnsQuery()->findOrFail($id)));
    }

    /** post (approve and send) · settle (the supplier's reference; a refund's amount and account) · cancel (reason). */
    public function returnStep(Request $request, string $id, string $step): JsonResponse
    {
        $screen = $this->screen($request, StockFloorScreen::canReturns());
        /** @var SupplierReturn $r */
        $r = $screen->returnsQuery()->findOrFail($id);
        $d = $request->validate(match ($step) {
            'settle' => ['reference' => ['required', 'string', 'max:120'], 'refund' => ['nullable', 'numeric', 'min:0'],
                'account_id' => ['nullable', Rule::in(array_keys(FinanceUi::moneyAccounts()))]],
            'cancel' => ['reason' => ['required', 'string', 'max:255']],
            default => [],
        }, ['reference.*' => 'Enter the supplier\'s reference.', 'reason.*' => 'Give the reason.', 'account_id.*' => 'Choose the account it came into.']);
        $currency = $request->user()->tenant?->currency_code ?? 'GHS';

        return $this->act(function (Actor $actor) use ($step, $r, $d, $screen, $currency) {
            $svc = app(ExpiryService::class);
            $message = match ($step) {
                'post' => ($svc->postReturn($actor, $r) ? 'Approved: the stock has left.' : ''),
                'settle' => ($svc->settle($actor, $r, ['reference' => $d['reference'], 'refund' => isset($d['refund']) ? Money::toMinor((string) $d['refund'], $currency) : null,
                    'account_id' => $d['account_id'] ?? null]) ? 'Settlement recorded.' : ''),
                default => ($svc->cancelReturn($actor, $r, $d['reason']) ? 'Cancelled.' : ''),
            };

            return ['message' => $message, 'doc' => $screen->returnDoc($r->fresh())];
        });
    }

    // -------------------------------------------------------------------------------------------- Waybills

    public function waybills(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, StockFloorScreen::canWaybills())->waybills($request->query()));
    }

    public function waybillShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, StockFloorScreen::canWaybills());

        return response()->json($screen->waybill($screen->waybillsQuery()->findOrFail($id)));
    }

    /** The new-waybill form: the kinds this person may make, and for a kind its recent documents. */
    public function waybillForm(Request $request): JsonResponse
    {
        $screen = $this->screen($request, WaybillResource::canCreate());
        $kind = (string) $request->query('kind', '');

        return response()->json(['kinds' => $screen->waybillKinds(), 'sources' => $kind !== '' ? $screen->sources($kind) : []]);
    }

    public function waybillPrepare(Request $request, WaybillService $waybills): JsonResponse
    {
        $this->screen($request, WaybillResource::canCreate());
        $d = $request->validate(['kind' => ['required', Rule::in(array_keys(Waybill::KINDS))], 'source_id' => ['required', 'uuid'],
            'reference' => ['required_if:kind,inbound', 'nullable', 'string', 'max:80'], 'transporter' => ['nullable', 'string', 'max:120'],
            'driver_name' => ['nullable', 'string', 'max:120'], 'driver_phone' => ['nullable', 'string', 'max:30'], 'vehicle_no' => ['nullable', 'string', 'max:30'],
            'packages' => ['nullable', 'integer', 'min:1'], 'weight_kg' => ['nullable', 'numeric', 'min:0'], 'to_address' => ['nullable', 'string', 'max:255'],
            'to_phone' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:500']],
            ['kind.*' => 'Choose what the waybill is for.', 'source_id.*' => 'Choose the document.', 'reference.required_if' => 'Enter the supplier\'s waybill number.']);

        return $this->act(fn (Actor $actor) => ['id' => $waybills->prepare($actor, $d['kind'], $d['source_id'], array_filter($d, fn ($v) => $v !== null && $v !== ''))->id]);
    }

    public function waybillStep(Request $request, WaybillService $waybills, string $id, string $step): JsonResponse
    {
        $screen = $this->screen($request, StockFloorScreen::canWaybills());
        $w = $screen->waybillsQuery()->findOrFail($id);
        $d = $request->validate(match ($step) {
            'dispatch' => ['driver_name' => ['nullable', 'string', 'max:120'], 'driver_phone' => ['nullable', 'string', 'max:30'], 'vehicle_no' => ['nullable', 'string', 'max:30'],
                'transporter' => ['nullable', 'string', 'max:120']],
            'deliver' => ['received_by' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:30'], 'note' => ['nullable', 'string', 'max:500'],
                'pod' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:5120']],
            default => ['reason' => ['required', 'string', 'max:255']],
        }, ['received_by.*' => 'Enter who received it.', 'reason.*' => 'Give the reason.', 'pod.mimetypes' => 'Attach a photo (JPEG, PNG, WebP) or a PDF.',
            'pod.max' => 'The file is larger than 5 MB.']);
        $pod = $step === 'deliver' ? $request->file('pod')?->store('waybill-pod', 'local') : null;

        return $this->act(function (Actor $actor) use ($waybills, $w, $step, $d, $pod, $screen) {
            match ($step) {
                'dispatch' => $waybills->dispatch($actor, $w, array_filter($d, fn ($v) => $v !== null && $v !== '')),
                'deliver' => $waybills->deliver($actor, $w, $d['received_by'], $pod, $d['note'] ?? null, $d['phone'] ?? null),
                default => $waybills->cancel($actor, $w, $d['reason']),
            };

            return ['message' => ['dispatch' => 'On the way.', 'deliver' => 'Delivered.'][$step] ?? 'Cancelled.', 'waybill' => $screen->waybill($w->fresh())];
        });
    }

    // -------------------------------------------------------------------------------------------- Bins

    public function bins(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, StockFloorScreen::canBins())->bins($request->query()));
    }

    private function bin(string $id): Bin
    {
        return BinResource::getEloquentQuery()->findOrFail($id);
    }

    /** For the bin form: the next free code in the store; for moving stock: the store's other bins and the product's units. */
    public function binHelp(Request $request): JsonResponse
    {
        $screen = $this->screen($request, StockFloorScreen::canBins());
        $d = $request->validate(['warehouse' => ['required', 'uuid'], 'except' => ['nullable', 'uuid'], 'product' => ['nullable', 'uuid']]);
        $w = Warehouse::where('use_bins', true)->findOrFail($d['warehouse']);
        abort_unless(Erp::can('stock.view', $w), 404);

        return response()->json($screen->binHelp($w, $d['except'] ?? null) + ['units' => filled($d['product'] ?? null) ? $screen->units($d['product']) : []]);
    }

    public function binSave(Request $request, BinService $bins): JsonResponse
    {
        $this->screen($request, StockFloorScreen::canBins());
        $d = $request->validate(['id' => ['nullable', 'uuid'], 'warehouse_id' => ['required_without:id', 'nullable', 'uuid'], 'code' => ['required', 'string', 'max:30'],
            'name' => ['nullable', 'string', 'max:255'], 'pick_order' => ['nullable', 'integer', 'min:0']],
            ['warehouse_id.*' => 'Choose the warehouse.', 'code.*' => 'Give the bin a code, e.g. A-01-02.']);
        $bin = filled($d['id'] ?? null) ? $this->bin($d['id']) : new Bin;
        abort_if($bin->exists && ! BinResource::canEdit($bin), 403, 'You cannot change this bin.');

        return $this->act(function (Actor $actor) use ($bins, $bin, $d) {
            $saved = $bins->saveBin($actor, $bin, array_filter(['warehouse_id' => $bin->exists ? null : ($d['warehouse_id'] ?? null), 'code' => $d['code'],
                'name' => $d['name'] ?? null, 'pick_order' => $d['pick_order'] ?? 100], fn ($v) => $v !== null));

            return ['message' => "Bin {$saved->code} saved."];
        });
    }

    public function binMove(Request $request, BinService $bins, string $id): JsonResponse
    {
        $this->screen($request, StockFloorScreen::canBins());
        $from = $this->bin($id);
        $d = $request->validate(['product_id' => ['required', 'uuid'], 'product_uom_id' => ['required', 'uuid'], 'qty' => ['required', 'numeric', 'min:0.001'],
            'to_bin_id' => ['required', 'uuid']], ['product_id.*' => 'Choose the product.', 'product_uom_id.*' => 'Choose the unit.',
                'qty.*' => 'Enter how many.', 'to_bin_id.*' => 'Choose the bin it goes to.']);
        $unit = ProductUom::where('product_id', $d['product_id'])->findOrFail($d['product_uom_id']);

        return $this->act(function (Actor $actor) use ($bins, $from, $d, $unit) {
            $bins->putAway($actor, $from->warehouse, Product::findOrFail($d['product_id']), $unit, (string) $d['qty'], $from->id, $d['to_bin_id']);

            return ['message' => 'Moved.'];
        });
    }

    public function binArchive(Request $request, ArchiveService $archive, string $id, string $step): JsonResponse
    {
        $this->screen($request, StockFloorScreen::canBins());
        $bin = $this->bin($id);
        $d = $request->validate(['reason' => [$step === 'archive' ? 'required' : 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);

        return $this->act(function (Actor $actor) use ($archive, $bin, $step, $d) {
            $step === 'archive' ? $archive->archive($actor, $bin, $d['reason']) : $archive->restore($actor, $bin);

            return ['message' => $step === 'archive' ? "Bin {$bin->code} archived." : "Bin {$bin->code} restored."];
        });
    }

    // -------------------------------------------------------------------------------------------- My day

    public function myDay(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, StockFloorScreen::canMyDay())->myDay($request->query()));
    }

    /** Start today's cycle count in a store: a blind count of the products due; a manager posts it. */
    public function startCycle(Request $request, CycleCounts $cycles): JsonResponse
    {
        $this->screen($request, StockFloorScreen::canMyDay());
        $d = $request->validate(['warehouse' => ['required', Rule::in(array_keys(StockFloorScreen::stores()))]], ['warehouse.*' => 'Choose a store you may see.']);

        return $this->act(function (Actor $actor) use ($cycles, $d) {
            $count = $cycles->start($actor, Warehouse::findOrFail($d['warehouse']));

            return ['open' => "/office/counts/{$count->id}", 'message' => "{$count->number} started: ".$count->lines()->count().' product(s) to count.'];
        });
    }
}
