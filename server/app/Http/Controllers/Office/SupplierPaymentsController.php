<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\SupplierPaymentsScreen;
use App\Domain\Payables\SupplierPaymentService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Security\ReconfirmNeeded;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Supplier payments and Aged payables in the new back office (App\Domain\Office\SupplierPaymentsScreen), and Pay a
 * supplier through SupplierPaymentService (bank or MoMo details checked first; above the limit, confirm it's you).
 */
class SupplierPaymentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->panel();
        abort_unless(SupplierPaymentsScreen::canView(), 403, 'You cannot see supplier payments.');

        return response()->json((new SupplierPaymentsScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->panel();
        abort_unless(SupplierPaymentsScreen::canView() || SupplierPaymentsScreen::canAging(), 403);

        return response()->json((new SupplierPaymentsScreen($request->user()))->options());
    }

    public function aging(Request $request): JsonResponse
    {
        $this->panel();
        abort_unless(SupplierPaymentsScreen::canAging(), 403, 'You cannot see aged payables.');

        return response()->json((new SupplierPaymentsScreen($request->user()))->aging());
    }

    /** The withholding tax for the amount typed, before paying. */
    public function preview(Request $request): JsonResponse
    {
        $this->panel();
        abort_unless(SupplierPaymentsScreen::canPay(), 403);
        $d = $request->validate(['supplier' => ['nullable', 'uuid'], 'amount' => ['nullable', 'string', 'max:20'], 'withhold' => ['boolean'], 'wht' => ['nullable', 'string', 'max:20']]);

        return response()->json(['text' => (new SupplierPaymentsScreen($request->user()))->whtPreview($d['supplier'] ?? null, (string) ($d['amount'] ?? ''),
            (bool) ($d['withhold'] ?? true), $d['wht'] ?? null)]);
    }

    public function pay(Request $request, SupplierPaymentService $payments): JsonResponse
    {
        $this->panel();
        abort_unless(SupplierPaymentsScreen::canPay(), 403, 'You cannot pay suppliers.');
        $d = $request->validate([
            'supplier_id' => ['required', 'uuid'], 'account_id' => ['required', 'uuid'], 'method' => ['required', Rule::in(array_keys(SupplierPayment::METHODS))],
            'amount' => ['required', 'numeric', 'gt:0'], 'withhold' => ['boolean'], 'wht' => ['nullable', 'numeric', 'min:0'], 'reference' => ['nullable', 'string', 'max:80'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'], 'branch_id' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:255'],
        ], ['supplier_id.required' => 'Choose the supplier.', 'amount.gt' => 'Enter the amount.', 'paid_on.before_or_equal' => 'The date cannot be in the future.']);
        $actor = Actor::user($request->user(), 'web');
        try {
            $p = DB::transaction(fn () => $payments->pay($actor, Supplier::findOrFail($d['supplier_id']), Branch::findOrFail($d['branch_id']), Account::findOrFail($d['account_id']),
                $d['method'], FinanceUi::toMinor((string) $d['amount']), $d['reference'] ?? null, $d['paid_on'], null, $d['note'] ?? null, (bool) ($d['withhold'] ?? true),
                filled($d['wht'] ?? null) ? FinanceUi::toMinor((string) $d['wht']) : null));
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => ConfirmItsYou::getUrl()], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json(['payment' => (new SupplierPaymentsScreen($request->user()))->rowOf($p->fresh(['supplier', 'account']))]);
    }

    private function panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}
