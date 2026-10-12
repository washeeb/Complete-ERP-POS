<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Hr\HrService;
use App\Domain\Hr\PayrollService;
use App\Domain\Pos\ReturnService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Services\AppointmentService;
use App\Domain\Services\CommissionService;
use App\Domain\Services\LicenceKeyService;
use App\Domain\Services\MembershipService;
use App\Domain\Services\PassService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\CommissionReport;
use App\Filament\Pages\StaffCalendar;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\LicenceKeys\LicenceKeyResource;
use App\Filament\Resources\MembershipPlans\MembershipPlanResource;
use App\Filament\Resources\Memberships\MembershipResource;
use App\Filament\Resources\ServicePasses\ServicePassResource;
use App\Models\Appointment;
use App\Models\Commission;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\LicenceKey;
use App\Models\MembershipPlan;
use App\Models\Payslip;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServicePass;
use App\Models\Till;
use App\Security\ScopeType;
use App\Support\Qty;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    enableModule($this->owner, $this->hq, 'services');
    $this->o = actorFor($this->owner);
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register($this->o, ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $this->till->id);
    $this->stylist = staff($this->owner, 'abla', 2, 'service_staff', ScopeType::Branch, $this->hq->id);
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;

    $catalog = app(CatalogService::class);
    $service = function (string $sku, string $name, string $price, int $minutes) use ($catalog) {
        $p = makeProduct($this->owner, $sku, $name, retail: $price);

        return $catalog->saveProduct($this->o, $p, ['track_stock' => false, 'service_minutes' => $minutes])->fresh();
    };
    $this->cut = $service('CUT', 'Haircut', '80.00', 45);
    $this->massage = $service('MASSAGE', 'Massage 1 h', '200.00', 60);
    // A pack: 5 massages for 800, valid 90 days.
    $this->pack = $catalog->saveProduct($this->o, makeProduct($this->owner, 'PACK-5M', '5 massages', retail: '800.00'),
        ['track_stock' => false, 'pack' => ['items' => [['product_ids' => [$this->massage->id], 'sessions' => 5]], 'valid_days' => 90]])->fresh();

    $hr = app(HrService::class);
    $emp = fn (string $name, array $extra = []) => $hr->saveEmployee($this->o, new Employee, $extra + ['name' => $name, 'branch_id' => $this->hq->id, 'hire_date' => now()->subYear()->toDateString(),
        'employment_type' => 'permanent', 'pay_method' => 'cash', 'takes_appointments' => true]);
    $this->abla = $emp('Abla Stylist', ['commission_bp' => 1000, 'basic_salary_minor' => 150000, 'user_id' => $this->stylist->id]);
    $this->yaw = $emp('Yaw Therapist', ['commission_bp' => 2000]); // commission only, no salary
    $this->ama = app(CustomerService::class)->save($this->o, new Customer, ['name' => 'Ama Owusu', 'phone' => '0241112222']);
});

/** A till sale; each item is [product, qty, extra line fields]; a pass line is priced at 0. */
function servicesSale($t, array $items, array $extra = []): Sale
{
    $lines = [];
    $engine = [];
    foreach ($items as $i => $it) {
        [$p, $qty] = $it;
        $unit = $p->units->first();
        $pass = $it[2]['service_pass_id'] ?? null;
        $price = $pass ? 0 : app(PriceResolver::class)->resolve($t->retail, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
        $engine[] = ['key' => (string) $i, 'product_id' => $pass ? "pass:{$pass}" : $p->id, 'category_id' => null, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price,
            'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null];
        $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price] + ($it[2] ?? []);
    }
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    foreach ($calc['lines'] as $i => $r) {
        $lines[$i]['line_total_minor'] = $r['total'];
    }
    $payments = $calc['total'] > 0 ? [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]] : [];

    return app(SaleService::class)->post($t->device, $t->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id, 'lines' => $lines, 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => $payments] + $extra)['sale'];
}

function servicesReturn($t, Sale $sale, int $lineIndex, string $qty, int $refund): Sale
{
    $ret = app(ReturnService::class);
    $line = collect($ret->returnable($sale->fresh('lines', 'payments'))['lines'])->values()[$lineIndex];

    return $ret->post($t->device, $t->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(), 'shift_id' => $t->shift->id,
        'original_sale_id' => $sale->id, 'reason' => 'Changed mind', 'lines' => [['original_line_id' => $line['id'], 'qty' => $qty]],
        'refunds' => $refund > 0 ? [['method' => 'cash', 'amount_minor' => $refund]] : []])['sale'];
}

it('books appointments in staff calendars without double-booking, and the till sale marks them done with commission', function () {
    $svc = app(AppointmentService::class);
    $s = actorFor($this->stylist);
    $at = now()->addDay()->setTime(10, 0);
    $a = $svc->book($s, new Appointment, ['branch_id' => $this->hq->id, 'employee_id' => $this->abla->id, 'product_id' => $this->cut->id, 'starts_at' => $at->toIso8601String(),
        'customer_id' => $this->ama->id]);
    expect($a->number)->toStartWith('APT-')->and($a->ends_at->diffInMinutes($a->starts_at, true))->toEqual(45)->and($a->customer_name)->toBe('Ama Owusu')
        ->and($a->phone)->toBe('+233241112222');
    // Abla is busy 10:00–10:45; Yaw is free.
    expect(fn () => $svc->book($s, new Appointment, ['branch_id' => $this->hq->id, 'employee_id' => $this->abla->id, 'product_id' => $this->cut->id,
        'starts_at' => $at->copy()->addMinutes(30)->toIso8601String(), 'customer_name' => 'Kofi']))->toThrow(DomainException::class, 'Abla Stylist is booked');
    $other = $svc->book($s, new Appointment, ['branch_id' => $this->hq->id, 'employee_id' => $this->yaw->id, 'product_id' => $this->massage->id,
        'starts_at' => $at->copy()->addMinutes(30)->toIso8601String(), 'customer_name' => 'Kofi']);
    expect(fn () => $svc->setStatus($s, $other, 'no_show'))->toThrow(DomainException::class, 'too early')
        ->and(fn () => $svc->book(actorFor($this->cashier), new Appointment, ['branch_id' => $this->hq->id, 'employee_id' => $this->yaw->id, 'product_id' => $this->cut->id,
            'starts_at' => $at->copy()->addHours(3)->toIso8601String(), 'customer_name' => 'X']))->toThrow(AccessDenied::class);
    $svc->setStatus($s, $other, 'cancelled', 'Called to cancel');
    // The calendar slot is free again.
    $svc->book($s, new Appointment, ['branch_id' => $this->hq->id, 'employee_id' => $this->yaw->id, 'product_id' => $this->massage->id,
        'starts_at' => $at->copy()->addMinutes(30)->toIso8601String(), 'customer_name' => 'Esi']);

    $svc->setStatus($s, $a->fresh(), 'arrived');
    $sale = servicesSale($this, [[$this->cut, '1', ['appointment_id' => $a->id]]], ['customer_id' => $this->ama->id]);
    $line = $sale->lines->first();
    expect($a->fresh()->status)->toBe('done')->and($a->fresh()->sale_id)->toBe($sale->id)->and($line->staff_id)->toBe($this->abla->id) // the appointment's staff member
        ->and(Commission::sole()->amount_minor)->toBe(intdiv($line->net_minor * 1000 + 5000, 10000))->and(Commission::sole()->employee_id)->toBe($this->abla->id);
    expect(fn () => servicesSale($this, [[$this->cut, '1', ['appointment_id' => $a->id]]]))->toThrow(DomainException::class, 'no longer open');

    // A product's own rate beats the person's; a return takes the commission back.
    app(CatalogService::class)->saveProduct($this->o, $this->massage, ['commission_bp' => 500]);
    $sale2 = servicesSale($this, [[$this->massage->fresh(), '1', ['staff_id' => $this->yaw->id]]]);
    $c = Commission::where('sale_id', $sale2->id)->sole();
    expect($c->rate_bp)->toBe(500);
    servicesReturn($this, $sale2, 0, '1', $sale2->total_minor);
    expect((int) Commission::where('employee_id', $this->yaw->id)->sum('amount_minor'))->toBe(0)
        ->and(AppointmentService::day($this->hq->id, $at->toDateString(), 'UTC'))->toHaveCount(2); // cancelled ones are left out
});

it('sells a prepaid pack as a pass, counts sessions down at the till, and handles returns of sessions and packs', function () {
    $sale = servicesSale($this, [[$this->pack, '1']], ['customer_id' => $this->ama->id]);
    $pass = ServicePass::sole();
    expect($pass->number)->toStartWith('PASS-')->and($pass->customer_id)->toBe($this->ama->id)->and(PassService::remaining($pass))->toBe([5])
        ->and($pass->expires_on->toDateString())->toBe(now()->addDays(90)->toDateString())
        ->and($pass->session_value_minor)->toBe(intdiv($sale->lines->first()->net_minor + 2, 5));
    // A pack belongs to someone.
    expect(fn () => servicesSale($this, [[$this->pack, '1']]))->toThrow(DomainException::class, 'choose the customer');

    // Two sessions used, by Yaw (commission on the session value).
    $use = servicesSale($this, [[$this->massage, '2', ['service_pass_id' => $pass->id, 'pass_item' => 0, 'staff_id' => $this->yaw->id]]], ['customer_id' => $this->ama->id]);
    expect($use->total_minor)->toBe(0)->and(PassService::remaining($pass->fresh()))->toBe([3])
        ->and(Commission::where('sale_id', $use->id)->sole()->base_minor)->toBe(2 * $pass->session_value_minor);
    // Only the customer's own, only for the services it covers, and never more than is left.
    $kofi = app(CustomerService::class)->save($this->o, new Customer, ['name' => 'Kofi', 'phone' => '0201234567']);
    expect(fn () => servicesSale($this, [[$this->massage, '1', ['service_pass_id' => $pass->id, 'pass_item' => 0]]], ['customer_id' => $kofi->id]))->toThrow(DomainException::class, 'another customer')
        ->and(fn () => servicesSale($this, [[$this->cut, '1', ['service_pass_id' => $pass->id, 'pass_item' => 0]]], ['customer_id' => $this->ama->id]))->toThrow(DomainException::class, 'does not cover')
        ->and(fn () => servicesSale($this, [[$this->massage, '4', ['service_pass_id' => $pass->id, 'pass_item' => 0]]], ['customer_id' => $this->ama->id]))->toThrow(DomainException::class, '3 session(s) left');

    // A session handed back; a pack with sessions used cannot come back at the till; an unused one is voided.
    servicesReturn($this, $use, 0, '1', 0);
    expect(PassService::remaining($pass->fresh()))->toBe([4])
        ->and(fn () => servicesReturn($this, $sale, 0, '1', $sale->total_minor))->toThrow(DomainException::class, 'already used');
    $sale2 = servicesSale($this, [[$this->pack, '1']], ['customer_id' => $this->ama->id]);
    expect(PassService::forCustomer($this->ama->id))->toHaveCount(2);
    servicesReturn($this, $sale2, 0, '1', $sale2->total_minor);
    expect(ServicePass::where('sale_id', $sale2->id)->sole()->status)->toBe('void')->and(PassService::forCustomer($this->ama->id))->toHaveCount(1);

    app(PassService::class)->void($this->o, $pass->fresh(), 'Refunded by bank transfer');
    expect(PassService::forCustomer($this->ama->id))->toBe([]);
    app(AutoPoster::class)->run($this->tenant->id);
    expect(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('bills memberships by recurring invoice with the period sessions as a pass; pauses and cancels', function () {
    $gymProduct = app(CatalogService::class)->saveProduct($this->o, makeProduct($this->owner, 'MEMBER', 'Membership', retail: '0.00'), ['track_stock' => false])->fresh();
    $svc = app(MembershipService::class);
    $plan = $svc->savePlan($this->o, new MembershipPlan, ['code' => 'spa-gold', 'name' => 'Spa Gold', 'product_id' => $gymProduct->id, 'price' => '300.00', 'interval_months' => 1,
        'items' => [['product_ids' => [$this->massage->id], 'sessions' => 2], ['product_ids' => [$this->cut->id], 'sessions' => null, 'label' => 'Haircuts']]]);
    expect($plan->code)->toBe('SPA-GOLD')->and($plan->items[1]['sessions'])->toBeNull()
        ->and(fn () => $svc->savePlan($this->o, new MembershipPlan, ['code' => 'X', 'name' => 'X', 'product_id' => $this->pack->id, 'price' => '0']))->toThrow(DomainException::class);

    $start = now()->startOfMonth();
    $m = $svc->enrol($this->o, $this->ama, $plan, $this->hq, $start->toDateString());
    $inv = Invoice::where('membership_id', $m->id)->sole();
    expect($m->number)->toStartWith('MEM-')->and($inv->total_minor)->toBe(30000)->and($inv->customer_ref)->toBe($m->number)
        ->and($m->next_bill_on->toDateString())->toBe($start->copy()->addMonth()->toDateString())
        ->and(fn () => $svc->enrol($this->o, $this->ama, $plan, $this->hq))->toThrow(DomainException::class, 'already a member');
    $pass = ServicePass::where('membership_id', $m->id)->sole();
    expect(PassService::remaining($pass))->toBe([2, null])->and($pass->expires_on->toDateString())->toBe($start->copy()->addMonth()->subDay()->toDateString());
    // Unlimited haircuts: used without counting down.
    servicesSale($this, [[$this->cut, '1', ['service_pass_id' => $pass->id, 'pass_item' => 1]]], ['customer_id' => $this->ama->id]);
    expect(PassService::remaining($pass->fresh()))->toBe([2, null]);

    // Nothing due yet; two months on, two periods are caught up.
    expect($svc->billDue($this->o))->toBe(0)
        ->and($svc->billDue($this->o, $start->copy()->addMonths(2)->toDateString()))->toBe(2)
        ->and(Invoice::where('membership_id', $m->id)->count())->toBe(3)->and(ServicePass::where('membership_id', $m->id)->count())->toBe(3);
    $m = $svc->pause($this->o, $m->fresh(), 'Travelling');
    expect($svc->billDue($this->o, $start->copy()->addMonths(5)->toDateString()))->toBe(0);
    $m = $svc->resume($this->o, $m);
    $m = $svc->cancel($this->o, $m, 'Moved away');
    expect($m->status)->toBe('cancelled')->and($m->ends_on->toDateString())->toBe($m->next_bill_on->copy()->subDay()->toDateString())
        ->and(fn () => $svc->pause(actorFor($this->cashier), $m, 'x'))->toThrow(AccessDenied::class);

    config(['erp.node.role' => 'branch']); // head office bills; branch servers never do
    expect($svc->billDue($this->o, now()->addYear()->toDateString()))->toBe(0);
    config(['erp.node.role' => 'hq']);
    app(AutoPoster::class)->run($this->tenant->id);
    expect(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('pays commission through payroll, including commission-only staff', function () {
    servicesSale($this, [[$this->massage, '1', ['staff_id' => $this->yaw->id]], [$this->cut, '1', ['staff_id' => $this->abla->id]]]);
    $yawOwed = CommissionService::total($this->yaw->id, now()->startOfMonth(), now()->endOfMonth(), 'UTC');
    expect($yawOwed)->toBeGreaterThan(0);
    $run = app(PayrollService::class)->prepare($this->o, (int) now()->year, (int) now()->month);
    $yaw = Payslip::where('payroll_run_id', $run->id)->where('employee_id', $this->yaw->id)->sole();
    $abla = Payslip::where('payroll_run_id', $run->id)->where('employee_id', $this->abla->id)->sole();
    expect($yaw->commission_minor)->toBe($yawOwed)->and(collect($yaw->lines)->firstWhere('code', 'COMMISSION')['amount_minor'])->toBe($yawOwed)
        ->and($yaw->gross_minor)->toBe($yawOwed)->and($abla->commission_minor)->toBe((int) Commission::where('employee_id', $this->abla->id)->sum('amount_minor'))
        ->and(CommissionService::summary(now()->toDateString(), now()->toDateString(), 'UTC'))->toHaveCount(2);
});

it('hands out a licence key per unit sold, prints it, refuses a sale with no keys left and takes keys back on return', function () {
    $sw = app(CatalogService::class)->saveProduct($this->o, makeProduct($this->owner, 'AV-1Y', 'Antivirus 1 year', retail: '150.00'), ['track_stock' => false, 'licence_keys' => true])->fresh();
    expect(fn () => app(CatalogService::class)->saveProduct($this->o, makeProduct($this->owner, 'AV-2', 'AV', retail: '1.00'), ['licence_keys' => true]))->toThrow(DomainException::class, 'Track stock');
    $keys = app(LicenceKeyService::class);
    expect($keys->load($this->o, $sw, $this->hq, "AAAA-1111-BBBB\nCCCC-2222-DDDD\nAAAA-1111-BBBB\n"))->toBe(2)
        ->and($keys->load($this->o, $sw, $this->hq, 'CCCC-2222-DDDD'))->toBe(0); // already loaded

    $sale = servicesSale($this, [[$sw, '2']]);
    expect(collect(LicenceKeyService::forSale($sale->id))->pluck('key')->all())->toBe(['AAAA-1111-BBBB', 'CCCC-2222-DDDD'])
        ->and(LicenceKey::where('status', 'available')->count())->toBe(0)
        ->and(fn () => servicesSale($this, [[$sw, '1']]))->toThrow(DomainException::class, 'no licence keys left');
    servicesReturn($this, $sale, 0, '1', 15000);
    expect(LicenceKey::where('status', 'returned')->count())->toBe(1)->and(LicenceKey::where('status', 'available')->count())->toBe(0); // never resold
});

it('shows the services screens', function () {
    app(AppointmentService::class)->book(actorFor($this->stylist), new Appointment, ['branch_id' => $this->hq->id, 'employee_id' => $this->abla->id, 'product_id' => $this->cut->id,
        'starts_at' => now()->addDay()->setTime(11, 0)->toIso8601String(), 'customer_name' => 'Akosua Mensah']);
    servicesSale($this, [[$this->cut, '1', ['staff_id' => $this->abla->id]]]);
    $this->actingAs(withMfa($this->owner));
    foreach ([AppointmentResource::getUrl('index'), MembershipPlanResource::getUrl('index'), MembershipResource::getUrl('index'), ServicePassResource::getUrl('index'),
        LicenceKeyResource::getUrl('index'), CommissionReport::getUrl()] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get(StaffCalendar::getUrl(['date' => now()->addDay()->toDateString(), 'branchId' => $this->hq->id]))->assertOk()->assertSee('Akosua Mensah')->assertSee('Abla Stylist');
    $this->get(AppointmentResource::getUrl('index'))->assertSee('Akosua Mensah');
    $this->get(CommissionReport::getUrl())->assertSee('Abla Stylist');
});
