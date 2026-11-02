<?php

namespace App\Filament\Widgets\Panels;

use App\Support\Look;
use Filament\Widgets\Widget;

/** Dashboard: the person's favourite pages (the star) and quick links to create the records they may create. */
class Shortcuts extends Widget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected string $view = 'filament.widgets.shortcuts';

    /** @return list<array{url: string, label: string}> */
    public function favourites(): array
    {
        return Look::favourites();
    }

    /** @return list<array{url: string, label: string}> the first few "+ New" records, most useful first */
    public function creates(): array
    {
        $preferred = ['Sale', 'Customer', 'Product', 'Purchase order', 'Purchase request', 'Stock adjustment', 'Expense claim', 'Supplier'];
        $all = collect(Look::quickCreate())->flatten(1);

        return $all->sortBy(fn ($i) => ($p = array_search($i['label'], $preferred, true)) === false ? 99 : $p)->take(6)->values()->all();
    }

    public static function canView(): bool
    {
        return true;
    }
}
