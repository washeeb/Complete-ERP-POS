<?php

use App\Models\Message;
use App\Models\MessageProvider;
use App\Models\MessageTemplate;

// Messaging in the new back office (settings and admin, part 5): provider setup (credentials never sent back),
// templates, and the message log with Try again.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    withMfa($this->owner);
});

it('saves the SMS provider, keeps a credential left empty, and never sends one back', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/messaging/sms', ['provider' => 'arkesel', 'sender_id' => 'ADOMMART', 'credentials' => [], 'active' => true])->assertStatus(422); // the API key
    $this->postJson('/office-api/messaging/sms', ['provider' => 'arkesel', 'sender_id' => 'ADOMMART', 'credentials' => ['api_key' => 'k-very-secret-123'], 'active' => true])->assertOk();
    $this->postJson('/office-api/messaging/sms', ['provider' => 'arkesel', 'sender_id' => 'ADOMMART', 'credentials' => ['api_key' => ''], 'active' => true])->assertOk(); // kept
    expect(MessageProvider::where('channel', 'sms')->sole()->credentials['api_key'])->toBe('k-very-secret-123');

    $this->postJson('/office-api/messaging/whatsapp', ['credentials' => ['phone_number_id' => '1234', 'access_token' => 'EAAG-secret-token'], 'active' => false])->assertOk();
    $res = $this->getJson('/office-api/messaging')->assertOk();
    expect($res->getContent())->not->toContain('k-very-secret-123')->not->toContain('EAAG-secret-token');
    $s = $res->json();
    expect($s['sms'])->toMatchArray(['provider' => 'arkesel', 'sender_id' => 'ADOMMART', 'active' => true])
        ->and(collect($s['sms']['fields']['arkesel'])->firstWhere('key', 'api_key')['saved'])->toBeTrue()
        ->and(collect($s['whatsapp']['fields'])->firstWhere('key', 'access_token')['saved'])->toBeTrue()
        ->and(collect($s['whatsapp']['fields'])->firstWhere('key', 'app_secret')['saved'])->toBeFalse()
        ->and($s['whatsapp']['webhook']['url'])->toContain('whatsapp');

    $this->postJson('/office-api/messaging/whatsapp/test', ['to' => '0244000000'])->assertStatus(422); // switched off
    $this->postJson('/office-api/messaging/email/test', ['to' => 'not-an-address'])->assertStatus(422);
});

it('changes a template, lists the log and tries a failed message again, and keeps others out', function () {
    $this->actingAs($this->owner, 'web');
    $t = collect($this->getJson('/office-api/message-templates?channel=sms')->assertOk()->json('rows'))->first();
    expect($t)->not->toBeNull()->and($t['channel'])->toBe('sms');
    $this->postJson("/office-api/message-templates/{$t['id']}", ['active' => true, 'body' => str_repeat('x', 481)])->assertStatus(422); // over 3 SMS
    $this->postJson("/office-api/message-templates/{$t['id']}", ['active' => false, 'body' => 'Thank you, {name}.'])->assertOk();
    expect(MessageTemplate::find($t['id']))->body->toBe('Thank you, {name}.')->is_active->toBeFalse();

    $m = new Message(['channel' => 'sms', 'to' => '0244111222', 'body' => 'Your order is ready', 'reference' => 'INV-7']);
    $m->forceFill(['tenant_id' => $this->tenant->id, 'sent_by_name' => 'System', 'status' => 'failed', 'attempts' => 3, 'error' => 'Provider said no'])->save();
    $l = $this->getJson('/office-api/message-log?q=INV-7')->assertOk()->json();
    expect($l['total'])->toBe(1)->and($l['rows'][0])->toMatchArray(['status' => 'failed', 'can_retry' => true, 'error' => 'Provider said no']);
    $this->postJson("/office-api/message-log/{$m->id}/retry")->assertOk();
    expect($m->fresh())->status->toBe('queued')->attempts->toBe(0);
    $this->postJson("/office-api/message-log/{$m->id}/retry")->assertStatus(422); // already waiting

    $clerk = staff($this->owner, 'efua', 2);
    $this->actingAs($clerk, 'web');
    foreach (['messaging', 'message-templates', 'message-log'] as $page) {
        $this->getJson("/office-api/{$page}")->assertForbidden();
    }
    $this->postJson('/office-api/messaging/sms', ['provider' => 'arkesel', 'credentials' => ['api_key' => 'x']])->assertForbidden();
});
