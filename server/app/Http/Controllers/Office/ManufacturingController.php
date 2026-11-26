<?php

namespace App\Http\Controllers\Office;

use App\Domain\Manufacturing\BomService;
use App\Domain\Manufacturing\WorkOrderService;
use App\Domain\Office\ManufacturingScreen;
use App\Domain\Office\StockScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\Actor;
use App\Filament\Pages\ProductionPlanning;
use App\Filament\Resources\Boms\BomResource;
use App\Filament\Resources\WorkCentres\WorkCentreResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Bom;
use App\Models\WorkCentre;
use App\Support\Exports\Exporter;
use App\Support\Qty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manufacturing in the new back office (App\Domain\Office\ManufacturingScreen): work orders (new; release, report output,
 * quality check, issue and return materials, record time, finish, cancel, the job card), bills of materials (save, put in
 * use, new revision, make, stop using), production planning (raise work orders) and work centres (save, archive) —
 * through the services the current pages use.
 */
class ManufacturingController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): ManufacturingScreen
    {
        $this->allowed($can);

        return new ManufacturingScreen($r->user());
    }

    /** Quantities per work-order material line ([line id => qty]), only those filled in. */
    private static function quantities(Request $request): array
    {
        $d = $request->validate(['quantities' => ['required', 'array'], 'quantities.*' => ['nullable', 'numeric', 'min:0']], ['quantities.*' => 'Enter the quantities.']);

        return collect($d['quantities'])->filter(fn ($q) => $q !== null && $q !== '' && (float) $q > 0)->map(fn ($q) => (string) $q)->all();
    }

    // -------------------------------------------------------------------------------------------- Work orders

    public function orders(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ManufacturingScreen::canView())->orders($request->query()));
    }

    public function orderForm(Request $request): JsonResponse
    {
        $product = $request->query('product');

        return response()->json($this->screen($request, ManufacturingScreen::canProduce())->orderForm(is_string($product) ? $product : null));
    }

    public function customerOrders(Request $request): JsonResponse
    {
        $screen = $this->screen($request, ManufacturingScreen::canProduce());

        return response()->json(['results' => $screen->customerOrders(mb_substr(trim((string) $request->query('q', '')), 0, 40))]);
    }

    public function orderStore(Request $request, WorkOrderService $svc): JsonResponse
    {
        $this->screen($request, ManufacturingScreen::canProduce());
        $d = $request->validate(['warehouse_id' => ['required', 'uuid'], 'product_id' => ['required', 'uuid'], 'bom_id' => ['nullable', 'uuid'], 'qty' => ['required', 'numeric', 'min:0.001'],
            'due_on' => ['nullable', 'date'], 'customer_order_id' => ['nullable', 'uuid'], 'note' => ['nullable', 'string', 'max:2000']],
            ['warehouse_id.*' => 'Choose the warehouse.', 'product_id.*' => 'Choose the product to make.', 'qty.*' => 'Enter how many to make.']);
        foreach (['bom_id', 'due_on', 'customer_order_id', 'note'] as $k) {
            $d[$k] = ($d[$k] ?? '') ?: null;
        }
        $d['qty'] = (string) $d['qty'];

        return $this->act(fn (Actor $actor) => ['id' => $svc->create($actor, $d)->id, 'message' => 'Work order created.']);
    }

    public function order(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, ManufacturingScreen::canView());

        return response()->json($screen->order($screen->ordersQuery()->findOrFail($id)));
    }

    /** release · output · inspect · issue · return · time · finish · cancel. */
    public function orderAction(Request $request, string $id, WorkOrderService $svc): JsonResponse
    {
        $o = $this->screen($request, ManufacturingScreen::canView())->ordersQuery()->findOrFail($id);
        $act = (string) $request->input('action');
        $this->allowed($act === 'inspect' ? Erp::can('manufacturing.qc', $o->warehouse) : Erp::can('manufacturing.produce', $o->warehouse));
        $opt = fn ($v) => ($v ?? '') === '' ? null : (string) $v;
        switch ($act) {
            case 'release':
                $sub = (bool) $request->boolean('sub_assemblies', true);

                return $this->act(fn (Actor $actor) => ['message' => $svc->release($actor, $o, $sub) ? 'Released.' : '']);
            case 'output':
                $d = $request->validate(['good' => ['nullable', 'numeric', 'min:0'], 'scrap' => ['nullable', 'numeric', 'min:0'], 'batch_no' => ['nullable', 'string', 'max:60'],
                    'expires_on' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:2000']]);

                return $this->act(fn (Actor $actor) => ['message' => $svc->reportOutput($actor, $o, (string) ($d['good'] ?? '0'), (string) ($d['scrap'] ?? '0'),
                    $opt($d['batch_no'] ?? null), $opt($d['expires_on'] ?? null), $opt($d['note'] ?? null)) ? 'Output recorded.' : '']);
            case 'inspect':
                $d = $request->validate(['passed' => ['nullable', 'numeric', 'min:0'], 'failed' => ['nullable', 'numeric', 'min:0'], 'checks' => ['nullable', 'array'],
                    'checks.*.check' => ['required', 'string', 'max:120'], 'checks.*.result' => ['required', 'in:pass,fail'], 'checks.*.value' => ['nullable', 'string', 'max:60'],
                    'note' => ['nullable', 'string', 'max:2000'], 'batch_no' => ['nullable', 'string', 'max:60'], 'expires_on' => ['nullable', 'date']],
                    ['checks.*.check.*' => 'Say what each check is.']);

                return $this->act(function (Actor $actor) use ($svc, $o, $d, $opt) {
                    $svc->inspect($actor, $o, (string) ($d['passed'] ?? '0'), (string) ($d['failed'] ?? '0'), $d['checks'] ?? [], $opt($d['note'] ?? null),
                        $opt($d['batch_no'] ?? null), $opt($d['expires_on'] ?? null));

                    return ['message' => 'Quality check recorded.'];
                });
            case 'issue':
            case 'return':
                $q = self::quantities($request);
                abort_if($q === [], 422, 'Enter at least one quantity.');

                return $this->act(fn (Actor $actor) => ['message' => ($act === 'issue' ? $svc->issue($actor, $o, $q) : $svc->returnMaterials($actor, $o, $q))
                    ? ($act === 'issue' ? 'Issued.' : 'Returned.') : '']);
            case 'time':
                $d = $request->validate(['seq' => ['required', 'integer'], 'minutes' => ['required', 'numeric', 'min:0.01'], 'note' => ['nullable', 'string', 'max:200']],
                    ['seq.*' => 'Choose the operation.', 'minutes.*' => 'Enter the minutes.']);

                return $this->act(fn (Actor $actor) => ['message' => $svc->recordTime($actor, $o, (int) $d['seq'], (string) $d['minutes'], $opt($d['note'] ?? null)) ? 'Time recorded.' : '']);
            case 'finish':
                $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

                return $this->act(fn (Actor $actor) => ['message' => $svc->finish($actor, $o, $opt($reason)) ? 'Finished.' : '']);
            case 'cancel':
                $reason = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Say why.'])['reason'];

                return $this->act(fn (Actor $actor) => ['message' => $svc->cancel($actor, $o, $reason) ? 'Cancelled.' : '']);
        }
        abort(422, 'Unknown action.');
    }

    /** The job card: as the current page's PDF. */
    public function card(Request $request, string $id, Exporter $exporter): StreamedResponse
    {
        $o = $this->screen($request, ManufacturingScreen::canView())->ordersQuery()->findOrFail($id);
        [$mh, $mr] = WorkOrderResource::materialRows($o);
        [$oh, $or] = WorkOrderResource::operationRows($o);
        $note = sprintf('%s · make %s %s in %s · needed by %s. Made: ______  Scrapped: ______  Operator: ______________  Supervisor: ______________',
            $o->number, Fields::qty($o->qty_planned), $o->product->name, $o->warehouse->name, $o->due_on?->format('j M Y') ?? '—');

        return $exporter->pdf(Actor::user($request->user(), 'web'), "Job card {$o->number}", [
            ['title' => "Job card {$o->number}: materials", 'headings' => $mh, 'rows' => $mr, 'note' => $note],
            ['title' => 'Operations', 'headings' => $oh, 'rows' => $or],
        ], [], 'portrait');
    }

    // -------------------------------------------------------------------------------------------- Bills of materials

    public function boms(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ManufacturingScreen::canView())->boms($request->query()));
    }

    public function bom(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, ManufacturingScreen::canView());

        return response()->json($screen->bom($screen->bomsQuery()->findOrFail($id)));
    }

    public function bomForm(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, BomResource::canCreate());
        $b = $id ? $screen->bomsQuery()->findOrFail($id) : null;
        abort_if($b && $b->status !== 'draft', 422, 'Only a draft can change. Start a new revision.');

        return response()->json($screen->bomForm($b));
    }

    public function products(Request $request): JsonResponse
    {
        $screen = $this->screen($request, ManufacturingScreen::canView());

        return response()->json(['results' => $screen->products(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    public function units(Request $request): JsonResponse
    {
        $this->screen($request, ManufacturingScreen::canView());

        return response()->json(['results' => (new StockScreen($request->user()))->units(mb_substr(trim((string) $request->query('q', '')), 0, 60), false)]);
    }

    public function bomSave(Request $request, BomService $svc, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, BomResource::canCreate());
        $d = $request->validate(['product_id' => [$id ? 'nullable' : 'required', 'uuid'], 'output_qty' => ['required', 'numeric', 'min:0.001'], 'qc_required' => ['boolean'],
            'note' => ['nullable', 'string', 'max:5000'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.kind' => ['required', 'in:material,by_product'],
            'lines.*.product_uom_id' => ['required', 'uuid'], 'lines.*.qty' => ['required', 'numeric', 'min:0.001'], 'lines.*.scrap_pct' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'lines.*.backflush' => ['boolean'], 'operations' => ['nullable', 'array'], 'operations.*.name' => ['required', 'string', 'max:120'],
            'operations.*.work_centre_id' => ['required', 'uuid'], 'operations.*.setup_minutes' => ['nullable', 'numeric', 'min:0'], 'operations.*.run_minutes' => ['nullable', 'numeric', 'min:0'],
            'operations.*.backflush' => ['boolean']],
            ['product_id.*' => 'Choose the product this BOM makes.', 'lines.*' => 'Add at least one material.', 'lines.*.product_uom_id.*' => 'Choose the item and unit on every line.',
                'lines.*.qty.*' => 'Enter a quantity above zero on every line.', 'operations.*.name.*' => 'Name each operation.', 'operations.*.work_centre_id.*' => 'Choose the work centre of each operation.']);
        $b = $id ? $screen->bomsQuery()->findOrFail($id) : new Bom;
        $d['output_qty'] = (string) $d['output_qty'];
        $d['operations'] = $d['operations'] ?? [];
        if ($id) {
            unset($d['product_id']);
        }

        return $this->act(fn (Actor $actor) => ['id' => $svc->save($actor, $b, $d)->id, 'message' => 'Saved.']);
    }

    /** activate (put in use) · revise (new revision) · make (a work order) · archive (stop using; reason). */
    public function bomAction(Request $request, string $id, BomService $svc, WorkOrderService $orders): JsonResponse
    {
        $b = $this->screen($request, ManufacturingScreen::canView())->bomsQuery()->findOrFail($id);
        switch ((string) $request->input('action')) {
            case 'activate':
                $this->allowed(ManufacturingScreen::canSetup());

                return $this->act(fn (Actor $actor) => ['message' => $svc->activate($actor, $b) ? 'In use.' : '']);
            case 'revise':
                $this->allowed(ManufacturingScreen::canSetup());

                return $this->act(fn (Actor $actor) => ['message' => 'New revision started.', 'open' => '/office/manufacturing/boms/'.$svc->revise($actor, $b)->id.'/edit']);
            case 'make':
                $this->allowed(ManufacturingScreen::canProduce());
                $d = $request->validate(['warehouse_id' => ['required', 'uuid'], 'qty' => ['required', 'numeric', 'min:0.001'], 'due_on' => ['nullable', 'date']],
                    ['warehouse_id.*' => 'Choose the warehouse.', 'qty.*' => 'Enter how many.']);

                return $this->act(fn (Actor $actor) => ['message' => 'Work order created.', 'open' => '/office/manufacturing/orders/'.$orders->create($actor,
                    ['warehouse_id' => $d['warehouse_id'], 'qty' => (string) $d['qty'], 'due_on' => ($d['due_on'] ?? '') ?: null, 'product_id' => $b->product_id, 'bom_id' => $b->id])->id]);
            case 'archive':
                $this->allowed(ManufacturingScreen::canSetup());
                $reason = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Say why.'])['reason'];

                return $this->act(fn (Actor $actor) => ['message' => $svc->archive($actor, $b, $reason) ? 'Stopped.' : '']);
        }
        abort(422, 'Unknown action.');
    }

    // -------------------------------------------------------------------------------------------- Production planning

    public function planning(Request $request): JsonResponse
    {
        $w = $request->query('warehouse');

        return response()->json($this->screen($request, ProductionPlanning::canAccess())->planning(is_string($w) ? $w : null));
    }

    /** Raise a draft work order for one suggestion (product), or for every one (all). */
    public function plan(Request $request, WorkOrderService $orders): JsonResponse
    {
        $screen = $this->screen($request, ProductionPlanning::canAccess() && ManufacturingScreen::canProduce());
        $d = $request->validate(['warehouse' => ['required', 'uuid'], 'product_id' => ['nullable', 'uuid']]);
        // The current page's own plan (its warehouses and suggestions), as its "Raise work order" buttons use.
        $page = new ProductionPlanning;
        $page->warehouse = $d['warehouse'];
        abort_unless(array_key_exists($d['warehouse'], $page->warehouses()), 422, 'Choose the warehouse.');
        $rows = $page->plan()['make'];

        return $this->act(function (Actor $actor) use ($orders, $rows, $d) {
            $n = 0;
            foreach ($rows as $row) {
                if (($d['product_id'] ?? null) && $row['product_id'] !== $d['product_id']) {
                    continue;
                }
                $orders->create($actor, ['warehouse_id' => $d['warehouse'], 'product_id' => $row['product_id'], 'qty' => Qty::format((int) round($row['suggested'] * 1000)),
                    'note' => 'Raised from production planning']);
                $n++;
            }
            abort_if($n === 0, 422, 'Nothing to raise for that product.');

            return ['message' => "{$n} draft work order(s) raised. They do nothing until released."];
        });
    }

    // -------------------------------------------------------------------------------------------- Work centres

    public function centres(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, WorkCentreResource::canViewAny())->centres($request->query()));
    }

    public function centreSave(Request $request, BomService $svc, ?string $id = null): JsonResponse
    {
        $this->screen($request, WorkCentreResource::canCreate());
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'branch_id' => ['required', 'uuid'], 'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'hours_per_day' => ['nullable', 'numeric', 'min:0', 'max:24']], ['name.*' => 'Name the work centre.', 'branch_id.*' => 'Choose the branch.']);
        $c = $id ? WorkCentreResource::getEloquentQuery()->findOrFail($id) : new WorkCentre;
        $d['hourly_rate'] = (string) ($d['hourly_rate'] ?? '0');
        $d['hours_per_day'] = (string) ($d['hours_per_day'] ?? '8');

        return $this->act(fn (Actor $actor) => ['id' => $svc->saveWorkCentre($actor, $c, $d)->id, 'message' => 'Saved.']);
    }

    public function centreArchive(Request $request, string $id, ArchiveService $svc): JsonResponse
    {
        $this->screen($request, WorkCentreResource::canViewAny());
        $c = WorkCentreResource::getEloquentQuery()->findOrFail($id);
        $d = $request->validate(['restore' => ['boolean'], 'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);
        $this->allowed($svc->allowed($request->user(), $c));

        return $this->act(function (Actor $actor) use ($svc, $c, $d) {
            ($d['restore'] ?? false) ? $svc->restore($actor, $c) : $svc->archive($actor, $c, $d['reason']);

            return ['message' => ($d['restore'] ?? false) ? 'Restored.' : 'Archived.'];
        });
    }
}
