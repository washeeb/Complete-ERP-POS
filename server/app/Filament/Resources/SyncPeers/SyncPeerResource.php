<?php

namespace App\Filament\Resources\SyncPeers;

use App\Domain\Support\Recorder;
use App\Domain\Sync\Replication;
use App\Domain\Sync\SyncEngine;
use App\Domain\Sync\SyncFile;
use App\Filament\Resources\SyncPeers\Pages\ListSyncPeers;
use App\Filament\Support\Erp;
use App\Models\OutboxEvent;
use App\Models\SyncPeer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Sync Center (spec §4.2): status per peer, queues, errors, and the manual controls. */
class SyncPeerResource extends Resource
{
    protected static ?string $model = SyncPeer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Sync Center';

    protected static ?string $modelLabel = 'sync peer';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return Erp::can('sync.manage');
    }

    public static function canCreate(): bool
    {
        return false; // pairing is done with the "Add branch server" action
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function unsent(SyncPeer $p): int
    {
        return OutboxEvent::withoutGlobalScopes()->where('tenant_id', $p->tenant_id)->where('origin_node', (string) config('erp.node.id'))
            ->where('seq', '>', $p->role === 'hq' ? $p->last_pushed_seq : 0)->whereIn('sync_group', $p->groupsWeSend())
            ->when($p->role !== 'hq', fn ($q) => $q->whereRaw('1 = 0')) // HQ does not track what branches pulled; branches report it
            ->count();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->description(fn (SyncPeer $r) => "Node {$r->node_id} · ".($r->role === 'hq' ? 'head office' : 'branch server')),
                TextColumn::make('last_success_at')->label('Last sync')->since()->placeholder('never'),
                TextColumn::make('status')->badge()->state(fn (SyncPeer $r) => ! $r->is_active ? 'Disabled' : ($r->last_error ? 'Error' : ($r->last_success_at ? 'OK' : 'Waiting')))
                    ->color(fn (string $state) => match ($state) {
                        'OK' => 'success', 'Error' => 'danger', default => 'gray'
                    }),
                TextColumn::make('queues')->label('Waiting')->state(fn (SyncPeer $r) => implode(' · ', array_filter([
                    ($n = self::unsent($r)) ? "{$n} to send" : null,
                    ($n = $r->inbox()->where('status', 'staged')->count()) ? "{$n} to review" : null,
                    ($n = $r->inbox()->where('status', 'skipped')->count()) ? "{$n} held (group off)" : null,
                    ($n = $r->inbox()->where('status', 'failed')->count()) ? "{$n} failed" : null,
                ])) ?: 'nothing'),
                TextColumn::make('last_error')->label('Last error')->wrap()->limit(120)->placeholder('—')->color('danger'),
            ])
            ->recordActions([
                Action::make('sync')->label('Sync now')->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (SyncPeer $r) => $r->base_url && $r->is_active)
                    ->action(function (SyncPeer $record) {
                        $s = app(SyncEngine::class)->run($record);
                        $s['error']
                            ? Notification::make()->danger()->title('Sync failed')->body($s['error'])->persistent()->send()
                            : Notification::make()->success()->title("Sent {$s['pushed']}, received {$s['pulled']}")
                                ->body("Applied {$s['applied']}, waiting for review {$s['staged']}, failed {$s['failed']}.")->send();
                    }),
                Action::make('review')->label('Review incoming')->icon(Heroicon::OutlinedEye)
                    ->visible(fn (SyncPeer $r) => $r->inbox()->whereIn('status', ['staged', 'skipped'])->exists())
                    ->modalHeading('Changes waiting to be applied')
                    ->modalDescription('Changes for groups set to Manual (or Off) are kept here until you decide. Accepting applies them in the order they happened.')
                    ->schema(fn (SyncPeer $record) => [
                        Text::make(new HtmlString('<ul>'.$record->inbox()->whereIn('status', ['staged', 'skipped'])
                            ->selectRaw('sync_group, event_type, count(*) as n')->groupBy('sync_group', 'event_type')->get()
                            ->map(fn ($r) => '<li><strong>'.e(Replication::GROUPS[$r->sync_group]['label'] ?? $r->sync_group).'</strong>: '.e($r->n).' × '.e($r->event_type).'</li>')
                            ->implode('').'</ul>')),
                        Select::make('group')->label('Group')->required()
                            ->options(fn () => $record->inbox()->whereIn('status', ['staged', 'skipped'])->distinct()->pluck('sync_group')
                                ->mapWithKeys(fn ($g) => [$g => Replication::GROUPS[$g]['label'] ?? $g])),
                        Select::make('decision')->required()->options(['accept' => 'Apply these changes', 'reject' => 'Reject (keep the record, never apply)']),
                    ])
                    ->action(function (SyncPeer $record, array $data) {
                        $ids = $record->inbox()->whereIn('status', ['staged', 'skipped'])->where('sync_group', $data['group'])->pluck('id')->all();
                        $n = app(SyncEngine::class)->decide($record, $ids, $data['decision'] === 'accept', Erp::user()->id);
                        Notification::make()->success()->title(($data['decision'] === 'accept' ? 'Applied ' : 'Rejected ').$n.' change(s)')->send();
                    }),
                ActionGroup::make([
                    Action::make('modes')->label('Choose what syncs')->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                        ->fillForm(fn (SyncPeer $r) => $r->groups)
                        ->schema(fn (SyncPeer $record) => array_map(fn ($g) => Select::make($g)->label(Replication::GROUPS[$g]['label'])
                            ->options(SyncPeer::MODES)->default('auto')->required()
                            ->helperText('Automatic: applied at each sync. Manual: kept for you to preview and accept. Off: kept but not applied.'),
                            $record->groupsWeReceive()))
                        ->action(function (SyncPeer $record, array $data) {
                            $record->groups = array_merge($record->groups ?? [], array_intersect_key($data, array_flip($record->groupsWeReceive())));
                            Erp::run(fn ($actor) => app(Recorder::class)->save($actor, $record, 'sync.modes_changed'), 'Saved');
                        }),
                    Action::make('export')->label('Export sync file (USB)')->icon(Heroicon::OutlinedArrowDownTray)
                        ->action(fn (SyncPeer $record) => response()->streamDownload(fn () => print (app(SyncFile::class)->export($record)),
                            'sync-'.config('erp.node.id').'-to-'.$record->node_id.'-'.now()->format('Ymd-His').'.erpsync')),
                    Action::make('toggle')->label(fn (SyncPeer $r) => $r->is_active ? 'Disable this peer' : 'Enable this peer')
                        ->icon(Heroicon::OutlinedPower)->color(fn (SyncPeer $r) => $r->is_active ? 'danger' : 'success')
                        ->requiresConfirmation()
                        ->modalDescription(fn (SyncPeer $r) => $r->is_active
                            ? 'Stops all sync with this server immediately (its requests are refused). Nothing is deleted; enable it again to resume where it stopped.'
                            : 'Resumes sync from where it stopped.')
                        ->action(function (SyncPeer $record) {
                            $record->is_active = ! $record->is_active;
                            Erp::run(fn ($actor) => app(Recorder::class)->save($actor, $record,
                                $record->is_active ? 'sync.peer_enabled' : 'sync.peer_disabled'), $record->is_active ? 'Enabled' : 'Disabled');
                        }),
                    Action::make('resync')->label('Full resync from this peer')->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
                        ->visible(fn (SyncPeer $r) => (bool) $r->base_url)
                        ->requiresConfirmation()
                        ->modalDescription('Receives everything again from the beginning. Safe (nothing is applied twice) but may take a while on a slow link.')
                        ->action(function (SyncPeer $record) {
                            app(SyncEngine::class)->resetForFullResync($record);
                            Notification::make()->success()->title('Full resync will run at the next sync')->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('Not connected to any other server')
            ->emptyStateDescription(config('erp.node.role') === 'branch'
                ? 'Join head office with: php artisan erp:node:join <url> <hq-node-id> <company-id>'
                : 'Use “Add branch server” to let a branch server sync with this one.');
    }

    public static function getPages(): array
    {
        return ['index' => ListSyncPeers::route('/')];
    }
}
