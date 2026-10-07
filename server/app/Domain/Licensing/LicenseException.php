<?php

namespace App\Domain\Licensing;

use App\Domain\Support\DomainException;

/** The licence does not allow this (expired, read-only, over a limit, module not licensed). */
final class LicenseException extends DomainException {}
