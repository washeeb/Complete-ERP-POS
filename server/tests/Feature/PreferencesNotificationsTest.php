<?php

use App\Domain\Access\AccessService;
use App\Domain\Messaging\Notifier;
use App\Filament\Pages\IntegrationsPage;
use App\Http\Responses\HomeLoginResponse;
use App\Support\NotifyPrefs;
use App\Support\UserPrefs;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('keeps only allowed preferences and opens the chosen home page after signing in', function () {
    $this->actingAs(withMfa($this->owner));
    app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $this->owner->fresh(),
        ['ui_prefs' => ['home' => 'today', 'rows' => '50', 'theme' => 'dark', 'date' => 'iso', 'density' => 'compact', 'evil' => 'x']]);
    expect($this->owner->fresh()->ui_prefs)->toBe(['home' => 'today', 'rows' => 50, 'theme' => 'dark', 'date' => 'iso', 'density' => 'compact']);

    $this->actingAs(withMfa($this->owner->fresh()));
    expect(UserPrefs::dateFormat())->toBe('Y-m-d')
        ->and((new HomeLoginResponse)->toResponse(tap(Request::create('/'), fn ($r) => $r->setUserResolver(fn () => $this->owner->fresh())))->getTargetUrl())->toEndWith('/admin/today');
    $this->get('/admin/sales')->assertOk()->assertSee('zx-compact', false)->assertSee("'dark'", false);

    // Unknown values fall back to the standard; a page that is not a favourite cannot be home.
    app(AccessService::class)->updateOwnProfile(actorFor($this->owner), $this->owner->fresh(), ['ui_prefs' => ['home' => '/admin/users', 'rows' => 7, 'date' => 'roman']]);
    expect($this->owner->fresh()->ui_prefs)->toMatchArray(['home' => 'dashboard', 'rows' => 10, 'date' => 'words']);
});

it('lets a person turn notification topics and channels off, never security notices', function () {
    $keeper = staff($this->owner, 'yaw', 4, 'warehouse_manager');
    $notifier = app(Notifier::class);
    expect($notifier->toPermission($this->tenant->id, 'stock.view', null, 'Low stock', topic: 'stock'))->toBeGreaterThanOrEqual(2);

    app(AccessService::class)->updateOwnProfile(actorFor($keeper), $keeper, ['notify_prefs' => ['stock' => ['in_app' => false], 'summary' => ['email' => false], 'made_up' => ['x' => true]]]);
    $keeper = $keeper->fresh();
    expect($keeper->notify_prefs)->not->toHaveKey('made_up')
        ->and(NotifyPrefs::wants($keeper, 'stock'))->toBeFalse()
        ->and(NotifyPrefs::wants($keeper, 'summary', 'email'))->toBeFalse()
        ->and(NotifyPrefs::wants($keeper, 'summary', 'in_app'))->toBeTrue()
        ->and(NotifyPrefs::wants($keeper, 'security'))->toBeTrue();

    $before = DB::table('notifications')->where('notifiable_id', $keeper->id)->count();
    $notifier->toPermission($this->tenant->id, 'stock.view', null, 'Low stock again', topic: 'stock');
    $notifier->toPermission($this->tenant->id, 'stock.view', null, 'Support signed in', topic: 'security');
    expect(DB::table('notifications')->where('notifiable_id', $keeper->id)->count())->toBe($before + 1); // only the security notice
});

it('shows the profile page with its preferences and notifications', function () {
    $this->actingAs(withMfa($this->owner));
    $this->get('/admin/profile')->assertOk()->assertSee('Preferences')->assertSee('Rows a list shows at first')
        ->assertSee('Notifications')->assertSee('Waiting for your approval')->assertSee('Payments received');
});

it('shows Integrations only when the licence names the add-on, with each connection\'s state', function () {
    $this->actingAs(withMfa($this->owner));
    expect(IntegrationsPage::canAccess())->toBeFalse();
    $this->get('/admin/integrations')->assertForbidden();

    activateTestLicense(['features' => ['integrations']]);
    DB::table('messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'channel' => 'sms', 'to' => '0240000000',
        'body' => 'x', 'status' => 'failed', 'attempts' => 3, 'sent_by_name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('message_providers')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'channel' => 'sms', 'provider' => 'arkesel',
        'credentials' => encrypt('{}'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs(withMfa($this->owner->fresh()));
    expect(IntegrationsPage::canAccess())->toBeTrue();
    $this->get('/admin/integrations')->assertOk()->assertSee('GRA E-VAT')->assertSee('Mobile money')->assertSee('Branch servers')
        ->assertSee('Needs attention')->assertSee('Through Arkesel');
});
