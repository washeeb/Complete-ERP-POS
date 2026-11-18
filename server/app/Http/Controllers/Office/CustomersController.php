<?php

namespace App\Http\Controllers\Office;

use App\Domain\Crm\Crm;
use App\Domain\Customers\CustomerService;
use App\Domain\Office\CustomersScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Pharmacy\PharmacyService;
use App\Domain\Sales\ReceivablesService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Support\CrmUi;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Customers in the new back office (App\Domain\Office\CustomersScreen): the list, one customer, the form, and the
 * current page's actions — record a payment, credit, opening balance, patient details, follow-up, archive, restore —
 * each through the same service as the current screen.
 */
class CustomersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new CustomersScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new CustomersScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new CustomersScreen($request->user()))->show($this->find($id)));
    }

    public function form(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        abort_unless(CustomersScreen::canManage(), 403, 'You cannot add or change customers.');

        return response()->json((new CustomersScreen($request->user()))->form($id ? $this->find($id) : null));
    }

    public function save(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        $request->validate([
            'values' => ['required', 'array'], 'values.type' => ['required', Rule::in(array_keys(Customer::TYPES))],
            'values.name' => ['required', 'string', 'max:255'], 'values.phone' => ['nullable', 'string', 'max:30'], 'values.email' => ['nullable', 'email', 'max:255'],
            'values.contact_name' => ['nullable', 'string', 'max:255'], 'values.tax_no' => ['nullable', 'string', 'max:40'], 'values.industry_id' => ['nullable', 'string'],
            'values.price_list_id' => ['nullable', 'uuid'], 'values.home_branch_id' => ['nullable', 'uuid'], 'values.address' => ['nullable', 'string', 'max:2000'],
            'values.tags' => ['nullable', 'array', 'max:30'], 'values.tags.*' => ['string', 'max:60'], 'values.notes' => ['nullable', 'string', 'max:5000'],
            'values.consents' => ['nullable', 'array'], 'values.consents.*' => ['boolean'],
            'values.consent_source' => ['nullable', Rule::in(array_keys(CustomersScreen::CONSENT_SOURCES))],
            'values.credit_limit' => ['nullable', 'numeric', 'min:0'], 'values.payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], ['values.name.required' => 'Enter the customer’s name.', 'values.email.email' => 'That email address is not valid.']);
        $customer = $id ? $this->find($id) : new Customer;
        $v = (array) $request->input('values');

        return $this->act($request, function (Actor $actor) use ($customer, $v, $id) {
            $screen = new CustomersScreen($actor->user);
            $saved = app(CustomerService::class)->save($actor, $customer, $screen->toDomain($v, $id === null), $v['consent_source'] ?? 'in person');

            return ['id' => $saved->id];
        });
    }

    /** The current page's buttons: pay, credit, opening, patient, follow_up, archive, restore. */
    public function action(Request $request, string $id): JsonResponse
    {
        $this->allowed();
        $a = $request->validate(['action' => ['required', Rule::in(['pay', 'credit', 'opening', 'patient', 'follow_up', 'archive', 'restore'])]])['action'];
        $d = $request->validate(match ($a) {
            'pay' => ['branch_id' => ['required', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', Rule::in(array_keys(ReceivablesService::METHODS))],
                'provider' => ['nullable', 'string'], 'reference' => ['nullable', 'string', 'max:80'], 'received_on' => ['nullable', 'date', 'before_or_equal:today'],
                'note' => ['nullable', 'string', 'max:255']],
            'credit' => ['limit' => ['nullable', 'numeric', 'min:0'], 'terms' => ['required', 'integer', 'min:0', 'max:365'], 'hold' => ['required', 'boolean'],
                'reason' => ['required', 'string', 'max:255']],
            'opening' => ['amount' => ['required', 'numeric'], 'as_of' => ['required', 'date']],
            'patient' => ['date_of_birth' => ['nullable', 'date', 'before_or_equal:today'], 'sex' => ['nullable', Rule::in(['female', 'male'])],
                'allergies' => ['nullable', 'array'], 'allergies.*' => ['string', 'max:80'], 'conditions' => ['nullable', 'string', 'max:2000'],
                'nhis_no' => ['nullable', 'string', 'max:30'], 'nhis_expires_on' => ['nullable', 'date']],
            'follow_up' => ['type' => ['required', 'string'], 'subject' => ['required', 'string', 'max:255'], 'due_at' => ['nullable', 'date'],
                'assigned_to' => ['nullable', 'uuid'], 'outcome' => ['nullable', 'string', 'max:255'], 'details' => ['nullable', 'string', 'max:5000']],
            'archive' => ['reason' => ['required', 'string', 'min:3', 'max:255']],
            default => [],
        }, ['amount.gt' => 'Enter an amount above zero.', 'reason.required' => 'Say why.', 'subject.required' => 'Say what it is about.']);
        $c = $this->find($id);

        return $this->act($request, function (Actor $actor) use ($a, $d, $c) {
            $screen = new CustomersScreen($actor->user);
            $blank = fn ($k) => ($d[$k] ?? '') === '' ? null : $d[$k];
            if ($a === 'pay') {
                app(ReceivablesService::class)->recordPayment($actor, $c, Branch::findOrFail($d['branch_id']), $d['method'], $screen->minor((string) $d['amount']),
                    $blank('reference'), $blank('received_on'), null, $d['method'] === 'momo' ? $blank('provider') : null, $blank('note'));
            } elseif ($a === 'credit') {
                app(CustomerService::class)->setCredit($actor, $c, $blank('limit') === null ? null : $screen->minor((string) $d['limit']), (int) $d['terms'],
                    (bool) $d['hold'], $d['reason']);
            } elseif ($a === 'opening') {
                app(CustomerService::class)->openingBalance($actor, $c, $screen->minor((string) $d['amount']), $d['as_of']);
            } elseif ($a === 'patient') {
                app(PharmacyService::class)->savePatient($actor, $c, ['date_of_birth' => $blank('date_of_birth'), 'sex' => $blank('sex'),
                    'allergies' => array_values($d['allergies'] ?? []), 'conditions' => $blank('conditions'), 'nhis_no' => $blank('nhis_no'),
                    'nhis_expires_on' => $blank('nhis_expires_on')]);
            } elseif ($a === 'follow_up') {
                abort_unless(CrmUi::canManage(), 403, 'You cannot plan follow-ups.');
                app(Crm::class)->logActivity($actor, ['customer_id' => $c->id, 'type' => $d['type'], 'subject' => $d['subject'], 'details' => $blank('details'),
                    'due_at' => $d['type'] === 'note' ? null : $blank('due_at'), 'assigned_to' => $blank('assigned_to'), 'outcome' => $blank('outcome')]);
            } elseif ($a === 'archive') {
                app(ArchiveService::class)->archive($actor, $c, $d['reason']);
            } else {
                app(ArchiveService::class)->restore($actor, $c);
            }

            return $screen->show($c->fresh());
        });
    }

    /** Archived customers stay reachable (to read and restore); the industry scope still applies. */
    private function find(string $id): Customer
    {
        return CustomersScreen::query()->findOrFail($id);
    }

    private function act(Request $request, Closure $fn): JsonResponse
    {
        $actor = Actor::user($request->user(), 'web');
        try {
            return response()->json(DB::transaction(fn () => $fn($actor)));
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(CustomersScreen::canView(), 403, 'You cannot see customers.');
    }
}
