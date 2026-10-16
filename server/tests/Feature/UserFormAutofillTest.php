<?php

use App\Filament\Resources\Users\UserResource;
use Filament\Facades\Filament;

it('keeps the browser from filling a saved sign-in into the new-user form', function () {
    [, $owner] = installCompany();
    setUpBranches($owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $html = $this->actingAs(withMfa($owner), 'web')->get(UserResource::getUrl('create'))->assertOk()->getContent();
    expect($html)->toContain('autocomplete="new-password" id="form.password"')
        ->toContain('autocomplete="off" id="form.username"')->toContain('autocomplete="off" id="form.email"');
});
