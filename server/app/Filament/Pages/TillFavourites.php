<?php

namespace App\Filament\Pages;

use App\Http\Controllers\Office\TillFavouritesController;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Sales → Till favourites (till new look, part 1b): the menu entry. The page is the new back office's
 * (/office/till-favourites); this class puts it in the menu, for whoever may change a branch's till settings.
 */
class TillFavourites extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Till favourites';

    protected static ?string $title = 'Till favourites';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'till-favourites';

    protected string $view = 'filament.pages.office-page';

    public static function canAccess(): bool
    {
        return TillFavouritesController::can();
    }
}
