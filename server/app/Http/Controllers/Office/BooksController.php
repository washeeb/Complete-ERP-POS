<?php

namespace App\Http\Controllers\Office;

use App\Domain\Budgets\BudgetService;
use App\Domain\Finance\AccountService;
use App\Domain\Finance\GeneralLedger;
use App\Domain\Finance\PeriodService;
use App\Domain\Office\BooksScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalPeriod;
use App\Security\ReconfirmNeeded;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The books' set-up in the new back office (App\Domain\Office\BooksScreen): chart of accounts, month-end and budgets,
 * each change through its domain service (AccountService, ArchiveService, PeriodService, BudgetService).
 */
class BooksController extends Controller
{
    // ---- Chart of accounts ----

    public function accounts(Request $request): JsonResponse
    {
        $this->allowed(BooksScreen::canAccounts());

        return response()->json((new BooksScreen($request->user()))->accounts($request->query()));
    }

    public function accountForm(Request $request): JsonResponse
    {
        $this->allowed(BooksScreen::canAccounts());

        return response()->json((new BooksScreen($request->user()))->accountForm());
    }

    /** Add an account, or (with an id) change one. */
    public function saveAccount(Request $request, AccountService $accounts, ?string $account = null): JsonResponse
    {
        $this->allowed(BooksScreen::canAccounts());
        abort_unless(BooksScreen::canManageAccounts(), 403, 'You cannot change the chart of accounts.');
        $d = $request->validate([
            'code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'parent_id' => ['nullable', 'uuid'], 'is_header' => ['boolean'], 'system_key' => ['nullable', 'string', 'max:60'], 'description' => ['nullable', 'string', 'max:255'],
        ], ['code.required' => 'Enter the code.', 'name.required' => 'Enter the name.', 'type.required' => 'Choose the type.']);
        $a = $account ? Account::findOrFail($account) : new Account;

        return $this->act(fn ($actor) => ['account' => ['id' => $accounts->save($actor, $a, $d + ['is_active' => $a->exists ? (bool) $a->is_active : true])->id]]);
    }

    public function archiveAccount(Request $request, string $account, ArchiveService $archive): JsonResponse
    {
        $this->allowed(BooksScreen::canAccounts());
        $d = $request->validate(['restore' => ['boolean'], 'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.required_unless' => 'Say why.']);
        $a = Account::findOrFail($account);

        return $this->act(function ($actor) use ($archive, $a, $d) {
            ($d['restore'] ?? false) ? $archive->restore($actor, $a) : $archive->archive($actor, $a, $d['reason']);

            return ['done' => true];
        });
    }

    // ---- Month-end ----

    public function periods(Request $request): JsonResponse
    {
        $this->allowed(BooksScreen::canPeriods());

        return response()->json((new BooksScreen($request->user()))->periods($request->query('year')));
    }

    /** Set up a financial year (the calendar year it ends in): its twelve months. */
    public function setupYear(Request $request, GeneralLedger $gl): JsonResponse
    {
        $this->allowed(BooksScreen::canPeriods());
        abort_unless(Erp::canAnywhere('finance.close'), 403, 'You cannot set up financial years.');
        $d = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);

        return $this->act(fn () => ['year' => $gl->period($request->user()->tenant_id, sprintf('%04d-06-15', $d['year']))->year]);
    }

    public function closePeriod(Request $request, string $period, PeriodService $periods): JsonResponse
    {
        $this->allowed(BooksScreen::canPeriods());
        $p = FiscalPeriod::findOrFail($period);

        return $this->act(fn ($actor) => ['message' => $periods->close($actor, $p)->label().' closed.']);
    }

    public function reopenPeriod(Request $request, string $period, PeriodService $periods): JsonResponse
    {
        $this->allowed(BooksScreen::canPeriods());
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why it is reopened.']);
        $p = FiscalPeriod::findOrFail($period);

        return $this->act(fn ($actor) => ['message' => $periods->reopen($actor, $p, $d['reason'])->label().' reopened.']);
    }

    // ---- Budgets ----

    public function budget(Request $request): JsonResponse
    {
        $this->allowed(BooksScreen::canBudgets());

        return response()->json((new BooksScreen($request->user()))->budget($request->query('year'), $request->query('branch')));
    }

    public function spread(Request $request): JsonResponse
    {
        $this->allowed(BooksScreen::canBudgets());
        $d = $request->validate(['annual' => ['nullable', 'numeric', 'min:0']]);

        return response()->json(['amounts' => BooksScreen::spread((string) ($d['annual'] ?? ''))]);
    }

    /** Save the year's budget for a branch: account id => twelve amounts as typed. */
    public function saveBudget(Request $request, BudgetService $budgets): JsonResponse
    {
        $this->allowed(BooksScreen::canBudgets());
        abort_unless(Erp::canAnywhere('budgets.manage'), 403, 'You cannot change budgets.');
        $d = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'branch' => ['required', 'uuid'], 'amounts' => ['present', 'array'],
            'amounts.*' => ['array', 'size:12'], 'amounts.*.*' => ['nullable', 'numeric']], ['amounts.*.*.numeric' => 'Type amounts as numbers.']);
        $rows = array_map(fn ($months) => array_map(fn ($v) => FinanceUi::toMinor(trim((string) $v) === '' ? 0 : (string) $v), array_values($months)), $d['amounts']);

        return $this->act(fn ($actor) => ['saved' => $budgets->save($actor, (int) $d['year'], Branch::findOrFail($d['branch']), $rows), 'message' => 'Budget saved.']);
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
