<?php

namespace App\Filament\Support;

use App\Domain\Org\ArchiveService;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Archive and Restore on the lists of industries, legal entities and branches (owner 2026-11-08). The list shows the
 * ones in use unless the person asks for the archived ones; the Archive button says what must happen first when it
 * cannot be archived yet.
 */
final class ArchiveUi
{
    public static function column(): TextColumn
    {
        return TextColumn::make('archived_at')->label('Status')->badge()
            ->state(fn (Model $r) => $r->archived_at ? 'Archived' : 'In use')
            ->color(fn (string $state) => $state === 'Archived' ? 'gray' : 'success')
            ->tooltip(fn (Model $r) => $r->archived_at
                ? 'Archived '.$r->archived_at->format('j M Y').(($by = User::find($r->archived_by)?->name) ? " by {$by}" : '').($r->archive_reason ? ": {$r->archive_reason}" : '')
                : null);
    }

    /** In use (the default), archived, or both. */
    public static function filter(): TernaryFilter
    {
        return TernaryFilter::make('archived')->label('Show')->placeholder('Both')
            ->trueLabel('Archived only')->falseLabel('In use')->default(false)
            ->queries(true: fn (Builder $q) => $q->whereNotNull('archived_at'), false: fn (Builder $q) => $q->whereNull('archived_at'), blank: fn (Builder $q) => $q);
    }

    public static function archive(string $what): Action
    {
        return Action::make('archive')->label('Archive')->icon('heroicon-o-archive-box')->color('gray')
            ->visible(fn (Model $r) => ! $r->archived_at && Erp::can('setup.company.manage'))
            ->modalHeading(fn (Model $r) => "Archive {$r->name}?")
            ->modalDescription(fn (Model $r) => ($why = app(ArchiveService::class)->blockers($r))
                ? 'It cannot be archived yet. '.implode(' ', $why)
                : "It leaves every list people choose from, so nothing new is given this {$what}. Its history stays: past sales, stock, books and the audit log keep showing it. You can restore it at any time.")
            ->modalSubmitAction(fn (Action $a, Model $r) => $a->disabled((bool) app(ArchiveService::class)->blockers($r)))
            ->schema([TextInput::make('reason')->label('Why')->required()->minLength(3)->maxLength(255)
                ->placeholder('e.g. created by mistake, branch closed')
                ->visible(fn (?Model $record) => $record && ! app(ArchiveService::class)->blockers($record))])
            ->action(fn (Model $r, array $data) => Erp::run(fn ($actor) => app(ArchiveService::class)->archive($actor, $r, $data['reason']), 'Archived'));
    }

    public static function restore(): Action
    {
        return Action::make('restore')->label('Restore')->icon('heroicon-o-arrow-uturn-left')->color('gray')
            ->visible(fn (Model $r) => (bool) $r->archived_at && Erp::can('setup.company.manage'))
            ->requiresConfirmation()->modalHeading(fn (Model $r) => "Restore {$r->name}?")
            ->modalDescription('It comes back into the lists people choose from. For a branch, the stores and tills archived with it are switched back on; enrol its devices again to trade.')
            ->action(fn (Model $r) => Erp::run(fn ($actor) => app(ArchiveService::class)->restore($actor, $r), 'Restored'));
    }
}
