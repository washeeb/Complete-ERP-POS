<?php

namespace App\Domain\Org;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\Industry;
use App\Modules\ModuleRegistry;
use App\Security\Authorizer;

/**
 * Industries (owner decisions 2026-10-12): one industry per branch, any number of branches per industry; head office
 * or a central warehouse may belong to none. Switching an industry module on for a branch with no industry makes it a
 * branch of that industry (created on first use).
 */
final class IndustryService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly ModuleRegistry $modules) {}

    public function save(Actor $actor, Industry $industry, array $data): Industry
    {
        $this->require($actor);
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $module = (string) ($data['module_key'] ?? $industry->module_key ?? '');
        if ($code === '' || ! preg_match('/^[A-Z0-9]{1,10}$/', $code) || $name === '') {
            throw new DomainException('Give the industry a short code (letters and digits) and a name.');
        }
        if (! $this->modules->has($module) || ! $this->modules->get($module)->definesIndustry()) {
            throw new DomainException('Choose the industry module its branches run.');
        }
        if (Industry::where('code', $code)->when($industry->exists, fn ($q) => $q->whereKeyNot($industry->getKey()))->exists()) {
            throw new DomainException('Another industry has that code.');
        }
        if ($industry->exists && $industry->module_key !== $module && $industry->branches()->exists()) {
            throw new DomainException('This industry has branches: its module cannot change. Create a new industry instead.');
        }
        $industry->fill(['code' => $code, 'name' => mb_substr($name, 0, 120), 'module_key' => $module,
            'legal_entity_id' => $data['legal_entity_id'] ?? $industry->legal_entity_id, 'sort' => (int) ($data['sort'] ?? $industry->sort ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true)]);
        $industry->tenant_id ??= $actor->tenantId;

        return $this->recorder->save($actor, $industry, $industry->exists ? 'industry.updated' : 'industry.created');
    }

    /** Put a branch in an industry (or make it company-level with null). Its industry module, if any, must match. */
    public function assign(Actor $actor, Branch $branch, ?Industry $industry): Branch
    {
        $this->require($actor);
        $running = $this->industryModulesOn($branch);
        if ($industry && $running && ! in_array($industry->module_key, $running, true)) {
            throw new DomainException("{$branch->name} runs ".$this->modules->get($running[0])->name.'. Switch that module off first, or choose its industry.');
        }
        if (! $industry && $running) {
            throw new DomainException("{$branch->name} runs ".$this->modules->get($running[0])->name.': a company-level branch runs no industry module. Switch it off first.');
        }
        $branch->industry_id = $industry?->id;

        return $this->recorder->save($actor, $branch, 'branch.industry_set', branchId: $branch->id);
    }

    /**
     * Called before an industry module is switched on for a branch: one industry per branch. A branch without an
     * industry joins (or founds) the industry of that module.
     */
    public function ensureFor(Actor $actor, Branch $branch, string $moduleKey): void
    {
        $module = $this->modules->get($moduleKey);
        if (! $module->definesIndustry()) {
            return;
        }
        $current = $branch->industry_id ? Industry::withoutGlobalScopes()->find($branch->industry_id) : null;
        if ($current && $current->module_key !== $moduleKey) {
            throw new DomainException("{$branch->name} is a {$current->name} branch, and a branch runs one industry. Open {$module->name} in a branch of its own.");
        }
        if ($current) {
            return;
        }
        $industry = Industry::withoutGlobalScopes()->where('tenant_id', $branch->tenant_id)->where('module_key', $moduleKey)->orderBy('sort')->first();
        if (! $industry) {
            $industry = new Industry(['code' => strtoupper(substr(preg_replace('/[^a-z]/', '', $moduleKey), 0, 6)), 'name' => $module->name,
                'module_key' => $moduleKey, 'sort' => Industry::withoutGlobalScopes()->where('tenant_id', $branch->tenant_id)->count() + 1, 'is_active' => true]);
            $industry->tenant_id = $branch->tenant_id;
            $this->recorder->save($actor, $industry, 'industry.created');
        }
        $branch->industry_id = $industry->id;
        $this->recorder->save($actor, $branch, 'branch.industry_set', branchId: $branch->id);
    }

    /** @return list<string> industry modules (not add-ons) switched on for the branch */
    private function industryModulesOn(Branch $branch): array
    {
        return BranchModule::withoutGlobalScopes()->where('branch_id', $branch->id)->where('status', 'enabled')->pluck('module_key')
            ->filter(fn ($k) => $this->modules->has($k) && $this->modules->get($k)->definesIndustry())->values()->all();
    }

    private function require(Actor $actor): void
    {
        if ($actor->source !== 'system' && (! $actor->user || ! $this->authz->allows($actor->user, 'setup.company.manage'))) {
            throw new AccessDenied;
        }
    }
}
