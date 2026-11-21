<?php

namespace App\Http\Controllers\Office;

use App\Domain\Customers\CustomerService;
use App\Domain\Office\CustomerOrdersScreen;
use App\Domain\Sales\CustomerOrderService;
use App\Domain\Sales\DeliveryService;
use App\Domain\Support\Actor;
use App\Filament\Resources\CustomerOrders\CustomerOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\QuickCreate;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Quotes & orders in the new back office (App\Domain\Office\CustomerOrdersScreen): the list, one quote or order, the
 * quotation form, and the current page's buttons, each through CustomerOrderService or DeliveryService as the current
 * screen. A record is found only within the branches the person may see.
 */
class CustomerOrdersController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r): CustomerOrdersScreen
    {
        $this->allowed(CustomerOrdersScreen::canView());

        return new CustomerOrdersScreen($r->user());
    }

    private function find(string $id): CustomerOrder
    {
        return CustomerOrderResource::getEloquentQuery()->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->screen($request)->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json($this->screen($request)->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request);

        return response()->json($screen->show($this->find($id)));
    }

    public function form(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request);
        $o = $id ? $this->find($id) : null;
        abort_unless($o ? CustomerOrderResource::canEdit($o) : CustomerOrdersScreen::canCreate(), 403, $o ? 'Only a quotation can be changed.' : 'You cannot make quotations.');

        return response()->json($screen->form($o));
    }

    public function customers(Request $request): JsonResponse
    {
        return response()->json(['results' => $this->screen($request)->customers(mb_substr((string) $request->query('q', ''), 0, 60))]);
    }

    public function units(Request $request): JsonResponse
    {
        return response()->json(['results' => $this->screen($request)->units(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    /** What the chosen store holds of a line's product ("Available"), only for a store the person may quote from. */
    public function available(Request $request): JsonResponse
    {
        $screen = $this->screen($request);
        $d = $request->validate(['unit' => ['required', 'uuid'], 'warehouse' => ['required', 'uuid']]);
        abort_unless(array_key_exists($d['warehouse'], Fields::warehouses('sales.orders.manage')), 404);

        return response()->json(['available' => $screen->available($d['unit'], $d['warehouse'])]);
    }

    public function save(Request $request, ?string $id = null): JsonResponse
    {
        $this->screen($request);
        $d = $request->validate([
            'customer_id' => ['required', 'uuid'], 'warehouse_id' => ['required', Rule::in(array_keys(Fields::warehouses('sales.orders.manage')))],
            'valid_until' => ['nullable', 'date_format:Y-m-d'], 'customer_ref' => ['nullable', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.product_uom_id' => ['required', 'uuid'], 'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
        ], ['customer_id.*' => 'Choose the customer.', 'warehouse_id.*' => 'Choose a store you may supply from.', 'lines.required' => 'Add at least one line.',
            'lines.*.product_uom_id.*' => 'Choose the product on every line.', 'lines.*.qty.*' => 'Enter a quantity above zero on every line.']);
        $o = $id ? $this->find($id) : new CustomerOrder;
        if ($id) {
            abort_unless(CustomerOrderResource::canEdit($o), 403, 'Only a quotation can be changed.');
        }

        return $this->act(fn (Actor $actor) => ['id' => app(CustomerOrderService::class)->saveQuotation($actor, $o, [
            'customer_id' => $d['customer_id'], 'warehouse_id' => $d['warehouse_id'], 'valid_until' => $d['valid_until'] ?? null,
            'customer_ref' => ($d['customer_ref'] ?? '') ?: null, 'note' => ($d['note'] ?? '') ?: null,
            'lines' => array_map(fn ($l) => ['product_uom_id' => $l['product_uom_id'], 'qty' => (string) $l['qty']], $d['lines']),
        ])->id]);
    }

    /** A customer added from the quotation (name, phone, email); one already there with that phone is chosen instead. */
    public function addCustomer(Request $request): JsonResponse
    {
        $this->screen($request);
        abort_unless(Erp::canAnywhere('customers.manage'), 403, 'You cannot add customers.');
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255']],
            ['name.*' => 'Give the customer a name.', 'email.*' => 'That e-mail address does not look right.']);
        if ($existing = QuickCreate::existing('customer', $d)) {
            return response()->json(['id' => $existing->id, 'label' => $existing->name, 'existed' => true]);
        }

        return $this->act(function (Actor $actor) use ($d) {
            $c = app(CustomerService::class)->save($actor, new Customer, ['name' => $d['name'], 'phone' => $d['phone'] ?? null, 'email' => $d['email'] ?? null]);

            return ['id' => $c->id, 'label' => $c->name, 'existed' => false];
        });
    }

    /** The current page's buttons: confirm, release, deliver, cancel. */
    public function action(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request);
        $a = $request->validate(['action' => ['required', Rule::in(['confirm', 'release', 'deliver', 'cancel'])]])['action'];
        $d = $request->validate(match ($a) {
            'release', 'cancel' => ['reason' => ['required', 'string', 'max:255']],
            'deliver' => ['qtys' => ['required', 'array'], 'qtys.*' => ['nullable', 'numeric', 'min:0'], 'delivered_to' => ['nullable', 'string', 'max:255'],
                'vehicle' => ['nullable', 'string', 'max:40']],
            default => [],
        }, ['reason.required' => 'Say why.', 'qtys.*' => 'Enter what is delivered now on each line (0 if none).']);
        $o = $this->find($id);

        return $this->act(function (Actor $actor) use ($a, $d, $o, $screen) {
            $svc = app(CustomerOrderService::class);
            $message = match ($a) {
                'confirm' => ($x = $svc->confirm($actor, $o))->status === 'on_hold'
                    ? "{$x->number} is on credit hold: {$x->hold_reason}" : "Order {$x->number} confirmed.",
                'release' => ($svc->release($actor, $o, $d['reason']) ? 'Released.' : ''),
                'cancel' => ($svc->cancel($actor, $o, $d['reason']) ? 'Done.' : ''),
                'deliver' => (function () use ($actor, $o, $d) {
                    $r = app(DeliveryService::class)->deliver($actor, $o, array_map(fn ($q) => (string) ($q ?? '0'), $d['qtys']),
                        ['delivered_to' => $d['delivered_to'] ?? null, 'vehicle' => $d['vehicle'] ?? null]);

                    return "{$r['delivery']->number} delivered. Invoice {$r['invoice']->number}: ".Money::display($r['invoice']->total_minor, $r['invoice']->currency_code).'.';
                })(),
            };
            $fresh = $o->fresh();

            return ['message' => $message, 'held' => $fresh->status === 'on_hold', 'order' => $screen->show($fresh)];
        });
    }
}
