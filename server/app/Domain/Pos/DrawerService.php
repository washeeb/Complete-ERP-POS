<?php

namespace App\Domain\Pos;

use App\Domain\Finance\GeneralLedger;
use App\Domain\Messaging\Notifier;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Device;
use App\Models\ExpenseClaim;
use App\Models\Shift;
use App\Models\Till;
use App\Models\TillDrawerEvent;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The till drawer besides sales (owner decisions 2026-10-20):
 *  - pay-out: an expense paid from the drawer (category, who was paid, what for, optional receipt photo). The cashier
 *    records it; above the pay-out limit a supervisor approves by PIN. It is also an expense claim, approved and paid
 *    from the till's cash, so Expenses, budgets and the books are complete;
 *  - pay-in: cash added to the drawer (e.g. more change from the safe), with the reason;
 *  - cash drop: a supervisor takes cash from the drawer to the safe, both PINs;
 *  - no sale: the drawer opened without a sale, with the reason (counted per cashier for loss prevention);
 *  - cash limit: the till warns when the cash in the drawer goes above the set amount; supervisors are told once.
 * Each changes the cash expected at the close (pay-outs and drops out, pay-ins in). Offline events are queued and
 * replayed by id; an approval given offline was checked on the till against the supervisor's offline sign-in.
 */
final class DrawerService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly SupervisorApproval $approvals,
        private readonly Settings $settings,
        private readonly DocumentNumbers $numbers,
        private readonly GeneralLedger $gl,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Expense accounts a till can pay out to: active operating expenses that are not kept by the system
     * (no cost of sales, salaries, depreciation, cash over/short…). Shown on the till as categories.
     *
     * @return array<string, string> id => name
     */
    public static function payoutAccounts(): array
    {
        return Account::where('type', 'expense')->where('is_header', false)->whereNull('control')->where('is_active', true)
            ->whereNull('system_key')->where('code', '>=', '6000')->where('code', '<', '7000')->orderBy('code')->pluck('name', 'id')->all();
    }

    /**
     * @param  array{id: string, kind: string, amount_minor?: int, account_id?: ?string, payee?: ?string, reason?: ?string, reference?: ?string,
     *     photo?: ?string, approval?: ?array{username: string, pin: string}, approved_by?: ?string, occurred_at?: ?string, shift_id?: ?string}  $p
     */
    public function record(Device $device, User $user, array $p, bool $offline = false): TillDrawerEvent
    {
        if (! Str::isUuid($p['id'] ?? '')) {
            throw new DomainException('Missing id.', 'malformed');
        }
        if ($existing = TillDrawerEvent::find($p['id'])) {
            return $existing; // replay
        }
        $kind = (string) ($p['kind'] ?? '');
        if (! array_key_exists($kind, TillDrawerEvent::KINDS)) {
            throw new DomainException('Choose what you are doing with the drawer.');
        }
        $till = Till::find($device->till_id) ?? throw new DomainException('This device is not assigned to a till.', 'no_till');
        $shift = ($p['shift_id'] ?? null) ? Shift::where('device_id', $device->id)->find($p['shift_id']) : Shift::where('device_id', $device->id)->where('status', 'open')->first();
        if (! $shift || (! $offline && $shift->status !== 'open')) {
            throw new DomainException('Open a shift on this till first.', 'no_shift');
        }
        $amount = (int) ($p['amount_minor'] ?? 0);
        $reason = trim((string) ($p['reason'] ?? ''));
        if (in_array($kind, ['payout', 'payin', 'drop'], true) && $amount <= 0) {
            throw new DomainException('Enter the amount.');
        }
        if (in_array($kind, ['payout', 'payin', 'no_sale'], true) && $reason === '') {
            throw new DomainException($kind === 'payout' ? 'Say what the money was for.' : 'Give the reason.');
        }
        $account = null;
        if ($kind === 'payout') {
            $account = Account::find($p['account_id'] ?? null);
            if (! $account || ! array_key_exists($account->id, self::payoutAccounts())) {
                throw new DomainException('Choose what the money was spent on.');
            }
        }

        // Approval: a cash drop always (someone else takes the cash), a pay-out above the limit.
        $limit = (int) $this->settings->get('pos.payout_approval_minor', $device->tenant_id);
        $needs = $kind === 'drop' || ($kind === 'payout' && $amount > $limit);
        $approver = null;
        if ($needs) {
            if ($offline) {
                $approver = ($p['approved_by'] ?? null) ? User::find($p['approved_by']) : null; // checked on the till against their offline sign-in
            } elseif (! empty($p['approval'])) {
                $approver = $this->approvals->verify($device, $p['approval'], 'pos.drawer', $till, null, 0, $user->id);
            } else {
                throw new DomainException($kind === 'drop' ? 'A supervisor takes the cash: their username and PIN.'
                    : 'Above '.number_format($limit / 100, 2).' a supervisor approves the pay-out with their PIN.', 'drawer_approval_needed');
            }
        }

        $occurredAt = ($p['occurred_at'] ?? null) ? Carbon::parse($p['occurred_at']) : now();
        $actor = Actor::user($user, 'pos', $device->id);

        return $this->recorder->transaction(function () use ($device, $user, $p, $kind, $amount, $reason, $account, $approver, $offline, $occurredAt, $shift, $actor, $till) {
            $event = new TillDrawerEvent;
            $event->id = $p['id'];
            $event->forceFill([
                'tenant_id' => $device->tenant_id, 'branch_id' => $shift->branch_id, 'till_id' => $till->id, 'device_id' => $device->id, 'shift_id' => $shift->id,
                'kind' => $kind, 'amount_minor' => $amount, 'account_id' => $account?->id,
                'payee' => ($p['payee'] ?? null) ? mb_substr(trim($p['payee']), 0, 255) : null, 'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'reference' => ($p['reference'] ?? null) ? mb_substr(trim($p['reference']), 0, 80) : null, 'receipt_path' => $this->photo($p['photo'] ?? null, $p['id']),
                'user_id' => $user->id, 'user_name' => $user->name, 'approved_by' => $approver?->id, 'approved_by_name' => $approver?->name,
                'offline' => $offline, 'occurred_at' => $occurredAt,
            ]);
            if ($kind === 'payout') {
                $event->expense_claim_id = $this->expenseClaim($actor, $event, $account, $approver)->id;
            }
            if ($kind === 'payin' || $kind === 'drop') {
                // Drawer ↔ safe (office cash), with or without vaults: the vault chain then carries only what stayed in the tray.
                [$from, $to] = $kind === 'drop' ? ['cash_till', 'cash_office'] : ['cash_office', 'cash_till'];
                $m = (new CashMovement)->forceFill(['tenant_id' => $device->tenant_id, 'branch_id' => $shift->branch_id, 'kind' => "till_{$kind}",
                    'from_account' => $from, 'to_account' => $to, 'amount_minor' => $amount, 'source_type' => 'till_drawer_events', 'source_id' => $event->id,
                    'memo' => mb_substr(($kind === 'drop' ? 'Cash drop from ' : 'Cash paid into ')."{$till->code} by {$user->name}".($reason !== '' ? ": {$reason}" : ''), 0, 255),
                    'occurred_at' => $occurredAt]);
                $this->recorder->save($actor, $m, 'cash.moved', branchId: $shift->branch_id);
                $event->cash_movement_id = $m->id;
            }
            $this->recorder->save($actor, $event, "drawer.{$kind}", $reason !== '' ? $reason : null, $shift->branch_id);
            if ($kind === 'limit_alert') {
                $this->notifier->toPermission($device->tenant_id, 'pos.drawer', $shift->branch_id, "Cash drop needed at {$till->code}",
                    "{$user->name}'s drawer holds about ".number_format($amount / 100, 2).', above the cash limit.', exceptUserIds: [$user->id]);
            }

            return $event;
        });
    }

    /**
     * Drawer movements of a shift that change the cash expected at the close: pay-ins add, pay-outs and drops take away.
     */
    public static function cashEffect(Shift $shift): int
    {
        return (int) TillDrawerEvent::where('shift_id', $shift->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'payin' THEN amount_minor WHEN kind IN ('payout', 'drop') THEN -amount_minor ELSE 0 END), 0) AS c")->value('c');
    }

    /** The pay-out as an expense claim, approved and paid from the till's cash (it is what posts the expense). */
    private function expenseClaim(Actor $actor, TillDrawerEvent $e, Account $account, ?User $approver): ExpenseClaim
    {
        $claim = (new ExpenseClaim)->forceFill([
            'tenant_id' => $e->tenant_id, 'number' => $this->numbers->next($e->tenant_id, 'expense_claim'), 'branch_id' => $e->branch_id,
            'claimant_id' => $e->user_id, 'claimant_name' => $e->user_name, 'spent_on' => $e->occurred_at->toDateString(), 'account_id' => $account->id,
            'description' => mb_substr("Till pay-out: {$e->reason}", 0, 255), 'payee' => $e->payee, 'amount_minor' => $e->amount_minor, 'receipt_path' => $e->receipt_path,
            'status' => 'paid', 'approved_by' => $approver?->id, 'approved_by_name' => $approver?->name ?? 'Within the till pay-out limit', 'approved_at' => $e->occurred_at,
            'paid_from_account_id' => $this->gl->account($e->tenant_id, 'cash_till')->id, 'paid_on' => $e->occurred_at->toDateString(), 'paid_by_name' => $e->user_name,
        ]);

        return $this->recorder->save($actor, $claim, 'expense_claim.paid', 'Paid from the till drawer', $e->branch_id);
    }

    /** A photo of the receipt sent by the till as a data URL (JPEG or PNG, up to about 3 MB), kept privately with expense receipts. */
    private function photo(?string $dataUrl, string $id): ?string
    {
        if (! $dataUrl) {
            return null;
        }
        if (! preg_match('#^data:image/(jpeg|png);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m) || strlen($m[2]) > 4_000_000) {
            throw new DomainException('The receipt photo could not be read; take it again or leave it out.');
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || @getimagesizefromstring($bytes) === false) {
            throw new DomainException('The receipt photo could not be read; take it again or leave it out.');
        }
        $path = 'expense-receipts/till-'.$id.'.'.($m[1] === 'png' ? 'png' : 'jpg');
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }
}
