<?php

use App\Filament\Resources\Shifts\Pages\ListShifts;
use App\Filament\Support\CleanTextColumn;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Livewire\Livewire;

// Owner request 2026-11-03: lists keep one line per row; breakdowns and long text go into the hover tip.
it('keeps every list column on one line with the details on hover', function () {
    $user = new User(['name' => 'AKOSUA MANSA ASANTEWAA OFORI-ATTA BOATENG']);

    $column = TextColumn::make('name')->description(fn () => 'Cash GH₵ 20.00 · MTN MoMo GH₵ 5.00')->wrap()->tooltip('Checked');
    $column->record($user);
    expect($column)->toBeInstanceOf(CleanTextColumn::class)
        ->and($column->canWrap())->toBeFalse()
        ->and($column->getDescriptionBelow())->toBeNull()
        ->and($column->formatState($user->name))->toBe('AKOSUA MANSA ASANTEWAA OFORI-ATTA BOATEN...')
        ->and($column->getTooltip($user->name))->toBe('AKOSUA MANSA ASANTEWAA OFORI-ATTA BOATENG · Cash GH₵ 20.00 · MTN MoMo GH₵ 5.00 · Checked');

    // Copying a shortened value copies all of it.
    expect($column->copyable()->getCopyableState($user->name))->toBe($user->name);

    // Names: one line, shortened, the full name on hover.
    $person = TextColumn::make('name')->asPerson('id');
    $person->record($user);
    expect($person->formatState($user->name))->toBe('AKOSUA MANSA ASANTEWAA O...')
        ->and($person->getTooltip($user->name))->toBe($user->name);

    // A short value with nothing more to say has no tip.
    $plain = TextColumn::make('name');
    $plain->record(new User(['name' => 'ESI']));
    expect($plain->getTooltip('ESI'))->toBeNull();
});

it('explains failed requests itself, quietly for a single missed background refresh', function () {
    [, $owner] = installCompany();
    setUpBranches($owner);
    $this->actingAs(withMfa($owner), 'web');

    Livewire::test(ListShifts::class)->assertSuccessful();
    config(['app.debug' => false]); // in debug mode Livewire's own error window shows instead
    $this->get('/admin/shifts')->assertOk()
        ->assertSee('Connected again')
        ->assertSee('misses < 2', false)
        ->assertDontSee('window.filamentErrorNotifications = {', false);
});
