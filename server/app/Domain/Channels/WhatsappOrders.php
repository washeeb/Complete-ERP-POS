<?php

namespace App\Domain\Channels;

use App\Domain\Messaging\Gateways;
use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\Phone;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageProvider;
use App\Models\PriceList;
use App\Models\ProductUom;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Models\WhatsappOrder;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use App\Support\Settings;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Orders by WhatsApp (owner request 2026-10-24). Meta calls this server with each message a customer sends to the
 * business number (signed with the app secret); text and photos (a prescription) from one number close together
 * make one entry. Staff turn it into an online order on the branch's WhatsApp channel — stock is held for it and it is
 * picked up or delivered like any online order — or decline it; the customer is answered on WhatsApp each time.
 */
final class WhatsappOrders
{
    public const PERMISSION = 'orders.manage';

    /** Messages from the same number within this many hours join the open entry (a list, then a photo). */
    public const JOIN_HOURS = 2;

    public const MAX_MEDIA_BYTES = 10 * 1048576;

    public const MEDIA_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    /** Tries to fetch a photo before it is marked as not fetched. */
    public const MEDIA_TRIES = 5;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly Messenger $messenger,
        private readonly OnlineOrderService $orders,
        private readonly DocumentNumbers $numbers,
        private readonly PriceResolver $prices,
    ) {}

    /** Meta's check that the address is ours: answer its challenge when the token matches. */
    public function verify(string $providerId, ?string $mode, ?string $token, ?string $challenge): ?string
    {
        $expected = (string) ($this->provider($providerId)?->credentials['verify_token'] ?? '');

        return $mode === 'subscribe' && $expected !== '' && hash_equals($expected, (string) $token) ? (string) $challenge : null;
    }

    /** A call from Meta: false when the signature is wrong (refused); messages kept when WhatsApp orders are on. */
    public function receive(string $providerId, string $raw, ?string $signature): bool
    {
        $p = $this->provider($providerId);
        $secret = (string) ($p?->credentials['app_secret'] ?? '');
        if (! $p || $secret === '' || ! $signature || ! hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature)) {
            return false;
        }
        app(TenantContext::class)->set($p->tenant_id);
        if (! app(Settings::class)->get('whatsapp.orders', $p->tenant_id)) {
            return true; // switched off: Meta is answered, nothing is kept
        }
        $payload = json_decode($raw, true);
        $touched = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $names = collect($value['contacts'] ?? [])->mapWithKeys(fn ($c) => [(string) ($c['wa_id'] ?? '') => $c['profile']['name'] ?? null]);
                foreach ((array) ($value['messages'] ?? []) as $msg) {
                    if (is_array($msg) && ($o = $this->store($p->tenant_id, $msg, $names[(string) ($msg['from'] ?? '')] ?? null))) {
                        $touched[$o->id] = $o;
                    }
                }
            }
        }
        if ($touched) {
            // Photos are fetched after Meta has its answer (it waits only seconds); erp:whatsapp-media catches up.
            defer(fn () => collect($touched)->each(fn (WhatsappOrder $o) => $this->fetchMedia($o->fresh())));
        }

        return true;
    }

    /** One message: added to the customer's open entry, or a new entry (then the automatic reply). */
    private function store(string $tenantId, array $msg, ?string $name): ?WhatsappOrder
    {
        $type = (string) ($msg['type'] ?? '');
        $id = (string) ($msg['id'] ?? '');
        $from = Phone::international((string) ($msg['from'] ?? ''));
        if ($id === '' || ! Phone::looksValid($from) || ! in_array($type, ['text', 'image', 'document'], true)) {
            return null; // reactions, stickers, voice notes, delivery receipts: not an order
        }
        if (WhatsappOrder::whereJsonContains('message_ids', $id)->exists()) {
            return null; // Meta sends a message again when it is not sure it arrived
        }
        $text = trim((string) ($type === 'text' ? ($msg['text']['body'] ?? '') : ($msg[$type]['caption'] ?? '')));
        $media = $type === 'text' ? null : ['id' => (string) ($msg[$type]['id'] ?? ''), 'mime' => (string) ($msg[$type]['mime_type'] ?? ''),
            'name' => isset($msg[$type]['filename']) ? mb_substr((string) $msg[$type]['filename'], 0, 120) : null, 'path' => null, 'tries' => 0, 'error' => null];
        $actor = Actor::system($tenantId, 'WhatsApp', 'whatsapp');

        [$o, $new] = $this->recorder->transaction(function () use ($tenantId, $from, $name, $text, $media, $id, $actor) {
            $open = WhatsappOrder::where('from_phone', $from)->where('status', 'new')->where('last_message_at', '>=', now()->subHours(self::JOIN_HOURS))
                ->latest('last_message_at')->lockForUpdate()->first();
            $o = $open ?? new WhatsappOrder(['tenant_id' => $tenantId, 'number' => $this->numbers->next($tenantId, 'whatsapp_order'), 'from_phone' => $from,
                'status' => 'new', 'received_at' => now(), 'customer_id' => $this->customerFor($from), 'media' => [], 'message_ids' => []]);
            $o->from_name = $name ? mb_substr($name, 0, 120) : $o->from_name;
            if ($text !== '') {
                $o->body = mb_substr(trim(($o->body ? $o->body."\n" : '').($media ? '['.($media['mime'] === 'application/pdf' ? 'Document' : 'Photo').'] ' : '').$text), 0, 10000);
            }
            if ($media && $media['id'] !== '') {
                $o->media = [...($o->media ?? []), $media];
            }
            $o->message_ids = [...($o->message_ids ?? []), $id];
            $o->last_message_at = now();
            $o->save();
            $this->recorder->audit->record($actor, $open ? 'whatsapp_order.message' : 'whatsapp_order.received', $o, ['from' => [null, $from], 'type' => [null, $media ? 'file' : 'text']]);

            return [$o, ! $open];
        });
        if ($new && ($reply = trim((string) app(Settings::class)->get('whatsapp.order_reply', $tenantId))) !== '') {
            defer(fn () => $this->say($o, str_replace('{number}', $o->number, $reply), 'WhatsApp orders'));
        }

        return $o;
    }

    /** Photos and documents still to fetch from Meta (stored privately: staff see them through the back office). */
    public function fetchMedia(?WhatsappOrder $o): int
    {
        $p = $o ? $this->providerFor($o->tenant_id) : null;
        if (! $o || ! $p) {
            return 0;
        }
        $media = $o->media ?? [];
        $n = 0;
        foreach ($media as $i => $m) {
            if (($m['path'] ?? null) || ($m['tries'] ?? 0) >= self::MEDIA_TRIES) {
                continue;
            }
            $media[$i]['tries'] = ($m['tries'] ?? 0) + 1;
            try {
                $file = Gateways::whatsappMedia($p->credentials ?? [], (string) $m['id'], self::MAX_MEDIA_BYTES);
                $mime = strtolower(trim(explode(';', $file['mime'])[0]));
                $ext = self::MEDIA_TYPES[$mime] ?? throw new DomainException('Only photos (JPEG, PNG, WebP) and PDF documents are kept.');
                $path = "whatsapp/{$o->tenant_id}/{$o->id}/{$i}.{$ext}";
                Storage::disk('local')->put($path, $file['bytes']);
                $media[$i] = ['path' => $path, 'mime' => $mime, 'error' => null] + $media[$i];
                $n++;
            } catch (Throwable $e) {
                $media[$i]['error'] = Str::limit($e->getMessage(), 200);
                if ($e instanceof DomainException && str_starts_with($e->getMessage(), 'Only photos')) {
                    $media[$i]['tries'] = self::MEDIA_TRIES; // will not change by asking again
                }
            }
        }
        $o->forceFill(['media' => $media])->save();

        return $n;
    }

    /** Every entry with a photo still to fetch (erp:whatsapp-media, every minute). */
    public function fetchPending(): int
    {
        $n = 0;
        foreach (WhatsappOrder::withoutGlobalScopes()->where('status', 'new')->where('last_message_at', '>=', now()->subDays(2))->get() as $o) {
            if (collect($o->media ?? [])->contains(fn ($m) => ! ($m['path'] ?? null) && ($m['tries'] ?? 0) < self::MEDIA_TRIES)) {
                app(TenantContext::class)->set($o->tenant_id);
                $n += $this->fetchMedia($o);
            }
        }

        return $n;
    }

    /**
     * Turn the message into an order at a branch: stock is held for it, and the customer is told what it comes to.
     *
     * @param  array{branch_id: string, fulfilment?: string, address?: ?string, note?: ?string, lines: list<array{product_uom_id: string, qty: string|int|float}>}  $data
     */
    public function makeOrder(Actor $actor, WhatsappOrder $o, array $data): SalesOrder
    {
        $branch = Branch::find($data['branch_id'] ?? null) ?? throw new DomainException('Choose the branch that fills the order.');
        $this->require($actor, $branch);
        if ($o->status !== 'new') {
            throw new DomainException("{$o->number} is already ".mb_strtolower(WhatsappOrder::STATUSES[$o->status] ?? $o->status).'.');
        }
        $delivery = ($data['fulfilment'] ?? 'pickup') === 'delivery';
        if ($delivery && trim((string) ($data['address'] ?? '')) === '') {
            throw new DomainException('Enter where to deliver.');
        }

        return $this->recorder->transaction(function () use ($actor, $o, $data, $branch, $delivery) {
            $channel = $this->channelFor($actor, $branch);
            $lines = [];
            foreach (array_values($data['lines'] ?? []) as $l) {
                $unit = ProductUom::with('product')->where('is_sellable', true)->find($l['product_uom_id'] ?? null) ?? throw new DomainException('Choose a product on each line.');
                $qty = Qty::toMilli((string) ($l['qty'] ?? '0'));
                if ($qty <= 0) {
                    throw new DomainException("Enter how many {$unit->product->name}.");
                }
                $price = $this->prices->resolve($channel->priceList, $unit->product_id, $unit->id, $qty, now())
                    ?? throw new DomainException("{$unit->product->name} has no price on {$channel->priceList->name}.");
                $lines[] = ['product_uom_id' => $unit->id, 'qty' => Qty::format($qty), 'unit_price_minor' => $price->amount_minor];
            }
            if ($lines === []) {
                throw new DomainException('Add what the customer is ordering.');
            }
            $customer = $o->customer;
            $order = $this->orders->ingest($channel, [
                'external_id' => $o->number, 'number' => $o->number, 'placed_at' => $o->received_at->toIso8601String(),
                'customer' => ['name' => $customer?->name ?? $o->from_name ?? '+'.$o->from_phone, 'phone' => '+'.$o->from_phone, 'email' => $customer?->email],
                'fulfilment' => $delivery ? 'delivery' : 'pickup', 'address' => $delivery ? ['text' => mb_substr(trim((string) $data['address']), 0, 500)] : null,
                'payment_status' => 'unpaid', 'note' => trim(trim((string) ($data['note'] ?? ''))."\nWhatsApp: ".Str::limit((string) $o->body, 1000)),
                'lines' => $lines,
            ])['order'];
            $o->forceFill(['status' => 'ordered', 'sales_order_id' => $order->id, 'handled_by_name' => $actor->name, 'handled_at' => now()])->save();
            $this->recorder->audit->record($actor, 'whatsapp_order.ordered', $o, ['status' => ['new', 'ordered'], 'sales_order' => [null, $order->number]], null, $branch->id);
            DB::afterCommit(fn () => $this->say($o, $this->confirmation($order->load('lines'), $branch), $actor->name));

            return $order;
        });
    }

    public function decline(Actor $actor, WhatsappOrder $o, string $reason): WhatsappOrder
    {
        $this->require($actor);
        if ($o->status !== 'new') {
            throw new DomainException("{$o->number} is already ".mb_strtolower(WhatsappOrder::STATUSES[$o->status] ?? $o->status).'.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Say why, so the customer knows (e.g. out of stock, needs a prescription).');
        }

        return $this->recorder->transaction(function () use ($actor, $o, $reason) {
            $o->forceFill(['status' => 'declined', 'decline_reason' => mb_substr($reason, 0, 255), 'handled_by_name' => $actor->name, 'handled_at' => now()])->save();
            $this->recorder->audit->record($actor, 'whatsapp_order.declined', $o, ['status' => ['new', 'declined']], $reason);
            DB::afterCommit(fn () => $this->say($o, "Sorry, we cannot fill your order {$o->number}: {$reason}", $actor->name));

            return $o;
        });
    }

    /** A message from staff to the customer (a question about the order, a price). */
    public function reply(Actor $actor, WhatsappOrder $o, string $text): void
    {
        $this->require($actor);
        $text = trim($text);
        if ($text === '') {
            throw new DomainException('Write the message.');
        }
        $this->recorder->audit->record($actor, 'whatsapp_order.replied', $o, ['message' => [null, Str::limit($text, 200)]]);
        $m = $this->say($o, $text, $actor->name);
        if ($m && in_array($m->status, ['failed', 'not_configured'], true)) {
            throw new DomainException('Not sent: '.$m->error);
        }
    }

    /** The customer is told their WhatsApp order is ready (OnlineOrderService::markReady). */
    public function tellReady(SalesOrder $order): void
    {
        $o = WhatsappOrder::where('sales_order_id', $order->id)->first();
        if ($o) {
            $branch = Branch::find($order->branch_id);
            $this->say($o, $order->fulfilment === 'delivery' ? "Your order {$o->number} is on its way."
                : "Your order {$o->number} is ready. You can pick it up at {$branch?->name}".($branch?->address ? " ({$branch->address})" : '').'.', 'WhatsApp orders');
        }
    }

    private function confirmation(SalesOrder $order, Branch $branch): string
    {
        $lines = $order->lines->map(fn ($l) => '• '.rtrim(rtrim((string) $l->qty, '0'), '.').' × '.$l->name)->implode("\n");

        return "Your order {$order->number} is confirmed:\n{$lines}\nTotal ".Money::display($order->total_minor, $order->currency_code).'. '
            .($order->fulfilment === 'delivery' ? 'We will deliver it.' : "We will tell you when it is ready to pick up at {$branch->name}.");
    }

    /** Sent and logged under Messaging; a WhatsApp provider that is not set up is logged, never an error here. */
    private function say(WhatsappOrder $o, string $text, string $by): ?Message
    {
        try {
            return $this->messenger->send($o->tenant_id, 'whatsapp', '+'.$o->from_phone, $text, null, $o->number, $by, Messenger::REPLY);
        } catch (Throwable) {
            return null;
        }
    }

    /** The branch's WhatsApp channel, made the first time (its main store and the default price list). */
    private function channelFor(Actor $actor, Branch $branch): SalesChannel
    {
        $channel = SalesChannel::where('type', 'whatsapp')->where('branch_id', $branch->id)->first();
        if ($channel) {
            if (! $channel->isActive()) {
                throw new DomainException("The WhatsApp channel of {$branch->name} is paused (Online → Channels).");
            }

            return $channel;
        }
        $warehouse = Warehouse::where('branch_id', $branch->id)->where('is_van', false)->where('is_active', true)->orderBy('code')->first()
            ?? throw new DomainException("{$branch->name} has no store to sell from.");
        $list = PriceList::where('is_default', true)->first() ?? throw new DomainException('Set a default price list first.');
        $channel = new SalesChannel;
        $channel->forceFill(['tenant_id' => $actor->tenantId, 'type' => 'whatsapp', 'name' => "WhatsApp — {$branch->name}", 'branch_id' => $branch->id,
            'warehouse_id' => $warehouse->id, 'price_list_id' => $list->id, 'safety_stock' => '0', 'status' => 'active', 'settings' => []]);

        return $this->recorder->save($actor, $channel, 'channel.created', 'First WhatsApp order at this branch', $branch->id);
    }

    /** A known customer with this mobile number (any way it was typed). */
    private function customerFor(string $intl): ?string
    {
        return Customer::whereNotNull('phone')->whereRaw("regexp_replace(phone, '\\D', '', 'g') LIKE ?", ['%'.substr($intl, -9)])->value('id');
    }

    private function provider(string $id): ?MessageProvider
    {
        return Str::isUuid($id) ? MessageProvider::withoutGlobalScopes()->where('channel', 'whatsapp')->where('is_active', true)->find($id) : null;
    }

    private function providerFor(string $tenantId): ?MessageProvider
    {
        return MessageProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('channel', 'whatsapp')->where('is_active', true)->first();
    }

    private function require(Actor $actor, ?Branch $branch = null): void
    {
        if (! $actor->user || ! ($branch ? $this->authz->allows($actor->user, self::PERMISSION, $branch) : $this->authz->allowsAnywhere($actor->user, self::PERMISSION))) {
            throw new AccessDenied;
        }
    }
}
