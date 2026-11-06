<?php

namespace App\Domain\Pos;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\OfficeReturn;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use App\Support\Settings;

/**
 * Returns from the back office (owner request 2026-11-06), for a customer who comes to the office or a mistake found
 * later. One person enters it against the receipt and a second approves it, however it is set at the till: money leaves
 * with no till and no drawer to count it against. The refund is store credit, the customer's account, or paid from a
 * chosen cash, bank or MoMo account. Approving books an ordinary return on the receipt (ReturnService), so stock, tax,
 * points and reports treat it like any other; it is not limited to the return period, because a manager decides.
 */
final class OfficeReturns
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly DocumentNumbers $numbers,
        private readonly ReturnService $returns, private readonly Settings $settings) {}

    /**
     * @param  array{warehouse_id?: ?string, lines: list<array{line_id: string, qty: string|float, damaged?: bool}>, reason_code: string, reason?: ?string,
     *     refund_method: string, account_id?: ?string, reference?: ?string}  $d
     */
    public function submit(Actor $actor, Sale $sale, array $d): OfficeReturn
    {
        $warehouse = Warehouse::find(($d['warehouse_id'] ?? null) ?: $sale->warehouse_id) ?? throw new DomainException('Choose the store the goods go back to.');
        $this->require($actor, 'returns.office', $warehouse);
        if ($sale->kind !== 'sale') {
            throw new DomainException('Choose the original sale, not a return.');
        }
        if (! array_key_exists((string) ($d['reason_code'] ?? ''), ReturnService::REASONS)) {
            throw new DomainException('Choose the reason for the return.');
        }
        $method = (string) ($d['refund_method'] ?? '');
        if (! array_key_exists($method, OfficeReturn::REFUNDS)) {
            throw new DomainException('Choose how the customer is refunded.');
        }
        $account = null;
        if ($method === 'money') {
            $account = Account::find($d['account_id'] ?? null);
            if (! $account || $account->type !== 'asset' || $account->is_header || $account->control || ! $account->is_active) {
                throw new DomainException('Choose the cash, bank or mobile money account it is paid from.');
            }
            if (trim((string) ($d['reference'] ?? '')) === '') {
                throw new DomainException('Enter the reference of the refund.');
            }
        }
        if ($method === 'account' && ! $sale->customer_id) {
            throw new DomainException('This sale has no customer, so there is no account to refund to.');
        }
        $rows = array_values(array_filter($d['lines'] ?? [], fn ($l) => (float) ($l['qty'] ?? 0) > 0));
        $lines = $this->returns->receiptLines($sale->load('lines', 'payments'), array_map(fn ($l) => ['original_line_id' => $l['line_id'], 'qty' => (string) $l['qty']], $rows));
        $total = $this->returns->totalOf($sale, $lines);

        return $this->recorder->transaction(function () use ($actor, $sale, $d, $warehouse, $method, $account, $rows, $total) {
            $doc = (new OfficeReturn)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'office_return'), 'status' => 'submitted',
                'original_sale_id' => $sale->id, 'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'reason_code' => $d['reason_code'],
                'lines' => array_map(fn ($l) => ['line_id' => $l['line_id'], 'qty' => (string) $l['qty'], 'damaged' => (bool) ($l['damaged'] ?? false)], $rows),
                'reason' => ($r = trim((string) ($d['reason'] ?? ''))) !== '' ? mb_substr($r, 0, 255) : null, 'refund_method' => $method, 'account_id' => $account?->id,
                'reference' => mb_substr(trim((string) ($d['reference'] ?? '')), 0, 80) ?: null, 'total_minor' => $total, 'currency_code' => $sale->currency_code,
                'created_by' => $actor->id, 'created_by_name' => $actor->name]);

            return $this->recorder->save($actor, $doc, 'office_return.submitted', "{$sale->receipt_no}: {$total}", $warehouse->branch_id);
        });
    }

    /** May this person approve it? A reason why not, or null. */
    public function whyNotApprove(Actor $actor, OfficeReturn $doc): ?string
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'returns.office.approve', $doc->warehouse)) {
            return 'A manager must approve this.';
        }

        return $doc->created_by === $actor->id ? 'Someone else must approve it: nobody approves their own.' : null;
    }

    public function approve(Actor $actor, OfficeReturn $doc): OfficeReturn
    {
        if ($doc->status !== 'submitted') {
            throw new DomainException("{$doc->number} is already ".strtolower(OfficeReturn::STATUSES[$doc->status]).'.');
        }
        if ($why = $this->whyNotApprove($actor, $doc)) {
            throw new DomainException($why, 'own_document');
        }

        return $this->recorder->transaction(function () use ($actor, $doc) {
            $doc = OfficeReturn::lockForUpdate()->findOrFail($doc->id);
            $sale = $this->returns->fromOffice($actor, $doc, $this->numbers->next($doc->tenant_id, 'office_return_receipt'));
            $doc->forceFill(['status' => 'posted', 'sale_id' => $sale->id, 'posted_by' => $actor->id, 'posted_by_name' => $actor->name, 'posted_at' => now(), 'total_minor' => -$sale->total_minor]);

            return $this->recorder->save($actor, $doc, 'office_return.posted', $sale->receipt_no, $doc->branch_id);
        });
    }

    public function cancel(Actor $actor, OfficeReturn $doc, string $reason): OfficeReturn
    {
        $this->require($actor, 'returns.office', $doc->warehouse);
        if ($doc->status !== 'submitted' || trim($reason) === '') {
            throw new DomainException(trim($reason) === '' ? 'Give the reason.' : 'Only a return waiting for approval can be cancelled.');
        }
        $doc->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr(trim($reason), 0, 255)]);

        return $this->recorder->save($actor, $doc, 'office_return.cancelled', $reason, $doc->branch_id);
    }

    private function require(Actor $actor, string $permission, Warehouse $warehouse): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $warehouse)) {
            throw new AccessDenied;
        }
    }
}
