<?php

namespace App\Domain\Org;

use App\Domain\Licensing\LicenseManager;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Archiving what was made by mistake, is no longer used, or has closed (owner requests 2026-11-08 and 2026-11-09):
 * industries, legal entities and branches, and every other record that cannot be deleted because history points at it
 * (ArchiveRules lists them). An archived record leaves the lists people choose from, so nothing new is given to it,
 * while sales, stock, books and the audit log keep showing it. Restore brings it back.
 *
 * Archiving needs a reason and refuses while something still depends on the record, saying what to do first. A
 * branch takes its stores and tills with it, and restoring brings back exactly those.
 */
final class ArchiveService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /** Why it cannot be archived now, in plain words; empty when it can. */
    public function blockers(Model $record): array
    {
        return match (true) {
            $record instanceof Branch => $this->branchBlockers($record),
            $record instanceof Industry => $this->industryBlockers($record),
            $record instanceof LegalEntity => $this->entityBlockers($record),
            ArchiveRules::supports($record) => ArchiveRules::blockers($record),
            default => throw new DomainException('This is not archived this way.'),
        };
    }

    /** May this person archive or restore it? (for showing the buttons) */
    public function allowed(?User $user, Model $record): bool
    {
        if (! $user || ! ArchiveRules::supports($record)) {
            return false;
        }
        [, , $permission, , $scoped] = ArchiveRules::of($record);

        return $scoped ? $this->authz->allows($user, $permission, ArchiveRules::branchOf($record)) : $this->authz->allowsAnywhere($user, $permission);
    }

    public function archive(Actor $actor, Model $record, string $reason): Model
    {
        $this->require($actor, $record);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new DomainException('Say why it is archived (for example "created by mistake" or "no longer used").');
        }
        if ($record->archived_at) {
            throw new DomainException('It is already archived.');
        }
        if ($why = $this->blockers($record)) {
            throw new DomainException('It cannot be archived yet: '.implode(' ', $why));
        }

        return $this->recorder->transaction(function () use ($actor, $record, $reason) {
            $branchId = ArchiveRules::branchOf($record)?->id;
            $off = [];
            if ($record instanceof Branch) { // its stores and tills go with it
                foreach ([Warehouse::class => 'warehouses', Till::class => 'tills'] as $class => $key) {
                    $ids = $class::where('branch_id', $record->id)->whereNull('archived_at')->where('is_active', true)->pluck('id')->all(); // only those in use
                    foreach ($class::whereIn('id', $ids)->get() as $child) {
                        $child->forceFill(['archived_at' => now(), 'archived_by' => $actor->user?->id, 'archive_reason' => mb_substr("Branch archived: {$reason}", 0, 255)]);
                        $this->recorder->save($actor, $child, ArchiveRules::of($child)[1].'.archived', "Branch archived: {$reason}", $record->id);
                    }
                    $off[$key] = $ids;
                }
            }
            $fill = ['archived_at' => now(), 'archived_by' => $actor->user?->id, 'archive_reason' => mb_substr($reason, 0, 255)];
            if ($this->hasSwitch($record)) {
                $fill['is_active'] = false;
            }
            if ($record instanceof Branch) {
                $fill['archived_children'] = $off ?: null;
            }
            $record->forceFill($fill);
            $saved = $this->recorder->save($actor, $record, ArchiveRules::of($record)[1].'.archived', $reason, $branchId);
            if ($record instanceof User) {
                $record->tokens()->delete(); // signed out everywhere, including tills
                $this->authz->forget($record);
            }

            return $saved;
        });
    }

    public function restore(Actor $actor, Model $record, ?string $reason = null): Model
    {
        $this->require($actor, $record);
        if (! $record->archived_at) {
            throw new DomainException('It is not archived.');
        }
        $parents = match (true) {
            $record instanceof Branch => [$record->industry, $record->legalEntity],
            $record instanceof Industry => [$record->legalEntity],
            $record instanceof Warehouse, $record instanceof Till => [$record->branch],
            default => [],
        };
        foreach (array_filter($parents) as $p) {
            if ($p->archived_at) {
                throw new DomainException("Restore {$p->name} first: it is archived too.");
            }
        }
        if ($record instanceof Branch) { // it takes a place in the licence again
            app(LicenseManager::class)->assertWithin('branches', Branch::whereNull('archived_at')->count(), 'branches');
        }
        if ($record instanceof User) {
            app(LicenseManager::class)->assertWithin('users', User::licensedCount(), 'active users');
        }

        return $this->recorder->transaction(function () use ($actor, $record, $reason) {
            $branchId = ArchiveRules::branchOf($record)?->id;
            foreach ($record instanceof Branch ? [Warehouse::class => 'warehouses', Till::class => 'tills'] : [] as $key) {
                $class = $key === 'tills' ? Till::class : Warehouse::class;
                foreach ($class::whereIn('id', (array) ($record->archived_children[$key] ?? []))->get() as $child) {
                    $child->forceFill(['archived_at' => null, 'archived_by' => null, 'archive_reason' => null, 'is_active' => true]);
                    $this->recorder->save($actor, $child, ArchiveRules::of($child)[1].'.restored', 'Branch restored', $record->id);
                }
            }
            $fill = ['archived_at' => null, 'archived_by' => null, 'archive_reason' => null];
            // Where Archive replaced an "in use" switch, restoring switches it back on; a switch with its own meaning
            // (for sale, running, offered) stays off for the person to decide.
            if ($this->hasSwitch($record) && ArchiveRules::of($record)[3] === 'replaced') {
                $fill['is_active'] = true;
            }
            if ($record instanceof Branch) {
                $fill['archived_children'] = null;
            }
            $record->forceFill($fill);
            $saved = $this->recorder->save($actor, $record, ArchiveRules::of($record)[1].'.restored', $reason, $branchId);
            if ($record instanceof User) {
                $this->authz->forget($record);
            }

            return $saved;
        });
    }

    private function hasSwitch(Model $record): bool
    {
        return ArchiveRules::of($record)[3] !== 'none';
    }

    private function branchBlockers(Branch $branch): array
    {
        $why = [];
        if ($branch->is_hq) {
            $why[] = 'It is the head office. Make another branch the head office first.';
        }
        $warehouses = Warehouse::where('branch_id', $branch->id)->pluck('id');
        if ($warehouses->isNotEmpty() && DB::table('stock_levels')->whereIn('warehouse_id', $warehouses)->where('on_hand', '!=', 0)->exists()) {
            $why[] = 'Its stores still hold stock. Transfer the stock to another branch, or count it to zero, first.';
        }
        if ($warehouses->isNotEmpty() && DB::table('stock_transfers')->where('status', 'in_transit')
            ->where(fn ($q) => $q->whereIn('from_warehouse_id', $warehouses)->orWhereIn('to_warehouse_id', $warehouses))->exists()) {
            $why[] = 'Stock is on the way to or from it. Receive or cancel those transfers first.';
        }
        if (DB::table('shifts')->where('branch_id', $branch->id)->where('status', 'open')->exists()) {
            $why[] = 'A till shift is still open there. Close it first.';
        }
        if (($n = Device::where('branch_id', $branch->id)->where('status', 'active')->count()) > 0) {
            $why[] = "{$n} device(s) are still enrolled there. Once each till has sent its sales, revoke it under System → Devices.";
        }

        return $why;
    }

    private function industryBlockers(Industry $industry): array
    {
        $open = Branch::where('industry_id', $industry->id)->whereNull('archived_at')->orderBy('code')->pluck('code');

        return $open->isEmpty() ? [] : ['Branches still use it ('.$open->implode(', ').'). Archive them or move them to another industry first.'];
    }

    private function entityBlockers(LegalEntity $entity): array
    {
        $why = [];
        if ($entity->is_primary) {
            $why[] = 'It is the head office company. Make another legal entity the head office company first.';
        }
        $industries = Industry::where('legal_entity_id', $entity->id)->whereNull('archived_at')->orderBy('sort')->pluck('name');
        if ($industries->isNotEmpty()) {
            $why[] = 'Industries still keep their books in it ('.$industries->implode(', ').'). Archive them or change their books first.';
        }
        $branches = Branch::where('legal_entity_id', $entity->id)->whereNull('archived_at')->orderBy('code')->pluck('code');
        if ($branches->isNotEmpty()) {
            $why[] = 'Branches still belong to it ('.$branches->implode(', ').'). Archive them or move them first.';
        }

        return $why;
    }

    private function require(Actor $actor, Model $record): void
    {
        if (! $this->allowed($actor->user, $record)) {
            throw new AccessDenied;
        }
    }
}
