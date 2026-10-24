<?php

use App\Filament\Pages\ManageSettings;
use App\Support\Settings;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** The Settings page as a short list of groups with a search (owner request 2026-10-24). */
it('finds a setting by any word of its name or explanation, and saving while searching leaves the others alone', function () {
    [$tenant, $owner] = installCompany();
    $this->actingAs(withMfa($owner));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app(Settings::class)->set(actorFor($owner), 'receipt.footer', 'Thank you, come again');

    Livewire::test(ManageSettings::class)->assertSee('Tills and cash')->assertSee('setting(s)')
        ->set('find', 'mobile money')->assertSee('How the till takes mobile money')->assertDontSee('Message at the bottom of receipts')
        ->set('data.momo__confirm', 'prompt')->call('save')->assertNotified('Settings saved');

    // Leaving with changes not saved asks first: Settings remembers what was saved, and every edit page carries the warning.
    $page = Livewire::test(ManageSettings::class);
    expect($page->get('savedDataHash'))->toBe(md5((string) str(json_encode($page->get('data'), JSON_UNESCAPED_UNICODE))->replace('\\', '')));
    expect(Filament::getPanel('admin')->hasUnsavedChangesAlerts())->toBeTrue()->and(Filament::getPanel('vendor')->hasUnsavedChangesAlerts())->toBeTrue();

    expect(app(Settings::class)->get('momo.confirm', $tenant->id))->toBe('prompt')
        ->and(app(Settings::class)->get('receipt.footer', $tenant->id))->toBe('Thank you, come again'); // hidden while searching: unchanged
});
