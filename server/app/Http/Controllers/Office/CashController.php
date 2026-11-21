<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\CashScreen;
use App\Domain\Pos\CashControl;
use App\Domain\Pos\DayCloseService;
use App\Domain\Pos\TellerVaults;
use App\Domain\Support\Actor;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CashHandover;
use App\Models\CashVariance;
use App\Models\ShiftTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultSession;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Cash area in the new back office (App\Domain\Office\CashScreen): Close the day, the Cash desk, Income control and
 * Shifts and vaults. Records are looked up only within the branches the person has that work in, and each action goes
 * through the same service as the current pages, which checks the person again against that branch or till.
 */
class CashController extends Controller
{
    use ActsOnAccess;

    private const RECOUNTS = 'office.cash.recounts';

    private function currency(Request $r): string
    {
        return Tenant::find($r->user()->tenant_id)->currency_code;
    }

    /** A blind count: note by note, or one amount when the currency has no list. @return array{0: ?array<string,int>, 1: ?int} */
    private function count(Request $r): array
    {
        $r->validate(['denoms' => ['nullable', 'array'], 'denoms.*' => ['nullable', 'integer', 'min:0'], 'amount' => ['nullable', 'numeric', 'min:0']],
            ['denoms.*' => 'Enter a whole number of each note and coin.', 'amount.*' => 'Enter the amount counted.']);
        $c = $this->currency($r);
        if (CashControl::denominations($c)) {
            return [collect((array) $r->input('denoms', []))->map(fn ($v) => (int) ($v ?: 0))->all(), null];
        }

        return [null, $r->filled('amount') ? Money::toMinor((string) $r->input('amount'), $c) : null];
    }

    // -------------------------------------------------------------------------------------------- Close the day

    public function closeDay(Request $request): JsonResponse
    {
        $this->allowed(CashScreen::canClose());

        return response()->json((new CashScreen($request->user()))->closeDay($request->query()));
    }

    public function close(Request $request, DayCloseService $days): JsonResponse
    {
        $this->allowed(CashScreen::canClose());
        $screen = new CashScreen($request->user());
        $d = $request->validate(['branch' => ['required', Rule::in(array_keys($screen->closeBranches()))], 'date' => ['required', 'date_format:Y-m-d'],
            'banked' => ['nullable', 'numeric', 'min:0'], 'bank_ref' => ['nullable', 'string', 'max:60'], 'bank_account' => ['nullable', 'uuid'],
            'safe' => ['nullable', 'numeric', 'min:0'], 'note' => ['nullable', 'string', 'max:500']],
            ['branch.*' => 'Choose a branch you may close.', 'date.*' => 'Choose the day.', 'banked.*' => 'Type the amount taken to the bank.', 'safe.*' => 'Type the amount kept in the safe.']);

        return $this->act(function (Actor $actor) use ($days, $d) {
            $days->close($actor, Branch::findOrFail($d['branch']), $d['date'], ['banked' => $d['banked'] ?? null, 'bank_ref' => $d['bank_ref'] ?? null,
                'bank_account_id' => $d['bank_account'] ?? null, 'safe' => $d['safe'] ?? null, 'note' => $d['note'] ?? null]);

            return ['message' => 'Day closed.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Cash desk

    public function desk(Request $request): JsonResponse
    {
        $this->allowed(CashScreen::canDesk());

        return response()->json((new CashScreen($request->user()))->desk($request->query()));
    }

    private function deskVault(Request $request, string $id): Vault
    {
        return Vault::whereIn('branch_id', array_keys((new CashScreen($request->user()))->deskBranches()))->findOrFail($id);
    }

    private function deskSession(Request $request, string $id): VaultSession
    {
        return VaultSession::whereIn('branch_id', array_keys((new CashScreen($request->user()))->deskBranches()))->findOrFail($id);
    }

    public function tellers(Request $request): JsonResponse
    {
        $this->allowed(CashScreen::canDesk());

        return response()->json(['tellers' => (new CashScreen($request->user()))->tellers($this->deskVault($request, (string) $request->query('vault')))]);
    }

    public function issue(Request $request, CashControl $cash): JsonResponse
    {
        $this->allowed(CashScreen::canDesk());
        $d = $request->validate(['vault' => ['required', 'uuid'], 'teller' => ['required', 'uuid'], 'float' => ['required', 'numeric', 'min:0'], 'note' => ['nullable', 'string', 'max:255']],
            ['teller.*' => 'Choose the teller.', 'float.*' => 'Type the float.']);
        $vault = $this->deskVault($request, $d['vault']);
        $c = $this->currency($request);

        return $this->act(function (Actor $actor) use ($cash, $vault, $d, $c) {
            $cash->issueFloat($actor, $vault, User::findOrFail($d['teller']), Money::toMinor((string) $d['float'], $c), $d['note'] ?? null);

            return ['message' => 'Float issued.'];
        });
    }

    public function cancel(Request $request, CashControl $cash, string $session): JsonResponse
    {
        $this->allowed(CashScreen::canDesk());
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Give a reason.']);
        $s = $this->deskSession($request, $session);

        return $this->act(function (Actor $actor) use ($cash, $s, $d) {
            $cash->cancelIssue($actor, $s, $d['reason']);

            return ['message' => 'Float taken back.'];
        });
    }

    public function receiveVault(Request $request, CashControl $cash, string $session): JsonResponse
    {
        $this->allowed(CashScreen::canDesk());
        $s = $this->deskSession($request, $session);
        [$denoms, $amount] = $this->count($request);

        return $this->act(function (Actor $actor) use ($cash, $s, $denoms, $amount) {
            $r = $cash->receiveVault($actor, $s, $denoms, $amount);

            return ['status' => $r['status'], 'message' => $r['status'] === 'recount' ? '' : 'Vault received.'];
        });
    }

    public function handOver(Request $request, CashControl $cash): JsonResponse
    {
        $this->allowed(CashScreen::canDesk());
        $screen = new CashScreen($request->user());
        $d = $request->validate(['branch' => ['required', Rule::in(array_keys($screen->deskBranches()))], 'sessions' => ['required', 'array', 'min:1'], 'sessions.*' => ['uuid'],
            'note' => ['nullable', 'string', 'max:255']], ['sessions.*' => 'Tick the vaults you are handing over.']);

        return $this->act(function (Actor $actor) use ($cash, $d) {
            $h = $cash->handOver($actor, Branch::findOrFail($d['branch']), $d['sessions'], $d['note'] ?? null);

            return ['message' => "Handed over ({$h->number})."];
        });
    }

    // -------------------------------------------------------------------------------------------- Income control

    public function income(Request $request): JsonResponse
    {
        $this->allowed(CashScreen::canIncome());

        return response()->json((new CashScreen($request->user()))->income());
    }

    private function scoped(string $model, string $id): mixed
    {
        $ids = CashScreen::incomeScope();

        return $model::query()->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->findOrFail($id);
    }

    /**
     * Count a handover blind. When the first count differs from the cashier's, the person is asked to count again and
     * the second count stands; that "second count" is remembered here, in their sign-in, not taken from the page.
     */
    public function receiveHandover(Request $request, CashControl $cash, string $handover): JsonResponse
    {
        $this->allowed(CashScreen::canIncome());
        $h = $this->scoped(CashHandover::class, $handover);
        [$denoms, $amount] = $this->count($request);
        $recounts = (array) $request->session()->get(self::RECOUNTS, []);

        $res = $this->act(function (Actor $actor) use ($cash, $h, $denoms, $amount, $recounts) {
            $r = $cash->receiveHandover($actor, $h, $denoms, $amount, (int) ($recounts[$h->id] ?? 0));

            return ['status' => $r['status'], 'message' => $r['status'] === 'recount' ? '' : 'Handover received.'];
        });
        $status = $res->getData(true)['status'] ?? null;
        if ($status === 'recount') {
            $request->session()->put(self::RECOUNTS, [$h->id => 1] + $recounts);
        } elseif ($status === 'received') {
            unset($recounts[$h->id]);
            $request->session()->put(self::RECOUNTS, $recounts);
        }

        return $res;
    }

    public function decide(Request $request, CashControl $cash, string $variance): JsonResponse
    {
        $this->allowed(CashScreen::canIncome());
        $v = $this->scoped(CashVariance::class, $variance);
        $d = $request->validate(['treatment' => ['required', Rule::in(array_keys(CashVariance::TREATMENTS))], 'months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'reason' => ['required', 'string', 'max:255']], ['treatment.*' => 'Choose how to treat it.', 'months.*' => 'Recover over 1 to 12 months.', 'reason.*' => 'Give a reason.']);

        return $this->act(function (Actor $actor) use ($cash, $v, $d) {
            $cash->decide($actor, $v, $d['treatment'], $d['reason'], (int) ($d['months'] ?? 1));

            return ['message' => 'Decided.'];
        });
    }

    public function deposit(Request $request, CashControl $cash, string $handover): JsonResponse
    {
        $this->allowed(CashScreen::canIncome());
        $h = $this->scoped(CashHandover::class, $handover);
        $banks = array_column((new CashScreen($request->user()))->income()['banks'], 'id');
        $d = $request->validate(['bank' => ['required', Rule::in($banks)], 'amount' => ['required', 'numeric', 'min:0'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'slip' => ['required', 'string', 'max:40']], ['bank.*' => 'Choose the bank account.', 'amount.*' => 'Type the amount deposited.', 'date.*' => 'Choose the day (not in the future).',
                'slip.*' => 'Enter the deposit slip number.']);
        $c = $this->currency($request);

        return $this->act(function (Actor $actor) use ($cash, $h, $d, $c) {
            $cash->deposit($actor, $h, Account::findOrFail($d['bank']), Money::toMinor((string) $d['amount'], $c), $d['date'], $d['slip']);

            return ['message' => 'Deposit recorded.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Shifts and vaults

    public function setup(Request $request): JsonResponse
    {
        $this->allowed(CashScreen::canSetup());

        return response()->json((new CashScreen($request->user()))->setup());
    }

    private function setupVault(Request $request, string $id): Vault
    {
        return Vault::whereIn('branch_id', array_keys((new CashScreen($request->user()))->setupBranches()))->findOrFail($id);
    }

    public function sellers(Request $request): JsonResponse
    {
        $this->allowed(CashScreen::canSetup());
        $screen = new CashScreen($request->user());
        $id = (string) $request->query('branch');
        abort_unless(array_key_exists($id, $screen->setupBranches()), 404);

        return response()->json($screen->sellers(Branch::findOrFail($id)));
    }

    public function saveShift(Request $request, CashControl $cash): JsonResponse
    {
        $this->allowed(CashScreen::canSetup());
        $d = $request->validate(['id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:40'], 'starts_at' => ['required', 'date_format:H:i'], 'ends_at' => ['required', 'date_format:H:i'],
            'sort' => ['nullable', 'integer'], 'is_active' => ['boolean']], ['name.*' => 'Give the shift a name.', 'starts_at.*' => 'Enter when it starts.', 'ends_at.*' => 'Enter when it ends.']);
        $tpl = filled($d['id'] ?? null) ? ShiftTemplate::findOrFail($d['id']) : new ShiftTemplate;

        return $this->act(function (Actor $actor) use ($cash, $tpl, $d) {
            $cash->saveTemplate($actor, $tpl, $d);

            return ['message' => 'Saved.'];
        });
    }

    public function saveVault(Request $request, CashControl $cash, string $vault): JsonResponse
    {
        $this->allowed(CashScreen::canSetup());
        $v = $this->setupVault($request, $vault);
        $d = $request->validate(['float' => ['required', 'numeric', 'min:0'], 'is_active' => ['boolean']], ['float.*' => 'Type the standard float.']);
        $c = $this->currency($request);

        return $this->act(function (Actor $actor) use ($cash, $v, $d, $c) {
            $cash->saveVault($actor, $v, Money::toMinor((string) $d['float'], $c), (bool) ($d['is_active'] ?? true));

            return ['message' => 'Saved.'];
        });
    }

    /** Add or change a teller's own vault (personal vaults). An existing vault must be in a branch the person sets up. */
    public function saveOwnVault(Request $request, TellerVaults $vaults): JsonResponse
    {
        $this->allowed(CashScreen::canSetup());
        $screen = new CashScreen($request->user());
        $d = $request->validate(['id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:80'], 'branch' => ['required', Rule::in(array_keys($screen->setupBranches()))],
            'holder' => ['required', 'uuid'], 'till' => ['nullable', 'uuid'], 'float' => ['required', 'numeric', 'min:0'], 'is_active' => ['boolean']],
            ['name.*' => 'Give the vault a name.', 'branch.*' => 'Choose a branch you set up.', 'holder.*' => 'Choose the teller who holds it.', 'float.*' => 'Type the standard float.']);
        $vault = filled($d['id'] ?? null) ? $this->setupVault($request, $d['id']) : new Vault;
        abort_if($vault->exists && $vault->shift_template_id !== null, 404);
        $c = $this->currency($request);

        return $this->act(function (Actor $actor) use ($vaults, $vault, $d, $c) {
            $vaults->save($actor, $vault, ['name' => $d['name'], 'branch_id' => $d['branch'], 'till_id' => $d['till'] ?? null, 'holder_id' => $d['holder'],
                'standard_float_minor' => Money::toMinor((string) $d['float'], $c), 'is_active' => (bool) ($d['is_active'] ?? true)]);

            return ['message' => 'Saved.'];
        });
    }

    public function lend(Request $request, TellerVaults $vaults, string $vault): JsonResponse
    {
        $this->allowed(CashScreen::canSetup());
        $v = $this->setupVault($request, $vault);
        $d = $request->validate(['user' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:255']], ['user.*' => 'Choose who to lend it to.', 'reason.*' => 'Give the reason.']);

        return $this->act(function (Actor $actor) use ($vaults, $v, $d) {
            $vaults->lend($actor, $v, User::findOrFail($d['user']), $d['reason']);

            return ['message' => 'Lent for today.'];
        });
    }
}
