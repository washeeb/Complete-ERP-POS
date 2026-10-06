<?php

namespace App\Domain\Expenses;

use App\Domain\Banking\BankingService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Branch;
use App\Models\ExpenseClaim;
use App\Security\Authorizer;
use Illuminate\Support\Carbon;

/**
 * Staff spending (owner decision 2026-10-13): a claim with its receipt photo is approved by someone else within
 * their limit (branch manager up to GHS 500 by default, finance above), then paid from the branch's petty cash
 * or reimbursed from a bank / MoMo account. Payment is what posts to the ledger.
 */
final class ExpenseService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
    ) {}

    /** @param array{spent_on: string, account_id: string, description: string, payee?: ?string, amount_minor: int, receipt_path?: ?string} $data */
    public function submit(Actor $actor, Branch $branch, array $data): ExpenseClaim
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'expenses.claim', $branch)) {
            throw new AccessDenied;
        }
        $account = Account::find($data['account_id'] ?? null) ?? throw new DomainException('Choose what the money was spent on.');
        if ($account->type !== 'expense' || $account->is_header || $account->control || ! $account->is_active) {
            throw new DomainException("{$account->label()} is not an expense account.");
        }
        $amount = (int) ($data['amount_minor'] ?? 0);
        if ($amount <= 0) {
            throw new DomainException('Enter the amount spent.');
        }
        if (trim((string) ($data['description'] ?? '')) === '') {
            throw new DomainException('Say what it was for.');
        }
        $day = Carbon::parse($data['spent_on'] ?? now()->toDateString());
        if ($day->isAfter(now()->endOfDay())) {
            throw new DomainException('The date cannot be in the future.');
        }

        return $this->recorder->transaction(function () use ($actor, $branch, $data, $account, $amount, $day) {
            $claim = (new ExpenseClaim)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'expense_claim'),
                'branch_id' => $branch->id, 'claimant_id' => $actor->id, 'claimant_name' => $actor->name, 'spent_on' => $day->toDateString(),
                'account_id' => $account->id, 'description' => mb_substr(trim($data['description']), 0, 255), 'payee' => ($data['payee'] ?? null) ? mb_substr($data['payee'], 0, 255) : null,
                'amount_minor' => $amount, 'receipt_path' => $data['receipt_path'] ?? null, 'status' => 'submitted']);

            return $this->recorder->save($actor, $claim, 'expense_claim.submitted', branchId: $branch->id);
        });
    }

    public function approve(Actor $actor, ExpenseClaim $claim): ExpenseClaim
    {
        $branch = Branch::findOrFail($claim->branch_id);
        if (! $actor->user || ! $this->authz->allows($actor->user, 'expenses.approve', $branch)) {
            throw new AccessDenied;
        }
        if ($claim->claimant_id === $actor->id) {
            throw new DomainException('Someone else must approve your own claim.', 'own_document');
        }
        if (! $this->authz->allowsAmount($actor->user, 'expenses.approve', $branch, 'expense_approve_minor', $claim->amount_minor)) {
            throw new DomainException('This is above your approval limit; finance must approve it.', 'over_limit');
        }

        return $this->recorder->transaction(function () use ($actor, $claim) {
            $claim = ExpenseClaim::lockForUpdate()->findOrFail($claim->id);
            if ($claim->status !== 'submitted') {
                throw new DomainException('This claim is not waiting for approval.');
            }
            $claim->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_by_name' => $actor->name, 'approved_at' => now()]);

            return $this->recorder->save($actor, $claim, 'expense_claim.approved', branchId: $claim->branch_id);
        });
    }

    public function reject(Actor $actor, ExpenseClaim $claim, string $reason): ExpenseClaim
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'expenses.approve', Branch::findOrFail($claim->branch_id))) {
            throw new AccessDenied;
        }
        if (trim($reason) === '') {
            throw new DomainException('Give a reason.');
        }

        return $this->recorder->transaction(function () use ($actor, $claim, $reason) {
            $claim = ExpenseClaim::lockForUpdate()->findOrFail($claim->id);
            if (! in_array($claim->status, ['submitted', 'approved'], true)) {
                throw new DomainException('This claim can no longer be rejected.');
            }
            $claim->forceFill(['status' => 'rejected', 'rejection_reason' => mb_substr(trim($reason), 0, 255), 'approved_by_name' => $actor->name]);

            return $this->recorder->save($actor, $claim, 'expense_claim.rejected', $reason, $claim->branch_id);
        });
    }

    /** Hand over the money: from petty cash (default) or another cash, bank or MoMo account. */
    public function pay(Actor $actor, ExpenseClaim $claim, Account $from, ?string $paidOn = null): ExpenseClaim
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'expenses.pay', Branch::findOrFail($claim->branch_id))) {
            throw new AccessDenied;
        }
        if (! BankingService::isMoneyAccount($from)) {
            throw new DomainException("{$from->label()} is not a cash, bank or mobile money account.");
        }
        $day = Carbon::parse($paidOn ?? now()->toDateString());
        if ($day->isAfter(now()->endOfDay())) {
            throw new DomainException('The date cannot be in the future.');
        }

        return $this->recorder->transaction(function () use ($actor, $claim, $from, $day) {
            $claim = ExpenseClaim::lockForUpdate()->findOrFail($claim->id);
            if ($claim->status !== 'approved') {
                throw new DomainException('Only approved claims are paid.');
            }
            $claim->forceFill(['status' => 'paid', 'paid_from_account_id' => $from->id, 'paid_on' => $day->toDateString(), 'paid_by_name' => $actor->name]);

            return $this->recorder->save($actor, $claim, 'expense_claim.paid', branchId: $claim->branch_id);
        });
    }
}
