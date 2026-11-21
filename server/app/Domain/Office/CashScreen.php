<?php

namespace App\Domain\Office;

use App\Domain\Pos\CashControl;
use App\Domain\Pos\DayCloseService;
use App\Domain\Pos\TellerVaults;
use App\Filament\Pages\CashDesk;
use App\Filament\Pages\CashSetup;
use App\Filament\Pages\CloseDay;
use App\Filament\Pages\IncomeControl;
use App\Filament\Support\Erp;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashHandover;
use App\Models\CashVariance;
use App\Models\DayClose;
use App\Models\ShiftTemplate;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultCover;
use App\Models\VaultSession;
use App\Security\Authorizer;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The Cash area in the new back office (part 14 of the rewrite), as the current pages: Close the day (DayCloseService),
 * the Cash desk and Income control (CashControl: floats, blind counts, handovers, differences, deposits) and Shifts and
 * vaults (CashControl and TellerVaults). Each list shows only the branches the person has that work in; every action
 * goes through the same service, which checks the person again against that branch or till (CashController).
 */
final class CashScreen
{
    public function __construct(private readonly User $user) {}

    public static function canClose(): bool
    {
        return CloseDay::canAccess();
    }

    public static function canDesk(): bool
    {
        return CashDesk::canAccess();
    }

    public static function canIncome(): bool
    {
        return IncomeControl::canAccess();
    }

    public static function canSetup(): bool
    {
        return CashSetup::canAccess();
    }

    private function currency(): string
    {
        return Tenant::find($this->user->tenant_id)->currency_code;
    }

    private function money(): array
    {
        $c = $this->currency();

        return ['currency' => $c, 'exponent' => Money::exponent($c)];
    }

    /** The notes and coins of the company's currency, for a blind count (never the figure it is checked against). */
    public function denominations(): array
    {
        return collect(CashControl::denominations($this->currency()))->map(fn ($d, $key) => ['key' => $key, 'label' => preg_replace('/ (note|coin)$/', '', $d[0]),
            'value' => $d[1], 'kind' => str_starts_with($key, 'n') ? 'note' : 'coin'])->values()->all();
    }

    private static function pairs(iterable $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $out[] = ['id' => (string) $k, 'label' => (string) $v];
        }

        return $out;
    }

    /**
     * Branches for one or more permissions together (null = every branch).
     *
     * @return list<string>|null
     */
    public static function scope(string ...$permissions): ?array
    {
        $ids = [];
        foreach ($permissions as $p) {
            $b = Erp::branchIds($p);
            if ($b === null) {
                return null;
            }
            $ids = [...$ids, ...$b];
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string, string> active branches within the scope, head office first */
    private function branchList(?array $ids, bool $codes = true): array
    {
        return Branch::where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderByDesc('is_hq')->orderBy('code')->get()
            ->mapWithKeys(fn (Branch $b) => [$b->id => $codes ? "{$b->code} · {$b->name}" : $b->name])->all();
    }

    // -------------------------------------------------------------------------------------------- Close the day

    /** @return array<string, string> branches this person may close */
    public function closeBranches(): array
    {
        return $this->branchList(Erp::branchIds(DayCloseService::PERMISSION), false);
    }

    private function tz(?Branch $b): string
    {
        return $b?->timezone ?: (Tenant::find($this->user->tenant_id)?->timezone ?: 'UTC');
    }

    public function closeDay(array $p): array
    {
        $branches = $this->closeBranches();
        $id = (string) ($p['branch'] ?? '');
        $id = array_key_exists($id, $branches) ? $id : (string) array_key_first($branches);
        $branch = Str::isUuid($id) ? Branch::find($id) : null;
        $today = Carbon::now($this->tz($branch))->toDateString();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['date'] ?? '')) ? (string) $p['date'] : $today;
        $state = $branch ? app(DayCloseService::class)->checks($branch, $date) : ['checks' => [], 'cash_counted' => 0, 'over_short' => 0, 'currency' => $this->currency()];
        $closed = $branch ? DayClose::where('branch_id', $branch->id)->whereDate('business_date', $date)->first() : null;
        $recent = $branch ? DayClose::where('branch_id', $branch->id)->orderByDesc('business_date')->limit(14)->get() : collect();
        $banks = BankAccount::where('is_active', true)->where('kind', 'bank')->orderBy('name')->get()
            ->mapWithKeys(fn (BankAccount $b) => [$b->id => $b->name.($b->number ? ' · '.$b->number : '')])->all();
        $e = Money::exponent($state['currency']);
        $amount = fn (?int $v) => $v === null ? '' : number_format($v / (10 ** $e), $e, '.', '');

        return ['currency' => $state['currency'], 'exponent' => $e, 'branches' => self::pairs($branches), 'branch' => $branch?->id, 'date' => $date, 'today' => $today,
            'checks' => $state['checks'], 'cash_counted' => (int) $state['cash_counted'], 'over_short' => (int) $state['over_short'],
            'closed' => $closed ? ['by' => $closed->closed_by_name, 'at' => $closed->closed_at?->toIso8601String(), 'problems' => (int) $closed->problems, 'note' => $closed->note,
                'deposit_recorded' => (bool) $closed->deposit_transfer_id] : null,
            // The form starts from what was entered when the day was closed (it can be closed again after a fix).
            'form' => ['banked' => $amount($closed?->banked_minor), 'bank_ref' => (string) ($closed?->bank_ref ?? ''), 'safe' => $amount($closed?->safe_minor),
                'bank_account' => (string) ($closed?->bank_account_id ?? array_key_first($banks) ?? ''), 'note' => (string) ($closed?->note ?? '')],
            'bank_accounts' => self::pairs($banks),
            // Without cash control the deposit is booked here; with it, each handover's deposit is booked at Income control.
            'deposits_here' => ! app(CashControl::class)->enabled($this->user->tenant_id) && ! $closed?->deposit_transfer_id,
            'recent' => $recent->map(fn (DayClose $r) => ['date' => $r->business_date->toDateString(), 'by' => $r->closed_by_name, 'cash_counted' => (int) $r->cash_counted_minor,
                'over_short' => (int) $r->over_short_minor, 'banked' => $r->banked_minor, 'bank_ref' => $r->bank_ref, 'statement' => DayCloseService::depositStatus($r)['text'],
                'safe' => $r->safe_minor, 'problems' => (int) $r->problems, 'note' => $r->note])->values()->all()];
    }

    // -------------------------------------------------------------------------------------------- Cash desk

    /** @return array<string, string> branches this person works the cash desk for */
    public function deskBranches(): array
    {
        return $this->branchList(self::scope('cash.vault.issue', 'cash.vault.receive', 'cash.view'));
    }

    public function desk(array $p): array
    {
        $cash = app(CashControl::class);
        $cash->ensureVaults($this->user->tenant_id);
        $branches = $this->deskBranches();
        $id = (string) ($p['branch'] ?? '');
        $id = array_key_exists($id, $branches) ? $id : (string) (array_key_first($branches) ?? '');
        $today = $cash->today($this->user->tenant_id);
        $rows = [];
        $mine = [];
        if ($id !== '') {
            $vaults = Vault::with(['till', 'shiftTemplate'])->where('branch_id', $id)->whereNotNull('shift_template_id')->where('is_active', true)->get()
                ->sortBy(fn (Vault $v) => $v->till->code.'|'.str_pad((string) $v->shiftTemplate->sort, 3, '0', STR_PAD_LEFT));
            $sessions = VaultSession::where('branch_id', $id)
                ->where(fn ($q) => $q->where('business_date', $today)->orWhereIn('status', ['issued', 'open', 'counted', 'received']))
                ->where('status', '<>', 'cancelled')->orderByDesc('business_date')->get()->groupBy('vault_id');
            foreach ($vaults as $v) {
                $s = $sessions->get($v->id)?->first();
                $rows[] = ['vault_id' => $v->id, 'vault' => $v->name, 'shift' => $v->shiftTemplate->label(), 'standard_float' => (int) $v->standard_float_minor,
                    'session' => $s ? ['id' => $s->id, 'status' => $s->status, 'status_label' => VaultSession::STATUSES[$s->status] ?? $s->status, 'teller' => $s->teller_name,
                        'float' => (int) $s->float_minor, 'from_day' => $s->business_date->toDateString() !== $today ? $s->business_date->toDateString() : null,
                        'recount_asked' => $s->status === 'counted' && $s->cashier_recounts > 0, 'cashier' => $s->status === 'received' ? $s->cashier_name : null] : null];
            }
            $mine = VaultSession::with('vault')->where('branch_id', $id)->where('status', 'received')->where('cashier_id', $this->user->id)->get()
                ->map(fn (VaultSession $s) => ['id' => $s->id, 'label' => "{$s->vault->name} · {$s->teller_name} · {$s->business_date->format('j M')}"])->values()->all();
        }

        return $this->money() + ['branches' => self::pairs($branches), 'branch' => $id ?: null, 'rows' => $rows, 'mine' => $mine,
            'denominations' => $this->denominations(),
            'can' => ['issue' => Erp::canAnywhere('cash.vault.issue'), 'receive' => Erp::canAnywhere('cash.vault.receive'), 'hand_over' => Erp::canAnywhere('cash.handover')]];
    }

    /** People who may sell at this vault's till (not the person issuing it). */
    public function tellers(Vault $vault): array
    {
        $authz = app(Authorizer::class);

        return self::pairs(User::where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->id !== $this->user->id && $authz->allows($u, 'pos.access', $vault->till))
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name]));
    }

    // -------------------------------------------------------------------------------------------- Income control

    /** @return list<string>|null branches whose handovers and differences this person sees */
    public static function incomeScope(): ?array
    {
        return self::scope('cash.view', 'cash.control');
    }

    public function income(): array
    {
        $ids = self::incomeScope();
        $scoped = fn ($q) => $ids === null ? $q : $q->whereIn('branch_id', $ids);
        $waiting = $scoped(CashHandover::with('branch')->where('status', 'handed_over'))->orderBy('handed_at')->get();
        $diffs = $scoped(CashVariance::with('branch')->where('status', 'open'))->orderBy('created_at')->get();
        $received = $scoped(CashHandover::with(['branch', 'sessions.vault'])->where('status', 'received'))->orderByDesc('received_at')->limit(30)->get();
        $recounts = (array) session('office.cash.recounts', []);
        $banks = BankAccount::with('account')->where('kind', 'bank')->where('is_active', true)->get()->filter(fn ($b) => $b->account)
            ->mapWithKeys(fn ($b) => [$b->account_id => $b->name])->all();
        if ($bank = Account::where('system_key', 'bank')->first()) {
            $banks += [$bank->id => $bank->name];
        }

        return $this->money() + [
            'waiting' => $waiting->map(fn (CashHandover $h) => ['id' => $h->id, 'number' => $h->number, 'branch' => $h->branch?->code, 'cashier' => $h->cashier_name,
                'at' => $h->handed_at?->toIso8601String(), 'recount' => isset($recounts[$h->id])])->values()->all(),
            'differences' => $diffs->map(fn (CashVariance $v) => ['id' => $v->id, 'at' => $v->created_at?->toIso8601String(), 'branch' => $v->branch?->code,
                'stage' => CashVariance::STAGES[$v->stage] ?? $v->stage, 'person' => $v->person_name, 'amount' => (int) $v->amount_minor])->values()->all(),
            'received' => $received->map(fn (CashHandover $h) => ['id' => $h->id, 'number' => $h->number, 'branch' => $h->branch?->code, 'cashier' => $h->cashier_name,
                'controller' => $h->controller_name, 'at' => $h->received_at?->toIso8601String(), 'declared' => (int) $h->declared_minor, 'counted' => $h->controller_count_minor,
                'deposited' => (bool) $h->deposit_transfer_id,
                'sessions' => $h->sessions->map(fn (VaultSession $s) => ['vault' => $s->vault?->name, 'date' => $s->business_date->toDateString(), 'teller' => $s->teller_name,
                    'expected' => $s->expected_minor, 'teller_count' => $s->teller_count_minor, 'cashier_count' => $s->cashier_count_minor])->values()->all()])->values()->all(),
            'banks' => self::pairs($banks), 'treatments' => self::pairs(CashVariance::TREATMENTS), 'today' => now()->toDateString(),
            'denominations' => $this->denominations(),
            'can' => ['receive' => Erp::canAnywhere('cash.control'), 'decide' => Erp::canAnywhere('cash.variance.approve'), 'deposit' => Erp::canAnywhere('cash.deposit')],
        ];
    }

    // -------------------------------------------------------------------------------------------- Shifts and vaults

    /** @return array<string, string> branches where this person sets up cash */
    public function setupBranches(): array
    {
        return $this->branchList(Erp::branchIds('cash.setup'));
    }

    public function setup(): array
    {
        $tenant = $this->user->tenant_id;
        $tv = app(TellerVaults::class);
        $personal = $tv->enabled($tenant);
        if (! $personal) {
            app(CashControl::class)->ensureVaults($tenant);
        }
        $ids = Erp::branchIds('cash.setup');
        $inScope = fn ($q) => $q->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
        $vaults = $personal ? [] : $inScope(Vault::with(['till', 'branch', 'shiftTemplate'])->whereNotNull('shift_template_id'))->get()
            ->sortBy(fn (Vault $v) => $v->branch->code.'|'.$v->till->code.'|'.str_pad((string) $v->shiftTemplate->sort, 3, '0', STR_PAD_LEFT))->values()
            ->map(fn (Vault $v) => ['id' => $v->id, 'name' => $v->name, 'branch' => $v->branch->code, 'standard_float' => (int) $v->standard_float_minor, 'active' => (bool) $v->is_active])->all();
        $own = [];
        if ($personal) {
            $covers = VaultCover::where('business_date', app(CashControl::class)->today($tenant))->get()->keyBy('vault_id');
            $own = $inScope(Vault::with(['holder', 'till', 'branch'])->whereNull('shift_template_id'))->get()
                ->sortBy(fn (Vault $v) => $v->branch->code.'|'.$v->name)->values()
                ->map(fn (Vault $v) => ['id' => $v->id, 'name' => $v->name, 'holder_id' => $v->holder_id, 'holder' => $v->holder?->name, 'branch_id' => $v->branch_id,
                    'branch' => $v->branch->code, 'till_id' => $v->till_id, 'till' => $v->till?->code, 'standard_float' => (int) $v->standard_float_minor, 'active' => (bool) $v->is_active,
                    'in_use' => $tv->inUse($v), 'lent' => ($c = $covers->get($v->id)) ? ['to' => $c->user_name, 'by' => $c->granted_by_name] : null])->all();
        }
        $templates = ShiftTemplate::orderBy('sort')->orderBy('name')->get();

        return $this->money() + ['personal' => $personal, 'enabled' => app(CashControl::class)->enabled($tenant),
            'till_required' => $tv->trays($tenant) === 'till', 'can_lend' => $personal && Erp::canAnywhere('cash.vault.lend'),
            'templates' => $templates->map(fn (ShiftTemplate $t) => ['id' => $t->id, 'name' => $t->name, 'starts_at' => substr((string) $t->starts_at, 0, 5),
                'ends_at' => substr((string) $t->ends_at, 0, 5), 'sort' => (int) $t->sort, 'active' => (bool) $t->is_active])->values()->all(),
            'next_sort' => (int) $templates->max('sort') + 1, 'next_vault' => 'Vault '.(Vault::whereNull('shift_template_id')->count() + 1),
            'vaults' => $vaults, 'own_vaults' => $own, 'branches' => self::pairs($this->setupBranches())];
    }

    /** People who may sell at the branch (for a teller's own vault), and its tills. */
    public function sellers(Branch $branch): array
    {
        $tv = app(TellerVaults::class);

        return ['people' => self::pairs(User::where('is_active', true)->orderBy('name')->get()->filter(fn (User $u) => $tv->canSellAt($u, $branch->id))
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name])),
            'tills' => self::pairs(Till::where('branch_id', $branch->id)->where('is_active', true)->orderBy('code')->pluck('name', 'id'))];
    }
}
