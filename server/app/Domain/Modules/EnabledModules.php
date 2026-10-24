<?php

namespace App\Domain\Modules;

use App\Models\BranchModule;
use App\Support\TenantContext;

/**
 * Which branches have each module switched on, read once per request (speed sweep 2026-10-24): every menu item and page
 * asks, and one question each cost a page dozens of database queries. Request-scoped; forgotten when a module changes.
 */
final class EnabledModules
{
    /** @var array<string, array<string, list<string>>> company => module => branch ids */
    private array $map = [];

    /** @return list<string> */
    public function branches(string $moduleKey): array
    {
        $tenant = (string) app(TenantContext::class)->id();
        $this->map[$tenant] ??= BranchModule::where('status', ModuleService::ENABLED)->get(['module_key', 'branch_id'])
            ->groupBy('module_key')->map(fn ($g) => $g->pluck('branch_id')->all())->all();

        return $this->map[$tenant][$moduleKey] ?? [];
    }

    public function forget(): void
    {
        $this->map = [];
    }
}
