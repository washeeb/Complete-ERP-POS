<?php

use App\Http\Middleware\BlockSiteCopiers;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
});

it('refuses website copiers, keeps the site out of search engines, and slows down crawling', function () {
    foreach (['Mozilla/4.5 (compatible; HTTrack 3.0x; Windows 98)', 'Wget/1.21.4', 'Cyotek WebCopy/1.9', 'Offline Explorer/8.4'] as $agent) {
        $this->get('/admin/login', ['User-Agent' => $agent])->assertForbidden();
    }
    $this->get('/admin/login', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/141.0'])->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    expect(file_get_contents(public_path('robots.txt')))->toContain('Disallow: /');

    // A crawler that hides its name: after the limit it is slowed down; tills and the API are not affected.
    for ($i = 0; $i < BlockSiteCopiers::GUEST_PER_MINUTE; $i++) {
        $this->get('/admin/login');
    }
    $this->get('/admin/login')->assertStatus(429);
    $this->get('/up')->assertOk();

    // A signed-in person is never slowed down.
    expect($this->actingAs($this->owner)->get('/admin')->status())->not->toBe(429);
});
