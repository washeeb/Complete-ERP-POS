<?php

namespace App\Filament\Pages;

use App\Domain\Access\OwnerSeparation;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Support\Erp;
use App\Models\AuditEvent;
use App\Models\User;
use App\Security\SecurityLevel;
use App\Support\ClientInfo;
use App\Support\Plain;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * People & access → IT and support activity (owner decision 2026-11-13: "Owner sees all IT activity"): everything IT Admin
 * and the SyncLytics support account did, and every technical change by anyone (users, access, roles, devices, settings,
 * sync, remote sessions, API keys, modules, backups, sign-in security), in plain words, newest first. Read-only, from the
 * audit log, which cannot be changed; a row opens the full record.
 */
class ItActivity extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|UnitEnum|null $navigationGroup = 'People & access';

    protected static ?string $navigationLabel = 'IT and support activity';

    protected static ?string $title = 'IT and support activity';

    protected static ?int $navigationSort = 4;

    protected ?string $subheading = 'Everything IT Admin and SyncLytics support did, and every change to users, access, devices, settings and the other technical parts, by anyone. Why: you run the business; this is how you see the technical side without being able to change it.';

    protected string $view = 'filament.pages.table-page';

    /** Technical changes, whoever made them (audit actions). */
    public const TECHNICAL = ['access.%', 'access_request.%', 'api_client.%', 'backup.%', 'computer.%', 'device.%', 'license.%', 'module.%', 'remote.%',
        'role.%', 'setting.changed', 'support.%', 'sync.%', 'user.%', 'auth.mfa_%', 'auth.passkey_added', 'auth.passkey_removed', 'auth.not_me', 'auth.new_computer'];

    /** A person's own screen preferences: not a technical change. */
    private const PERSONAL = ['user.dashboard_changed', 'user.favourites_changed'];

    public static function canAccess(): bool
    {
        $u = Erp::user();

        return $u !== null && ($u->isSupport() || OwnerSeparation::isOwnerAccount($u) || Erp::can('audit.view'));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = self::query()->where('occurred_at', '>=', now()->subDays(7))->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'In the last 7 days';
    }

    /** IT and support accounts: the support account, and anyone who holds or held a technical role. */
    public static function technicalPeople(): array
    {
        $tenant = Erp::user()?->tenant_id;

        return User::where('tenant_id', $tenant)->where(fn ($q) => $q->where('security_level', '>=', SecurityLevel::Platform->value)
            ->orWhereIn('id', DB::table('role_assignments as g')->join('roles as r', 'r.id', '=', 'g.role_id')->where('r.is_technical', true)->select('g.user_id')))
            ->pluck('id')->all();
    }

    public static function query(): Builder
    {
        $people = self::technicalPeople();

        return AuditEvent::query()->whereNotIn('action', self::PERSONAL)
            ->where(fn ($q) => $q->whereIn('actor_id', $people ?: ['00000000-0000-0000-0000-000000000000'])
                ->orWhere(fn ($t) => collect(self::TECHNICAL)->each(fn ($a) => $t->orWhere('action', 'like', $a))));
    }

    public function table(Table $table): Table
    {
        $people = self::technicalPeople();

        return $table
            ->query(self::query())
            ->defaultSort('seq', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('j M Y H:i')->sortable(),
                TextColumn::make('actor_name')->label('Who')->asPerson('actor_id')->searchable()
                    ->description(fn (AuditEvent $r) => in_array($r->actor_id, $people, true)
                        ? (User::whereKey($r->actor_id)->value('security_level') >= SecurityLevel::Platform->value ? 'SyncLytics support' : 'IT')
                        : ($r->actor_id ? null : Plain::source($r->source))),
                TextColumn::make('action')->label('What')->badge()->formatStateUsing(fn (string $state) => Plain::action($state))
                    ->description(fn (AuditEvent $r) => Plain::record($r->subject_type)),
                TextColumn::make('details')->label('Details')->wrap()
                    ->state(fn (AuditEvent $r) => trim(collect($r->changes)->take(4)->map(fn ($pair, $key) => Plain::field((string) $key).': '
                        .(is_array($pair) && count($pair) === 2 && array_is_list($pair) ? Plain::value((string) $key, $pair[0]).' → '.Plain::value((string) $key, $pair[1]) : Plain::value((string) $key, $pair)))
                        ->implode(' · ').($r->reason ? ' · '.$r->reason : ''), ' ·'))->placeholder('—')->limit(220),
                TextColumn::make('client')->label('Computer')->placeholder('—')->wrap()->toggleable()
                    ->state(fn (AuditEvent $r) => ClientInfo::summary($r->client, $r->ip)),
            ])
            ->filters([
                SelectFilter::make('who')->label('Who')->options(['tech' => 'IT and SyncLytics support only', 'support' => 'SyncLytics support only'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'tech' => $query->whereIn('actor_id', $people ?: ['00000000-0000-0000-0000-000000000000']),
                        'support' => $query->whereIn('actor_id', User::where('tenant_id', Erp::user()->tenant_id)->where('security_level', '>=', SecurityLevel::Platform->value)->pluck('id')),
                        default => $query,
                    }),
                SelectFilter::make('when')->label('When')->default('30')->options(['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'all' => 'All'])
                    ->query(fn (Builder $query, array $data) => is_numeric($data['value'] ?? null) ? $query->where('occurred_at', '>=', now()->subDays((int) $data['value'])) : $query),
            ])
            ->recordActions([ViewAction::make()->schema(fn (Schema $schema) => AuditEventResource::infolist($schema))])
            ->emptyStateHeading('Nothing in this period')
            ->emptyStateDescription('Nothing technical was changed, and IT and support did nothing, in the period chosen.');
    }
}
