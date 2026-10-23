<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Messaging\ProviderSetup;
use App\Domain\Pharmacy\RefillReminders;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Support\DomainException;
use App\Filament\Resources\RefillReminders\Pages\ListRefillReminders;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Message;
use App\Models\PriceList;
use App\Models\RefillReminder;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->o = actorFor($this->owner);
    enableModule($this->owner, $this->hq, 'pharmacy');
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->amlo = makeProduct($this->owner, 'AMLO5', 'Amlodipine 5mg', retail: '1.00');
    $this->amlo->forceFill(['daily_use' => '1'])->save(); // one tablet a day
    app(InventoryService::class)->adjust($this->o, $this->wh, $this->amlo, $this->amlo->units->first(), '500', 'opening', '', null, '0.50');
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register($this->o, ['branch_id' => $this->hq->id, 'till_id' => $till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $till->id);
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;
    $this->ama = app(CustomerService::class)->save($this->o, new Customer, ['name' => 'Ama Owusu', 'phone' => '0241112222', 'consents' => ['sms' => true]]);
    $this->kofi = app(CustomerService::class)->save($this->o, new Customer, ['name' => 'Kofi Mensah', 'phone' => '0201234567']); // never agreed to texts
    app(Settings::class)->set($this->o, 'refill.reminders', true);
    Http::fake(['sms.arkesel.com/*' => Http::response(['status' => 'success', 'data' => [['id' => 'a-1']]])]);
    app(ProviderSetup::class)->save($this->o, 'sms', ['provider' => 'arkesel', 'sender_id' => 'Pharmacy', 'credentials' => ['api_key' => 'k'], 'is_active' => true]);
});

function refillSale($t, Customer $customer, string $qty): void
{
    $unit = $t->amlo->units->first();
    $price = app(PriceResolver::class)->resolve($t->retail, $t->amlo->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
    $calc = PromotionEngine::compute(['lines' => [['key' => '0', 'product_id' => $t->amlo->id, 'category_id' => null, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price,
        'tax' => $t->amlo->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null]],
        'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    app(SaleService::class)->post($t->device, $t->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id, 'customer_id' => $customer->id,
        'lines' => [['product_id' => $t->amlo->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price, 'line_total_minor' => $calc['lines'][0]['total']]],
        'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]]);
}

it('reminds a customer who agreed to texts a few days before a daily medicine runs out, once', function () {
    $this->travelTo(Carbon::parse('2026-10-01 10:00', 'Africa/Accra'));
    refillSale($this, $this->ama, '30'); // 30 tablets, one a day: runs out 31 Oct, reminder 28 Oct
    refillSale($this, $this->kofi, '30');
    $svc = app(RefillReminders::class);
    expect($svc->run())->toBe(['made' => 2, 'sent' => 0]);
    $r = RefillReminder::where('customer_id', $this->ama->id)->sole();
    expect($r->runs_out_on->toDateString())->toBe('2026-10-31')->and($r->remind_on->toDateString())->toBe('2026-10-28')->and($r->status)->toBe('waiting');

    $this->travelTo(Carbon::parse('2026-10-28 08:00', 'Africa/Accra'));
    expect($svc->run()['sent'])->toBe(0); // before 09:00
    $this->travelTo(Carbon::parse('2026-10-28 09:15', 'Africa/Accra'));
    expect($svc->run()['sent'])->toBe(1)->and($svc->run()['sent'])->toBe(0) // once
        ->and($r->fresh()->status)->toBe('sent')->and($r->fresh()->channel)->toBe('sms')
        ->and(RefillReminder::where('customer_id', $this->kofi->id)->value('status'))->toBe('no_contact');
    $msg = Message::where('template_key', 'refill_reminder')->sole();
    expect($msg->body)->toContain('AMA OWUSU')->toContain('AMLODIPINE 5MG')->toContain('31 Oct');
});

it('replaces the reminder when the medicine is bought again, and lets staff send now or cancel', function () {
    $this->travelTo(Carbon::parse('2026-10-01 10:00', 'Africa/Accra'));
    refillSale($this, $this->ama, '30');
    $svc = app(RefillReminders::class);
    $svc->run();
    $this->travelTo(Carbon::parse('2026-10-20 10:00', 'Africa/Accra'));
    refillSale($this, $this->ama, '60'); // came back early with a bigger supply
    $svc->run();
    expect(RefillReminder::where('customer_id', $this->ama->id)->orderBy('created_at')->pluck('status')->all())->toBe(['bought', 'waiting']);
    $new = RefillReminder::where('status', 'waiting')->sole();
    expect($new->runs_out_on->toDateString())->toBe('2026-12-19');

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListRefillReminders::class)->assertCanSeeTableRecords([$new])->callTableAction('send', $new)->assertNotified('Reminder sent');
    expect($new->fresh()->status)->toBe('sent')->and(AuditEvent::where('action', 'refill_reminder.sent')->count())->toBe(1);
    expect(fn () => $svc->cancel($this->o, $new->fresh()))->toThrow(DomainException::class, 'Only a waiting reminder');
});

it('does nothing while the setting is off, and never for a medicine without a daily figure', function () {
    app(Settings::class)->set($this->o, 'refill.reminders', false);
    refillSale($this, $this->ama, '30');
    expect(app(RefillReminders::class)->run())->toBe(['made' => 0, 'sent' => 0]);
    app(Settings::class)->set($this->o, 'refill.reminders', true);
    $this->amlo->forceFill(['daily_use' => null])->save();
    expect(app(RefillReminders::class)->run()['made'])->toBe(0)->and(RefillReminder::count())->toBe(0);
});
