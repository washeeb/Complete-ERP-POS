<?php

use App\Models\ApiClient;
use App\Models\MomoGateway;
use App\Models\SupportAccessGrant;
use App\Models\SyncPeer;
use App\Models\WebhookEndpoint;
use App\Security\ScopeType;
use Illuminate\Http\UploadedFile;

// API clients, Mobile money, Support access and Sync Center in the new back office (part 23).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    withMfa($this->owner); // no IT Admin yet: the owner still does the technical work
});

it('makes an API client with a key shown once, rotates it, adds a webhook and revokes it', function () {
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson('/office-api/system/api-clients')->assertOk()->json();
    expect($form['scopes'])->not->toBeEmpty()->and($form['openapi'])->toContain('/api/public/v1/openapi.json');
    $this->postJson('/office-api/system/api-clients', ['name' => 'Website', 'scopes' => [], 'user_id' => $this->owner->id])->assertStatus(422);
    $made = $this->postJson('/office-api/system/api-clients', ['name' => 'Website', 'scopes' => [$form['scopes'][0]['id']], 'user_id' => $this->owner->id])->assertOk()->json();
    $c = ApiClient::sole();
    expect($made['secret']['title'])->toBe('Key for Website')->and($made['secret']['value'])->not->toBeEmpty()
        ->and($made['clients']['rows'][0])->toMatchArray(['name' => 'Website', 'active' => true, 'acts_as' => $this->owner->name])
        ->and(json_encode($made['clients']))->not->toContain($made['secret']['value']); // the list never carries the key
    expect($this->postJson("/office-api/system/api-clients/{$c->id}/action", ['action' => 'key'])->assertOk()->json('secret.value'))->not->toBe($made['secret']['value']);
    $hook = $this->postJson("/office-api/system/api-clients/{$c->id}/action", ['action' => 'webhook', 'url' => 'https://hooks.example.test/x', 'events' => ['product.updated']])->assertOk()->json();
    expect($hook['secret']['value'])->not->toBeEmpty()->and(WebhookEndpoint::where('api_client_id', $c->id)->count())->toBe(1)->and($hook['clients']['rows'][0]['webhooks'])->toBe(1);
    $this->postJson("/office-api/system/api-clients/{$c->id}/action", ['action' => 'revoke'])->assertStatus(422);
    $this->postJson("/office-api/system/api-clients/{$c->id}/action", ['action' => 'revoke', 'reason' => 'Website closed'])->assertOk();
    expect($c->fresh()->is_active)->toBeFalse();
});

it('lists mobile money requests and saves the payment provider, keeping a secret left empty', function () {
    $this->actingAs($this->owner, 'web');
    $m = $this->getJson('/office-api/system/momo')->assertOk()->json();
    expect($m)->toMatchArray(['total' => 0])->and($m['gateway']['providers'])->not->toBeEmpty()->and($m['about'])->toContain('Payment provider');
    $this->postJson('/office-api/system/momo/gateway', ['provider' => 'paystack', 'credentials' => ['secret_key' => 'pk_live_abc'], 'is_active' => true])->assertStatus(422);
    $this->postJson('/office-api/system/momo/gateway', ['provider' => 'paystack', 'credentials' => ['secret_key' => 'sk_test_abc123'], 'is_active' => true])->assertOk();
    $again = $this->getJson('/office-api/system/momo')->json('gateway');
    $field = collect($again['fields']['paystack'])->firstWhere('key', 'secret_key');
    expect($again['active'])->toBeTrue()->and($field)->toMatchArray(['saved' => true, 'value' => ''])->and($again['callback'])->toContain('/momo/webhook/');
    $this->postJson('/office-api/system/momo/gateway', ['provider' => 'paystack', 'credentials' => ['secret_key' => ''], 'is_active' => false])->assertOk();
    expect(MomoGateway::sole()->credentials['secret_key'] ?? null)->toBe('sk_test_abc123'); // empty kept the saved one
    expect($this->getJson('/office-api/system/momo?unused=1&status=success')->json('total'))->toBe(0);
});

it('gives and withdraws support access, and registers a branch server with its secret shown once', function () {
    $this->actingAs($this->owner, 'web');
    $s = $this->getJson('/office-api/system/support-access')->assertOk()->json();
    expect($s['can_grant'])->toBeTrue()->and($s['durations'])->toHaveCount(6);
    $this->postJson('/office-api/system/support-access', ['level' => 'view', 'until' => '2'])->assertStatus(422); // what support helps with
    $given = $this->postJson('/office-api/system/support-access', ['level' => 'view', 'until' => '2', 'reason' => 'Printer on Till 2'])->assertOk()->json('support');
    expect($given['rows'][0])->toMatchArray(['state' => 'Open', 'level' => 'See only', 'reason' => 'Printer on Till 2', 'can_revoke' => true]);
    $id = SupportAccessGrant::sole()->id;
    expect($this->postJson("/office-api/system/support-access/{$id}/revoke")->assertOk()->json('support.rows.0.state'))->toBe('Withdrawn');

    $sync = $this->getJson('/office-api/system/sync')->assertOk()->json();
    expect($sync['rows'])->toBe([])->and($sync['is_branch'])->toBeFalse();
    $added = $this->postJson('/office-api/system/sync/branch', ['node_id' => 'b01', 'name' => 'Branch A server'])->assertOk()->json();
    $peer = SyncPeer::sole();
    expect($added['secret']['second'])->toBe($peer->secret)->and($added['secret']['value'])->toContain('erp:node:join')
        ->and($added['sync']['rows'][0])->toMatchArray(['name' => 'Branch A server', 'status' => 'Waiting', 'active' => true]);
    expect(json_encode($added['sync']))->not->toContain($peer->secret);
    $modes = collect($added['sync']['rows'][0]['groups'])->mapWithKeys(fn ($g) => [$g['key'] => 'manual'])->all();
    if ($modes) {
        $this->postJson("/office-api/system/sync/{$peer->id}/action", ['action' => 'modes', 'groups' => $modes])->assertOk();
        expect(array_intersect_key($peer->fresh()->groups, $modes))->toEqualCanonicalizing($modes);
    }
    expect($this->postJson("/office-api/system/sync/{$peer->id}/action", ['action' => 'toggle'])->assertOk()->json('sync.rows.0.status'))->toBe('Disabled');
    $this->get("/office-api/system/sync/{$peer->id}/export")->assertOk();
    $this->post('/office-api/system/sync/import', ['kind' => 'till', 'file' => UploadedFile::fake()->createWithContent('q.json', 'not json')], ['Accept' => 'application/json'])->assertStatus(422);

    $cashier = withMfa(staff($this->owner, 'efua', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id));
    $this->actingAs($cashier, 'web');
    foreach (['system/api-clients', 'system/support-access', 'system/sync'] as $path) {
        $this->getJson("/office-api/{$path}")->assertForbidden();
    }
});
