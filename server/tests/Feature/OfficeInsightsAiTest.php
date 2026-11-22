<?php

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiReply;
use App\Models\AiAlert;
use App\Models\AiRequest;
use App\Security\ScopeType;
use Tests\Support\FakeAiProvider;

// Insights in the new back office (part 29): Ask your business, Alerts and the AI usage log.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = $this->b['HQ'];
    withMfa($this->owner);
    $this->cashier = staff($this->owner, 'yaa', 3, 'cashier', ScopeType::Branch, $this->hq->id);
    AiGateway::fake(null);
});

afterEach(fn () => AiGateway::fake(null));

function officeAlert($t, array $d = []): AiAlert
{
    return AiAlert::create($d + ['tenant_id' => $t->tenant->id, 'branch_id' => $t->hq->id, 'kind' => 'margin', 'severity' => 'warning', 'title' => 'Sugar sells below cost',
        'detail' => 'Last 7 days', 'fingerprint' => 'margin:'.uniqid(), 'status' => 'open']);
}

it('answers quick questions without AI, and questions through the AI with the chat so far', function () {
    $this->actingAs($this->owner, 'web');
    $ask = $this->getJson('/office-api/ask')->assertOk()->json();
    expect(collect($ask['quick'])->pluck('id'))->toContain('sales_month_branch', 'cash');
    $quick = $this->postJson('/office-api/ask/quick', ['key' => 'sales_month_branch'])->assertOk()->json();
    expect($quick['tables'])->toHaveCount(1)->and($quick['tables'][0])->toHaveKeys(['title', 'columns', 'rows']);
    $this->postJson('/office-api/ask/quick', ['key' => 'nope'])->assertStatus(422);

    // AI off: the answer is a note pointing to the quick questions, not an error.
    $off = $this->postJson('/office-api/ask', ['question' => 'Which branch sold most?'])->assertOk()->json();
    expect($off['content'])->toBe('')->and($off['notice'])->toContain('quick questions');

    $fake = new FakeAiProvider([new AiReply('HQ sold the most.')]);
    AiGateway::fake($fake);
    $on = $this->postJson('/office-api/ask', ['question' => 'And last month?', 'history' => [['role' => 'user', 'content' => 'Which branch sold most?'],
        ['role' => 'assistant', 'content' => 'HQ.']]])->assertOk()->json();
    expect($on['content'])->toBe('HQ sold the most.')->and($fake->sent())->toContain('Which branch sold most?')->toContain('And last month?');
    $this->postJson('/office-api/ask', ['question' => ''])->assertStatus(422);

    $this->actingAs($this->cashier, 'web');
    $this->getJson('/office-api/ask')->assertForbidden();
});

it('lists alerts, marks them dealt with or not a problem, and refreshes', function () {
    $a = officeAlert($this);
    $b = officeAlert($this, ['kind' => 'stock', 'severity' => 'high', 'title' => 'Milo runs out on Friday']);
    officeAlert($this, ['status' => 'resolved', 'title' => 'Old one']);
    $this->actingAs($this->owner, 'web');
    $list = $this->getJson('/office-api/alerts')->assertOk()->json();
    expect($list['total'])->toBe(2)->and($list['can_refresh'])->toBeTrue()->and(collect($list['rows'])->pluck('title')->all())->toContain('Sugar sells below cost', 'Milo runs out on Friday');
    expect($this->getJson('/office-api/alerts?severity=high')->json('total'))->toBe(1)->and($this->getJson('/office-api/alerts?status=all')->json('total'))->toBe(3);

    $this->postJson("/office-api/alerts/{$a->id}", ['status' => 'resolved', 'note' => 'Price raised'])->assertOk();
    expect($a->fresh()->only(['status', 'handled_note']))->toBe(['status' => 'resolved', 'handled_note' => 'Price raised']);
    $this->postJson("/office-api/alerts/{$a->id}", ['status' => 'dismissed'])->assertStatus(422); // no longer open
    $this->postJson("/office-api/alerts/{$b->id}", ['status' => 'dismissed'])->assertOk();
    expect($b->fresh()->status)->toBe('dismissed')->and($this->getJson('/office-api/alerts')->json('total'))->toBe(0);
    $this->postJson('/office-api/alerts/refresh')->assertOk()->assertJsonPath('message', fn ($m) => str_ends_with($m, 'open alert(s).'));

    $this->actingAs($this->cashier, 'web');
    $this->getJson('/office-api/alerts')->assertForbidden();
});

it('lists the AI usage log with this month\'s spend and shows one request', function () {
    $r = AiRequest::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'user_name' => 'Ama Owner', 'feature' => 'copilot', 'provider' => 'claude',
        'model' => 'claude-sonnet', 'prompt' => 'Which branch sold most this month?', 'response' => 'HQ.', 'tools' => [['name' => 'sales_summary', 'arguments' => ['group_by' => 'branch']]],
        'tokens_in' => 1000, 'tokens_out' => 100, 'cost_micro_usd' => 4500, 'status' => 'ok', 'duration_ms' => 1500, 'created_at' => now()]);
    $this->actingAs($this->owner, 'web');
    $log = $this->getJson('/office-api/ai-usage')->assertOk()->json();
    expect($log['total'])->toBe(1)->and($log['spent'])->toBe('US$ 0.0045')
        ->and($log['rows'][0])->toMatchArray(['who' => 'Ama Owner', 'feature' => 'Copilot', 'model' => 'claude · claude-sonnet', 'cost' => 'US$ 0.0045', 'status' => 'ok']);
    expect($this->getJson('/office-api/ai-usage?status=error')->json('total'))->toBe(0);
    $one = $this->getJson("/office-api/ai-usage/{$r->id}")->assertOk()->json();
    expect($one)->toMatchArray(['tokens' => '1000 / 100', 'took' => '1.5 s', 'response' => 'HQ.'])->and($one['tools'][0])->toContain('sales_summary');

    $this->actingAs($this->cashier, 'web');
    $this->getJson('/office-api/ai-usage')->assertForbidden();
});
