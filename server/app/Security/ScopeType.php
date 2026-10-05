<?php

namespace App\Security;

enum ScopeType: string
{
    case Tenant = 'tenant';
    case LegalEntity = 'legal_entity';
    case Branch = 'branch';
    case Warehouse = 'warehouse';
    case Till = 'till';

    public function label(): string
    {
        return match ($this) {
            self::Tenant => 'Whole company',
            self::LegalEntity => 'Legal entity',
            self::Branch => 'Branch',
            self::Warehouse => 'Warehouse',
            self::Till => 'Till',
        };
    }
}
