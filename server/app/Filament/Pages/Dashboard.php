<?php

namespace App\Filament\Pages;

use App\Domain\Access\AccessService;
use App\Filament\Support\Erp;
use App\Support\Dashboard\Panels;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;

/**
 * The home page (interface stage 1b): the person's own panels — figures, charts, tables, what waits for them,
 * shortcuts — in their order and widths, else their role's starting set. "Arrange" switches panels on and off, drags
 * them into order and sets their width; "Back to my role's" undoes it. A panel shows only when the person may see it.
 */
class Dashboard extends BaseDashboard
{
    public function getSubheading(): ?string
    {
        return 'Your panels. Arrange them as you like: what shows, in which order and how wide.';
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'md' => 12];
    }

    public function getWidgets(): array
    {
        return array_map(function (array $p) {
            $span = Panels::SPANS[$p['size']];

            return Panels::CATALOGUE[$p['key']][0]::make(['columnSpan' => ['default' => 1, 'md' => $span]]);
        }, Panels::layoutFor(Erp::user()));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('arrange')->label('Arrange')->icon('heroicon-o-squares-plus')->color('gray')
                ->modalHeading('Arrange your dashboard')
                ->modalDescription('Switch panels on or off, drag them into order (⇅) and choose how wide each is. Only panels you may see are listed.')
                ->modalWidth(Width::TwoExtraLarge)->modalSubmitActionLabel('Save my dashboard')
                ->fillForm(fn () => ['panels' => $this->arrangeRows()])
                ->schema([
                    Repeater::make('panels')->hiddenLabel()->reorderable()->addable(false)->deletable(false)->collapsible(false)
                        ->itemLabel(fn (array $state) => Panels::CATALOGUE[$state['key'] ?? '-'][1] ?? '')
                        ->schema([
                            Hidden::make('key'),
                            Grid::make(2)->schema([
                                Toggle::make('shown')->label('Show')->inline(false),
                                Select::make('size')->label('Width')->options(Panels::SIZES)->selectablePlaceholder(false),
                            ]),
                        ]),
                ])
                ->action(function (array $data) {
                    $rows = collect($data['panels'] ?? [])->where('shown', true)->map(fn ($r) => ['key' => $r['key'], 'size' => $r['size']])->values()->all();
                    Erp::run(fn ($actor) => app(AccessService::class)->updateOwnDashboard($actor, Erp::user(), $rows), 'Your dashboard is saved');
                    $this->redirect(static::getUrl());
                }),
            Action::make('resetDashboard')->label('Back to my role\'s')->icon('heroicon-o-arrow-uturn-left')->color('gray')->link()
                ->visible(fn () => Erp::user()?->dashboard !== null)
                ->requiresConfirmation()->modalHeading('Back to your role\'s dashboard?')
                ->modalDescription('Your own arrangement is forgotten and the panels for your role come back.')
                ->action(function () {
                    Erp::run(fn ($actor) => app(AccessService::class)->updateOwnDashboard($actor, Erp::user(), null), 'Back to your role\'s dashboard');
                    $this->redirect(static::getUrl());
                }),
        ];
    }

    /** The panels now shown (in order), then every other panel the person may see, switched off. */
    private function arrangeRows(): array
    {
        $shown = Panels::layoutFor(Erp::user());
        $keys = array_column($shown, 'key');
        $rows = array_map(fn ($p) => ['key' => $p['key'], 'shown' => true, 'size' => $p['size']], $shown);
        foreach (Panels::CATALOGUE as $key => [, , , $size]) {
            if (! in_array($key, $keys, true) && Panels::canView($key)) {
                $rows[] = ['key' => $key, 'shown' => false, 'size' => $size];
            }
        }

        return $rows;
    }
}
