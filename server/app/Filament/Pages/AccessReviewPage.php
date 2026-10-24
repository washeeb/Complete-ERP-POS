<?php

namespace App\Filament\Pages;

use App\Domain\Access\AccessReview;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Erp;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Who can do what (owner request 2026-10-24): the access review for the owner — risky combinations first (one person
 * ordering and receiving, adding suppliers and paying them…), with what to do about each, then everyone's roles and
 * where they apply. Read only; access is changed under People & access → Users.
 */
class AccessReviewPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'People & access';

    protected static ?string $navigationLabel = 'Who can do what';

    protected static ?string $title = 'Who can do what';

    protected static ?string $slug = 'access-review';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.access-review';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('audit.view');
    }

    public function getSubheading(): ?string
    {
        return 'Risky combinations of rights, and everyone\'s access. To change someone\'s access, open them under Users.';
    }

    public function review(): array
    {
        return app(AccessReview::class)->build(Erp::user()->tenant_id);
    }

    public function userUrl(string $id): ?string
    {
        return UserResource::canViewAny() ? UserResource::getUrl('edit', ['record' => $id]) : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label('Download (CSV)')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(function () {
                    $rows = app(AccessReview::class)->rows($this->review());

                    return response()->streamDownload(function () use ($rows) {
                        $out = fopen('php://output', 'w');
                        fputcsv($out, array_keys($rows->first() ?? ['Person' => '']), ',', '"', '');
                        foreach ($rows as $r) {
                            fputcsv($out, array_values($r), ',', '"', '');
                        }
                        fclose($out);
                    }, 'who-can-do-what-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
                }),
        ];
    }
}
