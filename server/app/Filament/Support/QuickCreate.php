<?php

namespace App\Filament\Support;

use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Hr\HrService;
use App\Domain\Purchasing\SupplierService;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Supplier;
use App\Models\Uom;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * "+" next to a list: add the missing category, brand, unit, supplier, customer or department without leaving the
 * form. It goes through the same rules, permission and audit log as its own screen, and is picked at once.
 */
final class QuickCreate
{
    /** Each kind: permission, form fields, how it is saved. */
    public static function on(Select $select, string $kind): Select
    {
        [$permission, $fields, $save] = match ($kind) {
            'category' => ['catalog.manage', [TextInput::make('name')->required()->maxLength(120)],
                fn ($actor, array $d) => app(CatalogService::class)->saveSimple($actor, new Category, ['name' => trim($d['name']), 'is_active' => true])],
            'brand' => ['catalog.manage', [TextInput::make('name')->required()->maxLength(120)],
                fn ($actor, array $d) => app(CatalogService::class)->saveSimple($actor, new Brand, ['name' => trim($d['name'])])],
            'uom' => ['catalog.manage', [TextInput::make('code')->required()->maxLength(10)->placeholder('PK'), TextInput::make('name')->required()->maxLength(60)->placeholder('Pack'),
                TextInput::make('decimals')->label('Decimal places when selling (0 = whole units)')->numeric()->minValue(0)->maxValue(3)->default(0)],
                fn ($actor, array $d) => app(CatalogService::class)->saveSimple($actor, new Uom, ['code' => $d['code'], 'name' => $d['name'], 'decimals' => (int) ($d['decimals'] ?? 0)])],
            'supplier' => ['suppliers.manage', [TextInput::make('code')->required()->maxLength(20)->placeholder('ERNEST'), TextInput::make('name')->required()->maxLength(255), TextInput::make('phone')->tel()->maxLength(30), TextInput::make('email')->email()->maxLength(255)],
                fn ($actor, array $d) => app(SupplierService::class)->save($actor, new Supplier, ['code' => $d['code'], 'name' => $d['name'], 'phone' => $d['phone'] ?? null, 'email' => $d['email'] ?? null])],
            'customer' => ['customers.manage', [TextInput::make('name')->required()->maxLength(255), TextInput::make('phone')->tel()->maxLength(30), TextInput::make('email')->email()->maxLength(255)],
                fn ($actor, array $d) => app(CustomerService::class)->save($actor, new Customer, ['name' => $d['name'], 'phone' => $d['phone'] ?? null, 'email' => $d['email'] ?? null])],
            'department' => ['hr.manage', [TextInput::make('code')->required()->maxLength(20), TextInput::make('name')->required()->maxLength(120)],
                fn ($actor, array $d) => app(HrService::class)->saveDepartment($actor, new Department, $d['code'], $d['name'])],
        };

        return $select
            ->createOptionForm($fields)
            ->createOptionUsing(fn (array $data) => Erp::run(fn ($actor) => $save($actor, $data))->getKey())
            ->createOptionAction(fn ($action) => $action->modalHeading('Add '.($kind === 'uom' ? 'a unit' : "a {$kind}"))->visible(fn () => Erp::canAnywhere($permission)));
    }
}
