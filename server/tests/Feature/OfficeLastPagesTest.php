<?php

use App\Models\Branch;
use App\Models\Department;
use App\Models\LegalEntity;
use App\Models\PayrollProfile;
use App\Models\Tenant;
use App\Security\ScopeType;
use Illuminate\Http\UploadedFile;

// The last pages of the new back office (part 38): departments, the tax and SSNIT rules, and the first-run setup wizard.

it('keeps departments: new, rename, archive and restore; refused to people without HR', function () {
    [, $owner] = installCompany();
    $b = setUpBranches($owner);
    $hrm = withMfa(staff($owner, 'abena', 4, 'hr_manager'));
    $this->actingAs($hrm, 'web');
    $id = $this->postJson('/office-api/departments', ['name' => 'Accounts'])->assertOk()->json('id');
    $this->postJson('/office-api/departments', ['name' => ''])->assertStatus(422);
    $code = Department::find($id)->code;
    $this->postJson("/office-api/departments/{$id}", ['name' => 'Finance and accounts'])->assertOk();
    expect(Department::find($id)->only(['code', 'name']))->toBe(['code' => $code, 'name' => 'Finance and accounts']); // the code never changes
    $row = collect($this->getJson('/office-api/departments')->assertOk()->json('rows'))->firstWhere('id', $id);
    expect($row)->toMatchArray(['name' => 'Finance and accounts', 'can_edit' => true, 'archived' => false, 'can_archive' => true]);
    $this->postJson("/office-api/departments/{$id}/archive", ['reason' => 'x'])->assertStatus(422);
    $this->postJson("/office-api/departments/{$id}/archive", ['reason' => 'Merged into Finance'])->assertOk();
    expect(collect($this->getJson('/office-api/departments')->json('rows'))->pluck('id'))->not->toContain($id)
        ->and(collect($this->getJson('/office-api/departments?show=archived')->json('rows'))->pluck('id')->all())->toBe([$id]);
    $this->postJson("/office-api/departments/{$id}/archive", ['restore' => true])->assertOk();
    expect(Department::find($id)->archived_at)->toBeNull();

    $this->actingAs(withMfa(staff($owner, 'efua', 2, 'teller', ScopeType::Branch, $b['HQ']->id)), 'web');
    $this->getJson('/office-api/departments')->assertForbidden();
    $this->getJson('/office-api/payroll-rules')->assertForbidden();
});

it('shows the tax and SSNIT rules, has them checked and confirmed, and exports them', function () {
    [, $owner] = installCompany();
    setUpBranches($owner);
    $fm = withMfa(staff($owner, 'kwame', 5, 'finance_manager'));
    $this->actingAs($fm, 'web');
    $r = $this->getJson('/office-api/payroll-rules')->assertOk()->json();
    expect($r['can_verify'])->toBeTrue()->and($r['profile']['verified'])->toBeNull()->and($r['profile']['bands'][0]['band'])->toStartWith('First GHS ')
        ->and(end($r['profile']['bands'])['band'])->toStartWith('Above GHS ')->and($r['profile']['note'])->toContain('SSNIT: employee');
    $v = $r['profile']['values'];
    expect(end($v['bands'])['width'])->toBe('');

    $bad = $v;
    $bad['bands'][0]['width'] = '';
    $this->postJson('/office-api/payroll-rules/verify', $bad)->assertStatus(422); // only the last band is open-ended
    $v['employee'] = '5.5';
    $this->postJson('/office-api/payroll-rules/verify', $v)->assertOk();
    $p = PayrollProfile::sole();
    expect($p->verified_by_name)->toBe('Kwame')->and($p->rules['ssnit']['employee_bp'])->toBe(550)
        ->and($p->rules['ssnit']['tier2_bp'])->toBe(550 + $p->rules['ssnit']['employer_bp'] - $p->rules['ssnit']['tier1_bp'])
        ->and($this->getJson('/office-api/payroll-rules')->json('profile.verified'))->toStartWith('Checked by Kwame on ');
    $this->get('/office-api/payroll-rules/export?format=csv')->assertOk();

    $this->actingAs(withMfa(staff($owner, 'abena', 4, 'hr_manager')), 'web'); // prepares payroll, does not approve it
    expect($this->getJson('/office-api/payroll-rules')->json('can_verify'))->toBeFalse();
    $this->postJson('/office-api/payroll-rules/verify', $v)->assertForbidden();
});

it('sets the company up once: the wizard, its brand, and the new back office sending the owner there first', function () {
    [$tenant, $owner] = installCompany();
    withMfa($owner);
    $this->actingAs($owner, 'web');
    expect($this->getJson('/office-api/me')->json('setup'))->toBe('/office/setup-wizard');
    $w = $this->getJson('/office-api/setup-wizard')->assertOk()->json();
    expect($w['done'])->toBeFalse()->and($w['values']['branches'])->not->toBe([])->and(collect($w['modes'])->pluck('id')->all())->toBe(['cloud', 'central', 'standalone'])
        ->and($w['timezones'])->toContain('Africa/Accra');

    $data = $w['values'];
    $data['company']['name'] = 'Adom Mart';
    $data['legal_entity'] = ['name' => 'Adom Mart Enterprise', 'tin' => 'C0001234567', 'vat_no' => ''];
    $data['brand'] = ['color' => '#B5471B', 'receipt_logo' => false];
    $data['branches'] = [['name' => 'Head Office', 'is_hq' => true, 'tills' => 1, 'modules' => []], ['name' => 'Kumasi shop', 'is_hq' => false, 'tills' => 2, 'modules' => []]];
    $two = $data;
    $two['branches'][1]['is_hq'] = true;
    $this->post('/office-api/setup-wizard', ['data' => json_encode($two)], ['Accept' => 'application/json'])->assertStatus(422)
        ->assertJsonPath('detail', 'Mark exactly one branch as head office (HQ).');

    $logo = UploadedFile::fake()->image('logo.png', 300, 100);
    $res = $this->post('/office-api/setup-wizard', ['data' => json_encode($data), 'logo' => $logo], ['Accept' => 'application/json'])->assertOk()->json();
    expect($res['open'])->toBe('/office/')->and($res)->not->toHaveKey('brand_problem');
    $tenant = Tenant::find($tenant->id);
    expect($tenant->isSetUp())->toBeTrue()->and($tenant->name)->toBe('Adom Mart')->and(Branch::count())->toBe(2)
        ->and(LegalEntity::sole()->tin)->toBe('C0001234567')
        ->and($this->getJson('/office-api/me')->json('setup'))->toBeNull()
        ->and($this->getJson('/office-api/setup-wizard')->json('done'))->toBeTrue();
    $this->post('/office-api/setup-wizard', ['data' => json_encode($data)], ['Accept' => 'application/json'])->assertStatus(422); // only once
});
