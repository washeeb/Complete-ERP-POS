<?php

namespace App\Domain\Ai;

/** What a model answered: text and/or tool calls, with token counts. */
final class AiReply
{
    /** @param list<array{id: string, name: string, arguments: array}> $toolCalls */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls = [],
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
    ) {}
}
