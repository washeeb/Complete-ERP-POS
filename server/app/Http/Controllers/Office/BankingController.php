<?php

namespace App\Http\Controllers\Office;

use App\Domain\Banking\BankingService;
use App\Domain\Office\BankingScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Branch;
use App\Security\ReconfirmNeeded;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Bank & cash in the new back office (App\Domain\Office\BankingScreen): accounts, Import statement, Move money,
 * reconciliation and Money moved, every change through BankingService (and ArchiveService).
 */
class BankingController extends Controller
{
    public function accounts(Request $request): JsonResponse
    {
        $this->allowed(BankingScreen::canView());

        return response()->json((new BankingScreen($request->user()))->accounts($request->query()));
    }

    public function forms(Request $request): JsonResponse
    {
        $this->allowed(BankingScreen::canView() || BankingScreen::canTransfers());

        return response()->json((new BankingScreen($request->user()))->forms());
    }

    /** Add a bank, MoMo or cash account (its ledger account chosen, or a new one with the code given), or change one. */
    public function saveAccount(Request $request, BankingService $banking, ?string $bank = null): JsonResponse
    {
        $this->allowed(BankingScreen::canView());
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'kind' => ['required', Rule::in(array_keys(BankAccount::KINDS))], 'provider' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:60'], 'account_id' => ['nullable', 'uuid'], 'code' => ['nullable', 'string', 'max:20']], ['name.required' => 'Give the account a name.']);
        $b = $bank ? BankAccount::findOrFail($bank) : new BankAccount;

        return $this->act(fn ($actor) => ['bank' => ['id' => $banking->saveAccount($actor, $b, $d)->id]]);
    }

    public function archive(Request $request, string $bank, ArchiveService $archive): JsonResponse
    {
        $this->allowed(BankingScreen::canView());
        $d = $request->validate(['restore' => ['boolean'], 'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.required_unless' => 'Say why.']);
        $b = BankAccount::findOrFail($bank);

        return $this->act(function ($actor) use ($archive, $b, $d) {
            ($d['restore'] ?? false) ? $archive->restore($actor, $b) : $archive->archive($actor, $b, $d['reason']);

            return ['done' => true];
        });
    }

    /** Import statement: a CSV with its columns named (remembered for the account); lines already imported are skipped. */
    public function import(Request $request, string $bank, BankingService $banking): JsonResponse
    {
        $this->allowed(BankingScreen::canView());
        $d = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimetypes:text/csv,text/plain,application/vnd.ms-excel,application/csv,application/octet-stream'],
            'header_rows' => ['required', 'integer', 'min:0', 'max:20'], 'date' => ['required', 'string', 'max:60'], 'date_format' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:60'], 'reference' => ['nullable', 'string', 'max:60'], 'amount' => ['nullable', 'string', 'max:60'],
            'money_in' => ['nullable', 'string', 'max:60'], 'money_out' => ['nullable', 'string', 'max:60'], 'balance' => ['nullable', 'string', 'max:60'],
        ], ['file.required' => 'Choose the statement file.', 'date.required' => 'Name the date column.']);
        $b = BankAccount::findOrFail($bank);
        $file = $request->file('file');
        $mapping = array_filter(array_diff_key($d, ['file' => 1]), fn ($v) => $v !== null && $v !== '');

        return $this->act(function ($actor) use ($banking, $b, $file, $mapping) {
            $r = $banking->import($actor, $b, $file->getClientOriginalName(), (string) file_get_contents($file->getRealPath()), $mapping);

            return $r + ['message' => "{$r['added']} line(s) added".($r['skipped'] ? ", {$r['skipped']} already there" : '').'.'];
        });
    }

    /** Move money between your own accounts: banking the till cash, topping up petty cash, sweeping MoMo to the bank. */
    public function transfer(Request $request, BankingService $banking): JsonResponse
    {
        $this->allowed(BankingScreen::canView() || BankingScreen::canTransfers());
        $d = $request->validate([
            'from_account_id' => ['required', 'uuid'], 'branch_id' => ['required', 'uuid'], 'to_account_id' => ['required', 'uuid', 'different:from_account_id'],
            'to_branch_id' => ['required', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0'], 'moved_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:255'],
        ], ['to_account_id.different' => 'Choose two different accounts.', 'amount.gt' => 'Enter the amount.', 'moved_on.before_or_equal' => 'The date cannot be in the future.']);

        return $this->act(fn ($actor) => ['transfer' => ['id' => $banking->transfer($actor, Account::findOrFail($d['from_account_id']), Account::findOrFail($d['to_account_id']),
            Branch::findOrFail($d['branch_id']), Branch::findOrFail($d['to_branch_id']), FinanceUi::toMinor((string) $d['amount']), $d['moved_on'], $d['reference'] ?? null, $d['note'] ?? null)->id],
            'message' => 'Money moved.']);
    }

    public function transfers(Request $request): JsonResponse
    {
        $this->allowed(BankingScreen::canTransfers());

        return response()->json((new BankingScreen($request->user()))->transfers($request->query()));
    }

    public function reconcile(Request $request, string $bank): JsonResponse
    {
        $this->allowed(BankingScreen::canReconcile());

        return response()->json((new BankingScreen($request->user()))->reconcile(BankAccount::with('account')->findOrFail($bank), $request->query('as_at')));
    }

    /**
     * One reconciliation step: match (ticked lines), auto (the suggestions), earlier (ticked ledger lines were on an
     * earlier statement), post (one ticked statement line to an account), ignore (with a reason), unmatch.
     */
    public function reconcileAction(Request $request, string $bank, string $step, BankingService $banking): JsonResponse
    {
        $this->allowed(BankingScreen::canReconcile());
        abort_unless(in_array($step, ['match', 'auto', 'earlier', 'post', 'ignore', 'unmatch'], true), 404);
        $d = $request->validate(array_merge(['statement' => ['array'], 'statement.*' => ['uuid'], 'ledger' => ['array'], 'ledger.*' => ['uuid'],
            'account_id' => ['nullable', 'uuid'], 'memo' => ['nullable', 'string', 'max:255'], 'reason' => ['nullable', 'string', 'max:255'], 'match_id' => ['nullable', 'uuid']],
            match ($step) {
                'post' => ['account_id' => ['required', 'uuid']], 'ignore' => ['reason' => ['required', 'string', 'max:255']], 'unmatch' => ['match_id' => ['required', 'uuid']], default => []
            }),
            ['account_id.required' => 'Choose the account to post it to.', 'reason.required' => 'Say why it is ignored.']);
        $b = BankAccount::findOrFail($bank);
        $statement = $d['statement'] ?? [];
        $ledger = $d['ledger'] ?? [];

        return $this->act(function ($actor) use ($banking, $b, $step, $statement, $ledger, $d) {
            switch ($step) {
                case 'match':
                    $banking->match($actor, $b, $statement, $ledger);

                    return ['message' => 'Matched.'];
                case 'auto':
                    $n = $banking->autoMatch($actor, $b);

                    return ['message' => $n ? "{$n} line(s) matched." : 'Nothing to match automatically.'];
                case 'earlier':
                    $banking->clearAsEarlier($actor, $b, $ledger);

                    return ['message' => 'Cleared as on an earlier statement.'];
                case 'post':
                    if (count($statement) !== 1) {
                        throw new DomainException('Tick one statement line and choose the account to post it to.');
                    }
                    $banking->postFromStatement($actor, $b, BankStatementLine::where('bank_account_id', $b->id)->findOrFail($statement[0]), Account::findOrFail($d['account_id']),
                        Branch::where('is_hq', true)->firstOrFail(), ($d['memo'] ?? '') ?: null);

                    return ['message' => 'Posted and matched.'];
                case 'ignore':
                    foreach ($statement as $id) {
                        $banking->ignore($actor, BankStatementLine::where('bank_account_id', $b->id)->findOrFail($id), $d['reason']);
                    }

                    return ['message' => 'Ignored.'];
                default:
                    $banking->unmatch($actor, $b, (string) ($d['match_id'] ?? ''));

                    return ['message' => 'Match undone.'];
            }
        });
    }

    private function act(callable $fn): JsonResponse
    {
        try {
            return response()->json(DB::transaction(fn () => $fn(Actor::user(request()->user(), 'web'))));
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => ConfirmItsYou::getUrl()], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(bool $can): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless($can, 403, 'You cannot see this.');
    }
}
