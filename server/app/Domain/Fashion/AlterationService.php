<?php

namespace App\Domain\Fashion;

use App\Domain\Modules\ModuleService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Alteration;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Security\Authorizer;
use App\Support\Settings;

/**
 * Alterations (Fashion): a garment taken in for tailoring at an agreed charge. Paid at the till on collection as a line
 * of the alteration product at that charge (outside price lists and promotions, like a markdown sticker); free
 * alterations are handed over without a sale.
 */
final class AlterationService
{
    public const TRANSITIONS = ['received' => ['in_progress', 'ready', 'cancelled'], 'in_progress' => ['ready', 'cancelled'], 'ready' => ['in_progress']];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly ModuleService $modules,
        private readonly Settings $settings,
    ) {}

    /** @param array{branch_id: string, customer_id?: ?string, customer_name?: ?string, phone?: ?string, garment?: string, work?: string, charge_minor?: int, due_on?: ?string} $data */
    public function open(Actor $actor, array $data): Alteration
    {
        $branch = Branch::findOrFail($data['branch_id'] ?? null);
        $this->require($actor, $branch);
        $customer = ! empty($data['customer_id']) ? Customer::find($data['customer_id']) ?? throw new DomainException('That customer is not known here.') : null;
        $name = trim((string) ($data['customer_name'] ?? $customer?->name ?? ''));
        $garment = trim((string) ($data['garment'] ?? ''));
        $work = trim((string) ($data['work'] ?? ''));
        $charge = (int) ($data['charge_minor'] ?? 0);
        if ($name === '' || $garment === '' || $work === '' || $charge < 0) {
            throw new DomainException('Enter the customer, the garment, the work to do and the charge (0 if free).');
        }
        if ($charge > 0 && ! $this->product($actor->tenantId)) {
            throw new DomainException('Set up the alteration product first (Settings → Fashion).', 'alteration_product_missing');
        }

        return $this->recorder->transaction(function () use ($actor, $branch, $customer, $name, $garment, $work, $charge, $data) {
            $a = (new Alteration)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'alteration'), 'branch_id' => $branch->id,
                'customer_id' => $customer?->id, 'customer_name' => mb_substr($name, 0, 120),
                'phone' => preg_replace('/[^0-9+]/', '', (string) ($data['phone'] ?? $customer?->phone ?? '')) ?: null,
                'garment' => mb_substr($garment, 0, 160), 'work' => mb_substr($work, 0, 2000), 'charge_minor' => $charge, 'status' => 'received',
                'due_on' => $data['due_on'] ?? null, 'received_at' => now()]);

            return $this->recorder->save($actor, $a, 'alteration.received', branchId: $branch->id);
        });
    }

    public function setStatus(Actor $actor, Alteration $a, string $status, ?string $tailor = null): Alteration
    {
        $this->require($actor, Branch::findOrFail($a->branch_id));
        if (! in_array($status, self::TRANSITIONS[$a->status] ?? [], true)) {
            throw new DomainException('That alteration cannot move to '.strtolower(Alteration::STATUSES[$status] ?? $status).' now.', 'wrong_status');
        }
        $a->forceFill(['status' => $status] + ($tailor ? ['tailor_name' => mb_substr(trim($tailor), 0, 120)] : []));

        return $this->recorder->save($actor, $a, "alteration.{$status}", branchId: $a->branch_id);
    }

    /** A free alteration (charge 0) collected without a sale. */
    public function handOver(Actor $actor, Alteration $a): Alteration
    {
        $this->require($actor, Branch::findOrFail($a->branch_id));
        if ($a->status !== 'ready' || $a->charge_minor > 0) {
            throw new DomainException($a->charge_minor > 0 ? 'This alteration has a charge: take payment at the till.' : 'Only a ready alteration can be handed over.', 'payment_needed');
        }
        $a->forceFill(['status' => 'collected', 'collected_at' => now()]);

        return $this->recorder->save($actor, $a, 'alteration.collected', branchId: $a->branch_id);
    }

    /** The product alterations are sold as (no stock; its tax group taxes the charge). */
    public function product(string $tenantId): ?Product
    {
        return Product::where('sku', strtoupper((string) $this->settings->get('fashion.alteration_sku', $tenantId)))->where('track_stock', false)->first();
    }

    /** Till line for an alteration: the agreed charge, checked (null when the line is not one). */
    public function forLine(array $l, Branch $branch, Product $product, bool $offline): ?Alteration
    {
        if (empty($l['alteration_id'])) {
            return null;
        }
        $a = Alteration::where('branch_id', $branch->id)->find($l['alteration_id']);
        $ok = $a && in_array($a->status, Alteration::OPEN, true) && $product->id === $this->product($branch->tenant_id)?->id;
        if (! $ok && ! $offline) {
            throw new DomainException('That alteration cannot be paid here (not open at this branch, or not the alteration product).', 'alteration_invalid');
        }

        return $a;
    }

    /** After the sale is recorded: the alterations it paid for are collected. */
    public function paidBySale(Actor $actor, Sale $sale, array $alterationIds, bool $offline, array &$flags): void
    {
        foreach (array_unique($alterationIds) as $id) {
            $a = Alteration::lockForUpdate()->find($id);
            if (! $a || ! in_array($a->status, Alteration::OPEN, true)) {
                $offline ? $flags[] = ['alteration_conflict', 'Paid offline for alteration '.($a?->number ?? $id).' that was not open']
                    : throw new DomainException('That alteration is no longer open.', 'alteration_invalid');

                continue;
            }
            $a->forceFill(['status' => 'collected', 'collected_at' => now(), 'sale_id' => $sale->id]);
            $this->recorder->save($actor, $a, 'alteration.collected', branchId: $a->branch_id, payload: ['sale_id' => $sale->id]);
        }
    }

    private function require(Actor $actor, Branch $branch): void
    {
        if (! $this->modules->isEnabled($branch, 'fashion')) {
            throw new DomainException('The Fashion module is not on at this branch.', 'module_disabled');
        }
        if (! $actor->user || ! $this->authz->allows($actor->user, 'fashion.alterations', $branch)) {
            throw new AccessDenied;
        }
    }
}
