<?php

namespace App\Http\Controllers\Office;

use App\Domain\Expenses\ExpenseService;
use App\Domain\Office\ExpenseClaimsScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\ExpenseClaim;
use App\Security\ReconfirmNeeded;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Expense claims in the new back office (App\Domain\Office\ExpenseClaimsScreen): the list, one claim, New claim (with
 * its receipt photo) and Approve, Reject and Pay, through ExpenseService as the current screen. Paying above the
 * company's limit asks the person to confirm it is them (428 with the "Confirm it's you" address).
 */
class ExpenseClaimsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new ExpenseClaimsScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new ExpenseClaimsScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new ExpenseClaimsScreen($request->user()))->show($this->find($id)));
    }

    public function form(Request $request): JsonResponse
    {
        $this->allowed();
        abort_unless(ExpenseClaimsScreen::canCreate(), 403, 'You cannot make expense claims.');

        return response()->json((new ExpenseClaimsScreen($request->user()))->form());
    }

    /** New claim, sent as a form (the receipt photo goes with it). */
    public function save(Request $request, ExpenseService $expenses): JsonResponse
    {
        $this->allowed();
        abort_unless(ExpenseClaimsScreen::canCreate(), 403, 'You cannot make expense claims.');
        $d = $request->validate([
            'branch_id' => ['required', 'uuid'], 'spent_on' => ['required', 'date', 'before_or_equal:today'], 'account_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0'], 'description' => ['required', 'string', 'max:255'], 'payee' => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'uuid'], 'receipt' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:5120'],
        ], ['description.required' => 'Say what it was for.', 'amount.gt' => 'Enter the amount.', 'spent_on.before_or_equal' => 'The date cannot be in the future.',
            'receipt.mimetypes' => 'Attach a photo (JPEG, PNG, WebP) or a PDF.', 'receipt.max' => 'The receipt is larger than 5 MB.']);
        $path = $request->file('receipt')?->store('expense-receipts', 'local');

        $answer = $this->act($request, fn (Actor $actor) => ['id' => $expenses->submit($actor, Branch::findOrFail($d['branch_id']), [
            'spent_on' => $d['spent_on'], 'account_id' => $d['account_id'], 'description' => $d['description'], 'payee' => $d['payee'] ?? null,
            'amount_minor' => FinanceUi::toMinor($d['amount']), 'receipt_path' => $path, 'project_id' => $d['project_id'] ?? null,
        ])->id]);
        if ($path && $answer->getStatusCode() !== 200) {
            Storage::disk('local')->delete($path); // not kept: nothing refers to it
        }

        return $answer;
    }

    /** approve · reject (reason) · pay (account, paid_on) */
    public function action(Request $request, string $id, ExpenseService $expenses): JsonResponse
    {
        $this->allowed();
        $a = $request->validate(['action' => ['required', Rule::in(['approve', 'reject', 'pay'])]])['action'];
        $d = $request->validate(match ($a) {
            'reject' => ['reason' => ['required', 'string', 'max:255']],
            'pay' => ['account_id' => ['required', 'uuid'], 'paid_on' => ['required', 'date', 'before_or_equal:today']],
            default => [],
        }, ['reason.required' => 'Say why.']);
        $c = $this->find($id);

        return $this->act($request, function (Actor $actor) use ($a, $d, $c, $expenses) {
            match ($a) {
                'approve' => $expenses->approve($actor, $c),
                'reject' => $expenses->reject($actor, $c, $d['reason']),
                default => $expenses->pay($actor, $c, Account::findOrFail($d['account_id']), $d['paid_on']),
            };

            return (new ExpenseClaimsScreen($actor->user))->show($c->fresh());
        });
    }

    private function find(string $id): ExpenseClaim
    {
        return ExpenseClaimResource::getEloquentQuery()->findOrFail($id);
    }

    private function act(Request $request, Closure $fn): JsonResponse
    {
        $actor = Actor::user($request->user(), 'web');
        try {
            return response()->json(DB::transaction(fn () => $fn($actor)));
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => ConfirmItsYou::getUrl()], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(ExpenseClaimsScreen::canView(), 403, 'You cannot see expense claims.');
    }
}
