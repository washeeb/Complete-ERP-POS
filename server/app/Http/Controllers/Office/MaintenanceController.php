<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\MaintenanceScreen;
use App\Domain\Office\StockScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Service\Maintenance;
use App\Domain\Support\Actor;
use App\Filament\Resources\MaintenanceAssets\MaintenanceAssetResource;
use App\Filament\Resources\MaintenanceOrders\MaintenanceOrderResource;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Customer;
use App\Models\MaintenanceAsset;
use App\Models\MaintenanceOrder;
use App\Models\MaintenancePlan;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Maintenance in the new back office (App\Domain\Office\MaintenanceScreen): equipment (save, report a breakdown, meter
 * reading, servicing plans, archive) and work orders (open; start, log time, use parts, parts back, waiting for parts,
 * done, invoice the customer, cancel) — each through the Maintenance service as the current pages.
 */
class MaintenanceController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): MaintenanceScreen
    {
        $this->allowed($can);

        return new MaintenanceScreen($r->user());
    }

    private static function blank(array $d, array $keys): array
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $d) && $d[$k] === '') {
                $d[$k] = null;
            }
        }

        return $d;
    }

    // -------------------------------------------------------------------------------------------- Equipment

    public function equipment(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, MaintenanceScreen::canView())->equipment($request->query()));
    }

    public function asset(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, $id ? MaintenanceScreen::canView() : MaintenanceAssetResource::canCreate());

        return response()->json($screen->asset($id ? $screen->assetsQuery()->findOrFail($id) : null));
    }

    public function assetSave(Request $request, Maintenance $m, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, $id ? MaintenanceScreen::canManage() : MaintenanceAssetResource::canCreate());
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'status' => ['nullable', Rule::in(array_keys(MaintenanceAsset::STATUSES))], 'owner' => ['required', 'in:own,customer'],
            'category' => ['required', Rule::in(array_keys(MaintenanceAsset::CATEGORIES))], 'branch_id' => ['nullable', 'uuid'], 'location' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', 'uuid'], 'make' => ['nullable', 'string', 'max:80'], 'model' => ['nullable', 'string', 'max:80'], 'serial_no' => ['nullable', 'string', 'max:80'],
            'supplier_id' => ['nullable', 'uuid'], 'acquired_on' => ['nullable', 'date'], 'warranty_until' => ['nullable', 'date'], 'contract_until' => ['nullable', 'date'],
            'fixed_asset_id' => ['nullable', 'uuid'], 'meter_unit' => ['nullable', 'in:hours,km'], 'notes' => ['nullable', 'string', 'max:5000']],
            ['name.*' => 'Name the equipment (e.g. “Generator 20 kVA, back yard”).']);
        $a = $id ? $screen->assetsQuery()->findOrFail($id) : new MaintenanceAsset;
        if (! $id) {
            unset($d['status']); // new equipment starts working, as the current form
        }
        $d = self::blank($d + ['meter_unit' => null], ['branch_id', 'location', 'customer_id', 'make', 'model', 'serial_no', 'supplier_id', 'acquired_on', 'warranty_until',
            'contract_until', 'fixed_asset_id', 'meter_unit', 'notes']);

        return $this->act(fn (Actor $actor) => ['id' => $m->saveAsset($actor, $a, $d)->id, 'message' => 'Saved.']);
    }

    /** breakdown (what is wrong, details) · meter (reading). */
    public function assetAction(Request $request, string $id, Maintenance $m): JsonResponse
    {
        $a = $this->screen($request, MaintenanceScreen::canWork())->assetsQuery()->findOrFail($id);
        if ($request->input('action') === 'meter') {
            abort_unless((bool) $a->meter_unit, 422, 'This equipment has no meter.');
            $reading = (float) $request->validate(['reading' => ['required', 'numeric', 'min:0']], ['reading.*' => 'Enter the reading now.'])['reading'];

            return $this->act(fn (Actor $actor) => ['message' => $m->meter($actor, $a, $reading) ? 'Reading saved.' : '']);
        }
        abort_unless($request->input('action') === 'breakdown' && $a->status !== 'retired', 422, 'Unknown action.');
        $d = $request->validate(['title' => ['required', 'string', 'max:255'], 'details' => ['nullable', 'string', 'max:5000']], ['title.*' => 'Say what is wrong.']);

        return $this->act(fn (Actor $actor) => ['message' => 'Work order opened.', 'open' => '/office/maintenance/orders/'.$m->open($actor, $a,
            ['kind' => $a->owner === 'customer' ? 'visit' : 'breakdown', 'title' => $d['title'], 'details' => ($d['details'] ?? '') ?: null, 'priority' => 'high', 'down' => true])->id]);
    }

    public function planSave(Request $request, string $id, Maintenance $m, ?string $plan = null): JsonResponse
    {
        $a = $this->screen($request, MaintenanceScreen::canManage())->assetsQuery()->findOrFail($id);
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'every_days' => ['nullable', 'integer', 'min:1', 'max:3650'], 'every_meter' => ['nullable', 'numeric', 'min:1'],
            'last_done_on' => ['nullable', 'date', 'before_or_equal:today'], 'lead_days' => ['nullable', 'integer', 'min:0', 'max:60'], 'assigned_to' => ['nullable', 'uuid'],
            'is_active' => ['boolean'], 'checklist' => ['nullable', 'array', 'max:50'], 'checklist.*' => ['nullable', 'string', 'max:255']], ['name.*' => 'Name the servicing.']);
        $p = $plan ? MaintenancePlan::where('asset_id', $a->id)->findOrFail($plan) : new MaintenancePlan;
        $d = self::blank($d, ['every_days', 'every_meter', 'last_done_on', 'assigned_to']);
        $d['checklist'] = array_values(array_filter(array_map(fn ($x) => trim((string) $x), $d['checklist'] ?? []), fn ($s) => $s !== ''));

        return $this->act(fn (Actor $actor) => ['id' => $m->savePlan($actor, $a, $p, $d)->id, 'message' => 'Saved.']);
    }

    public function archive(Request $request, string $id, ArchiveService $svc): JsonResponse
    {
        $a = $this->screen($request, MaintenanceScreen::canView())->assetsQuery()->findOrFail($id);
        $d = $request->validate(['restore' => ['boolean'], 'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);
        $this->allowed($svc->allowed($request->user(), $a));

        return $this->act(function (Actor $actor) use ($svc, $a, $d) {
            ($d['restore'] ?? false) ? $svc->restore($actor, $a) : $svc->archive($actor, $a, $d['reason']);

            return ['message' => ($d['restore'] ?? false) ? 'Restored.' : 'Archived.'];
        });
    }

    public function customers(Request $request): JsonResponse
    {
        $this->screen($request, MaintenanceScreen::canView());
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        return response()->json(['results' => Customer::where('is_active', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('phone', 'ilike', "%{$q}%"))
            ->orderBy('name')->limit(30)->get()->map(fn ($c) => ['id' => $c->id, 'label' => $c->name, 'detail' => $c->phone])->all()]);
    }

    // -------------------------------------------------------------------------------------------- Work orders

    public function orders(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, MaintenanceScreen::canView())->orders($request->query()));
    }

    public function orderForm(Request $request): JsonResponse
    {
        $asset = $request->query('asset');

        return response()->json($this->screen($request, MaintenanceOrderResource::canCreate())->orderForm(is_string($asset) ? $asset : null));
    }

    public function orderStore(Request $request, Maintenance $m): JsonResponse
    {
        $screen = $this->screen($request, MaintenanceOrderResource::canCreate());
        $d = $request->validate(['asset_id' => ['required', 'uuid'], 'kind' => ['required', Rule::in(array_keys(MaintenanceOrder::KINDS))],
            'priority' => ['required', Rule::in(array_keys(MaintenanceScreen::PRIORITIES))], 'title' => ['required', 'string', 'max:255'], 'due_on' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'uuid'], 'down' => ['boolean'], 'details' => ['nullable', 'string', 'max:5000']],
            ['asset_id.*' => 'Choose the equipment.', 'title.*' => 'Say what is to be done.']);
        $a = $screen->assetsQuery()->whereNull('maintenance_assets.archived_at')->findOrFail($d['asset_id']);
        $d = self::blank($d, ['due_on', 'assigned_to', 'details']);
        $d['down'] = (bool) ($d['down'] ?? false);

        return $this->act(fn (Actor $actor) => ['id' => $m->open($actor, $a, $d)->id, 'message' => 'Work order opened.']);
    }

    public function order(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, MaintenanceScreen::canView());

        return response()->json($screen->order($screen->ordersQuery()->findOrFail($id)));
    }

    /** start · labour (hours, note) · parts / parts_back (store, lines) · wait (why) · complete (outcome, checklist, meter) · invoice (labour, service) · cancel (why). */
    public function orderAction(Request $request, string $id, Maintenance $m): JsonResponse
    {
        $act = (string) $request->input('action');
        $screen = $this->screen($request, $act === 'invoice' ? MaintenanceScreen::canManage() : MaintenanceScreen::canWork());
        $o = $screen->ordersQuery()->findOrFail($id);
        switch ($act) {
            case 'start':
                return $this->act(fn (Actor $actor) => ['message' => $m->start($actor, $o) ? 'Started.' : '']);
            case 'labour':
                $d = $request->validate(['hours' => ['required', 'numeric', 'min:0.05', 'max:24'], 'note' => ['nullable', 'string', 'max:120']], ['hours.*' => 'Enter the hours spent (at most 24).']);

                return $this->act(fn (Actor $actor) => ['message' => $m->labour($actor, $o, (int) round($d['hours'] * 60), ($d['note'] ?? '') ?: null) ? 'Time logged.' : '']);
            case 'parts':
            case 'parts_back':
                $d = $request->validate(['warehouse_id' => ['required', 'uuid'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.product_uom_id' => ['required', 'uuid'],
                    'lines.*.qty' => ['required', 'numeric', 'min:0.001']], ['warehouse_id.*' => 'Choose the store.', 'lines.*' => 'Add at least one part.',
                        'lines.*.product_uom_id.*' => 'Choose the part and its unit.', 'lines.*.qty.*' => 'Enter how many.']);
                $lines = array_map(fn ($l) => ['product_uom_id' => $l['product_uom_id'], 'qty' => (string) $l['qty']], $d['lines']);

                return $this->act(fn (Actor $actor) => ['message' => $m->parts($actor, $o, Warehouse::findOrFail($d['warehouse_id']), $lines, $act === 'parts_back')
                    ? ($act === 'parts_back' ? 'Back in stock.' : 'Parts used.') : '']);
            case 'wait':
                $why = $request->validate(['why' => ['required', 'string', 'max:255']], ['why.*' => 'Say what parts it is waiting for.'])['why'];

                return $this->act(fn (Actor $actor) => ['message' => $m->waitForParts($actor, $o, $why) ? 'Marked waiting.' : '']);
            case 'complete':
                $d = $request->validate(['outcome' => ['required', 'string', 'max:5000'], 'checklist' => ['nullable', 'array'], 'checklist.*.item' => ['required', 'string', 'max:255'],
                    'checklist.*.done' => ['boolean'], 'skip_checklist' => ['boolean'], 'meter_reading' => ['nullable', 'numeric', 'min:0']], ['outcome.*' => 'Say what was done.']);

                return $this->act(fn (Actor $actor) => ['message' => $m->complete($actor, $o, $d) ? 'Done.' : '']);
            case 'invoice':
                $d = $request->validate(['labour' => ['nullable', 'numeric', 'min:0'], 'service' => ['nullable', 'uuid']]);

                return $this->act(fn (Actor $actor) => ['message' => 'Invoiced.', 'open' => '/office/invoices/'.$m->invoice($actor, $o,
                    ($d['service'] ?? null) ? Product::find($d['service']) : null, FinanceUi::toMinor($d['labour'] ?? 0))->id]);
            case 'cancel':
                $why = $request->validate(['why' => ['required', 'string', 'max:255']], ['why.*' => 'Say why.'])['why'];

                return $this->act(fn (Actor $actor) => ['message' => $m->cancel($actor, $o, $why) ? 'Cancelled.' : '']);
        }
        abort(422, 'Unknown action.');
    }

    /** Parts (products and units) for Use parts / Parts back. */
    public function units(Request $request): JsonResponse
    {
        $this->screen($request, MaintenanceScreen::canWork());

        return response()->json(['results' => (new StockScreen($request->user()))->units(mb_substr(trim((string) $request->query('q', '')), 0, 60), false)]);
    }

    /** Services (not stock items) labour can be invoiced as. */
    public function services(Request $request): JsonResponse
    {
        $this->screen($request, MaintenanceScreen::canManage());
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        return response()->json(['results' => Product::where('is_active', true)->where('track_stock', false)->where('name', 'ilike', "%{$q}%")
            ->orderBy('name')->limit(30)->get()->map(fn ($p) => ['id' => $p->id, 'label' => $p->name, 'detail' => $p->sku])->all()]);
    }
}
