<?php

namespace Tests\Support;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiReply;
use App\Domain\Ai\AiUnavailable;

/** A scripted model for tests: answers come from a queue; every call is recorded. */
final class FakeAiProvider implements AiProvider
{
    /** @var list<array{system: string, messages: array, tools: array, images: array}> */
    public array $calls = [];

    /** @param list<AiReply|\Closure> $script */
    public function __construct(public array $script = [], private readonly bool $cloud = false) {}

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'scripted';
    }

    public function isCloud(): bool
    {
        return $this->cloud;
    }

    public function costMicroUsd(int $tokensIn, int $tokensOut): int
    {
        return $this->cloud ? $tokensIn * 3 + $tokensOut * 15 : 0;
    }

    public function chat(string $system, array $messages, array $tools = [], array $images = [], bool $json = false): AiReply
    {
        $this->calls[] = compact('system', 'messages', 'tools', 'images');
        $next = array_shift($this->script) ?? throw new AiUnavailable('The fake model has no more answers.');

        return $next instanceof \Closure ? $next($messages, $tools) : $next;
    }

    /** Everything the model was sent, as one string (to check what left the server). */
    public function sent(): string
    {
        return json_encode(array_column($this->calls, 'messages'), JSON_UNESCAPED_UNICODE);
    }
}
