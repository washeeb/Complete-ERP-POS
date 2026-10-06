<?php

use App\Filament\Resources\Promotions\Pages\CreatePromotion;
use App\Filament\Resources\Promotions\Pages\EditPromotion;
use App\Filament\Resources\Promotions\PromotionResource;
use App\Filament\Resources\StoredValues\Pages\ListStoredValues;
use App\Filament\Resources\StoredValues\StoredValueResource;
use App\Models\Promotion;
use App\Models\StoredValue;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
});

it('sets up a buy-2-get-1-free and a coupon from the screen, as people write them', function () {
    Livewire::test(CreatePromotion::class)->fillForm(['name' => 'Milo 3 for 2', 'type' => 'buy_x_get_y', 'buy_qty' => 2, 'get_qty' => 1, 'percent' => '100',
        'product_ids' => [$this->milo->id], 'days' => [5, 6], 'time_from' => '16:00', 'time_to' => '18:00', 'is_active' => true])->call('create')->assertHasNoFormErrors();
    Livewire::test(CreatePromotion::class)->fillForm(['name' => 'Back to school', 'type' => 'coupon', 'coupon_code' => 'school5', 'amount' => '5', 'min_spend' => '50', 'is_active' => true])
        ->call('create')->assertHasNoFormErrors();

    $bogo = Promotion::where('type', 'buy_x_get_y')->sole();
    $coupon = Promotion::where('type', 'coupon')->sole();
    expect($bogo->value_bp)->toBe(10000)->and($bogo->days)->toBe([5, 6])->and($coupon->coupon_code)->toBe('SCHOOL5')
        ->and($coupon->value_minor)->toBe(500)->and($coupon->min_spend_minor)->toBe(5000);

    $this->get(PromotionResource::getUrl())->assertOk()->assertSee('Buy 2 get 1 free')->assertSee('Code SCHOOL5')->assertSee('16:00–18:00');
    Livewire::test(EditPromotion::class, ['record' => $coupon->id])->assertFormSet(['amount' => '5.00', 'min_spend' => '50.00'])
        ->fillForm(['amount' => '7.5'])->call('save')->assertHasNoFormErrors();
    expect($coupon->fresh()->value_minor)->toBe(750);
});

it('refuses a promotion without its numbers', function () {
    Livewire::test(CreatePromotion::class)->fillForm(['name' => 'Half done', 'type' => 'bundle', 'bundle_qty' => 3, 'is_active' => true])->call('create')->assertNotified();
    expect(Promotion::count())->toBe(0);
});

it('sells a gift card from the screen and shows its balance and history', function () {
    Livewire::test(ListStoredValues::class)->callTableAction('sell', null, ['amount' => '100', 'paid_by' => 'momo', 'reference' => 'MP123'])->assertNotified();
    $card = StoredValue::sole();
    $this->get(StoredValueResource::getUrl())->assertOk()->assertSee($card->code)->assertSee('100.00');
});

it('keeps promotions and gift cards from people without access', function () {
    $this->actingAs(withMfa(staff($this->owner, 'esi', 2, 'cashier', ScopeType::Branch, $this->branches['HQ']->id)));
    $this->get(PromotionResource::getUrl('create'))->assertForbidden();
    $this->get(StoredValueResource::getUrl())->assertForbidden();
});
