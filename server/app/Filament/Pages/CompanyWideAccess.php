<?php

namespace App\Filament\Pages;

use App\Domain\Access\AccessService;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Security\ScopeType;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Everyone who can see the whole company, and who should be given a branch or an industry instead. */
class CompanyWideAccess extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'People & access';

    protected static ?string $navigationLabel = 'Company-wide access';

    protected static ?string $title = 'Company-wide access';

    protected static ?string $slug = 'company-wide-access';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('admin.roles.manage');
    }

    public static function getNavigationBadge(): ?string
    {
        return ($n = self::query()->whereNotIn('role_id', self::allowedRoles())->count()) > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function getSubheading(): ?string
    {
        return 'Company-wide access shows every industry and branch. The system cannot know where someone works, so give each flagged person their branch here.';
    }

    private static function query(): Builder
    {
        return RoleAssignment::query()->active()->whereIn('scope_type', [ScopeType::Tenant, ScopeType::LegalEntity]);
    }

    /** @return list<string> */
    private static function allowedRoles(): array
    {
        return Role::all()->filter(fn (Role $r) => AccessService::mayBeCompanyWide($r))->modelKeys();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => self::query()->with('user', 'role'))
            ->columns([
                TextColumn::make('user.name')->label('Person'),
                TextColumn::make('role.name')->label('Role'),
                TextColumn::make('scope_type')->label('Applies to')->formatStateUsing(fn (ScopeType $state) => $state->label()),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (RoleAssignment $a) => AccessService::mayBeCompanyWide($a->role) ? 'Allowed' : 'Needs a branch')
                    ->color(fn (string $state) => $state === 'Allowed' ? 'success' : 'warning'),
            ])
            ->filters([
                TernaryFilter::make('needs')->label('Needs a branch')->default(true)
                    ->queries(true: fn ($q) => $q->whereNotIn('role_id', self::allowedRoles()), false: fn ($q) => $q->whereIn('role_id', self::allowedRoles()), blank: fn ($q) => $q),
            ])
            ->recordActions([
                Action::make('narrow')->label('Give a branch')->icon(Heroicon::OutlinedBuildingStorefront)
                    ->schema([
                        Select::make('scope_type')->label('Applies to')->required()->live()->default(ScopeType::Branch->value)
                            ->options([ScopeType::Branch->value => ScopeType::Branch->label(), ScopeType::Industry->value => ScopeType::Industry->label()]),
                        Select::make('scope_id')->label('Which one')->required()->searchable()
                            ->options(fn (Get $get) => $get('scope_type') === ScopeType::Industry->value
                                ? Industry::licensed()->orderBy('sort')->pluck('name', 'id')
                                : Branch::orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"])),
                    ])
                    ->action(fn (RoleAssignment $record, array $data) => Erp::run(fn ($actor) => app(AccessService::class)
                        ->narrow($actor, $record, ScopeType::from($data['scope_type']), $data['scope_id']), 'Access changed')),
            ])
            ->emptyStateHeading('Nobody here')
            ->emptyStateDescription('Everyone in this list is within the rule in Settings → Security.');
    }
}
