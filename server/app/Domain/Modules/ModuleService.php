<?php

namespace App\Domain\Modules;

use App\Domain\Branding\Branding;
use App\Domain\Catalog\IndustryUnits;
use App\Domain\Licensing\LicenseException;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Org\IndustryService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Modules\ModuleManifest;
use App\Modules\ModuleRegistry;
use App\Security\Authorizer;
use App\Support\IndustryContext;

/**
 * Per-branch module state. Disabling hides a module and blocks new writes; it never deletes data.
 * A planned (unreleased) module can only be *reserved*: recorded as wanted for the branch,
 * never presented as working.
 */
final class ModuleService
{
    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    public const RESERVED = 'reserved';

    public function __construct(
        private readonly Recorder $recorder,
        private readonly ModuleRegistry $registry,
        private readonly Authorizer $authz,
    ) {}

    public function setStatus(Actor $actor, Branch $branch, string $moduleKey, string $status, ?string $reason = null, bool $bootstrap = false): BranchModule
    {
        if ($branch->tenant_id !== $actor->tenantId) {
            throw new AccessDenied;
        }
        if (! $bootstrap && (! $actor->user || ! $this->authz->allows($actor->user, 'admin.modules.manage', $branch))) {
            throw new AccessDenied;
        }
        $module = $this->registry->get($moduleKey);
        if ($status === 'enabled' && $module->kind !== 'core' && ! app(LicenseManager::class)->allowsModule($moduleKey)) {
            throw new LicenseException("The {$module->name} module is not in your licence. Contact ".Branding::vendorContact().' to add it.', 'license_module');
        }
        if (! in_array($status, [self::ENABLED, self::DISABLED, self::RESERVED], true)) {
            throw new DomainException('Unknown module status.');
        }
        if ($module->isCore() && $status !== self::ENABLED) {
            throw new DomainException('Core is always installed.');
        }
        if (! $module->isAvailable() && $status === self::ENABLED) {
            throw new DomainException("{$module->name} is not released yet ({$module->statusLabel()}). Reserve it for this branch instead.");
        }
        if ($module->isAvailable() && $status === self::RESERVED) {
            $status = self::ENABLED; // nothing to wait for
        }

        $current = $this->statuses($branch);
        if ($status !== self::DISABLED) {
            foreach ($module->requires as $dep) {
                if (! in_array($current[$dep] ?? self::DISABLED, [self::ENABLED, self::RESERVED], true)) {
                    throw new DomainException("{$module->name} needs {$this->registry->get($dep)->name} on this branch first.");
                }
            }
        } else {
            foreach ($current as $key => $st) {
                if ($st !== self::DISABLED && $key !== $moduleKey && in_array($moduleKey, $this->registry->get($key)->requires, true)) {
                    throw new DomainException("{$this->registry->get($key)->name} on this branch depends on {$module->name}.");
                }
            }
        }

        if ($status === self::ENABLED) {
            app(IndustryService::class)->ensureFor($actor, $branch, $moduleKey); // one industry per branch
            app(IndustryUnits::class)->ensure($branch->tenant_id, $moduleKey); // e.g. tablet, strip, vial for Pharmacy
        }

        $row = BranchModule::withoutGlobalScopes()->firstOrNew(['branch_id' => $branch->id, 'module_key' => $moduleKey]);
        $row->tenant_id = $branch->tenant_id;
        $row->status = $status;
        $row->module_version = $module->version;
        $row->reason = $reason;
        $row->changed_by = $actor->id;

        return $this->recorder->save($actor, $row, "module.{$status}", $reason, $branch->id);
    }

    /** @return array<string, string> module_key => status */
    public function statuses(Branch $branch): array
    {
        return BranchModule::withoutGlobalScopes()->where('branch_id', $branch->id)->pluck('status', 'module_key')->all();
    }

    public function isEnabled(Branch|string $branch, string $moduleKey): bool
    {
        $id = $branch instanceof Branch ? $branch->id : $branch;

        return BranchModule::withoutGlobalScopes()->where('branch_id', $id)->where('module_key', $moduleKey)
            ->where('status', self::ENABLED)->exists();
    }

    /**
     * Is the module on at any branch of the current company (of the industry chosen at the top, when one is) and still
     * in the licence? Decides which module screens and fields the back office shows. A module left switched on after a
     * licence downgrade stays hidden.
     */
    public static function enabledAnywhere(string $moduleKey): bool
    {
        $branches = IndustryContext::branchIds(); // the industry chosen at the top: only its modules' screens show

        return app(LicenseManager::class)->allowsModule($moduleKey)
            && BranchModule::where('module_key', $moduleKey)->where('status', self::ENABLED)
                ->when($branches !== null, fn ($q) => $q->whereIn('branch_id', $branches))->exists();
    }

    /**
     * Modules a customer may choose (wizard, Modules screen, industry pickers): only the ones their licence covers.
     * A licence without a module list (development, unlicensed trial) covers them all.
     *
     * @return array<string, ModuleManifest>
     */
    public static function licensed(): array
    {
        $licence = app(LicenseManager::class);

        return array_filter(app(ModuleRegistry::class)->selectable(), fn ($m) => $licence->allowsModule($m->key));
    }
}
