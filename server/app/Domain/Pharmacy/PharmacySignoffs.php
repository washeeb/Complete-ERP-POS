<?php

namespace App\Domain\Pharmacy;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\PharmacySignoff;
use App\Security\Authorizer;
use Illuminate\Support\Facades\DB;

/** A pharmacist checks and signs medicine sales made without their approval (where tellers may sell them). */
final class PharmacySignoffs
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /** @param list<string> $saleIds */
    public function sign(Actor $actor, array $saleIds, ?string $note = null): int
    {
        $sales = DB::table('sales')->where('tenant_id', $actor->tenantId)->whereIn('id', $saleIds)->get(['id', 'branch_id', 'cashier_id']);
        if ($sales->isEmpty()) {
            throw new DomainException('Tick the sales to sign off.');
        }
        foreach ($sales->pluck('branch_id')->unique() as $branchId) {
            if (! $actor->user || ! $this->authz->allows($actor->user, 'pharmacy.dispense', Branch::findOrFail($branchId))) {
                throw new AccessDenied('Signing off medicine sales needs a pharmacist (permission to dispense).');
            }
        }
        $done = DB::table('pharmacy_signoffs')->whereIn('sale_id', $sales->pluck('id'))->pluck('sale_id')->flip();
        $note = mb_substr(trim((string) $note), 0, 255) ?: null;

        return $this->recorder->transaction(function () use ($actor, $sales, $done, $note) {
            $n = 0;
            foreach ($sales as $s) {
                if (isset($done[$s->id])) {
                    continue;
                }
                $signoff = (new PharmacySignoff)->forceFill(['tenant_id' => $actor->tenantId, 'branch_id' => $s->branch_id, 'sale_id' => $s->id,
                    'signed_by' => $actor->id, 'signed_by_name' => $actor->name, 'note' => $note, 'signed_at' => now()]);
                $this->recorder->save($actor, $signoff, 'pharmacy.sale_signed_off', $note, $s->branch_id);
                $n++;
            }

            return $n;
        });
    }
}
