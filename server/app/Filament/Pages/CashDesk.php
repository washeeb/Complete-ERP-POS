<?php

namespace App\Filament\Pages;

use App\Domain\Pos\CashControl;
use App\Filament\Support\CashCountForm;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultSession;
use App\Security\Authorizer;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The Cashier's desk (cash control): issue each vault's float to its teller, receive the vaults the tellers have
 * counted (counting blind again), and hand the day to the Income Controller.
 */
class CashDesk extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Cash';

    protected static ?string $navigationLabel = 'Cash desk';

    protected static ?string $title = 'Cash desk';

    protected static ?string $slug = 'cash-desk';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.cash-desk';

    public ?string $branchId = null;

    public static function canAccess(): bool
    {
        $u = Erp::user();

        return $u && app(CashControl::class)->enabled($u->tenant_id)
            && (Erp::canAnywhere('cash.vault.issue') || Erp::canAnywhere('cash.vault.receive') || Erp::canAnywhere('cash.view'));
    }

    public function mount(): void
    {
        app(CashControl::class)->ensureVaults(Erp::user()->tenant_id);
        $this->branchId = $this->branches()->keys()->first();
    }

    /** Branches this person works the cash desk for. */
    public function branches(): Collection
    {
        $ids = collect(['cash.vault.issue', 'cash.vault.receive', 'cash.view'])->map(fn ($p) => Erp::branchIds($p));
        $q = Branch::where('is_active', true)->orderByDesc('is_hq')->orderBy('code');
        if (! $ids->contains(null)) {
            $q->whereIn('id', $ids->flatten()->unique()->all());
        }

        return $q->get()->mapWithKeys(fn (Branch $b) => [$b->id => "{$b->code} · {$b->name}"]);
    }

    public function currency(): string
    {
        return Tenant::find(Erp::user()->tenant_id)->currency_code;
    }

    public function money(?int $minor): string
    {
        return $minor === null ? '—' : Money::format($minor, $this->currency());
    }

    /** Each vault of the branch with today's session (or one still waiting from an earlier day). */
    public function rows(): Collection
    {
        if (! $this->branchId) {
            return collect();
        }
        $today = app(CashControl::class)->today(Erp::user()->tenant_id);
        $vaults = Vault::with(['till', 'shiftTemplate'])->where('branch_id', $this->branchId)->where('is_active', true)->get()
            ->sortBy(fn (Vault $v) => $v->till->code.'|'.str_pad((string) $v->shiftTemplate->sort, 3, '0', STR_PAD_LEFT));
        $sessions = VaultSession::where('branch_id', $this->branchId)
            ->where(fn ($q) => $q->where('business_date', $today)->orWhereIn('status', ['issued', 'open', 'counted', 'received']))
            ->where('status', '<>', 'cancelled')->orderByDesc('business_date')->get()->groupBy('vault_id');

        return $vaults->map(fn (Vault $v) => ['vault' => $v, 'session' => $sessions->get($v->id)?->first()])->values();
    }

    public function mine(): Collection
    {
        return VaultSession::with('vault')->where('branch_id', $this->branchId)->where('status', 'received')->where('cashier_id', Erp::user()->id)->get();
    }

    public function issueAction(): Action
    {
        return Action::make('issue')->label('Issue float')->size('sm')
            ->visible(fn () => Erp::canAnywhere('cash.vault.issue'))
            ->modalHeading(fn (array $arguments) => 'Issue the float: '.Vault::find($arguments['vault'])?->name)
            ->modalDescription('Count the float into the tray, then choose the teller who takes it. Only they can open the till on this vault.')
            ->fillForm(fn (array $arguments) => ['float' => number_format(Vault::find($arguments['vault'])->standard_float_minor / (10 ** Money::exponent($this->currency())), Money::exponent($this->currency()), '.', '')])
            ->schema(fn (array $arguments) => [
                Select::make('teller')->label('Teller')->required()->searchable()->options(fn () => $this->tellers(Vault::find($arguments['vault']))),
                TextInput::make('float')->label('Float')->numeric()->required(),
                TextInput::make('note')->label('Note (optional)')->maxLength(255),
            ])
            ->action(fn (array $data, array $arguments) => Erp::run(fn ($actor) => app(CashControl::class)->issueFloat($actor, Vault::findOrFail($arguments['vault']),
                User::findOrFail($data['teller']), Money::toMinor((string) $data['float'], $this->currency()), $data['note'] ?? null), 'Float issued'));
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')->label('Take back')->size('sm')->color('gray')
            ->visible(fn () => Erp::canAnywhere('cash.vault.issue'))
            ->modalDescription('The teller has not opened the till: the float goes back to the safe.')
            ->schema([TextInput::make('reason')->label('Reason')->required()->maxLength(255)])
            ->action(fn (array $data, array $arguments) => Erp::run(fn ($actor) => app(CashControl::class)->cancelIssue($actor, VaultSession::findOrFail($arguments['session']), $data['reason']), 'Float taken back'));
    }

    public function receiveAction(): Action
    {
        return Action::make('receive')->label('Receive and count')->size('sm')
            ->visible(fn () => Erp::canAnywhere('cash.vault.receive'))
            ->modalHeading(fn (array $arguments) => 'Count the vault: '.VaultSession::find($arguments['session'])?->vault?->name)
            ->modalDescription('Count everything in the tray, float included, before looking at anything else. Your count is compared with the teller\'s afterwards.')
            ->schema(fn () => CashCountForm::schema($this->currency()))
            ->action(function (array $data, array $arguments) {
                [$denoms, $amount] = CashCountForm::read($data, $this->currency());
                $r = Erp::run(fn ($actor) => app(CashControl::class)->receiveVault($actor, VaultSession::findOrFail($arguments['session']), $denoms, $amount));
                if (($r['status'] ?? null) === 'recount') {
                    Notification::make()->title('Your count does not match the teller\'s')->body('Count the tray again from the start and enter it once more. The second count stands.')->warning()->persistent()->send();
                } elseif (($r['status'] ?? null) === 'received') {
                    Notification::make()->title('Vault received')->success()->send();
                }
            });
    }

    public function handOverAction(): Action
    {
        return Action::make('handOver')->label('Hand over to the income controller')->icon(Heroicon::OutlinedArrowRightCircle)
            ->visible(fn () => Erp::canAnywhere('cash.handover') && $this->mine()->isNotEmpty())
            ->modalDescription('Bundle the trays you received and hand them over. The income controller counts them again, blind.')
            ->fillForm(fn () => ['sessions' => $this->mine()->pluck('id')->all()])
            ->schema([
                CheckboxList::make('sessions')->label('Vaults handed over')->required()
                    ->options(fn () => $this->mine()->mapWithKeys(fn (VaultSession $s) => [$s->id => "{$s->vault->name} · {$s->teller_name} · {$s->business_date->format('j M')}"])),
                TextInput::make('note')->label('Note (optional)')->maxLength(255),
            ])
            ->action(fn (array $data) => Erp::run(fn ($actor) => app(CashControl::class)->handOver($actor, Branch::findOrFail($this->branchId), $data['sessions'], $data['note'] ?? null), 'Handed over'));
    }

    protected function getHeaderActions(): array
    {
        return [$this->handOverAction()];
    }

    /** People who may sell at this vault's till. */
    private function tellers(Vault $vault): array
    {
        $authz = app(Authorizer::class);

        return User::where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->id !== Erp::user()->id && $authz->allows($u, 'pos.access', $vault->till))
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name])->all();
    }
}
