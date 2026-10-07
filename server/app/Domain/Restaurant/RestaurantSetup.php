<?php

namespace App\Domain\Restaurant;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\DiningOrder;
use App\Models\DiningTable;
use App\Models\KitchenStation;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Security\Authorizer;

/** Restaurant set-up (owner decision 2026-10-17): kitchen stations, the floor plan, and modifier groups. */
final class RestaurantSetup
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /** @param array{branch_id?: string, code?: string, name?: string, auto_print?: bool, is_active?: bool} $data */
    public function saveStation(Actor $actor, KitchenStation $s, array $data): KitchenStation
    {
        $branch = Branch::findOrFail($data['branch_id'] ?? $s->branch_id);
        $this->require($actor, $branch);
        $code = strtoupper(trim((string) ($data['code'] ?? $s->code)));
        if (! preg_match('/^[A-Z0-9_-]{1,20}$/', $code) || trim((string) ($data['name'] ?? $s->name)) === '') {
            throw new DomainException('Enter a short code (e.g. KITCHEN, BAR) and a name.');
        }
        if (KitchenStation::where('branch_id', $branch->id)->where('code', $code)->when($s->exists, fn ($q) => $q->whereKeyNot($s->id))->exists()) {
            throw new DomainException("{$branch->code} already has a station {$code}.");
        }
        $s->forceFill(['tenant_id' => $actor->tenantId, 'branch_id' => $branch->id, 'code' => $code, 'name' => trim((string) ($data['name'] ?? $s->name)),
            'auto_print' => (bool) ($data['auto_print'] ?? $s->auto_print ?? false), 'is_active' => (bool) ($data['is_active'] ?? $s->is_active ?? true)]);

        return $this->recorder->save($actor, $s, $s->exists ? 'kitchen_station.updated' : 'kitchen_station.created', branchId: $branch->id);
    }

    /** @param array{branch_id?: string, code?: string, area?: ?string, seats?: int, pos_x?: int, pos_y?: int, is_active?: bool} $data */
    public function saveTable(Actor $actor, DiningTable $t, array $data): DiningTable
    {
        $branch = Branch::findOrFail($data['branch_id'] ?? $t->branch_id);
        $this->require($actor, $branch);
        $code = strtoupper(trim((string) ($data['code'] ?? $t->code)));
        if ($code === '' || mb_strlen($code) > 20) {
            throw new DomainException('Enter the table number or name (up to 20 characters).');
        }
        if (DiningTable::where('branch_id', $branch->id)->where('code', $code)->when($t->exists, fn ($q) => $q->whereKeyNot($t->id))->exists()) {
            throw new DomainException("{$branch->code} already has a table {$code}.");
        }
        $seats = (int) ($data['seats'] ?? $t->seats ?? 4);
        if ($seats < 1 || $seats > 60) {
            throw new DomainException('Seats: 1 to 60.');
        }
        $active = (bool) ($data['is_active'] ?? $t->is_active ?? true);
        if ($t->exists && ! $active && DiningOrder::where('dining_table_id', $t->id)->where('status', 'open')->exists()) {
            throw new DomainException("Table {$code} has an open bill; settle or move it first.");
        }
        $t->forceFill(['tenant_id' => $actor->tenantId, 'branch_id' => $branch->id, 'code' => $code, 'area' => trim((string) ($data['area'] ?? $t->area)) ?: null,
            'seats' => $seats, 'pos_x' => max(0, min(99, (int) ($data['pos_x'] ?? $t->pos_x ?? 0))), 'pos_y' => max(0, min(99, (int) ($data['pos_y'] ?? $t->pos_y ?? 0))),
            'is_active' => $active]);

        return $this->recorder->save($actor, $t, $t->exists ? 'dining_table.updated' : 'dining_table.created', branchId: $branch->id);
    }

    /**
     * @param  array{name?: string, min_choices?: int, max_choices?: int, is_active?: bool, options?: list<array{id?: ?string, name: string, product_id?: ?string}>}  $data
     */
    public function saveModifierGroup(Actor $actor, ModifierGroup $g, array $data): ModifierGroup
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'restaurant.manage')) {
            throw new AccessDenied;
        }
        $name = trim((string) ($data['name'] ?? $g->name));
        $min = (int) ($data['min_choices'] ?? $g->min_choices ?? 0);
        $max = (int) ($data['max_choices'] ?? $g->max_choices ?? 1);
        $options = null;
        if (array_key_exists('options', $data)) {
            $options = [];
            foreach (array_values($data['options'] ?? []) as $i => $o) {
                $n = trim((string) ($o['name'] ?? ''));
                if ($n === '') {
                    continue;
                }
                $product = ! empty($o['product_id']) ? Product::find($o['product_id']) ?? throw new DomainException("{$n}: choose the product it is charged as.") : null;
                $options[] = ['id' => $o['id'] ?? null, 'name' => mb_substr($n, 0, 120), 'product_id' => $product?->id, 'sort' => $i];
            }
            if ($options === []) {
                throw new DomainException('Add at least one choice.');
            }
        }
        $count = $options !== null ? count($options) : $g->options()->count();
        if ($name === '' || $min < 0 || $max < 1 || $min > $max || $min > $count) {
            throw new DomainException('Enter a name; "at least" cannot be more than "at most" or than the number of choices.');
        }

        return $this->recorder->transaction(function () use ($actor, $g, $name, $min, $max, $options, $data) {
            $isNew = ! $g->exists;
            $g->forceFill(['tenant_id' => $actor->tenantId, 'name' => $name, 'min_choices' => $min, 'max_choices' => $max,
                'is_active' => (bool) ($data['is_active'] ?? $g->is_active ?? true)]);
            $this->recorder->save($actor, $g, $isNew ? 'modifier_group.created' : 'modifier_group.updated');
            if ($options !== null) {
                $keep = [];
                foreach ($options as $o) {
                    $opt = ($o['id'] ? ModifierOption::where('modifier_group_id', $g->id)->find($o['id']) : null) ?? new ModifierOption(['modifier_group_id' => $g->id]);
                    $opt->fill(['name' => $o['name'], 'product_id' => $o['product_id'], 'sort' => $o['sort']])->save();
                    $keep[] = $opt->id;
                }
                ModifierOption::where('modifier_group_id', $g->id)->whereNotIn('id', $keep)->delete(); // past orders keep the name they were sold with
                $g->forceFill(['updated_at' => now()])->saveQuietly(); // tills see the new choices (catalog version)
                $this->recorder->audit->record($actor, 'modifier_group.options_changed', $g, ['options' => [null, count($keep)]]);
                $this->recorder->outbox->publish($actor, $g->fresh(), 'modifier_group.options_changed');
            }

            return $g->fresh('options');
        });
    }

    private function require(Actor $actor, Branch $branch): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'restaurant.manage', $branch)) {
            throw new AccessDenied;
        }
    }
}
