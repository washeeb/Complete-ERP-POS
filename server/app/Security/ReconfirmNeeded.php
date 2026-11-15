<?php

namespace App\Security;

use App\Domain\Support\DomainException;

/** The person must confirm it is them first (Reconfirm): screens offer the "Confirm it's you" page. */
final class ReconfirmNeeded extends DomainException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'reconfirm');
    }
}
