<?php

namespace App\Domain\Ai;

/**
 * A language model behind one common shape.
 *
 * Messages: ['role' => 'user'|'assistant'|'tool', 'content' => string]; an assistant turn that called tools carries
 * 'tool_calls' => [['id', 'name', 'arguments']]; a tool result carries 'tool_call_id' and 'name'.
 * Tools: ['name', 'description', 'parameters' => JSON schema]. Images: [['mime' => …, 'data' => base64]] go with the last user turn.
 */
interface AiProvider
{
    public function name(): string;

    public function model(): string;

    /** True when the data leaves the company's own servers. */
    public function isCloud(): bool;

    public function chat(string $system, array $messages, array $tools = [], array $images = [], bool $json = false): AiReply;

    /** Cost of a call in millionths of a US dollar (0 for local models). */
    public function costMicroUsd(int $tokensIn, int $tokensOut): int;
}
