<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\ServicesScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Services\AppointmentService;
use App\Domain\Services\LicenceKeyService;
use App\Domain\Services\MembershipService;
use App\Domain\Services\PassService;
use App\Domain\Support\Actor;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\MembershipPlans\MembershipPlanResource;
use App\Filament\Resources\Memberships\MembershipResource;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MembershipPlan;
use App\Models\Product;
use App\Models\SaleLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Services in the new back office (App\Domain\Office\ServicesScreen): book and change appointments (and arrived,
 * no-show, cancel), void a pass, sign up a customer (and invoice, pause, resume, cancel), plans (save, archive),
 * commissions, and licence keys (load, hand out keys still owed, show, void) — each through the same service as the
 * current pages.
 */
class ServicesController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): ServicesScreen
    {
        $this->allowed($can);

        return new ServicesScreen($r->user());
    }

    // -------------------------------------------------------------------------------------------- Appointments and the calendar

    public function calendar(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ServicesScreen::canCalendar())->calendar($request->query()));
    }

    public function appointments(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ServicesScreen::canAppointments())->appointments($request->query()));
    }

    public function bookingForm(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, AppointmentResource::canCreate())->bookingForm((string) $request->query('branch', '') ?: null));
    }

    public function staff(Request $request): JsonResponse
    {
        return response()->json(['staff' => $this->screen($request, ServicesScreen::canAppointments())->staff((string) $request->query('branch', ''))]);
    }

    /** Customers by name or phone, for a booking or a membership. */
    public function customers(Request $request): JsonResponse
    {
        $this->screen($request, ServicesScreen::canAppointments() || ServicesScreen::canMemberships());
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        return response()->json(['results' => Customer::where('is_active', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
            ->orderBy('name')->limit(20)->get()->map(fn ($c) => ['id' => $c->id, 'label' => $c->name, 'detail' => $c->phone])->all()]);
    }

    public function book(Request $request, AppointmentService $svc): JsonResponse
    {
        $this->screen($request, AppointmentResource::canCreate());
        $d = $request->validate(['branch_id' => ['required', 'uuid'], 'product_id' => ['required', 'uuid'], 'employee_id' => ['required', 'uuid'], 'starts_at' => ['required', 'date'],
            'customer_id' => ['nullable', 'uuid'], 'customer_name' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:255']],
            ['branch_id.*' => 'Choose the branch.', 'product_id.*' => 'Choose the service.', 'employee_id.*' => 'Choose who will do it.', 'starts_at.*' => 'Enter the date and time.']);

        return $this->act(fn (Actor $actor) => ['id' => $svc->book($actor, new Appointment, array_filter($d, fn ($v) => $v !== null && $v !== ''))->id, 'message' => 'Booked.']);
    }

    /** arrived · no_show · change (time, who) · cancel (reason). */
    public function appointmentAction(Request $request, string $id, AppointmentService $svc): JsonResponse
    {
        $screen = $this->screen($request, ServicesScreen::canAppointments());
        $a = $screen->appointmentsQuery()->findOrFail($id);
        $act = $request->validate(['action' => ['required', 'in:arrived,no_show,change,cancel']])['action'];
        $d = $request->validate(match ($act) {
            'change' => ['starts_at' => ['required', 'date'], 'employee_id' => ['required', 'uuid']],
            'cancel' => ['reason' => ['required', 'string', 'max:255']],
            default => [],
        }, ['starts_at.*' => 'Enter the date and time.', 'employee_id.*' => 'Choose who will do it.', 'reason.*' => 'Say why.']);

        return $this->act(function (Actor $actor) use ($act, $d, $a, $svc) {
            match ($act) {
                'change' => $svc->book($actor, $a, $d),
                'cancel' => $svc->setStatus($actor, $a, 'cancelled', $d['reason']),
                default => $svc->setStatus($actor, $a, $act),
            };

            return ['message' => ['arrived' => 'Marked arrived.', 'no_show' => 'Marked no-show.', 'change' => 'Appointment changed.', 'cancel' => 'Cancelled.'][$act]];
        });
    }

    // -------------------------------------------------------------------------------------------- Prepaid passes

    public function passes(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ServicesScreen::canPasses())->passes($request->query()));
    }

    public function passHistory(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, ServicesScreen::canPasses());

        return response()->json($screen->passHistory($screen->passesQuery()->findOrFail($id)));
    }

    public function passVoid(Request $request, string $id, PassService $svc): JsonResponse
    {
        $screen = $this->screen($request, ServicesScreen::canPasses());
        $p = $screen->passesQuery()->findOrFail($id);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Say why.'])['reason'];

        return $this->act(fn (Actor $actor) => ['message' => $svc->void($actor, $p, $reason) ? 'Pass voided.' : '']);
    }

    // -------------------------------------------------------------------------------------------- Memberships and plans

    public function memberships(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ServicesScreen::canMemberships())->memberships($request->query()));
    }

    /** Sign up a customer: the first period is invoiced straight away. */
    public function enrol(Request $request, MembershipService $svc): JsonResponse
    {
        $this->screen($request, MembershipResource::canCreate());
        $d = $request->validate(['customer_id' => ['required', 'uuid'], 'membership_plan_id' => ['required', 'uuid'], 'branch_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'price' => ['nullable', 'numeric', 'min:0.01']],
            ['customer_id.*' => 'Choose the customer.', 'membership_plan_id.*' => 'Choose the plan.', 'branch_id.*' => 'Choose the branch it is invoiced from.']);

        return $this->act(fn (Actor $actor) => ['id' => $svc->enrol($actor, Customer::findOrFail($d['customer_id']), MembershipPlan::findOrFail($d['membership_plan_id']),
            Branch::findOrFail($d['branch_id']), $d['starts_on'], ($d['price'] ?? '') !== '' && $d['price'] !== null ? FinanceUi::toMinor($d['price']) : null)->id,
            'message' => 'Signed up: the first period is invoiced (Receivables → Invoices).']);
    }

    /** bill (invoice due periods) · pause (reason) · resume · cancel (reason). */
    public function membershipAction(Request $request, string $id, MembershipService $svc): JsonResponse
    {
        $screen = $this->screen($request, ServicesScreen::canMemberships());
        $m = $screen->membershipsQuery()->findOrFail($id);
        $act = $request->validate(['action' => ['required', 'in:bill,pause,resume,cancel']])['action'];
        $reason = in_array($act, ['pause', 'cancel'], true) ? $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Say why.'])['reason'] : '';
        if ($act === 'bill') {
            $this->allowed(config('erp.node.role') !== 'branch');
        }

        return $this->act(function (Actor $actor) use ($act, $m, $svc, $reason) {
            match ($act) {
                'bill' => $svc->billDue($actor, null, $m),
                'pause' => $svc->pause($actor, $m, $reason),
                'resume' => $svc->resume($actor, $m),
                default => $svc->cancel($actor, $m, $reason),
            };

            return ['message' => ['bill' => 'Invoiced.', 'pause' => 'Paused: no invoices until resumed.', 'resume' => 'Resumed.', 'cancel' => 'Cancelled.'][$act]];
        });
    }

    public function plans(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ServicesScreen::canPlans())->plans($request->query()));
    }

    public function planSave(Request $request, MembershipService $svc, ?string $id = null): JsonResponse
    {
        $this->screen($request, MembershipPlanResource::canCreate());
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'price' => ['required', 'numeric', 'min:0.01'], 'interval_months' => ['required', Rule::in(array_keys(MembershipService::INTERVALS))],
            'product_id' => ['required', 'uuid'], 'description' => ['nullable', 'string', 'max:2000'], 'is_active' => ['boolean'], 'items' => ['nullable', 'array', 'max:50'],
            'items.*.product_ids' => ['required', 'array', 'min:1'], 'items.*.product_ids.*' => ['uuid'], 'items.*.sessions' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'items.*.label' => ['nullable', 'string', 'max:120']],
            ['name.*' => 'Name the plan.', 'price.*' => 'Enter the price per period.', 'product_id.*' => 'Choose the product it is invoiced as.', 'items.*.product_ids.*' => 'Each item names the services it can be used for.']);
        $plan = $id ? MembershipPlan::findOrFail($id) : new MembershipPlan;
        $d['items'] = array_map(fn ($i) => $i + ['sessions' => null], $d['items'] ?? []);
        $d['description'] = ($d['description'] ?? '') ?: null;

        return $this->act(fn (Actor $actor) => ['id' => $svc->savePlan($actor, $plan, $d)->id, 'message' => 'Plan saved.']);
    }

    public function planArchive(Request $request, string $id, ArchiveService $svc): JsonResponse
    {
        $this->screen($request, ServicesScreen::canPlans());
        $p = MembershipPlan::findOrFail($id);
        $d = $request->validate(['restore' => ['boolean'], 'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);
        $this->allowed($svc->allowed($request->user(), $p));

        return $this->act(function (Actor $actor) use ($svc, $p, $d) {
            ($d['restore'] ?? false) ? $svc->restore($actor, $p) : $svc->archive($actor, $p, $d['reason']);

            return ['message' => ($d['restore'] ?? false) ? 'Restored.' : 'Archived.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Commissions, licence keys

    public function commissions(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ServicesScreen::canCommissions())->commissions($request->query()));
    }

    public function keys(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ServicesScreen::canKeys())->keys($request->query()));
    }

    public function keysLoad(Request $request, LicenceKeyService $svc): JsonResponse
    {
        $this->screen($request, ServicesScreen::canKeys());
        $d = $request->validate(['product_id' => ['required', 'uuid'], 'branch_id' => ['required', 'uuid'], 'keys' => ['required', 'string', 'max:500000']],
            ['product_id.*' => 'Choose the product.', 'branch_id.*' => 'Choose the branch.', 'keys.*' => 'Paste the keys, one per line.']);

        return $this->act(function (Actor $actor) use ($svc, $d) {
            $n = $svc->load($actor, Product::findOrFail($d['product_id']), Branch::findOrFail($d['branch_id']), $d['keys']);

            return ['message' => $n === 0 ? '0 keys loaded: they were all loaded before.' : "{$n} key(s) loaded."];
        });
    }

    /** Sales made while a product had no keys left: hand the missing keys out now (reprint the receipt). */
    public function keysOwed(Request $request, LicenceKeyService $svc): JsonResponse
    {
        $this->screen($request, ServicesScreen::canKeys());
        $owed = DB::table('sale_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('p.licence_keys', true)->where('l.qty', '>', 0)
            ->whereRaw('(SELECT COUNT(*) FROM licence_keys k WHERE k.sale_line_id = l.id) < FLOOR(l.qty)')->pluck('l.id');

        return $this->act(function (Actor $actor) use ($svc, $owed) {
            $n = 0;
            foreach ($owed as $lineId) {
                $n += $svc->handOutLater($actor, SaleLine::findOrFail($lineId));
            }

            return ['message' => "{$n} key(s) handed out."];
        });
    }

    public function keyShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, ServicesScreen::canKeys());

        return response()->json(['key' => $screen->keysQuery()->findOrFail($id)->licence_key]);
    }

    public function keyVoid(Request $request, string $id, LicenceKeyService $svc): JsonResponse
    {
        $screen = $this->screen($request, ServicesScreen::canKeys());
        $k = $screen->keysQuery()->findOrFail($id);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Say why.'])['reason'];

        return $this->act(fn (Actor $actor) => ['message' => $svc->void($actor, $k, $reason) ? 'Key voided.' : '']);
    }
}
