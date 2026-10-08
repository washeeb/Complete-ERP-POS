<?php

namespace App\Domain\Api;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\ApiClient;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Security\Authorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Systems that use the public API. A client has scopes (what kinds of data its keys may touch) and acts as a person
 * (a service user): that person's permissions and branches apply as well, so the API can never see more than the
 * back office would show them. Keys are shown once; only their hash is kept.
 */
final class ApiClients
{
    /** scope => [label, the permission the run-as person must also hold] */
    public const SCOPES = [
        'products:read' => ['Read products and prices', 'catalog.view'],
        'stock:read' => ['Read stock levels', 'stock.view'],
        'customers:read' => ['Read customers', 'customers.view'],
        'customers:write' => ['Create and update customers', 'customers.manage'],
        'sales:read' => ['Read till sales', 'sales.view'],
        'invoices:read' => ['Read invoices', 'receivables.view'],
        'orders:write' => ['Create customer orders', 'sales.orders.manage'],
        'webhooks:manage' => ['Manage its own webhooks', null],
    ];

    public const EVENTS = ['product.updated' => 'A product was created or changed', 'customer.updated' => 'A customer was created or changed',
        'sale.posted' => 'A till sale or return was recorded', 'invoice.posted' => 'An invoice was issued', 'customer_order.confirmed' => 'A customer order was confirmed',
        'customer_order.delivered' => 'A customer order was delivered'];

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /** @return array{0: ApiClient, 1: string} the client and its first key (shown once) */
    public function create(Actor $actor, array $data): array
    {
        $this->require($actor);
        $name = trim((string) ($data['name'] ?? ''));
        $scopes = array_values(array_intersect(array_keys(self::SCOPES), (array) ($data['scopes'] ?? [])));
        $user = User::find($data['user_id'] ?? null);
        if ($name === '' || ! $scopes || ! $user || ! $user->is_active) {
            throw new DomainException('Give the client a name, at least one scope and the person it acts as.');
        }
        $client = (new ApiClient)->forceFill(['tenant_id' => $actor->tenantId, 'name' => mb_substr($name, 0, 120), 'scopes' => $scopes, 'user_id' => $user->id,
            'branch_id' => $data['branch_id'] ?? null, 'is_active' => true, 'created_by_name' => $actor->name]);

        return $this->recorder->transaction(function () use ($actor, $client) {
            $client->save();
            $this->recorder->audit->record($actor, 'api_client.created', $client, ['scopes' => [null, implode(', ', $client->scopes)]]);

            return [$client, $this->issueKey($actor, $client)];
        });
    }

    /** A new key (e.g. to rotate): the old ones keep working until revoked. */
    public function issueKey(Actor $actor, ApiClient $client): string
    {
        $this->require($actor);

        return $this->recorder->transaction(function () use ($actor, $client) {
            $token = $client->createToken('api', $client->scopes)->plainTextToken;
            $this->recorder->audit->record($actor, 'api_client.key_issued', $client, []);

            return $token;
        });
    }

    /** Switch a client off: every key stops at once. */
    public function revoke(Actor $actor, ApiClient $client, string $reason): ApiClient
    {
        $this->require($actor);
        if (trim($reason) === '') {
            throw new DomainException('Give the reason.');
        }

        return $this->recorder->transaction(function () use ($actor, $client, $reason) {
            $client->tokens()->delete();
            $client->forceFill(['is_active' => false])->save();
            WebhookEndpoint::where('api_client_id', $client->id)->update(['is_active' => false]);
            $this->recorder->audit->record($actor, 'api_client.revoked', $client, ['is_active' => [true, false]], $reason);

            return $client;
        });
    }

    /** Add a webhook address (from the back office, or by the client itself with webhooks:manage). @return array{0: WebhookEndpoint, 1: string} endpoint and its signing secret */
    public function addWebhook(?Actor $actor, string $tenantId, ?ApiClient $client, string $url, array $events): array
    {
        if ($actor) {
            $this->require($actor);
        }
        $events = array_values(array_intersect(array_keys(self::EVENTS), $events));
        if (! preg_match('#^https://[^\s/$.?\#].[^\s]*$#i', $url) || ! $events) {
            throw new DomainException('A webhook needs an https:// address and at least one event.');
        }
        $secret = 'whsec_'.Str::random(40);
        $endpoint = WebhookEndpoint::create(['tenant_id' => $tenantId, 'api_client_id' => $client?->id, 'url' => mb_substr($url, 0, 500), 'events' => $events, 'secret' => $secret, 'is_active' => true]);
        if ($actor) {
            DB::transaction(fn () => $this->recorder->audit->record($actor, 'webhook.created', $endpoint, ['url' => [null, $endpoint->url]]));
        }

        return [$endpoint, $secret];
    }

    private function require(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'api.manage')) {
            throw new AccessDenied;
        }
    }
}
