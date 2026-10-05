<?php

namespace App\Domain\Support;

class AccessDenied extends DomainException
{
    public function __construct(string $message = 'You do not have permission to do this here.')
    {
        parent::__construct($message, 'scope_denied');
    }
}
