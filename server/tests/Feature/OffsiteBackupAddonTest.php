<?php

use App\Domain\Backup\Destinations;
use App\Domain\Licensing\LicenseManager;
use App\Filament\Pages\Backups;
use App\Models\BackupDestination;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    $this->license = function (?array $features) {
        activateTestLicense(['features' => $features]);
        LicenseManager::forget();
    };
});

it('keeps folders free and makes cloud and remote copies the paid add-on', function () {
    ($this->license)(['finance', 'hr']);
    expect(Destinations::allowed('folder'))->toBeTrue()
        ->and(Destinations::allowed('s3'))->toBeFalse()->and(Destinations::allowed('gdrive'))->toBeFalse()->and(Destinations::allowed('sftp'))->toBeFalse();

    ($this->license)(['finance', 'hr', 'offsite_backup']);
    expect(Destinations::allowed('s3'))->toBeTrue()->and(Destinations::allowed('sftp'))->toBeTrue();

    ($this->license)(null); // "all standard features" does not include an add-on
    expect(Destinations::allowed('s3'))->toBeFalse();
});

it('refuses a new cloud destination without the add-on, and says why', function () {
    ($this->license)(['finance', 'hr']);
    Livewire::test(Backups::class)->callAction('add', ['name' => 'Backblaze', 'type' => 's3', 'keep' => 7, 'is_active' => true,
        'config' => ['s3' => ['endpoint' => 'https://s3.example.test', 'region' => 'x', 'bucket' => 'b', 'prefix' => '', 'key' => 'k', 'secret' => 's']]])
        ->assertNotified('Not saved: off-site backups are a paid add-on');
    expect(BackupDestination::count())->toBe(0);
});

it('keeps cloud copies a client set up before the add-on existed', function () {
    ($this->license)(['finance', 'hr']);
    $old = BackupDestination::create(['name' => 'Old SFTP', 'type' => 'sftp', 'config' => ['host' => 'x'], 'keep' => 3, 'is_active' => true, 'grandfathered' => true]);
    $new = BackupDestination::create(['name' => 'New S3', 'type' => 's3', 'config' => ['bucket' => 'x'], 'keep' => 3, 'is_active' => true]);
    expect(Destinations::allowed('sftp', $old))->toBeTrue()
        ->and(Destinations::allowed('s3', $new))->toBeFalse()
        ->and(Destinations::allowed('s3', $old))->toBeFalse(); // only the kind it already was
});
