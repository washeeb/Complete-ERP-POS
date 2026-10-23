<?php

use App\Domain\Access\SupportAccount;
use App\Domain\Support\SystemHealth;
use App\Mail\PlainMessage;
use App\Models\BackupRun;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
});

it('tells the owner once a day when a backup failed, on the bell and by e-mail', function () {
    Mail::fake();
    BackupRun::create(['file_name' => 'b1.zip', 'status' => 'failed', 'error' => 'disk full', 'started_by' => 'scheduler', 'started_at' => now()->subHours(2)]);

    $this->artisan('erp:health-watch')->assertSuccessful();
    $n = $this->owner->notifications()->where('data->title', 'The server needs attention')->sole();
    expect($n->data['body'])->toContain('Backups');
    Mail::assertSent(PlainMessage::class, fn ($m) => $m->hasTo($this->owner->email) && str_contains($m->text, 'Backups'));

    $this->artisan('erp:health-watch')->assertSuccessful(); // the same problem again today: not told twice
    expect($this->owner->notifications()->where('data->title', 'The server needs attention')->count())->toBe(1);
});

it('lists load and memory on Linux servers, and nothing about them elsewhere', function () {
    $checks = collect(app(SystemHealth::class)->run())->pluck('check');
    expect(is_readable('/proc/meminfo') ? $checks->contains('Memory') : ! $checks->contains('Memory'))->toBeTrue();
});

it('lets the company switch the SyncLytics support account off and on', function () {
    [$support] = app(SupportAccount::class)->ensure($this->tenant, 'support@synclytics.test', 'SyncLytics Support', 'a-long-support-password');
    $panel = Filament::getPanel('admin');
    expect($support->canAccessPanel($panel))->toBeTrue();
    app(Settings::class)->set(actorFor($this->owner), 'security.support_account', false);
    expect($support->fresh()->canAccessPanel($panel))->toBeFalse()->and($this->owner->canAccessPanel($panel))->toBeTrue();
});
