<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiReply;
use App\Domain\Ai\AiUnavailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Any service that speaks the OpenAI chat-completions format: Google Gemini, Groq, OpenRouter, Mistral, OpenAI and
 * others (several have free plans). Cloud: names, phones and e-mails are masked before anything is sent, as for Claude.
 * The cost is not known for free plans, so it is logged as 0 and the monthly budget does not apply.
 */
final class OpenAiCompatibleProvider implements AiProvider
{
    public function __construct(private readonly string $service, private readonly string $url, private readonly string $key, private readonly string $model) {}

    public function name(): string
    {
        return $this->service;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isCloud(): bool
    {
        return true;
    }

    public function costMicroUsd(int $tokensIn, int $tokensOut): int
    {
        return 0;
    }

    public function chat(string $system, array $messages, array $tools = [], array $images = [], bool $json = false): AiReply
    {
        $out = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $m) {
            $out[] = match ($m['role']) {
                'assistant' => array_filter(['role' => 'assistant', 'content' => ($m['content'] ?? '') !== '' ? $m['content'] : null,
                    'tool_calls' => array_map(fn ($c) => ['id' => $c['id'], 'type' => 'function',
                        'function' => ['name' => $c['name'], 'arguments' => json_encode((object) $c['arguments'])]], $m['tool_calls'] ?? []) ?: null], fn ($v) => $v !== null),
                'tool' => ['role' => 'tool', 'tool_call_id' => $m['tool_call_id'] ?? '', 'content' => (string) $m['content']],
                default => ['role' => 'user', 'content' => $m['content']],
            };
        }
        if ($images) {
            $last = count($out) - 1;
            $out[$last]['content'] = [['type' => 'text', 'text' => (string) $out[$last]['content']],
                ...array_map(fn ($i) => ['type' => 'image_url', 'image_url' => ['url' => "data:{$i['mime']};base64,{$i['data']}"]], $images)];
        }
        $body = ['model' => $this->model, 'messages' => $out, 'temperature' => 0.2]
            + ($tools ? ['tools' => array_map(fn ($t) => ['type' => 'function', 'function' => $t], $tools)] : [])
            + ($json ? ['response_format' => ['type' => 'json_object']] : []);
        try {
            $r = Http::timeout(120)->acceptJson()->withToken($this->key)->post(rtrim($this->url, '/').'/chat/completions', $body);
        } catch (Throwable) {
            throw new AiUnavailable("The {$this->service} AI service could not be reached. Check the server's internet connection.");
        }
        if ($r->status() === 429) {
            throw new AiUnavailable("The {$this->service} AI service says the limit is reached for now (free plans allow a number of questions a minute or a day). Try again later.");
        }
        if (in_array($r->status(), [401, 403], true)) {
            throw new AiUnavailable("The {$this->service} AI service refused the API key (.env: AI_CLOUD_API_KEY).");
        }
        if (! $r->successful()) {
            $err = $r->json('error.message') ?? $r->json('0.error.message') ?? $r->body();
            throw new AiUnavailable("The {$this->service} AI service answered with an error: ".Str::limit((string) $err, 200));
        }
        $msg = $r->json('choices.0.message') ?? [];
        $calls = array_map(fn ($c) => ['id' => (string) ($c['id'] ?? Str::uuid7()), 'name' => $c['function']['name'] ?? '',
            'arguments' => is_array($c['function']['arguments'] ?? null) ? $c['function']['arguments'] : (json_decode((string) ($c['function']['arguments'] ?? '{}'), true) ?: [])],
            $msg['tool_calls'] ?? []);

        return new AiReply((string) ($msg['content'] ?? ''), $calls, (int) $r->json('usage.prompt_tokens', 0), (int) $r->json('usage.completion_tokens', 0));
    }
}
