<?php

namespace App\Domain\Support;

use RuntimeException;

/** A business rule said no. The message is safe to show to the person who tried. */
class DomainException extends RuntimeException
{
    /** @param  array<string, mixed>  $data  facts the caller needs to act (e.g. the discount a manager must approve); never secrets */
    public function __construct(string $message, public readonly string $errorCode = 'rule_violation', public readonly array $data = [])
    {
        parent::__construct($message);
    }
}
