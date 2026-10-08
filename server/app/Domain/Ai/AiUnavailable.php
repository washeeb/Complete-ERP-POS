<?php

namespace App\Domain\Ai;

use App\Domain\Support\DomainException;

/** The AI cannot answer (switched off, over budget, local model not running…); the message says why, in plain words. */
final class AiUnavailable extends DomainException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'ai_unavailable');
    }
}
