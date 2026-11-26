<?php

namespace App\Http\Controllers\Office;

use App\Domain\Channels\ChannelAdmin;
use App\Domain\Channels\OnlineOrderService;
use App\Domain\Channels\Reconciler;
use App\Domain\Channels\WhatsappOrders;
use App\Domain\Office\OnlineScreen;
use App\Domain\Office\StockScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Service\ServiceDesk;
use App\Domain\Support\Actor;
use App\Filament\Resources\SalesChannels\SalesChannelResource;
use App\Filament\Support\Erp;
use App\Filament\Support\ServiceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\SalesChannel;
use App\Models\Ticket;
use App\Models\WhatsappOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Online in the new back office (App\Domain\Office\OnlineScreen): online orders (mark ready, hand over, cancel),
 * channels (save, activate, pause, compare with the store, retry failed updates, archive) and WhatsApp orders (make the
 * order, reply, decline, make a ticket) — each through the services the current pages use.
 */
class OnlineController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): OnlineScreen
    {
        $this->allowed($can);

        return new OnlineScreen($r->user());
    }

    // -------------------------------------------------------------------------------------------- Online orders

    public function orders(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, OnlineScreen::canOrders())->orders($request->query()));
    }

    public function order(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, OnlineScreen::canOrders());

        return response()->json($screen->order($screen->ordersQuery()->with('lines')->findOrFail($id)));
    }

    /** ready · fulfil (payment method and reference when not paid) · cancel (reason). */
    public function orderAction(Request $request, string $id, OnlineOrderService $orders): JsonResponse
    {
        $o = $this->screen($request, OnlineScreen::canOrders())->ordersQuery()->findOrFail($id);
        $this->allowed(Erp::can('orders.manage', $o->branch));
        switch ((string) $request->input('action')) {
            case 'ready':
                return $this->act(fn (Actor $actor) => ['message' => $orders->markReady($actor, $o) ? 'Marked ready for pickup.' : '']);
            case 'fulfil':
                $d = $o->payment_status === 'paid' ? [] : $request->validate(['payment_method' => ['required', Rule::in(array_keys(OnlineOrderService::PAYMENT_METHODS))],
                    'payment_reference' => ['nullable', 'string', 'max:80']], ['payment_method.*' => 'Say how it was paid.']);

                return $this->act(fn (Actor $actor) => ['message' => $orders->fulfil($actor, $o, $d['payment_method'] ?? null, ($d['payment_reference'] ?? '') ?: null) ? 'Handed over.' : '']);
            case 'cancel':
                $reason = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Say why.'])['reason'];

                return $this->act(fn (Actor $actor) => ['message' => $orders->cancel($actor, $o, $reason) ? 'Cancelled.' : '']);
        }
        abort(422, 'Unknown action.');
    }

    // -------------------------------------------------------------------------------------------- Channels

    public function channels(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, OnlineScreen::canChannels())->channels($request->query()));
    }

    public function channel(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, OnlineScreen::canChannels());

        return response()->json($screen->channel($id ? $screen->channelsQuery()->findOrFail($id) : null));
    }

    public function channelSave(Request $request, ChannelAdmin $admin, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, SalesChannelResource::canCreate());
        $d = $request->validate(['type' => [$id ? 'nullable' : 'required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:80'], 'branch_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'], 'price_list_id' => ['required', 'uuid'], 'safety_stock' => ['nullable', 'numeric', 'min:0'], 'base_url' => ['nullable', 'url', 'max:255'],
            'slug' => ['nullable', 'string', 'max:60'], 'headline' => ['nullable', 'string', 'max:120'], 'intro' => ['nullable', 'string', 'max:500'],
            'pickup_note' => ['nullable', 'string', 'max:500'], 'secrets' => ['nullable', 'array'], 'secrets.*' => ['nullable', 'string', 'max:500']],
            ['name.*' => 'Name the channel.', 'branch_id.*' => 'Choose the fulfilling branch.', 'warehouse_id.*' => 'Choose the warehouse the stock comes from.',
                'price_list_id.*' => 'Choose the price list.', 'base_url.*' => 'Enter the store address (https://…).']);
        $c = $id ? $screen->channelsQuery()->findOrFail($id) : new SalesChannel;
        $data = ['type' => $d['type'] ?? null, 'name' => $d['name'], 'branch_id' => $d['branch_id'], 'warehouse_id' => $d['warehouse_id'], 'price_list_id' => $d['price_list_id'],
            'safety_stock' => (string) ($d['safety_stock'] ?? '0'), 'base_url' => ($d['base_url'] ?? '') ?: null];
        if (($c->exists ? $c->type : $data['type']) === 'storefront') {
            $data['settings'] = ['slug' => $d['slug'] ?? '', 'headline' => $d['headline'] ?? '', 'intro' => $d['intro'] ?? '', 'pickup_note' => $d['pickup_note'] ?? ''];
        }
        $secrets = array_filter($d['secrets'] ?? [], fn ($v) => is_string($v) && trim($v) !== ''); // blank keeps the one saved

        return $this->act(fn (Actor $actor) => ['id' => $admin->save($actor, $c, $data, $secrets)->id, 'message' => 'Saved.']);
    }

    /** activate · pause (reason) · reconcile (compare with the store) · retry (failed updates). */
    public function channelAction(Request $request, string $id, ChannelAdmin $admin, Reconciler $reconciler): JsonResponse
    {
        $c = $this->screen($request, OnlineScreen::canChannels())->channelsQuery()->findOrFail($id);
        switch ((string) $request->input('action')) {
            case 'activate':
                return $this->act(fn (Actor $actor) => ['message' => $admin->activate($actor, $c) ? 'Activated.' : '']);
            case 'pause':
                $reason = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Say why.'])['reason'];

                return $this->act(fn (Actor $actor) => ['message' => $admin->pause($actor, $c, $reason) ? 'Paused.' : '']);
            case 'retry':
                return $this->act(fn (Actor $actor) => ['message' => $admin->retryFailed($actor, $c).' update(s) queued again.']);
            case 'reconcile':
                return $this->act(function () use ($reconciler, $c) {
                    $diffs = $reconciler->run($c);

                    return ['message' => $diffs ? count($diffs).' difference(s) found and queued to fix.' : 'Store matches.',
                        'details' => collect($diffs)->take(10)->map(fn ($d) => "{$d['sku']}: {$d['field']} store {$d['store']}, ours {$d['erp']}")->values()->all()];
                });
        }
        abort(422, 'Unknown action.');
    }

    public function channelArchive(Request $request, string $id, ArchiveService $svc): JsonResponse
    {
        $c = $this->screen($request, OnlineScreen::canChannels())->channelsQuery()->findOrFail($id);
        $d = $request->validate(['restore' => ['boolean'], 'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);
        $this->allowed($svc->allowed($request->user(), $c));

        return $this->act(function (Actor $actor) use ($svc, $c, $d) {
            ($d['restore'] ?? false) ? $svc->restore($actor, $c) : $svc->archive($actor, $c, $d['reason']);

            return ['message' => ($d['restore'] ?? false) ? 'Restored.' : 'Archived.'];
        });
    }

    // -------------------------------------------------------------------------------------------- WhatsApp orders

    public function whatsapp(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, OnlineScreen::canWhatsapp())->whatsapp($request->query()));
    }

    public function message(Request $request, string $id): JsonResponse
    {
        return response()->json($this->screen($request, OnlineScreen::canWhatsapp())->message(WhatsappOrder::findOrFail($id)));
    }

    /** make_order (branch, pickup or delivery, items, note) · reply (text) · decline (reason) · ticket (kind, priority). */
    public function messageAction(Request $request, string $id, WhatsappOrders $svc): JsonResponse
    {
        $this->screen($request, OnlineScreen::canWhatsapp());
        $o = WhatsappOrder::findOrFail($id);
        switch ((string) $request->input('action')) {
            case 'make_order':
                $d = $request->validate(['branch_id' => ['required', 'uuid'], 'fulfilment' => ['required', 'in:pickup,delivery'], 'address' => ['required_if:fulfilment,delivery', 'nullable', 'string', 'max:500'],
                    'lines' => ['required', 'array', 'min:1'], 'lines.*.product_uom_id' => ['required', 'uuid'], 'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
                    'note' => ['nullable', 'string', 'max:255']], ['branch_id.*' => 'Choose the branch that fills the order.', 'address.*' => 'Enter where to deliver.',
                        'lines.*' => 'Add at least one item.', 'lines.*.product_uom_id.*' => 'Choose the item and its unit.', 'lines.*.qty.*' => 'Enter how many.']);
                $d['lines'] = array_map(fn ($l) => ['product_uom_id' => $l['product_uom_id'], 'qty' => (string) $l['qty']], $d['lines']);

                return $this->act(fn (Actor $actor) => ['message' => 'Order made; the customer was sent the total.', 'open' => '/office/online/orders/'.$svc->makeOrder($actor, $o, $d)->id]);
            case 'reply':
                $text = $request->validate(['text' => ['required', 'string', 'max:1000']], ['text.*' => 'Write the message.'])['text'];

                return $this->act(function (Actor $actor) use ($svc, $o, $text) {
                    $svc->reply($actor, $o, $text);

                    return ['message' => 'Sent.'];
                });
            case 'decline':
                $reason = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Say why (the customer sees this).'])['reason'];

                return $this->act(fn (Actor $actor) => ['message' => $svc->decline($actor, $o, $reason) ? 'Declined; the customer was told.' : '']);
            case 'ticket':
                $this->allowed(ServiceUi::can('helpdesk.work'));
                $d = $request->validate(['kind' => ['required', Rule::in(array_keys(array_diff_key(Ticket::KINDS, ['internal' => 1])))], 'priority' => ['required', Rule::in(array_keys(Ticket::PRIORITIES))]]);

                return $this->act(fn (Actor $actor) => ['message' => 'Ticket opened.', 'open' => '/office/tickets/'.app(ServiceDesk::class)->fromWhatsappOrder($actor, $o, $d)->id]);
        }
        abort(422, 'Unknown action.');
    }

    /** Items for "Make the order". */
    public function units(Request $request): JsonResponse
    {
        $this->screen($request, OnlineScreen::canWhatsapp());

        return response()->json(['results' => (new StockScreen($request->user()))->units(mb_substr(trim((string) $request->query('q', '')), 0, 60), false)]);
    }
}
