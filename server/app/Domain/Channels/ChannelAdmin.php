<?php

namespace App\Domain\Channels;

use App\Domain\Channels\Connectors\ConnectorRegistry;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\SalesChannel;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Str;

final class ChannelAdmin
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly ConnectorRegistry $connectors,
        private readonly ChannelSync $sync,
    ) {}

    /** $secrets: credential fields + optional webhook_secret; blank values keep what is stored (secrets are never shown back). */
    public function save(Actor $actor, SalesChannel $channel, array $data, array $secrets = []): SalesChannel
    {
        $this->require($actor);
        $type = $channel->exists ? $channel->type : (string) ($data['type'] ?? '');
        if (! $this->connectors->isAvailable($type)) {
            throw new DomainException($this->connectors->label($type).' is not available yet.');
        }
        $branch = Branch::findOrFail($data['branch_id'] ?? $channel->branch_id);
        $warehouse = Warehouse::findOrFail($data['warehouse_id'] ?? $channel->warehouse_id);
        if ($warehouse->branch_id !== $branch->id) {
            throw new DomainException('The stock warehouse must belong to the fulfilling branch.');
        }
        PriceList::findOrFail($data['price_list_id'] ?? $channel->price_list_id);
        if (isset($data['safety_stock']) && Qty::toMilli((string) $data['safety_stock']) < 0) {
            throw new DomainException('Safety stock cannot be negative.');
        }
        if ($type !== 'storefront' && empty($data['base_url'] ?? $channel->base_url)) {
            throw new DomainException('Enter the store address (https://…).');
        }

        $channel->fill(array_intersect_key($data, array_flip(['name', 'branch_id', 'warehouse_id', 'price_list_id', 'safety_stock', 'base_url', 'settings'])));
        if (! $channel->exists) {
            $channel->type = $type;
            $channel->status = 'draft';
            $channel->tenant_id = $actor->tenantId;
            if ($type === 'storefront') {
                $channel->settings = ($channel->settings ?? []) + ['slug' => Str::slug($channel->name) ?: 'shop'];
            }
        }
        $creds = $channel->credentials ?? [];
        foreach ($this->connectors->for($type)->credentialFields() as $field => $label) {
            if (! empty($secrets[$field])) {
                $creds[$field] = trim((string) $secrets[$field]);
            }
        }
        $channel->credentials = $creds;
        if (! empty($secrets['webhook_secret'])) {
            $channel->webhook_secret = trim((string) $secrets['webhook_secret']);
        }

        return $this->recorder->save($actor, $channel, $channel->exists ? 'channel.updated' : 'channel.created');
    }

    public function activate(Actor $actor, SalesChannel $channel): SalesChannel
    {
        $this->require($actor);
        $connector = $this->connectors->for($channel->type);
        foreach ($connector->credentialFields() as $field => $label) {
            if (empty($channel->credentials[$field])) {
                throw new DomainException("Enter the {$label} before activating.");
            }
        }
        if (in_array('order_webhooks', $connector->capabilities(), true) && ! $channel->webhook_secret) {
            throw new DomainException('Enter the webhook secret before activating, so incoming orders can be verified.');
        }
        $channel->status = 'active';
        $this->recorder->save($actor, $channel, 'channel.activated');
        if ($channel->type !== 'storefront') {
            $this->sync->queueAll($channel); // first full push; later changes flow from the outbox
        }

        return $channel;
    }

    public function pause(Actor $actor, SalesChannel $channel, string $reason): SalesChannel
    {
        $this->require($actor);
        $channel->status = 'paused';

        return $this->recorder->save($actor, $channel, 'channel.paused', $reason);
    }

    private function require(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'channels.manage')) {
            throw new AccessDenied;
        }
    }
}
