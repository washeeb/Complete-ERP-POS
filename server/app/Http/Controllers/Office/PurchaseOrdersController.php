<?php

namespace App\Http\Controllers\Office;

use App\Domain\Ai\InvoiceScanner;
use App\Domain\Office\PurchaseOrdersScreen;
use App\Domain\Office\SuppliersScreen;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\OrderImporter;
use App\Domain\Purchasing\OrderSender;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Fields;
use App\Filament\Support\QuickCreate;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ReconfirmNeeded;
use App\Support\Gs1;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Purchase orders in the new back office (App\Domain\Office\PurchaseOrdersScreen): the list, one order, the draft form,
 * "Order from a file", and the current page's buttons, each through the same service as the current screen.
 */
class PurchaseOrdersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new PurchaseOrdersScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new PurchaseOrdersScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new PurchaseOrdersScreen($request->user()))->show($this->find($id)));
    }

    public function form(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        $po = $id ? $this->find($id) : null;
        abort_unless($po ? PurchaseOrderResource::canEdit($po) : PurchaseOrdersScreen::canCreate(), 403, $po ? 'Only a draft order can be changed.' : 'You cannot make purchase orders.');

        return response()->json((new PurchaseOrdersScreen($request->user()))->form($po));
    }

    /** Product units for a line: buying units of any active product (as the current form's product field). */
    public function units(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json(['results' => (new SuppliersScreen($request->user()))->units(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    /** A line's pack rule and stock in the delivery store, as the form is filled. */
    public function lineInfo(Request $request): JsonResponse
    {
        $this->allowed();
        $d = $request->validate(['supplier' => ['nullable', 'uuid'], 'unit' => ['required', 'uuid'], 'warehouse' => ['nullable', 'uuid']]);
        if (($d['warehouse'] ?? null) && ! array_key_exists($d['warehouse'], Fields::warehouses(['purchasing.manage', 'purchasing.view']))) {
            $d['warehouse'] = null; // only stores the person may see
        }

        return response()->json((new PurchaseOrdersScreen($request->user()))->lineInfo($d['supplier'] ?? null, $d['unit'], $d['warehouse'] ?? null));
    }

    public function save(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        $request->validate([
            'values' => ['required', 'array'], 'values.supplier_id' => ['required', 'uuid'], 'values.warehouse_id' => ['required', 'uuid'],
            'values.expected_on' => ['nullable', 'date'], 'values.department_id' => ['nullable', 'uuid'], 'values.note' => ['nullable', 'string', 'max:5000'],
            'values.lines' => ['required', 'array', 'min:1', 'max:500'], 'values.lines.*.kind' => ['required', Rule::in(['stock', 'service'])],
            'values.lines.*.qty' => ['required', 'numeric', 'gt:0'], 'values.lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'values.lines.*.description' => ['nullable', 'string', 'max:160'], 'values.lines.*.uom_code' => ['nullable', 'string', 'max:10'],
        ], ['values.supplier_id.required' => 'Choose the supplier.', 'values.warehouse_id.required' => 'Choose where it is delivered.',
            'values.lines.required' => 'Add at least one line.', 'values.lines.*.qty.gt' => 'Enter a quantity above zero on every line.',
            'values.lines.*.qty.required' => 'Enter a quantity on every line.']);
        $po = $id ? $this->find($id) : new PurchaseOrder;
        $v = (array) $request->input('values');

        return $this->act($request, fn (Actor $actor) => ['id' => app(PurchaseOrderService::class)->saveDraft($actor, $po, (new PurchaseOrdersScreen($actor->user))->toDomain($v))->id]);
    }

    /** A supplier added from the order form (name, phone, email); one already there under that name or code is chosen instead. */
    public function addSupplier(Request $request): JsonResponse
    {
        $this->allowed();
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:20'], 'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255']]);
        if ($existing = QuickCreate::existing('supplier', $d)) {
            return response()->json(['id' => $existing->id, 'label' => $existing->name, 'existed' => true]);
        }

        return $this->act($request, function (Actor $actor) use ($d) {
            $s = app(SupplierService::class)->save($actor, new Supplier, ['code' => $d['code'] ?? null, 'name' => $d['name'], 'phone' => $d['phone'] ?? null, 'email' => $d['email'] ?? null]);

            return ['id' => $s->id, 'label' => $s->name, 'existed' => false];
        });
    }

    /** The batch and expiry in a GS1 pack code (the square code on medicine boxes), to fill a received line. */
    public function packCode(Request $request): JsonResponse
    {
        $this->allowed();
        $code = Gs1::parse(mb_substr((string) $request->query('code', ''), 0, 200));

        return response()->json(['batch' => $code['batch'] ?? null, 'expires_on' => $code['expires_on'] ?? null]);
    }

    /** The current page's buttons: submit, approve, reject, send, copy, confirm_delivered, receive, close_short, cancel. */
    public function action(Request $request, string $id): JsonResponse
    {
        $this->allowed();
        $a = $request->validate(['action' => ['required', Rule::in(['submit', 'approve', 'reject', 'send', 'copy', 'confirm_delivered', 'receive', 'close_short', 'cancel'])]])['action'];
        $d = $request->validate(match ($a) {
            'reject', 'close_short', 'cancel' => ['reason' => ['required', 'string', 'max:255']],
            'send' => ['channel' => ['required', 'string'], 'to' => ['required', 'string', 'max:160'], 'note' => ['nullable', 'string', 'max:500']],
            'confirm_delivered' => ['qtys' => ['required', 'array'], 'qtys.*' => ['nullable', 'numeric', 'min:0'], 'note' => ['nullable', 'string', 'max:255']],
            'receive' => ['supplier_ref' => ['nullable', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array'],
                'lines.*.purchase_order_line_id' => ['required', 'uuid'], 'lines.*.qty' => ['required', 'numeric', 'min:0'], 'lines.*.bin_id' => ['nullable', 'uuid'],
                'lines.*.batch_no' => ['nullable', 'string', 'max:60'], 'lines.*.expires_on' => ['nullable', 'date'], 'lines.*.serials' => ['nullable', 'array'],
                'lines.*.serials.*' => ['string', 'max:80']],
            default => [],
        }, ['reason.required' => 'Say why.', 'to.required' => 'Enter where to send it.', 'lines.*.qty.required' => 'Enter what came on every line (0 if none).']);
        $po = $this->find($id);
        $svc = app(PurchaseOrderService::class);

        return $this->act($request, function (Actor $actor) use ($a, $d, $po, $svc) {
            $extra = [];
            if ($a === 'submit') {
                $svc->submit($actor, $po);
            } elseif ($a === 'approve') {
                $svc->approve($actor, $po);
            } elseif ($a === 'reject') {
                $svc->reject($actor, $po, $d['reason']);
            } elseif ($a === 'send') {
                $extra['open'] = app(OrderSender::class)->send($actor, $po, $d['channel'], $d['to'], $d['note'] ?? null)['open'];
            } elseif ($a === 'copy') {
                $copy = $svc->copy($actor, $po);
                $extra['copy'] = ['id' => $copy->id, 'number' => $copy->number];
            } elseif ($a === 'confirm_delivered') {
                $svc->confirmDelivered($actor, $po, array_map(fn ($q) => (string) ($q ?? ''), $d['qtys']), $d['note'] ?? null);
            } elseif ($a === 'receive') {
                $grn = app(GoodsReceiptService::class)->receive($actor, $po, ['supplier_ref' => $d['supplier_ref'] ?? null, 'note' => $d['note'] ?? null,
                    'lines' => array_map(fn ($l) => array_filter($l, fn ($x) => $x !== null && $x !== '' && $x !== []), $d['lines'])]);
                $extra['receipt'] = ['number' => $grn->number, 'held' => $grn->status === 'held'];
            } elseif ($a === 'close_short') {
                $svc->closeShort($actor, $po, $d['reason']);
            } else {
                $svc->cancel($actor, $po, $d['reason']);
            }

            return ['order' => (new PurchaseOrdersScreen($actor->user))->show($po->fresh())] + $extra;
        });
    }

    /** "Order from a file": an Excel or CSV list, or a supplier's pro-forma read by the AI; a draft to check. */
    public function import(Request $request): JsonResponse
    {
        $this->allowed();
        abort_unless(PurchaseOrdersScreen::canCreate(), 403, 'You cannot make purchase orders.');
        $d = $request->validate([
            'source' => ['required', Rule::in(['sheet', 'proforma'])], 'supplier_id' => ['required_if:source,sheet', 'nullable', 'uuid'], 'warehouse_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:10240'],
        ], ['supplier_id.required_if' => 'Choose the supplier for a list.', 'file.required' => 'Attach the file.', 'file.max' => 'The file is larger than 10 MB.']);
        $file = $request->file('file');
        $types = $d['source'] === 'proforma' ? InvoiceScanner::TYPES : OrderImporter::SHEET_TYPES;
        if (! in_array($file->getMimeType(), $types, true) && ! ($d['source'] === 'sheet' && in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'xlsx', 'xls', 'txt'], true))) {
            return response()->json(['error' => 'file_type', 'detail' => $d['source'] === 'proforma' ? 'Attach a photo (JPEG, PNG, WebP) or a PDF.' : 'Attach an Excel or CSV file.'], 422);
        }
        abort_unless(array_key_exists($d['warehouse_id'], Fields::warehouses('purchasing.manage')), 422, 'Choose one of the stores offered.');
        $warehouse = Warehouse::findOrFail($d['warehouse_id']);
        $supplier = filled($d['supplier_id'] ?? null) ? Supplier::findOrFail($d['supplier_id']) : null;
        $name = mb_substr($file->getClientOriginalName(), 0, 255);
        $path = $file->store('purchase-imports', 'local');

        $answer = $this->act($request, function (Actor $actor) use ($d, $warehouse, $supplier, $path, $name) {
            $r = $d['source'] === 'proforma'
                ? app(OrderImporter::class)->fromProforma($actor, $warehouse, $supplier, $path, $name)
                : app(OrderImporter::class)->fromSheet($actor, $supplier, $warehouse, Storage::disk('local')->path($path), $name);

            return ['id' => $r['order']->id, 'number' => $r['order']->number, 'lines' => $r['order']->lines->count(), 'skipped' => array_values($r['skipped'])];
        });
        if ($d['source'] === 'sheet' || $answer->getStatusCode() !== 200) {
            Storage::disk('local')->delete($path); // the list is in the order now; a pro-forma photo stays with the AI record
        }

        return $answer;
    }

    private function find(string $id): PurchaseOrder
    {
        return PurchaseOrderResource::getEloquentQuery()->findOrFail($id);
    }

    private function act(Request $request, Closure $fn): JsonResponse
    {
        $actor = Actor::user($request->user(), 'web');
        try {
            return response()->json(DB::transaction(fn () => $fn($actor)));
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => ConfirmItsYou::getUrl()], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(PurchaseOrdersScreen::canView(), 403, 'You cannot see purchase orders.');
    }
}
