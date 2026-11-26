<?php

namespace App\Domain\Office;

use App\Domain\Channels\Connectors\ConnectorRegistry;
use App\Domain\Channels\OnlineOrderService;
use App\Domain\Channels\WhatsappOrders;
use App\Filament\Resources\SalesChannels\SalesChannelResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Filament\Resources\WhatsappOrders\WhatsappOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Support\ServiceUi;
use App\Http\Controllers\StorefrontController;
use App\Models\Branch;
use App\Models\ChannelJob;
use App\Models\PriceList;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WhatsappOrder;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Builder;

/**
 * Online in the new back office (part 33 of the rewrite), as the current pages: Online orders (list, one order), Channels
 * (list, one channel) and WhatsApp orders (list, one message). Same queries (scoped to the person's branches) and rules:
 * the OnlineOrderService, ChannelAdmin and WhatsappOrders services (OnlineController). A channel's secrets are never sent
 * back: only whether each is saved.
 */
final class OnlineScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const OPEN = ['pending', 'confirmed', 'ready'];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    public static function canOrders(): bool
    {
        return SalesOrderResource::canViewAny();
    }

    public static function canChannels(): bool
    {
        return SalesChannelResource::canViewAny();
    }

    public static function canWhatsapp(): bool
    {
        return WhatsappOrderResource::canViewAny();
    }

    // -------------------------------------------------------------------------------------------- Online orders

    public function ordersQuery(): Builder
    {
        return SalesOrderResource::getEloquentQuery();
    }

    private static function orderTone(SalesOrder $o): string
    {
        return $o->short_stock && $o->isOpen() ? 'danger' : match ($o->status) {
            'fulfilled' => 'success', 'cancelled' => 'subtle', 'ready' => 'informative', default => 'warning'
        };
    }

    /** @param  array<string, mixed>  $p  q, status (open by default; one status; all), channel, page, per */
    public function orders(array $p): array
    {
        $q = $this->ordersQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('sales_orders.number', 'ilike', "%{$s}%")->orWhere('customer_name', 'ilike', "%{$s}%")->orWhere('customer_phone', 'ilike', "%{$s}%"));
        }
        $status = (string) ($p['status'] ?? 'open');
        match (true) {
            $status === 'open' => $q->whereIn('sales_orders.status', self::OPEN),
            isset(SalesOrder::STATUSES[$status]) => $q->where('sales_orders.status', $status),
            default => $status = 'all',
        };
        if (($p['channel'] ?? '') !== '') {
            $q->where('channel_id', $p['channel']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'status' => $status, 'statuses' => self::choices(SalesOrder::STATUSES),
            'channels' => self::choices(SalesChannel::orderBy('name')->pluck('name', 'id')->all()),
            'rows' => $q->orderByDesc('placed_at')->forPage($page, $per)->get()->map(fn (SalesOrder $o) => ['id' => $o->id, 'number' => $o->number, 'channel' => $o->channel?->name,
                'placed' => $o->placed_at?->diffForHumans(), 'customer' => $o->customer_name, 'phone' => $o->customer_phone, 'branch' => $o->branch?->code,
                'total' => Money::display((int) $o->total_minor, $o->currency_code), 'payment' => SalesOrderResource::PAYMENT_STATUSES[$o->payment_status] ?? $o->payment_status,
                'paid' => $o->payment_status === 'paid', 'status' => $o->status, 'status_label' => SalesOrder::STATUSES[$o->status] ?? $o->status, 'tone' => self::orderTone($o),
                'short' => (bool) $o->short_stock && $o->isOpen()])->all()];
    }

    public function order(SalesOrder $o): array
    {
        $manage = Erp::can('orders.manage', $o->branch);
        $m = fn ($v) => Money::display((int) $v, $o->currency_code);

        return ['id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'status_label' => SalesOrder::STATUSES[$o->status] ?? $o->status, 'tone' => self::orderTone($o),
            'channel' => $o->channel?->name, 'placed' => $o->placed_at?->toIso8601String(), 'customer' => $o->customer_name, 'phone' => $o->customer_phone,
            'email' => $o->customer_email, 'fulfilment' => ($o->fulfilment === 'pickup' ? 'Pickup' : 'Delivery').' · '.$o->branch?->name,
            'payment' => SalesOrderResource::PAYMENT_STATUSES[$o->payment_status] ?? $o->payment_status, 'paid' => $o->payment_status === 'paid',
            'payment_reference' => $o->payment_reference, 'total' => $m($o->total_minor), 'short' => (bool) $o->short_stock, 'note' => $o->note,
            'cancel_reason' => $o->status === 'cancelled' ? $o->cancel_reason : null, 'methods' => self::choices(OnlineOrderService::PAYMENT_METHODS),
            'lines' => $o->lines->map(fn ($l) => ['id' => $l->id, 'item' => "{$l->sku} · {$l->name}",
                'qty' => rtrim(rtrim(Qty::format(Qty::toMilli((string) $l->qty)), '0'), '.').' '.$l->uom_code, 'price' => $m($l->unit_price_minor), 'total' => $m($l->line_total_minor)])->all(),
            'actions' => ['ready' => $o->status === 'confirmed' && $manage, 'fulfil' => in_array($o->status, ['confirmed', 'ready'], true) && $manage,
                'cancel' => $o->isOpen() && $manage]];
    }

    // -------------------------------------------------------------------------------------------- Channels

    public function channelsQuery(): Builder
    {
        return SalesChannelResource::getEloquentQuery();
    }

    private static function address(SalesChannel $c): ?string
    {
        if ($c->type === 'storefront') {
            return url('/shop/'.($c->settings['slug'] ?? ''));
        }

        return in_array('order_webhooks', app(ConnectorRegistry::class)->for($c->type)->capabilities(), true) ? url("/api/v1/channels/{$c->id}/webhooks") : null;
    }

    /** @param  array<string, mixed>  $p  show */
    public function channels(array $p): array
    {
        $q = $this->channelsQuery()->with('branch');
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('archived_at'), 'both' => null, default => $q->whereNull('archived_at')
        };
        $registry = app(ConnectorRegistry::class);
        $archive = new CompanyScreen($this->user);

        return ['can_create' => SalesChannelResource::canCreate(),
            'rows' => $q->orderBy('name')->get()->map(function (SalesChannel $c) use ($registry, $archive) {
                $waiting = ChannelJob::where('channel_id', $c->id)->where('status', 'pending')->count();
                $failed = ChannelJob::where('channel_id', $c->id)->where('status', 'dead')->count();

                return ['id' => $c->id, 'name' => $c->name, 'type' => $registry->label($c->type), 'branch' => $c->branch?->name, 'status' => $c->status,
                    'tone' => ['active' => 'success', 'paused' => 'warning'][$c->status] ?? 'subtle',
                    'updates' => $c->type === 'storefront' ? 'live' : (implode(' · ', array_filter([$waiting ? "{$waiting} waiting" : null, $failed ? "{$failed} failed" : null])) ?: 'up to date'),
                    'failed' => $failed > 0, 'address' => self::address($c)] + $archive->archiveOf($c);
            })->all()];
    }

    /** The channel form: its fields, and for each kind of channel the secrets it needs (and whether each is saved). */
    public function channel(?SalesChannel $c): array
    {
        $registry = app(ConnectorRegistry::class);
        $types = ConnectorRegistry::availableOptions();
        $secrets = [];
        foreach (array_keys($types) as $type) {
            $connector = $registry->for($type);
            $fields = collect($connector->credentialFields())->map(fn ($label, $field) => ['id' => $field, 'label' => $label,
                'saved' => $c?->type === $type && ! empty($c->credentials[$field])])->values()->all();
            if (in_array('order_webhooks', $connector->capabilities(), true)) {
                $fields[] = ['id' => 'webhook_secret', 'label' => 'Webhook secret', 'saved' => $c?->type === $type && (bool) $c->webhook_secret,
                    'hint' => 'The same secret you enter in the store’s webhook settings; incoming orders with a different signature are refused.'];
            }
            $secrets[$type] = $fields;
        }
        $active = $c ? $c->status === 'active' : false;

        return ['id' => $c?->id, 'types' => self::choices($types), 'type_label' => $c ? $registry->label($c->type) : null, 'status' => $c?->status,
            'secrets' => $secrets, 'shop_base' => url('/shop').'/', 'defaults' => StorefrontController::DEFAULT_TEXT,
            'branches' => self::choices(Branch::choices($c?->branch_id)), 'price_lists' => self::choices(PriceList::orderBy('name')->pluck('name', 'id')->all()),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name', 'branch_id'])->map(fn ($w) => ['id' => $w->id, 'label' => $w->name, 'branch_id' => $w->branch_id])->all(),
            'address' => $c ? self::address($c) : null,
            'values' => ['type' => $c?->type ?? 'storefront', 'name' => (string) $c?->name, 'branch_id' => (string) $c?->branch_id, 'warehouse_id' => (string) $c?->warehouse_id,
                'price_list_id' => (string) $c?->price_list_id, 'safety_stock' => rtrim(rtrim((string) ($c?->safety_stock ?? '0'), '0'), '.') ?: '0', 'base_url' => (string) $c?->base_url,
                'slug' => (string) ($c?->settings['slug'] ?? ''), 'headline' => (string) ($c?->settings['headline'] ?? ''), 'intro' => (string) ($c?->settings['intro'] ?? ''),
                'pickup_note' => (string) ($c?->settings['pickup_note'] ?? '')],
            'actions' => $c ? ['activate' => $c->status !== 'active', 'pause' => $active,
                'reconcile' => $active && in_array('reconcile', $registry->for($c->type)->capabilities(), true),
                'retry' => ChannelJob::where('channel_id', $c->id)->where('status', 'dead')->exists(),
                'last_error' => ChannelJob::where('channel_id', $c->id)->where('status', 'dead')->latest('updated_at')->value('last_error')] : null,
            'archive' => $c ? (new CompanyScreen($this->user))->archiveOf($c) : null];
    }

    // -------------------------------------------------------------------------------------------- WhatsApp orders

    public static function from(WhatsappOrder $o): string
    {
        return WhatsappOrderResource::from($o);
    }

    /** @param  array<string, mixed>  $p  q, status (new by default; all), page, per */
    public function whatsapp(array $p): array
    {
        $q = WhatsappOrder::query()->with('customer');
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhere('from_phone', 'ilike', "%{$s}%")->orWhere('from_name', 'ilike', "%{$s}%"));
        }
        $status = (string) ($p['status'] ?? 'new');
        isset(WhatsappOrder::STATUSES[$status]) ? $q->where('status', $status) : $status = 'all';
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'status' => $status, 'statuses' => self::choices(WhatsappOrder::STATUSES),
            'rows' => $q->orderByDesc('last_message_at')->forPage($page, $per)->get()->map(fn (WhatsappOrder $o) => ['id' => $o->id, 'number' => $o->number,
                'received' => $o->last_message_at?->diffForHumans(), 'from' => self::from($o), 'body' => mb_strimwidth((string) $o->body, 0, 80, '…') ?: null,
                'photos' => count($o->media ?? []) ?: null, 'status' => $o->status, 'status_label' => WhatsappOrder::STATUSES[$o->status] ?? $o->status,
                'tone' => ['new' => 'warning', 'ordered' => 'success'][$o->status] ?? 'subtle'])->all()];
    }

    public function message(WhatsappOrder $o): array
    {
        $ids = Erp::branchIds(WhatsappOrders::PERMISSION);
        $branches = Branch::where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->pluck('name', 'id')->all();
        $new = $o->status === 'new';

        return ['id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'status_label' => WhatsappOrder::STATUSES[$o->status] ?? $o->status,
            'tone' => ['new' => 'warning', 'ordered' => 'success'][$o->status] ?? 'subtle', 'from' => self::from($o), 'received' => $o->received_at?->toIso8601String(),
            'customer' => $o->customer?->name, 'order' => $o->sales_order_id ? ['number' => $o->salesOrder?->number, 'open' => "/office/online/orders/{$o->sales_order_id}"] : null,
            'handled' => $o->handled_by_name ? $o->handled_by_name.($o->handled_at ? ', '.$o->handled_at->format('j M H:i') : '') : null, 'decline_reason' => $o->decline_reason,
            'body' => $o->body,
            'media' => collect($o->media ?? [])->map(fn ($m, $i) => ! ($m['path'] ?? null)
                ? ['url' => null, 'image' => false, 'name' => ($m['tries'] ?? 0) >= WhatsappOrders::MEDIA_TRIES ? 'Not fetched: '.($m['error'] ?? 'unknown') : 'Fetching from WhatsApp…']
                : ['url' => route('whatsapp.file', ['order' => $o->id, 'index' => $i]), 'image' => str_starts_with((string) $m['mime'], 'image/'), 'name' => $m['name'] ?? 'Document'])->values()->all(),
            'branches' => self::choices($branches), 'kinds' => self::choices(array_diff_key(Ticket::KINDS, ['internal' => 1])), 'priorities' => self::choices(Ticket::PRIORITIES),
            'actions' => ['make_order' => $new, 'reply' => true, 'decline' => $new, 'ticket' => $new && ServiceUi::can('helpdesk.work')]];
    }
}
