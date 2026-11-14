<?php

namespace App\Filament\Resources\AccessRequests;

use App\Domain\Access\AccessRequests;
use App\Filament\Resources\AccessRequests\Pages\ListAccessRequests;
use App\Filament\Support\Erp;
use App\Models\AccessRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * People & access → Access requests: what IT Admin asked for, waiting for the owner or SyncLytics support (owner decision
 * 2026-11-13). The owner and support approve or turn down; IT sees its own and can withdraw one still waiting.
 */
class AccessRequestResource extends Resource
{
    protected static ?string $model = AccessRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = 'People & access';

    protected static ?string $navigationLabel = 'Access requests';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::can('admin.users.view') || Erp::can('admin.users.manage') || Erp::can('admin.roles.manage');
    }

    public static function canCreate(): bool
    {
        return false; // asked for from the user and role screens
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = AccessRequest::query()->waiting()->count();

        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        $decider = fn (AccessRequest $r) => $r->isWaiting() && AccessRequests::mayDecide(Erp::user(), $r);

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (AccessRequest $r) => $r->status === 'pending' && ! $r->isWaiting() ? AccessRequest::STATUSES['expired'] : (AccessRequest::STATUSES[$r->status] ?? $r->status))
                    ->color(fn (AccessRequest $r) => match (true) {
                        $r->isWaiting() => 'warning', $r->status === 'approved' => 'success', in_array($r->status, ['rejected', 'failed'], true) => 'danger', default => 'gray',
                    }),
                TextColumn::make('kind')->label('What')->formatStateUsing(fn ($state) => AccessRequest::KINDS[$state] ?? $state),
                TextColumn::make('summary')->label('Details')->wrap()->limit(220),
                TextColumn::make('requested_by_name')->label('Asked by'),
                TextColumn::make('created_at')->label('Asked')->since(),
                TextColumn::make('expires_at')->label('Lapses')->since()->visible(fn () => true)->color('gray'),
                TextColumn::make('decided_by_name')->label('Decided by')->placeholder('—')
                    ->description(fn (AccessRequest $r) => $r->decision_note),
            ])
            ->filters([
                SelectFilter::make('status')->options(AccessRequest::STATUSES),
            ])
            ->recordActions([
                Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')->visible($decider)
                    ->requiresConfirmation()->modalHeading('Approve this access change?')
                    ->modalDescription(fn (AccessRequest $r) => $r->summary.' It is made now, in your name, and recorded in the audit log.')
                    ->schema([TextInput::make('note')->label('Note (optional)')->maxLength(250)])
                    ->action(fn (AccessRequest $record, array $data) => self::approve($record, $data['note'] ?? null)),
                Action::make('reject')->label('Turn down')->icon(Heroicon::OutlinedXMark)->color('danger')->visible($decider)
                    ->schema([TextInput::make('note')->label('Why (the person who asked is told)')->required()->maxLength(250)])
                    ->action(fn (AccessRequest $record, array $data) => Erp::run(fn ($actor) => app(AccessRequests::class)->reject($actor, $record, $data['note']), 'Turned down')),
                Action::make('withdraw')->label('Withdraw')->color('gray')
                    ->visible(fn (AccessRequest $r) => $r->isWaiting() && $r->requested_by === Erp::user()->id)->requiresConfirmation()
                    ->action(fn (AccessRequest $record) => Erp::run(fn ($actor) => app(AccessRequests::class)->withdraw($actor, $record), 'Withdrawn')),
            ])
            ->emptyStateHeading('No access requests')
            ->emptyStateDescription('When IT Admin adds a user, gives access or changes a role, it waits here for the owner or SyncLytics support.');
    }

    private static function approve(AccessRequest $r, ?string $note): void
    {
        $done = Erp::run(fn ($actor) => app(AccessRequests::class)->approve($actor, $r, $note));
        if ($done instanceof AccessRequest && $done->status === 'failed') {
            Notification::make()->danger()->persistent()->title('Approved, but it could not be done')->body($done->decision_note)->send();
        } elseif ($done) {
            Notification::make()->success()->title('Approved and done')->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ListAccessRequests::route('/')];
    }
}
