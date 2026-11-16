<?php

namespace App\Filament\Pages;

use App\Domain\Access\AccessService;
use App\Filament\Support\Erp;
use App\Models\User;
use App\Security\SecurityLevel;
use App\Support\StaffCard;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * People & access → Staff cards (owner request 2026-11-15): tick the people, press *Print new cards*, and the cards
 * (photo, name, role, branch, barcode) print straight away: on an A4 sheet to cut out, or on a PVC card printer. The till
 * scans the card instead of the username. A new card replaces the old one at once; a lost card is stopped here too.
 */
class StaffCards extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'People & access';

    protected static ?string $navigationLabel = 'Staff cards';

    protected static ?string $title = 'Staff cards';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'staff-cards';

    protected ?string $subheading = 'Tick the people, then Print new cards. The till scans the card instead of the username, so signing in is quicker and nobody sees a username typed. A new card stops the old one at once.';

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return Erp::can('admin.users.manage');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => User::query()->where('is_active', true)->where('security_level', '<', SecurityLevel::Platform->value)
                ->with(['roleAssignments' => fn ($q) => $q->active()->with('role')]))
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('photo')->label('')->circular()->imageSize(36)->state(fn (User $u) => $u->getFilamentAvatarUrl()),
                TextColumn::make('name')->label('Name')->searchable()->sortable()->description(fn (User $u) => $u->username),
                TextColumn::make('roles')->label('Role and where')->wrap()
                    ->state(fn (User $u) => $u->roleAssignments->map(fn ($g) => ($g->role?->name ?? '?').' · '.$g->scopeLabel())->implode("\n") ?: null)->placeholder('No role'),
                TextColumn::make('pin_hash')->label('Till PIN')->formatStateUsing(fn () => 'Set')->placeholder('Not set')->badge()
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
                TextColumn::make('badge_issued_at')->label('Card')->date('j M Y')->placeholder('No card')->sortable()
                    ->description(fn (User $u) => $u->badge_issued_at ? 'Printed' : null),
            ])
            ->filters([
                SelectFilter::make('card')->label('Card')->options(['none' => 'No card yet', 'has' => 'Has a card'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'none' => $query->whereNull('badge_hash'),
                        'has' => $query->whereNotNull('badge_hash'),
                        default => $query,
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('print')->label('Print new cards')->icon(Heroicon::OutlinedPrinter)->color('primary')
                    ->modalHeading('Print new staff cards')
                    ->modalDescription('Each person ticked gets a new card; a card they already have stops working at once. The cards open in a new tab, ready to print; that page works once (the codes are not kept).')
                    ->schema([Radio::make('layout')->label('Print on')->options(StaffCard::LAYOUTS)->default('a4')->required()])
                    ->action(function (Collection $records, array $data) {
                        $url = Erp::run(fn ($actor) => StaffCard::issue($actor, $records, $data['layout']));
                        if (is_string($url)) {
                            $this->js('window.open('.json_encode($url).', "_blank")');
                        }
                    })->deselectRecordsAfterCompletion(),
                BulkAction::make('lost')->label('Card lost: stop it')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->requiresConfirmation()->modalDescription('The cards stop working at once. These people sign in with their username and PIN until new cards are printed.')
                    ->action(fn (Collection $records) => Erp::run(fn ($actor) => $records->each(fn (User $u) => $u->badge_hash
                        ? app(AccessService::class)->revokeBadge($actor, $u) : null) && true, 'Cards stopped'))->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('Nobody here yet')
            ->emptyStateDescription('Add people under People & access → Users first.');
    }
}
