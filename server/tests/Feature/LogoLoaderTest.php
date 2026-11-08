<?php

use App\Support\Loader;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
});

it('inlines the ZionEX loader at the size asked, from the one source file', function () {
    $svg = Loader::svg(30);
    expect($svg)->toContain('class="zx-loader"')->toContain('width="30" height="30"')->not->toContain('<!--')
        ->and(substr_count($svg, '<rect'))->toBe(9)
        ->and(file_get_contents(public_path('brand/loader.svg')))->toBe(file_get_contents(base_path('../branding/product/loader.svg')));
});

it('shows the loader while a page opens, on the sign-in page and inside the back office', function () {
    $this->get('/admin/login')->assertOk()->assertSee('id="zx-page-loader"', false);
    $this->actingAs(withMfa($this->owner), 'web')->get('/admin')->assertOk()
        ->assertSee('id="zx-page-loader"', false)->assertSee('id="ses-progress"', false);
});
