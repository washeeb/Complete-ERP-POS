<?php

namespace App\Domain\Pos;

use App\Domain\Banking\BankingService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CashHandover;
use App\Models\CashMovement;
use App\Models\CashVariance;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultSession;
use App\Security\Authorizer;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Cash control with vaults (owner decisions 2026-10-12). Each till has one vault (cash tray with its own float) per shift.
 *
 *   Cashier issues the float and assigns the teller → teller opens the till on that vault, sells, counts blind at close
 *   → Cashier receives the vault and counts blind → hands the day to the Income Controller → who counts blind again,
 *   decides each difference (cash over/short, or charged to the staff member) and records the bank deposit.
 *
 * Counts are blind at every step: nobody sees the figure they are checking against before entering their own. A count
 * that disagrees with the previous person's gets one recount, still blind. Whoever checks the money never took it
 * (the cashier is not the teller; the income controller is not the cashier; nobody decides their own difference).
 *
 * Every move of cash between the books' cash accounts is a CashMovement (append-only), posted by the auto-poster:
 * float office → till; till → cashier; cashier → office; differences wait in "Cash differences awaiting approval"
 * until decided.
 */
final class CashControl
{
    /** Notes and coins by currency: key => [label, value in minor units]. Keys keep a 2-cedi note apart from the coin. */
    public const DENOMINATIONS = [
        'GHS' => ['n200' => ['GH₵200 note', 20000], 'n100' => ['GH₵100 note', 10000], 'n50' => ['GH₵50 note', 5000], 'n20' => ['GH₵20 note', 2000],
            'n10' => ['GH₵10 note', 1000], 'n5' => ['GH₵5 note', 500], 'n2' => ['GH₵2 note', 200], 'n1' => ['GH₵1 note', 100],
            'c2' => ['GH₵2 coin', 200], 'c1' => ['GH₵1 coin', 100], 'c50p' => ['50p coin', 50], 'c20p' => ['20p coin', 20], 'c10p' => ['10p coin', 10],
            'c5p' => ['5p coin', 5], 'c1p' => ['1p coin', 1]],
    ];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly Settings $settings,
        private readonly DocumentNumbers $numbers,
    ) {}

    public function enabled(string $tenantId): bool
    {
        return (bool) $this->settings->get('cash.vaults', $tenantId);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function denominations(string $currency): array
    {
        return self::DENOMINATIONS[$currency] ?? [];
    }

    /**
     * A count given note by note ({key: count}) or as one amount when the currency has no list. Returns [total, denoms].
     *
     * @return array{0: int, 1: ?array<string, int>}
     */
    public static function total(string $currency, ?array $denoms, ?int $amountMinor): array
    {
        $list = self::denominations($currency);
        if ($denoms !== null && $list) {
            $clean = [];
            $sum = 0;
            foreach ($denoms as $key => $count) {
                if (! isset($list[$key])) {
                    throw new DomainException("Unknown note or coin: {$key}.");
                }
                if (! is_numeric($count) || (int) $count < 0 || (int) $count != $count) {
                    throw new DomainException("Enter a whole number of {$list[$key][0]}s.");
                }
                if ((int) $count > 0) {
                    $clean[$key] = (int) $count;
                    $sum += (int) $count * $list[$key][1];
                }
            }

            return [$sum, $clean];
        }
        if ($amountMinor === null || $amountMinor < 0) {
            throw new DomainException('Enter the amount counted.');
        }

        return [$amountMinor, null];
    }

    /** One vault per active till and active shift; new tills and shifts get theirs here. */
    public function ensureVaults(string $tenantId): void
    {
        if (config('erp.node.role') === 'branch') {
            return; // vaults are set up at head office and reach branch servers by sync
        }
        $actor = Actor::system($tenantId, 'Cash control', 'system');
        $templates = ShiftTemplate::where('is_active', true)->orderBy('sort')->get();
        foreach (Till::where('is_active', true)->get() as $till) {
            foreach ($templates as $tpl) {
                if (Vault::where('till_id', $till->id)->where('shift_template_id', $tpl->id)->exists()) {
                    continue;
                }
                $v = new Vault(['name' => "{$till->code} · {$tpl->name}", 'standard_float_minor' => 0, 'is_active' => true]);
                $v->forceFill(['tenant_id' => $tenantId, 'branch_id' => $till->branch_id, 'till_id' => $till->id, 'shift_template_id' => $tpl->id]);
                $this->recorder->save($actor, $v, 'vault.created', branchId: $till->branch_id);
            }
        }
    }

    public function saveTemplate(Actor $actor, ShiftTemplate $tpl, array $data): ShiftTemplate
    {
        $this->require($actor, 'cash.setup');
        $name = trim((string) ($data['name'] ?? ''));
        $from = (string) ($data['starts_at'] ?? '');
        $to = (string) ($data['ends_at'] ?? '');
        if ($name === '' || ! preg_match('/^\d{2}:\d{2}/', $from) || ! preg_match('/^\d{2}:\d{2}/', $to)) {
            throw new DomainException('Give the shift a name and its start and end times.');
        }
        if (ShiftTemplate::where('name', $name)->where('id', '<>', $tpl->id ?? '')->exists()) {
            throw new DomainException('A shift with that name exists.');
        }
        $tpl->fill(['name' => mb_substr($name, 0, 40), 'starts_at' => substr($from, 0, 5), 'ends_at' => substr($to, 0, 5),
            'sort' => (int) ($data['sort'] ?? $tpl->sort ?? 0), 'is_active' => (bool) ($data['is_active'] ?? true)]);
        $tpl->tenant_id ??= $actor->tenantId;
        $saved = $this->recorder->save($actor, $tpl, $tpl->exists ? 'shift_template.updated' : 'shift_template.created');
        $this->ensureVaults($actor->tenantId);

        return $saved;
    }

    public function saveVault(Actor $actor, Vault $vault, int $standardFloatMinor, bool $active): Vault
    {
        $this->require($actor, 'cash.setup', $vault->till);
        if ($standardFloatMinor < 0) {
            throw new DomainException('The standard float cannot be negative.');
        }
        $vault->fill(['standard_float_minor' => $standardFloatMinor, 'is_active' => $active]);

        return $this->recorder->save($actor, $vault, 'vault.updated', branchId: $vault->branch_id);
    }

    /** The business day in the company's time zone. */
    public function today(string $tenantId): string
    {
        return now(Tenant::find($tenantId)?->timezone ?: 'UTC')->toDateString();
    }

    // ------------------------------------------------------------------------------------------------ cashier: issue

    public function issueFloat(Actor $actor, Vault $vault, User $teller, int $floatMinor, ?string $note = null): VaultSession
    {
        $till = $vault->till;
        $this->require($actor, 'cash.vault.issue', $till);
        if (! $vault->is_active) {
            throw new DomainException('That vault is switched off.');
        }
        if ($floatMinor < 0) {
            throw new DomainException('The float cannot be negative.');
        }
        if ($teller->id === $actor->id) {
            throw new DomainException('You cannot issue a vault to yourself: the person who checks the cash does not sell.');
        }
        if (! $teller->is_active || ! $this->authz->allows($teller, 'pos.access', $till)) {
            throw new DomainException("{$teller->name} is not set up to sell at {$till->code}.");
        }
        $day = $this->today($actor->tenantId);
        if (VaultSession::where('vault_id', $vault->id)->where('business_date', $day)->where('status', '<>', 'cancelled')->exists()) {
            throw new DomainException("{$vault->name} already has a float today.");
        }
        if (VaultSession::where('teller_id', $teller->id)->where('business_date', $day)->whereIn('status', ['issued', 'open'])->exists()) {
            throw new DomainException("{$teller->name} already holds a vault that is not counted yet.");
        }

        return $this->recorder->transaction(function () use ($actor, $vault, $till, $teller, $floatMinor, $day, $note) {
            $s = (new VaultSession)->forceFill([
                'tenant_id' => $actor->tenantId, 'branch_id' => $till->branch_id, 'till_id' => $till->id, 'vault_id' => $vault->id,
                'business_date' => $day, 'status' => 'issued', 'teller_id' => $teller->id, 'teller_name' => $teller->name,
                'float_minor' => $floatMinor, 'issued_by' => $actor->id, 'issued_by_name' => $actor->name, 'issued_at' => now(),
                'note' => $note ? mb_substr($note, 0, 255) : null,
            ]);
            $this->recorder->save($actor, $s, 'vault.float_issued', branchId: $till->branch_id);
            $this->move($actor, $s->branch_id, 'float_issue', 'cash_office', 'cash_till', $floatMinor, $s, "Float for {$vault->name} ({$teller->name})");

            return $s;
        });
    }

    /** Before the teller opens the till: take the float back. */
    public function cancelIssue(Actor $actor, VaultSession $s, string $reason): VaultSession
    {
        $this->require($actor, 'cash.vault.issue', $s->till);
        if ($s->status !== 'issued') {
            throw new DomainException('Only a vault whose shift has not started can be cancelled.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Give a reason.');
        }

        return $this->recorder->transaction(function () use ($actor, $s, $reason) {
            $s->forceFill(['status' => 'cancelled', 'note' => mb_substr($reason, 0, 255)]);
            $this->recorder->save($actor, $s, 'vault.float_cancelled', $reason, $s->branch_id);
            $this->move($actor, $s->branch_id, 'float_return', 'cash_till', 'cash_office', $s->float_minor, $s, "Float returned: {$s->vault->name}");

            return $s;
        });
    }

    // ------------------------------------------------------------------------------------------------ teller (called by ShiftService)

    /** Vaults waiting for this teller at this till today (or still open), newest first. */
    public function sessionsFor(User $teller, Till $till): Collection
    {
        return VaultSession::with('vault')->where('teller_id', $teller->id)->where('till_id', $till->id)
            ->whereIn('status', ['issued', 'open'])->where('business_date', '>=', now()->subDay()->toDateString())
            ->orderByDesc('issued_at')->get();
    }

    /** The vault a shift is opened on. Offline replays are linked when they match and never refused. */
    public function attachShift(VaultSession $s, Shift $shift, User $teller, bool $offline): void
    {
        if (! $offline) {
            if ($s->teller_id !== $teller->id) {
                throw new AccessDenied("That vault was issued to {$s->teller_name}.");
            }
            if ($s->till_id !== $shift->till_id) {
                throw new DomainException('That vault belongs to another till.');
            }
            if ($s->status !== 'issued') {
                throw new DomainException('That vault is already in use or counted.', 'vault_used');
            }
        }
        $s->forceFill(['status' => 'open', 'shift_id' => $shift->id]);
        $this->recorder->save(Actor::user($teller, 'pos', $shift->device_id), $s, 'vault.opened', branchId: $s->branch_id);
    }

    /** The teller's blind count at close. */
    public function tellerCounted(Shift $shift, User $teller, int $countMinor, ?array $denoms, int $expectedMinor): void
    {
        $s = VaultSession::where('shift_id', $shift->id)->first();
        if (! $s || $s->status !== 'open') {
            return;
        }
        $s->forceFill(['status' => 'counted', 'teller_count_minor' => $countMinor, 'teller_denoms' => $denoms, 'expected_minor' => $expectedMinor, 'counted_at' => now()]);
        $this->recorder->save(Actor::user($teller, 'pos', $shift->device_id), $s, 'vault.counted_by_teller', branchId: $s->branch_id);
    }

    // ------------------------------------------------------------------------------------------------ cashier: receive and hand over

    /**
     * The cashier's blind count of a vault the teller has closed. A count that differs from the teller's is not taken the
     * first time: the cashier is asked to recount (without seeing either figure). The second count stands.
     *
     * @return array{status: string, session: VaultSession}
     */
    public function receiveVault(Actor $actor, VaultSession $s, ?array $denoms, ?int $amountMinor = null): array
    {
        $this->require($actor, 'cash.vault.receive', $s->till);
        if ($s->status !== 'counted') {
            throw new DomainException($s->status === 'open' ? 'The teller has not closed this till yet.' : 'This vault is not waiting to be received.');
        }
        if ($s->teller_id === $actor->id) {
            throw new DomainException('Someone else must receive your own vault.');
        }
        $currency = Tenant::find($s->tenant_id)->currency_code;
        [$count, $clean] = self::total($currency, $denoms, $amountMinor);
        if ($count !== $s->teller_count_minor && $s->cashier_recounts < 1) {
            $s->forceFill(['cashier_recounts' => $s->cashier_recounts + 1]);
            $this->recorder->save($actor, $s, 'vault.recount_requested', branchId: $s->branch_id);

            return ['status' => 'recount', 'session' => $s];
        }

        return $this->recorder->transaction(function () use ($actor, $s, $count, $clean) {
            $s->forceFill(['status' => 'received', 'cashier_id' => $actor->id, 'cashier_name' => $actor->name, 'cashier_count_minor' => $count,
                'cashier_denoms' => $clean, 'received_at' => now()]);
            $this->recorder->save($actor, $s, 'vault.received', branchId: $s->branch_id);
            $expected = (int) $s->expected_minor;
            $name = $s->vault->name;
            $this->move($actor, $s->branch_id, 'vault_receive', 'cash_till', 'cash_cashier', min($count, $expected), $s, "Vault {$name} received from {$s->teller_name}");
            if ($count < $expected) {
                $this->move($actor, $s->branch_id, 'vault_difference', 'cash_till', 'cash_differences', $expected - $count, $s, "Vault {$name}: short");
            } elseif ($count > $expected) {
                $this->move($actor, $s->branch_id, 'vault_difference', 'cash_differences', 'cash_cashier', $count - $expected, $s, "Vault {$name}: over");
            }
            if ($count !== $expected) {
                $this->variance($actor, $s->branch_id, 'teller', $s->teller_id, $s->teller_name, $count - $expected, vaultSession: $s);
            }

            return ['status' => 'received', 'session' => $s];
        });
    }

    /** The cashier hands the vaults they received (one branch, one day) to the income controller. */
    public function handOver(Actor $actor, Branch $branch, array $sessionIds, ?string $note = null): CashHandover
    {
        $this->require($actor, 'cash.handover', $branch);
        $sessions = VaultSession::where('branch_id', $branch->id)->whereIn('id', $sessionIds)->get();
        if ($sessions->isEmpty() || $sessions->count() !== count(array_unique($sessionIds))) {
            throw new DomainException('Choose the vaults you are handing over.');
        }
        foreach ($sessions as $s) {
            if ($s->status !== 'received' || $s->cashier_id !== $actor->id) {
                throw new DomainException("{$s->vault->name} is not one you have received and not yet handed over.");
            }
        }

        return $this->recorder->transaction(function () use ($actor, $branch, $sessions, $note) {
            $h = (new CashHandover)->forceFill([
                'tenant_id' => $actor->tenantId, 'branch_id' => $branch->id, 'number' => $this->numbers->next($actor->tenantId, 'cash_handover'),
                'business_date' => $sessions->min('business_date'), 'status' => 'handed_over', 'cashier_id' => $actor->id, 'cashier_name' => $actor->name,
                'declared_minor' => (int) $sessions->sum('cashier_count_minor'), 'handed_at' => now(), 'note' => $note ? mb_substr($note, 0, 255) : null,
            ]);
            $this->recorder->save($actor, $h, 'cash_handover.made', branchId: $branch->id);
            foreach ($sessions as $s) {
                $s->forceFill(['status' => 'handed_over', 'handover_id' => $h->id]);
                $this->recorder->save($actor, $s, 'vault.handed_over', branchId: $branch->id);
            }

            return $h;
        });
    }

    // ------------------------------------------------------------------------------------------------ income controller

    /** @return array{status: string, handover: CashHandover} */
    public function receiveHandover(Actor $actor, CashHandover $h, ?array $denoms, ?int $amountMinor = null, int $recounts = 0): array
    {
        $this->require($actor, 'cash.control', $h->branch);
        if ($h->status !== 'handed_over') {
            throw new DomainException('This handover has been received already.');
        }
        if ($h->cashier_id === $actor->id) {
            throw new DomainException('Someone else must receive your own handover.');
        }
        [$count, $clean] = self::total(Tenant::find($h->tenant_id)->currency_code, $denoms, $amountMinor);
        if ($count !== $h->declared_minor && $recounts < 1) {
            return ['status' => 'recount', 'handover' => $h]; // the page asks for a second blind count
        }

        return $this->recorder->transaction(function () use ($actor, $h, $count, $clean) {
            $h->forceFill(['status' => 'received', 'controller_id' => $actor->id, 'controller_name' => $actor->name,
                'controller_count_minor' => $count, 'controller_denoms' => $clean, 'received_at' => now()]);
            $this->recorder->save($actor, $h, 'cash_handover.received', branchId: $h->branch_id);
            $declared = $h->declared_minor;
            $this->move($actor, $h->branch_id, 'handover_receive', 'cash_cashier', 'cash_office', min($count, $declared), $h, "Handover {$h->number} from {$h->cashier_name}");
            if ($count < $declared) {
                $this->move($actor, $h->branch_id, 'handover_difference', 'cash_cashier', 'cash_differences', $declared - $count, $h, "Handover {$h->number}: short");
            } elseif ($count > $declared) {
                $this->move($actor, $h->branch_id, 'handover_difference', 'cash_differences', 'cash_office', $count - $declared, $h, "Handover {$h->number}: over");
            }
            if ($count !== $declared) {
                $this->variance($actor, $h->branch_id, 'cashier', $h->cashier_id, $h->cashier_name, $count - $declared, handover: $h);
            }

            return ['status' => 'received', 'handover' => $h];
        });
    }

    /**
     * Decide a difference. "over_short": the business bears it (or keeps it). "charge_staff" (shortages only): recovered
     * from the person's pay as a staff loan over $months months; needs their employee record (HR & payroll).
     */
    public function decide(Actor $actor, CashVariance $v, string $treatment, string $reason, int $months = 1): CashVariance
    {
        $this->require($actor, 'cash.variance.approve', $v->branch);
        if ($v->status !== 'open') {
            throw new DomainException('This difference has been decided already.');
        }
        if ($v->person_id === $actor->id) {
            throw new DomainException('Someone else must decide a difference on your own count.');
        }
        if (! array_key_exists($treatment, CashVariance::TREATMENTS)) {
            throw new DomainException('Choose how to treat the difference.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Give a reason.');
        }
        $amount = abs($v->amount_minor);

        return $this->recorder->transaction(function () use ($actor, $v, $treatment, $reason, $months, $amount) {
            if ($treatment === 'charge_staff') {
                if ($v->amount_minor > 0) {
                    throw new DomainException('Only a shortage can be charged to staff; an overage is kept as cash over.');
                }
                $employee = Employee::where('user_id', $v->person_id)->first()
                    ?? throw new DomainException("{$v->person_name} has no employee record, so it cannot be recovered from pay. Choose cash over/short, or add their employee record under HR first.");
                $months = max(1, min(12, $months));
                $differences = Account::where('system_key', 'cash_differences')->firstOrFail();
                $loan = (new EmployeeLoan)->forceFill([
                    'tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'employee_loan'), 'employee_id' => $employee->id,
                    'kind' => 'shortage', 'principal_minor' => $amount, 'monthly_minor' => (int) ceil($amount / $months),
                    'first_month' => now()->startOfMonth()->toDateString(), 'paid_from_account_id' => $differences->id,
                    'issued_on' => now()->toDateString(), 'note' => mb_substr("Cash shortage: {$reason}", 0, 255), 'issued_by_name' => $actor->name,
                ]);
                $this->recorder->save($actor, $loan, 'employee_loan.shortage_charged', $reason, $v->branch_id);
                $v->employee_loan_id = $loan->id; // its posting moves the shortage from "differences" to the staff member's account
            } elseif ($v->amount_minor < 0) {
                $this->move($actor, $v->branch_id, 'variance_over_short', 'cash_differences', 'cash_over_short', $amount, $v, "Cash short: {$v->person_name}");
            } else {
                $this->move($actor, $v->branch_id, 'variance_over_short', 'cash_over_short', 'cash_differences', $amount, $v, "Cash over: {$v->person_name}");
            }
            $v->forceFill(['status' => 'approved', 'treatment' => $treatment, 'reason' => mb_substr($reason, 0, 255),
                'decided_by_name' => $actor->name, 'decided_at' => now()]);

            return $this->recorder->save($actor, $v, 'cash_variance.decided', $reason, $v->branch_id);
        });
    }

    /** The bank deposit of a received handover's cash (office → bank), as a money transfer the bank reconciliation ticks off. */
    public function deposit(Actor $actor, CashHandover $h, Account $bank, int $amountMinor, string $date, string $slip): CashHandover
    {
        $this->require($actor, 'cash.deposit', $h->branch);
        if ($h->status !== 'received') {
            throw new DomainException('Receive the handover before recording its deposit.');
        }
        if ($h->deposit_transfer_id) {
            throw new DomainException('This handover\'s deposit is recorded already.');
        }
        if (trim($slip) === '') {
            throw new DomainException('Enter the deposit slip number.');
        }
        $office = Account::where('system_key', 'cash_office')->firstOrFail();

        return $this->recorder->transaction(function () use ($actor, $h, $bank, $amountMinor, $date, $slip, $office) {
            $t = app(BankingService::class)->transfer($actor, $office, $bank, $h->branch, $h->branch, $amountMinor, $date,
                "Deposit slip {$slip}", "Cash handover {$h->number}", permission: 'cash.deposit');
            $h->forceFill(['deposit_transfer_id' => $t->id]);

            return $this->recorder->save($actor, $h, 'cash_handover.deposited', branchId: $h->branch_id);
        });
    }

    // ------------------------------------------------------------------------------------------------ internals

    private function variance(Actor $actor, string $branchId, string $stage, string $personId, string $personName, int $amount, ?VaultSession $vaultSession = null, ?CashHandover $handover = null): void
    {
        $v = (new CashVariance)->forceFill([
            'tenant_id' => $actor->tenantId, 'branch_id' => $branchId, 'stage' => $stage, 'vault_session_id' => $vaultSession?->id,
            'handover_id' => $handover?->id, 'person_id' => $personId, 'person_name' => $personName, 'amount_minor' => $amount, 'status' => 'open',
        ]);
        $this->recorder->save($actor, $v, 'cash_variance.found', branchId: $branchId);
    }

    private function move(Actor $actor, string $branchId, string $kind, string $from, string $to, int $amount, Model $source, string $memo): void
    {
        if ($amount <= 0) {
            return;
        }
        $m = (new CashMovement)->forceFill([
            'tenant_id' => $actor->tenantId, 'branch_id' => $branchId, 'kind' => $kind, 'from_account' => $from, 'to_account' => $to,
            'amount_minor' => $amount, 'source_type' => $source->getTable(), 'source_id' => $source->getKey(), 'memo' => mb_substr($memo, 0, 255),
            'occurred_at' => now(),
        ]);
        $this->recorder->save($actor, $m, 'cash.moved', branchId: $branchId);
    }

    private function require(Actor $actor, string $permission, ?Model $target = null): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $target)) {
            throw new AccessDenied;
        }
    }
}
