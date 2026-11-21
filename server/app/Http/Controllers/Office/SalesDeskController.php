<?php

namespace App\Http\Controllers\Office;

use App\Domain\Customers\StoredValues;
use App\Domain\Messaging\PhonePush;
use App\Domain\Office\CustomerOrdersScreen;
use App\Domain\Office\SalesDeskScreen;
use App\Domain\Pos\OfficeReturns;
use App\Domain\Pos\ReturnService;
use App\Domain\Support\Actor;
use App\Domain\Sync\ReviewQueue;
use App\Filament\Resources\ReviewItems\ReviewItemResource;
use App\Filament\Resources\StoredValues\StoredValueResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\OfficeReturn;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The rest of Sales in the new back office (App\Domain\Office\SalesDeskScreen): Till requests (approving and declining
 * go through OfficeController::decideTillRequest, as on the To-do), Back-office returns (and "Return goods" on a sale's
 * page), the Review queue and Gift cards & credit. Each record is found only where the person may see it.
 */
class SalesDeskController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): SalesDeskScreen
    {
        $this->allowed($can);

        return new SalesDeskScreen($r->user());
    }

    // -------------------------------------------------------------------------------------------- Till requests

    public function tillRequests(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SalesDeskScreen::canTill())->tillRequests($request->query()));
    }

    /** This browser said yes to notifications: till requests now pop up on it (as the current page). */
    public function push(Request $request, PhonePush $push): JsonResponse
    {
        $this->screen($request, SalesDeskScreen::canTill());
        $d = $request->validate(['endpoint' => ['required', 'string', 'starts_with:https://', 'max:2000'], 'public_key' => ['required', 'string', 'max:200'],
            'auth_token' => ['required', 'string', 'max:100'], 'device' => ['nullable', 'string', 'max:255']]);
        $push->subscribe($request->user(), $d['endpoint'], $d['public_key'], $d['auth_token'], $d['device'] ?? null);

        return response()->json(['message' => 'Notifications are on for this device: till requests now pop up here even when the back office is closed.']);
    }

    // -------------------------------------------------------------------------------------------- Back-office returns

    public function returns(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SalesDeskScreen::canReturns())->returns($request->query()));
    }

    private function returnDoc(SalesDeskScreen $screen, string $id): OfficeReturn
    {
        return $screen->returnsQuery()->findOrFail($id);
    }

    public function returnShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, SalesDeskScreen::canReturns());

        return response()->json($screen->returnDoc($this->returnDoc($screen, $id)));
    }

    public function returnStep(Request $request, string $id, string $step): JsonResponse
    {
        $screen = $this->screen($request, SalesDeskScreen::canReturns());
        $doc = $this->returnDoc($screen, $id);
        $d = $step === 'cancel' ? $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Give the reason.']) : [];

        return $this->act(function (Actor $actor) use ($step, $doc, $d, $screen) {
            $svc = app(OfficeReturns::class);
            $step === 'approve' ? $svc->approve($actor, $doc) : $svc->cancel($actor, $doc, $d['reason']);

            return ['message' => $step === 'approve' ? 'Return posted.' : 'Cancelled.', 'doc' => $screen->returnDoc($doc->fresh())];
        });
    }

    /** A sale the person may see (as the rebuilt sale page). */
    private function sale(string $id): Sale
    {
        $sale = Sale::findOrFail($id);
        abort_unless(Erp::can('sales.view', Branch::find($sale->branch_id)), 403, 'You cannot see sales of this branch.');

        return $sale;
    }

    public function returnForm(Request $request, string $sale): JsonResponse
    {
        $this->allowed(Erp::canAnywhere('returns.office'));
        $form = (new SalesDeskScreen($request->user()))->returnForm($this->sale($sale));
        abort_if($form === null, 422, 'Only a posted sale can be returned here.');

        return response()->json($form);
    }

    /** "Return goods" from a sale: sent for someone else's approval; nothing changes until then. */
    public function returnSubmit(Request $request, string $sale): JsonResponse
    {
        $this->allowed(Erp::canAnywhere('returns.office'));
        $s = $this->sale($sale);
        $d = $request->validate([
            'lines' => ['required', 'array', 'min:1'], 'lines.*.line_id' => ['required', 'uuid'], 'lines.*.qty' => ['nullable', 'numeric', 'min:0'], 'lines.*.damaged' => ['boolean'],
            'warehouse_id' => ['required', Rule::in(array_keys(Fields::warehouses('returns.office')))], 'reason_code' => ['required', Rule::in(array_keys(ReturnService::REASONS))],
            'reason' => ['nullable', 'string', 'max:255'], 'refund_method' => ['required', Rule::in(array_keys(OfficeReturn::REFUNDS))],
            'account_id' => ['required_if:refund_method,money', 'nullable', Rule::in(array_keys(FinanceUi::moneyAccounts()))],
            'reference' => ['required_if:refund_method,money', 'nullable', 'string', 'max:80'],
        ], ['warehouse_id.*' => 'Choose where the goods go back to.', 'reason_code.*' => 'Choose the reason.', 'refund_method.*' => 'Choose how the customer is refunded.',
            'account_id.*' => 'Choose the account the money is paid from.', 'reference.*' => 'Enter the payment reference.']);

        return $this->act(function (Actor $actor) use ($s, $d) {
            $doc = app(OfficeReturns::class)->submit($actor, $s, $d);

            return ['id' => $doc->id, 'message' => "Return {$doc->number} sent for approval."];
        });
    }

    // -------------------------------------------------------------------------------------------- Review queue

    public function review(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SalesDeskScreen::canReview())->review($request->query()));
    }

    public function resolve(Request $request, ReviewQueue $queue, string $id): JsonResponse
    {
        $this->screen($request, SalesDeskScreen::canReview());
        $item = ReviewItemResource::getEloquentQuery()->findOrFail($id);
        $d = $request->validate(['resolution' => ['required', 'string', 'max:255']], ['resolution.*' => 'Say what you checked or did.']);

        return $this->act(function (Actor $actor) use ($queue, $item, $d) {
            $queue->resolve($actor, $item, $d['resolution']);

            return ['message' => 'Resolved.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Gift cards & credit

    public function cards(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SalesDeskScreen::canCards())->cards($request->query()));
    }

    public function cardHistory(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, SalesDeskScreen::canCards());

        return response()->json(['rows' => $screen->cardHistory(StoredValueResource::getEloquentQuery()->findOrFail($id))]);
    }

    /** Customers by name, code or phone, for "For customer". */
    public function cardCustomers(Request $request): JsonResponse
    {
        $this->allowed(SalesDeskScreen::canCards());

        return response()->json(['results' => (new CustomerOrdersScreen($request->user()))->customers(mb_substr((string) $request->query('q', ''), 0, 60))]);
    }

    public function sellCard(Request $request, StoredValues $values): JsonResponse
    {
        $this->allowed(SalesDeskScreen::canCards());
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:1'], 'paid_by' => ['required', Rule::in(array_keys(SalesDeskScreen::PAID_BY))],
            'reference' => ['nullable', 'string', 'max:30'], 'code' => ['nullable', 'string', 'max:30'], 'customer_id' => ['nullable', 'uuid']],
            ['amount.*' => 'Enter the amount (at least 1).', 'paid_by.*' => 'Choose how it was paid.']);
        $currency = $request->user()->tenant?->currency_code ?? 'GHS';

        return $this->act(function (Actor $actor) use ($values, $d, $currency) {
            $card = $values->sellCard($actor, Money::toMinor((string) $d['amount'], $currency), $d['paid_by'], ($d['reference'] ?? '') ?: null,
                filled($d['customer_id'] ?? null) ? Customer::findOrFail($d['customer_id']) : null, ($d['code'] ?? '') ?: null);

            return ['message' => "Gift card {$card->code}: balance ".Money::display($card->balanceMinor(), $currency).'.', 'code' => $card->code,
                'id' => Str::isUuid($card->id) ? $card->id : null];
        });
    }
}
