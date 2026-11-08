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
use App\Models\Warehouse;
use App\Security\Authorizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Archiving industries, legal entities and branches made by mistake, never used, or closed (owner request 2026-11-08):
 * they leave every list people choose from, so nothing new is assigned to them, while their history stays complete
 * (sales, stock, books and audit keep pointing at them). Restore brings one back as it was.
 *
 * Archiving refuses while something still depends on the record, and says what to do first: a branch with stock, open
 * shifts, transfers on the way or tills still enrolled (a till may hold sales it has not sent yet); an industry with
 * branches in use; a legal entity with industries or branches in use, or the head office company. A branch's
 * warehouses and tills are switched off with it, and restoring turns back on exactly the ones it switched off.
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
            default => throw new DomainException('Only industries, legal entities and branches are archived this way.'),
        };
    }

    public function archive(Actor $actor, Model $record, string $reason): Model
    {
        $this->require($actor, $record);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new DomainException('Say why it is archived (for example "created by mistake" or "branch closed").');
        }
        if ($record->archived_at) {
            throw new DomainException('It is already archived.');
        }
        if ($why = $this->blockers($record)) {
            throw new DomainException('It cannot be archived yet: '.implode(' ', $why));
        }

        return $this->recorder->transaction(function () use ($actor, $record, $reason) {
            $off = [];
            if ($record instanceof Branch) {
                foreach ([Warehouse::class => 'warehouses', Till::class => 'tills'] as $class => $key) {
                    $ids = $class::where('branch_id', $record->id)->where('is_active', true)->pluck('id')->all();
                    foreach ($class::whereIn('id', $ids)->get() as $child) {
                        $child->is_active = false;
                        $this->recorder->save($actor, $child, class_basename($class) === 'Till' ? 'till.updated' : 'warehouse.updated', "Branch archived: {$reason}", $record->id);
                    }
                    $off[$key] = $ids;
                }
            }
            $record->forceFill(['is_active' => false, 'archived_at' => now(), 'archived_by' => $actor->user?->id,
                'archive_reason' => mb_substr($reason, 0, 255)] + ($record instanceof Branch ? ['archived_children' => $off ?: null] : []));

            return $this->recorder->save($actor, $record, $this->event($record).'.archived', $reason, $record instanceof Branch ? $record->id : null);
        });
    }

    public function restore(Actor $actor, Model $record, ?string $reason = null): Model
    {
        $this->require($actor, $record);
        if (! $record->archived_at) {
            throw new DomainException('It is not archived.');
        }
        $parent = match (true) {
            $record instanceof Branch => [$record->industry, $record->legalEntity],
            $record instanceof Industry => [$record->legalEntity],
            default => [],
        };
        if ($record instanceof Branch) { // it takes a place in the licence again
            app(LicenseManager::class)->assertWithin('branches', Branch::whereNull('archived_at')->count(), 'branches');
        }
        foreach (array_filter($parent) as $p) {
            if ($p->archived_at) {
                throw new DomainException("Restore {$p->name} first: it is archived too.");
            }
        }

        return $this->recorder->transaction(function () use ($actor, $record, $reason) {
            foreach ($record instanceof Branch ? [Warehouse::class => 'warehouses', Till::class => 'tills'] : [] as $class => $key) {
                foreach ($class::whereIn('id', (array) ($record->archived_children[$key] ?? []))->get() as $child) {
                    $child->is_active = true;
                    $this->recorder->save($actor, $child, class_basename($class) === 'Till' ? 'till.updated' : 'warehouse.updated', 'Branch restored', $record->id);
                }
            }
            $record->forceFill(['is_active' => true, 'archived_at' => null, 'archived_by' => null, 'archive_reason' => null] + ($record instanceof Branch ? ['archived_children' => null] : []));

            return $this->recorder->save($actor, $record, $this->event($record).'.restored', $reason, $record instanceof Branch ? $record->id : null);
        });
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

    private function event(Model $record): string
    {
        return match (true) {
            $record instanceof Branch => 'branch',
            $record instanceof Industry => 'industry',
            default => 'legal_entity',
        };
    }

    private function require(Actor $actor, Model $record): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'setup.company.manage', $record instanceof Branch ? $record : null)) {
            throw new AccessDenied;
        }
    }
}
