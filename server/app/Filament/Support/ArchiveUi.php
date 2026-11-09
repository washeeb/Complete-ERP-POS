<?php

namespace App\Filament\Support;

use App\Domain\Org\ArchiveRules;
use App\Domain\Org\ArchiveService;
use App\Models\Branch;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Archive and Restore on the lists of everything that is archived instead of deleted (owner 2026-11-08/09; the kinds
 * are in ArchiveRules). Lists show the ones in use unless the person asks for the archived ones; the Archive button
 * says what must happen first when it cannot be archived yet. Add to a table: ArchiveUi::column(), ->filters([…,
 * ArchiveUi::filter()]) and ->recordActions([…, ...ArchiveUi::actions()]).
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
            ->queries(true: fn (Builder $q) => $q->whereNotNull($q->qualifyColumn('archived_at')),
                false: fn (Builder $q) => $q->whereNull($q->qualifyColumn('archived_at')), blank: fn (Builder $q) => $q);
    }

    /** Adds the status column, the In use / Archived filter and the Archive and Restore buttons to a finished list. */
    public static function apply(Table $table): Table
    {
        return $table->pushColumns([self::column()])->pushFilters([self::filter()])->pushRecordActions(self::actions());
    }

    /** Archive and Restore together. */
    public static function actions(): array
    {
        return [self::archive(), self::restore()];
    }

    /** The record's name for the dialogs: its name, else its code or number. */
    private static function title(Model $r): string
    {
        return (string) ($r->name ?? $r->title ?? $r->code ?? $r->number ?? $r->username ?? ArchiveRules::of($r)[0]);
    }

    public static function archive(?string $what = null): Action
    {
        return Action::make('archive')->label('Archive')->icon('heroicon-o-archive-box')->color('gray')
            ->visible(fn (Model $r) => ! $r->archived_at && app(ArchiveService::class)->allowed(Erp::user(), $r))
            ->modalHeading(fn (Model $r) => 'Archive '.self::title($r).'?')
            ->modalDescription(fn (Model $r) => ($why = app(ArchiveService::class)->blockers($r))
                ? 'It cannot be archived yet. '.implode(' ', $why)
                : 'It leaves every list people choose from, so nothing new is given this '.($what ?? ArchiveRules::of($r)[0]).'. Its history stays: '
                    .'past sales, stock, books and the audit log keep showing it. You can restore it at any time.')
            ->modalSubmitAction(fn (Action $a, Model $r) => $a->disabled((bool) app(ArchiveService::class)->blockers($r)))
            ->schema([TextInput::make('reason')->label('Why')->required()->minLength(3)->maxLength(255)
                ->placeholder('e.g. created by mistake, no longer used')
                ->visible(fn (?Model $record) => $record && ! app(ArchiveService::class)->blockers($record))])
            ->action(fn (Model $r, array $data) => Erp::run(fn ($actor) => app(ArchiveService::class)->archive($actor, $r, $data['reason']), 'Archived'));
    }

    public static function restore(): Action
    {
        return Action::make('restore')->label('Restore')->icon('heroicon-o-arrow-uturn-left')->color('gray')
            ->visible(fn (Model $r) => (bool) $r->archived_at && app(ArchiveService::class)->allowed(Erp::user(), $r))
            ->requiresConfirmation()->modalHeading(fn (Model $r) => 'Restore '.self::title($r).'?')
            ->modalDescription(fn (Model $r) => match (ArchiveRules::of($r)[3]) {
                'kept' => 'It comes back into the lists people choose from. It stays switched off (not for sale, not running) until you switch it on.',
                default => 'It comes back into the lists people choose from.',
            }.($r instanceof Branch ? ' The stores and tills archived with it come back too; enrol its devices again to trade.' : ''))
            ->action(fn (Model $r) => Erp::run(fn ($actor) => app(ArchiveService::class)->restore($actor, $r), 'Restored'));
    }
}
