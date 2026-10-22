<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\PanelBrand;
use App\Models\AuditEvent;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\Till;
use App\Security\ScopeType;
use Filament\Facades\Filament;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('says why a page cannot be opened, who can give access, and sends an access request to them', function () {
    $clerk = staff($this->owner, 'clerk', 2);
    $page = $this->actingAs($clerk, 'web')->get(UserResource::getUrl());
    $page->assertForbidden()->assertSee('You can’t open this page', false)->assertSee('View users and their access')->assertSee($this->owner->name)
        ->assertSee('Ask for access');

    $this->from(UserResource::getUrl())->post(route('access.request'), ['path' => '/admin/users', 'permission' => 'admin.users.view', 'note' => 'For the new staff'])
        ->assertRedirect();
    $n = $this->owner->notifications()->sole();
    expect($n->data['title'])->toBe('Clerk asks for access')->and($n->data['body'])->toContain('View users and their access')->toContain('For the new staff')
        ->and(AuditEvent::where('action', 'access.requested')->exists())->toBeTrue();
});

it('puts a menu search at the top of the side menu', function () {
    $this->actingAs(withMfa($this->owner), 'web')->get('/admin/sales')->assertOk()->assertSee('id="ses-menu-q"', false)->assertSee('Search the menu');
});

it('shows the company of the industry, not the group, on the till and in the back office', function () {
    $entity = LegalEntity::primary();
    $entity->forceFill(['name' => 'Asante Pharmacy Ltd'])->save();
    enableModule($this->owner, $this->branches['HQ'], 'pharmacy');
    $industry = Industry::where('module_key', 'pharmacy')->firstOrFail();
    $industry->forceFill(['legal_entity_id' => $entity->id])->save();
    $this->branches['HQ']->forceFill(['industry_id' => $industry->id, 'legal_entity_id' => null])->save();

    $till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    [, $code] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->branches['HQ']->id, 'till_id' => $till->id, 'name' => 'Till', 'kind' => 'pos']);
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $cashier, '4826');
    $token = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->json('token');
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/pos/bootstrap')->assertOk()
        ->assertJsonPath('company.name', 'Asante Pharmacy Ltd')->assertJsonPath('company.group_name', $this->tenant->name);

    $pharmacist = staff($this->owner, 'ama', 4, 'branch_manager', ScopeType::Branch, $this->branches['HQ']->id);
    $this->actingAs($pharmacist, 'web');
    expect(PanelBrand::industryCompany($pharmacist))->toBe('Asante Pharmacy Ltd') // works in the pharmacy only
        ->and(PanelBrand::industryCompany($this->owner))->toBeNull(); // sees every industry: the group name stays
});
