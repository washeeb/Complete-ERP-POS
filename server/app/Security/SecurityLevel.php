<?php

namespace App\Security;

/** Hierarchical ceiling (spec §9.1). A role above a user's level never applies to that user. */
enum SecurityLevel: int
{
    case External = 0;
    case Restricted = 1;
    case Staff = 2;
    case Supervisor = 3;
    case Manager = 4;
    case Executive = 5;
    case Owner = 6;
    case Platform = 7;

    public function label(): string
    {
        return match ($this) {
            self::External => 'L0 · External (portal)',
            self::Restricted => 'L1 · Restricted / read-only',
            self::Staff => 'L2 · Staff / cashier',
            self::Supervisor => 'L3 · Supervisor',
            self::Manager => 'L4 · Branch / department manager',
            self::Executive => 'L5 · Executive / finance controller',
            self::Owner => 'L6 · Account owner / director',
            self::Platform => 'L7 · Platform operator',
        };
    }

    /** @return array<int, string> */
    public static function options(int $max = 7): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            if ($case->value <= $max) {
                $out[$case->value] = $case->label();
            }
        }

        return $out;
    }
}
