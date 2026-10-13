<?php

namespace App\Domain\Org;

use App\Domain\Licensing\LicenseManager;
use App\Domain\Modules\ModuleService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\PriceList;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\Authorizer;

/** Company, legal entity, branches, warehouses and tills — including the first-run setup wizard. */
final class OrgService
{
    public const DEPLOYMENT_MODES = [
        'cloud' => 'Cloud HQ (branches connect over the internet, optional branch servers)',
        'central' => 'Central on-premise server (branches on LAN/VPN)',
        'standalone' => 'Standalone single PC (fully offline, cloud optional later)',
    ];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly ModuleService $modules,
    ) {}

    /**
     * First-run wizard. $data = [deployment_mode, company => [...], legal_entity => [...],
     * branches => [[code, name, is_hq, tills, modules => [key...]], ...]].
     */
    public function completeSetup(Actor $actor, array $data): Tenant
    {
        $this->require($actor, 'setup.company.manage');
        $tenant = Tenant::findOrFail($actor->tenantId);
        if ($tenant->isSetUp()) {
            throw new DomainException('Setup has already been completed. Change branches and modules from their own pages.');
        }
        if (! array_key_exists($data['deployment_mode'] ?? '', self::DEPLOYMENT_MODES)) {
            throw new DomainException('Choose a deployment mode.');
        }
        $branches = $data['branches'] ?? [];
        if ($branches === []) {
            throw new DomainException('Add at least one branch.');
        }
        if (count(array_filter($branches, fn ($b) => ! empty($b['is_hq']))) !== 1) {
            throw new DomainException('Mark exactly one branch as head office (HQ).');
        }
        $codes = array_map(fn ($b) => strtoupper(trim($b['code'])), $branches);
        if (count($codes) !== count(array_unique($codes))) {
            throw new DomainException('Branch codes must be unique.');
        }

        return $this->recorder->transaction(function () use ($actor, $tenant, $data, $branches) {
            $tenant->fill(array_intersect_key($data['company'] ?? [], array_flip(['name', 'country_code', 'currency_code', 'locale', 'timezone'])));
            $tenant->deployment_mode = $data['deployment_mode'];
            $tenant->setup_completed_at = now();
            $this->recorder->save($actor, $tenant, 'setup.completed');
            // Currency/country may have changed in the wizard: align still-unused starter data.
            $this->alignStarterCurrency($tenant);

            $entity = null;
            if (! empty($data['legal_entity']['name'])) {
                $entity = new LegalEntity(array_intersect_key($data['legal_entity'], array_flip(['name', 'registration_no', 'tin', 'vat_no'])));
                $entity->is_primary = ! LegalEntity::exists(); // the head office company
                $entity->tenant_id = $tenant->id;
                $entity->currency_code = $tenant->currency_code;
                $this->recorder->save($actor, $entity, 'legal_entity.created');
            }

            foreach ($branches as $b) {
                $branch = $this->createBranch($actor, [
                    'code' => $b['code'], 'name' => $b['name'], 'is_hq' => ! empty($b['is_hq']),
                    'legal_entity_id' => $entity?->id, 'address' => $b['address'] ?? null, 'phone' => $b['phone'] ?? null,
                ], (int) ($b['tills'] ?? 1), bootstrap: true);

                foreach ($b['modules'] ?? [] as $key) {
                    $this->modules->setStatus($actor, $branch, $key, ModuleService::RESERVED, 'Chosen in setup wizard', bootstrap: true);
                }
            }

            return $tenant;
        });
    }

    /** Creates the branch with a main warehouse, N tills and Core enabled. */
    public function createBranch(Actor $actor, array $data, int $tills = 1, bool $bootstrap = false): Branch
    {
        if (! $bootstrap) {
            $this->require($actor, 'setup.company.manage');
        }
        if ($tills < 0 || $tills > 50) {
            throw new DomainException('A branch can start with 0 to 50 tills.');
        }
        $code = strtoupper(trim($data['code']));
        if (! preg_match('/^[A-Z0-9]{1,6}$/', $code)) {
            throw new DomainException('Branch code: 1–6 letters or digits (it appears in receipt numbers).');
        }
        if (Branch::withoutGlobalScopes()->where('tenant_id', $actor->tenantId)->where('code', $code)->exists()) {
            throw new DomainException("Branch code {$code} is already used.");
        }

        app(LicenseManager::class)->assertWithin('branches', Branch::count(), 'branches');

        return $this->recorder->transaction(function () use ($actor, $data, $code, $tills) {
            $branch = new Branch(array_intersect_key($data, array_flip(['legal_entity_id', 'name', 'is_hq', 'address', 'phone', 'timezone'])));
            $branch->code = $code;
            $branch->tenant_id = $actor->tenantId;
            $this->recorder->save($actor, $branch, 'branch.created', branchId: null);

            $warehouse = new Warehouse(['branch_id' => $branch->id, 'code' => $code.'-W1', 'name' => $branch->name.' main store']);
            $warehouse->tenant_id = $actor->tenantId;
            $this->recorder->save($actor, $warehouse, 'warehouse.created', branchId: $branch->id);

            for ($i = 1; $i <= $tills; $i++) {
                $till = new Till(['branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'code' => sprintf('T%02d', $i), 'name' => "Till {$i}"]);
                $till->tenant_id = $actor->tenantId;
                $this->recorder->save($actor, $till, 'till.created', branchId: $branch->id);
            }

            $this->modules->setStatus($actor, $branch, 'core', ModuleService::ENABLED, 'Core is always installed', bootstrap: true);

            return $branch;
        });
    }

    public function updateBranch(Actor $actor, Branch $branch, array $data, ?string $reason = null): Branch
    {
        $this->require($actor, 'setup.company.manage', $branch);
        $branch->fill(array_intersect_key($data, array_flip(['name', 'address', 'phone', 'timezone', 'is_active', 'legal_entity_id'])));

        return $this->recorder->transaction(function () use ($actor, $branch, $data, $reason) {
            $this->recorder->save($actor, $branch, 'branch.updated', $reason, $branch->id);
            if (array_key_exists('industry_id', $data) && ($data['industry_id'] ?: null) !== $branch->industry_id) {
                app(IndustryService::class)->assign($actor, $branch, ($id = $data['industry_id'] ?: null) ? Industry::findOrFail($id) : null);
            }

            return $branch;
        });
    }

    public function saveWarehouse(Actor $actor, Warehouse $warehouse, array $data): Warehouse
    {
        $branch = Branch::findOrFail($data['branch_id'] ?? $warehouse->branch_id);
        $this->require($actor, 'setup.company.manage', $branch);
        $warehouse->fill(array_intersect_key($data, array_flip(['branch_id', 'code', 'name', 'is_active'])));
        $warehouse->code = strtoupper($warehouse->code);
        $warehouse->tenant_id = $actor->tenantId;

        return $this->recorder->save($actor, $warehouse, $warehouse->exists ? 'warehouse.updated' : 'warehouse.created', branchId: $branch->id);
    }

    public function saveTill(Actor $actor, Till $till, array $data): Till
    {
        $branch = Branch::findOrFail($data['branch_id'] ?? $till->branch_id);
        $this->require($actor, 'setup.company.manage', $branch);
        $warehouse = Warehouse::findOrFail($data['warehouse_id'] ?? $till->warehouse_id);
        if ($warehouse->branch_id !== $branch->id) {
            throw new DomainException('A till sells from a warehouse in its own branch.');
        }
        $till->fill(array_intersect_key($data, array_flip(['branch_id', 'warehouse_id', 'code', 'name', 'is_active'])));
        $till->code = strtoupper($till->code);
        $till->tenant_id = $actor->tenantId;

        return $this->recorder->save($actor, $till, $till->exists ? 'till.updated' : 'till.created', branchId: $branch->id);
    }

    private function alignStarterCurrency(Tenant $tenant): void
    {
        PriceList::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereDoesntHave('prices')
            ->update(['currency_code' => $tenant->currency_code]);
    }

    /**
     * A registered company of the group (Company → Legal entities). Separate books: an industry registered as its own
     * company is given its legal entity (Company → Industries) and its branches post into that entity's books. Exactly
     * one entity is the primary (head office) company.
     *
     * @param  array{name?: string, registration_no?: ?string, tin?: ?string, vat_no?: ?string, address?: ?string, is_primary?: bool, is_active?: bool}  $data
     */
    public function saveLegalEntity(Actor $actor, LegalEntity $entity, array $data): LegalEntity
    {
        $this->require($actor, 'setup.company.manage');
        $name = trim((string) ($data['name'] ?? $entity->name ?? ''));
        if ($name === '') {
            throw new DomainException('Enter the registered name.');
        }
        $tin = strtoupper(preg_replace('/\s+/', '', (string) ($data['tin'] ?? $entity->tin ?? '')));
        if ($tin !== '' && ! preg_match('/^[A-Z0-9]{8,15}$/', $tin)) {
            throw new DomainException('The TIN is 8 to 15 letters and digits (for example C0012345678).');
        }
        $first = ! LegalEntity::when($entity->exists, fn ($q) => $q->whereKeyNot($entity->getKey()))->exists();
        $primary = $first || (bool) ($data['is_primary'] ?? $entity->is_primary);
        $active = (bool) ($data['is_active'] ?? $entity->is_active ?? true);
        if ($entity->exists && $entity->is_primary && ! $primary) {
            throw new DomainException('Make another legal entity the head office company instead; there is always one.');
        }
        if (! $active && ($primary || Industry::where('legal_entity_id', $entity->id)->exists() || Branch::where('legal_entity_id', $entity->id)->where('is_active', true)->exists())) {
            throw new DomainException('This legal entity is in use (head office, an industry or a branch). Move them first.');
        }
        $entity->fill(['name' => mb_substr($name, 0, 150), 'registration_no' => ($data['registration_no'] ?? $entity->registration_no) ?: null, 'tin' => $tin ?: null,
            'vat_no' => ($data['vat_no'] ?? $entity->vat_no) ?: null, 'address' => ($data['address'] ?? $entity->address) ?: null,
            'is_primary' => $primary, 'is_active' => $active]);
        $entity->tenant_id ??= $actor->tenantId;

        return $this->recorder->transaction(function () use ($actor, $entity, $primary) {
            if ($primary) {
                foreach (LegalEntity::where('is_primary', true)->when($entity->exists, fn ($q) => $q->whereKeyNot($entity->getKey()))->get() as $old) {
                    $old->is_primary = false;
                    $this->recorder->save($actor, $old, 'legal_entity.updated');
                }
            }

            return $this->recorder->save($actor, $entity, $entity->exists ? 'legal_entity.updated' : 'legal_entity.created');
        });
    }

    private function require(Actor $actor, string $permission, ?Branch $branch = null): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $branch)) {
            throw new AccessDenied;
        }
    }
}
