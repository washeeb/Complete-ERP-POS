<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiReply;
use App\Domain\Ai\AiUnavailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** A model running on the company's own server with Ollama (https://ollama.com). Nothing leaves the network. */
final class OllamaProvider implements AiProvider
{
    public function __construct(private readonly string $url, private readonly string $model) {}

    public function name(): string
    {
        return 'ollama';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isCloud(): bool
    {
        return false;
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
                'assistant' => ['role' => 'assistant', 'content' => $m['content'] ?? '',
                    'tool_calls' => array_map(fn ($c) => ['function' => ['name' => $c['name'], 'arguments' => (object) $c['arguments']]], $m['tool_calls'] ?? [])],
                'tool' => ['role' => 'tool', 'content' => $m['content'], 'tool_name' => $m['name'] ?? null],
                default => ['role' => 'user', 'content' => $m['content']],
            };
        }
        if ($images) {
            $out[count($out) - 1]['images'] = array_column($images, 'data');
        }
        $body = ['model' => $this->model, 'messages' => $out, 'stream' => false, 'options' => ['temperature' => 0.2]]
            + ($tools ? ['tools' => array_map(fn ($t) => ['type' => 'function', 'function' => $t], $tools)] : [])
            + ($json ? ['format' => 'json'] : []);
        try {
            $r = Http::timeout(180)->acceptJson()->post(rtrim($this->url, '/').'/api/chat', $body);
        } catch (Throwable) {
            throw new AiUnavailable("The local AI model is not reachable at {$this->url}. Is Ollama running? (See the installation guide, AI chapter.)");
        }
        if (! $r->successful()) {
            throw new AiUnavailable('The local AI model answered with an error: '.Str::limit((string) ($r->json('error') ?? $r->body()), 200));
        }
        $msg = $r->json('message') ?? [];
        $calls = array_map(fn ($c) => ['id' => (string) Str::uuid7(), 'name' => $c['function']['name'] ?? '',
            'arguments' => is_array($c['function']['arguments'] ?? null) ? $c['function']['arguments'] : (json_decode((string) ($c['function']['arguments'] ?? '{}'), true) ?: [])],
            $msg['tool_calls'] ?? []);

        return new AiReply((string) ($msg['content'] ?? ''), $calls, (int) $r->json('prompt_eval_count', 0), (int) $r->json('eval_count', 0));
    }
}
