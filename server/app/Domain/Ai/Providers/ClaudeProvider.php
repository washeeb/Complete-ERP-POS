<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiReply;
use App\Domain\Ai\AiUnavailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Claude through the Anthropic Messages API. Used only when switched on, with a key and a monthly budget. */
final class ClaudeProvider implements AiProvider
{
    public function __construct(private readonly string $key, private readonly string $model) {}

    public function name(): string
    {
        return 'claude';
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
        $prices = config('ai.claude_prices');
        [$in, $out] = $prices[$this->model] ?? $prices['default']; // USD per million tokens = micro-USD per token

        return (int) ceil($tokensIn * $in + $tokensOut * $out);
    }

    public function chat(string $system, array $messages, array $tools = [], array $images = [], bool $json = false): AiReply
    {
        $out = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'tool') { // results go back in a user turn; consecutive results share it
                $block = ['type' => 'tool_result', 'tool_use_id' => $m['tool_call_id'], 'content' => $m['content']];
                $last = count($out) - 1;
                if ($last >= 0 && $out[$last]['role'] === 'user' && is_array($out[$last]['content']) && ($out[$last]['content'][0]['type'] ?? '') === 'tool_result') {
                    $out[$last]['content'][] = $block;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$block]];
                }
            } elseif ($m['role'] === 'assistant') {
                $content = ($m['content'] ?? '') !== '' ? [['type' => 'text', 'text' => $m['content']]] : [];
                foreach ($m['tool_calls'] ?? [] as $c) {
                    $content[] = ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => (object) $c['arguments']];
                }
                $out[] = ['role' => 'assistant', 'content' => $content];
            } else {
                $out[] = ['role' => 'user', 'content' => $m['content']];
            }
        }
        if ($images) {
            $last = count($out) - 1;
            $text = $out[$last]['content'];
            $out[$last]['content'] = [...array_map(fn ($i) => $i['mime'] === 'application/pdf'
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $i['data']]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $i['mime'], 'data' => $i['data']]], $images), ['type' => 'text', 'text' => $text]];
        }
        $body = ['model' => $this->model, 'max_tokens' => 2000, 'system' => $system, 'messages' => $out]
            + ($tools ? ['tools' => array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['parameters']], $tools)] : []);
        try {
            $r = Http::timeout(120)->withHeaders(['x-api-key' => $this->key, 'anthropic-version' => '2023-06-01'])->acceptJson()
                ->post('https://api.anthropic.com/v1/messages', $body);
        } catch (Throwable) {
            throw new AiUnavailable('Claude is not reachable (no internet?). Built-in insights still work.');
        }
        if (! $r->successful()) {
            throw new AiUnavailable('Claude answered with an error: '.Str::limit((string) ($r->json('error.message') ?? $r->body()), 200));
        }
        $text = '';
        $calls = [];
        foreach ($r->json('content') ?? [] as $b) {
            if (($b['type'] ?? '') === 'text') {
                $text .= $b['text'];
            } elseif (($b['type'] ?? '') === 'tool_use') {
                $calls[] = ['id' => $b['id'], 'name' => $b['name'], 'arguments' => (array) ($b['input'] ?? [])];
            }
        }

        return new AiReply($text, $calls, (int) $r->json('usage.input_tokens', 0), (int) $r->json('usage.output_tokens', 0));
    }
}
