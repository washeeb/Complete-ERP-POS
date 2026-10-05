<?php

namespace App\Domain\Support;

use RuntimeException;

/** A business rule said no. The message is safe to show to the person who tried. */
class DomainException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'rule_violation')
    {
        parent::__construct($message);
    }
}
