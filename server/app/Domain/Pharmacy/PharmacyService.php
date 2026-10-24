<?php

namespace App\Domain\Pharmacy;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Customer;
use App\Models\DrugInteraction;
use App\Models\PatientProfile;
use App\Models\Product;
use App\Security\Authorizer;

/**
 * Pharmacy (owner decision 2026-10-16): patient profiles, the local interaction table, and the allergy /
 * interaction warnings shown to the pharmacist. The warnings rule is identical on the till (pos/src/pharmacy.ts).
 */
final class PharmacyService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /** "Amoxicillin, ibuprofen " → ["amoxicillin", "ibuprofen"] */
    public static function names(array|string|null $list): array
    {
        $items = is_array($list) ? $list : explode(',', (string) $list);

        return array_values(array_unique(array_filter(array_map(fn ($s) => mb_strtolower(trim((string) $s)), $items))));
    }

    /**
     * Allergy and interaction warnings for medicines dispensed together.
     *
     * @param  list<array{id: string, name: string, generic_name?: ?string, active_ingredients?: ?array}>  $products
     * @param  list<array{ingredient_a: string, ingredient_b: string, severity: string, note: string}>  $interactions
     * @return list<array{kind: string, severity: string, message: string}>
     */
    public static function warnings(array $products, array $allergies, array $interactions): array
    {
        $allergies = self::names($allergies);
        $ingredientsOf = fn (array $p) => self::names(array_merge($p['active_ingredients'] ?? [], [$p['generic_name'] ?? '']));
        $out = [];
        foreach ($products as $p) {
            foreach (array_intersect($ingredientsOf($p), $allergies) as $i) {
                $out[] = ['kind' => 'allergy', 'severity' => 'major', 'message' => "{$p['name']}: patient is allergic to {$i}."];
            }
        }
        $list = array_values($products);
        for ($x = 0; $x < count($list); $x++) {
            for ($y = $x + 1; $y < count($list); $y++) {
                if ($list[$x]['id'] === $list[$y]['id']) {
                    continue;
                }
                foreach ($interactions as $r) {
                    $a = mb_strtolower($r['ingredient_a']);
                    $b = mb_strtolower($r['ingredient_b']);
                    $ix = $ingredientsOf($list[$x]);
                    $iy = $ingredientsOf($list[$y]);
                    if ((in_array($a, $ix, true) && in_array($b, $iy, true)) || (in_array($b, $ix, true) && in_array($a, $iy, true))) {
                        $out[] = ['kind' => 'interaction', 'severity' => $r['severity'], 'message' => "{$list[$x]['name']} + {$list[$y]['name']}: {$r['note']}"];
                    }
                }
            }
        }

        return $out;
    }

    public static function warningsFor(array $productIds, ?string $customerId): array
    {
        $products = Product::whereIn('id', array_unique($productIds))->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'generic_name' => $p->generic_name,
            'active_ingredients' => $p->active_ingredients ?? []])->values()->all();
        $allergies = $customerId ? (PatientProfile::find($customerId)?->allergies ?? []) : [];

        return self::warnings($products, $allergies, DrugInteraction::get(['ingredient_a', 'ingredient_b', 'severity', 'note'])->toArray());
    }

    /** @param array{date_of_birth?: ?string, sex?: ?string, allergies?: array|string|null, conditions?: ?string, nhis_no?: ?string, nhis_expires_on?: ?string} $data */
    public function savePatient(Actor $actor, Customer $customer, array $data): PatientProfile
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'pharmacy.patients')) {
            throw new AccessDenied;
        }
        $p = PatientProfile::find($customer->id) ?? (new PatientProfile)->forceFill(['customer_id' => $customer->id, 'tenant_id' => $customer->tenant_id]);
        $p->forceFill(['date_of_birth' => ($data['date_of_birth'] ?? null) ?: null, 'sex' => ($data['sex'] ?? null) ?: null,
            'allergies' => self::names($data['allergies'] ?? []), 'conditions' => ($data['conditions'] ?? null) ?: null,
            'nhis_no' => ($data['nhis_no'] ?? null) ? mb_strtoupper(trim($data['nhis_no'])) : null, 'nhis_expires_on' => ($data['nhis_expires_on'] ?? null) ?: null]);

        return $this->recorder->save($actor, $p, $p->exists ? 'patient.updated' : 'patient.created');
    }

    /** @param array{ingredient_a: string, ingredient_b: string, severity: string, note: string} $data */
    public function saveInteraction(Actor $actor, DrugInteraction $rule, array $data): DrugInteraction
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'pharmacy.manage')) {
            throw new AccessDenied;
        }
        [$a, $b] = [mb_strtolower(trim((string) ($data['ingredient_a'] ?? ''))), mb_strtolower(trim((string) ($data['ingredient_b'] ?? '')))];
        if ($a === '' || $b === '' || $a === $b || ! array_key_exists($data['severity'] ?? '', DrugInteraction::SEVERITIES) || trim((string) ($data['note'] ?? '')) === '') {
            throw new DomainException('Enter two different ingredients, the severity and what to do.');
        }
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }
        if (DrugInteraction::where('ingredient_a', $a)->where('ingredient_b', $b)->when($rule->exists, fn ($q) => $q->whereKeyNot($rule->id))->exists()) {
            throw new DomainException('That pair is already in the table.');
        }
        $rule->forceFill(['tenant_id' => $actor->tenantId, 'ingredient_a' => $a, 'ingredient_b' => $b, 'severity' => $data['severity'],
            'note' => mb_substr(trim($data['note']), 0, 255), 'source' => 'local']);

        return $this->recorder->save($actor, $rule, $rule->exists ? 'drug_interaction.updated' : 'drug_interaction.created');
    }

    /** Adds the pairs of the starter list (InteractionStarter) not in the table yet; never changes a pair already there. */
    public function addStarter(Actor $actor): int
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'pharmacy.manage')) {
            throw new AccessDenied;
        }

        return $this->recorder->transaction(function () use ($actor) {
            $added = 0;
            foreach (InteractionStarter::ROWS as [$a, $b, $severity, $note]) {
                [$a, $b] = $a > $b ? [$b, $a] : [$a, $b];
                if (DrugInteraction::where('ingredient_a', $a)->where('ingredient_b', $b)->exists()) {
                    continue;
                }
                $rule = (new DrugInteraction)->forceFill(['tenant_id' => $actor->tenantId, 'ingredient_a' => $a, 'ingredient_b' => $b, 'severity' => $severity,
                    'note' => $note, 'source' => 'starter']);
                $this->recorder->save($actor, $rule, 'drug_interaction.created', 'Starter list');
                $added++;
            }

            return $added;
        });
    }

    /** Medicines the warnings cannot check: neither a generic name nor active ingredients. */
    public static function unchecked(): int
    {
        return Product::whereNotNull('drug_schedule')->where('is_active', true)->where(fn ($q) => $q->whereNull('generic_name')->orWhere('generic_name', ''))
            ->where(fn ($q) => $q->whereNull('active_ingredients')->orWhereRaw("active_ingredients::jsonb = '[]'::jsonb"))->count();
    }
}
