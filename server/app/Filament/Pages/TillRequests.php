<?php

namespace App\Filament\Pages;

use App\Domain\Messaging\PhonePush;
use App\Domain\Pos\TillApprovals;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\TillApproval;
use App\Security\Authorizer;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Sales → Till requests: what tills are asking a manager for (owner request 2026-11-15: the manager may not be near the
 * till, and typing their PIN there shows it). Opened from the bell or the phone notification; Approve asks the manager to
 * confirm it is them (passkey, fingerprint, authenticator) and the till goes on within seconds.
 */
class TillRequests extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Till requests';

    protected static ?string $title = 'Till requests';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'till-requests';

    protected ?string $subheading = 'A till asks for your yes: a return, a discount, removing items, opening the drawer. Check what is asked, then Approve or Decline. Why here: you need not be at the till, and your PIN is never typed where others can see it.';

    protected string $view = 'filament.pages.till-requests';

    /** Opened from a notification: that one request. */
    #[Url]
    public ?string $id = null;

    #[Computed]
    public function pushKey(): string
    {
        return app(PhonePush::class)->publicKey(Erp::user()->tenant_id);
    }

    #[Computed]
    public function hasDevice(): bool
    {
        return PhonePush::hasDevice(Erp::user());
    }

    /** This browser said yes to notifications (layout.push). */
    public function savePush(string $endpoint, string $publicKey, string $authToken, ?string $device = null): void
    {
        if (! Str::startsWith($endpoint, 'https://') || strlen($endpoint) > 2000 || strlen($publicKey) > 200 || strlen($authToken) > 100) {
            return;
        }
        app(PhonePush::class)->subscribe(Erp::user(), $endpoint, $publicKey, $authToken, $device);
        unset($this->hasDevice);
        Notification::make()->success()->title('Notifications are on for this device')->body('Till requests now pop up here even when the back office is closed.')->send();
    }

    /** @return list<string> */
    private static function permissions(): array
    {
        return array_values(array_unique(array_map(fn ($k) => $k[1], TillApproval::KINDS)));
    }

    /** @return list<string> branches where this person approves something at the tills */
    private static function branches(): array
    {
        $u = Erp::user();
        $authz = app(Authorizer::class);

        return Branch::all()->filter(fn (Branch $b) => collect(self::permissions())->contains(fn ($p) => $authz->allows($u, $p, $b)))->modelKeys();
    }

    public static function canAccess(): bool
    {
        $u = Erp::user();

        return $u !== null && collect(self::permissions())->contains(fn ($p) => app(Authorizer::class)->allowsAnywhere($u, $p));
    }

    public static function getNavigationBadge(): ?string
    {
        $u = Erp::user();
        if (! $u) {
            return null;
        }
        $svc = app(TillApprovals::class);
        $n = TillApproval::whereIn('branch_id', self::branches())->where('status', 'pending')->where('expires_at', '>', now())->get()->filter(fn ($r) => $svc->mayDecide($u, $r))->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        $svc = app(TillApprovals::class);
        $currency = Tenant::find(Erp::user()?->tenant_id)?->currency_code ?? 'GHS';
        $open = fn (TillApproval $r) => $r->isOpen() && $svc->mayDecide(Erp::user(), $r);

        return $table
            ->query(fn () => TillApproval::query()->with(['till', 'branch'])->whereIn('branch_id', self::branches())
                ->when($this->id, fn (Builder $query) => $query->whereKey($this->id)))
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('created_at')->label('Asked')->since()->sortable()
                    ->description(fn (TillApproval $r) => $r->status === 'pending' && $r->expires_at->isFuture() ? 'Answer within '.$r->expires_at->diffForHumans(syntax: true) : null),
                TextColumn::make('till.name')->label('Till')->placeholder('—')->description(fn (TillApproval $r) => $r->branch?->name),
                TextColumn::make('cashier_name')->label('Cashier')->searchable(),
                TextColumn::make('kind')->label('What')->formatStateUsing(fn (TillApproval $r) => $r->label())->wrap()
                    ->description(fn (TillApproval $r) => $r->summary.($r->reason ? ' · Reason: '.$r->reason : '')),
                TextColumn::make('details')->label('Items')->wrap()->placeholder('—')
                    ->state(fn (TillApproval $r) => collect($r->details ?? [])->map(fn ($d) => trim(($d['qty'] ?? '').' × '.($d['name'] ?? ''), ' ×')
                        .(isset($d['amount_minor']) ? ' '.Money::display((int) $d['amount_minor'], $currency) : ''))->implode(' · ') ?: null),
                TextColumn::make('amount_minor')->label('Amount')->alignEnd()
                    ->formatStateUsing(fn ($state, TillApproval $r) => $r->kind === 'discount' ? sprintf('%.2f %%', $r->limit_amount / 100) : ($state ? Money::display((int) $state, $currency) : '—')),
                TextColumn::make('status')->label('Answer')->badge()
                    ->state(fn (TillApproval $r) => $r->status === 'pending' && $r->expires_at->isPast() ? 'expired' : $r->status)
                    ->formatStateUsing(fn (string $state) => TillApproval::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'warning', 'approved', 'used' => 'success', 'declined' => 'danger', default => 'gray'
                    })
                    ->description(fn (TillApproval $r) => $r->decided_by_name ? $r->decided_by_name.($r->decided_via === 'till' ? ' at the till' : '').($r->decision_note ? ': '.$r->decision_note : '') : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('Show')->default($this->id ? 'all' : 'pending')
                    ->options(['pending' => 'Waiting', 'answered' => 'Answered', 'all' => 'All'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'pending' => $query->where('status', 'pending')->where('expires_at', '>', now()),
                        'answered' => $query->whereNot('status', 'pending'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')->button()
                    ->visible($open)
                    ->requiresConfirmation()->modalHeading(fn (TillApproval $r) => 'Approve: '.$r->label())
                    ->modalDescription(fn (TillApproval $r) => "{$r->cashier_name} asks: {$r->summary}".($r->reason ? " Reason: {$r->reason}." : '').' The till goes on as soon as you approve.')
                    ->action(fn (TillApproval $record) => Erp::run(fn ($a) => $svc->decide($a, $record, true), 'Approved: the till goes on')),
                Action::make('decline')->label('Decline')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible($open)
                    ->schema([Textarea::make('note')->label('Why (the cashier sees it)')->required()->maxLength(255)])
                    ->action(fn (TillApproval $record, array $data) => Erp::run(fn ($a) => $svc->decide($a, $record, false, $data['note']), 'Declined')),
            ])
            ->headerActions([
                Action::make('all')->label('Show all requests')->color('gray')->visible(fn () => filled($this->id))->action(fn () => $this->id = null),
            ])
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('When a till asks for your yes, it shows here, on the bell and on your phone.');
    }
}
