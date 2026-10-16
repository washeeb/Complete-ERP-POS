<?php

use App\Models\Role;
use App\Security\RoleCatalog;

it('has the pharmacy and clinic roles at their security levels; only pharmacists dispense', function () {
    installCompany();
    $roles = Role::whereIn('key', ['superintendent_pharmacist', 'pharmacist', 'pharmacy_technician', 'medicine_counter_assistant', 'doctor',
        'physician_assistant', 'nurse', 'midwife', 'lab_technician'])->with('permissions')->get()->keyBy('key');
    expect($roles)->toHaveCount(9)
        ->and($roles->map->security_level->all())->toMatchArray(['superintendent_pharmacist' => 4, 'pharmacist' => 4, 'doctor' => 3, 'physician_assistant' => 3,
            'nurse' => 2, 'midwife' => 2, 'medicine_counter_assistant' => 2, 'lab_technician' => 2, 'pharmacy_technician' => 2])
        ->and($roles['medicine_counter_assistant']->name)->toBe('Medicine Counter Assistant (MCA)');
    $dispensers = $roles->filter(fn ($r) => $r->permissions->pluck('key')->contains('pharmacy.dispense'))->keys()->sort()->values()->all();
    expect($dispensers)->toBe(['pharmacist', 'superintendent_pharmacist']);
});

it('offers the clinic roles only where Pharmacy runs', function () {
    [, $owner] = installCompany();
    setUpBranches($owner, [['code' => 'RT', 'name' => 'Retail', 'is_hq' => true, 'tills' => 1, 'modules' => ['grocery']]]);
    expect(RoleCatalog::hiddenKeys())->toContain('nurse', 'midwife', 'doctor', 'medicine_counter_assistant', 'superintendent_pharmacist');
});
