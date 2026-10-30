<?php

namespace App\Filament\Pages;

use App\Domain\Pos\CashControl;
use App\Domain\Pos\TellerVaults;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\ShiftTemplate;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultCover;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/** Shifts (Morning, Afternoon, …), each till's vault per shift (cashier chain), and each teller's own vault (personal vaults). */
class CashSetup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Cash';

    protected static ?string $navigationLabel = 'Shifts and vaults';

    protected static ?string $title = 'Shifts and vaults';

    protected static ?string $slug = 'cash-setup';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.cash-setup';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('cash.setup');
    }

    public function mount(): void
    {
        if (! $this->personal()) {
            app(CashControl::class)->ensureVaults(Erp::user()->tenant_id);
        }
    }

    /** Personal vaults on: each teller holds her own vault. */
    public function personal(): bool
    {
        return app(TellerVaults::class)->enabled(Erp::user()->tenant_id);
    }

    /** Each teller's own vault, branch by branch, with who has it today. */
    public function personalVaults(): Collection
    {
        $ids = Erp::branchIds('cash.setup');
        $vaults = app(TellerVaults::class);
        $day = app(CashControl::class)->today(Erp::user()->tenant_id);
        $covers = VaultCover::where('business_date', $day)->get()->keyBy('vault_id');

        return Vault::with(['holder', 'till', 'branch'])->whereNull('shift_template_id')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->get()
            ->sortBy(fn (Vault $v) => $v->branch->code.'|'.$v->name)->values()
            ->map(fn (Vault $v) => ['vault' => $v, 'lent' => $covers->get($v->id), 'in_use' => $vaults->inUse($v)]);
    }

    public function enabled(): bool
    {
        return app(CashControl::class)->enabled(Erp::user()->tenant_id);
    }

    public function currency(): string
    {
        return Tenant::find(Erp::user()->tenant_id)->currency_code;
    }

    public function money(int $minor): string
    {
        return Money::format($minor, $this->currency());
    }

    public function templates(): Collection
    {
        return ShiftTemplate::orderBy('sort')->orderBy('name')->get();
    }

    public function vaults(): Collection
    {
        $ids = Erp::branchIds('cash.setup');

        return Vault::with(['till', 'branch', 'shiftTemplate'])->whereNotNull('shift_template_id')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->get()
            ->sortBy(fn (Vault $v) => $v->branch->code.'|'.$v->till->code.'|'.str_pad((string) $v->shiftTemplate->sort, 3, '0', STR_PAD_LEFT))->values();
    }

    private function amount(int $minor): string
    {
        $e = Money::exponent($this->currency());

        return number_format($minor / (10 ** $e), $e, '.', '');
    }

    public function templateAction(): Action
    {
        return Action::make('template')->label(fn (array $arguments) => isset($arguments['template']) ? 'Edit' : 'Add a shift')->size('sm')
            ->fillForm(fn (array $arguments) => ($t = ShiftTemplate::find($arguments['template'] ?? null))
                ? ['name' => $t->name, 'starts_at' => substr($t->starts_at, 0, 5), 'ends_at' => substr($t->ends_at, 0, 5), 'sort' => $t->sort, 'is_active' => $t->is_active]
                : ['sort' => $this->templates()->max('sort') + 1, 'is_active' => true])
            ->schema([
                TextInput::make('name')->label('Name')->required()->maxLength(40)->placeholder('Evening'),
                TimePicker::make('starts_at')->label('Starts')->seconds(false)->required(),
                TimePicker::make('ends_at')->label('Ends')->seconds(false)->required(),
                TextInput::make('sort')->label('Order')->numeric()->integer(),
                Toggle::make('is_active')->label('In use'),
            ])
            ->action(fn (array $data, array $arguments) => Erp::run(fn ($actor) => app(CashControl::class)->saveTemplate($actor,
                ShiftTemplate::find($arguments['template'] ?? null) ?? new ShiftTemplate, $data), 'Saved'));
    }

    public function vaultAction(): Action
    {
        return Action::make('vault')->label('Edit')->size('sm')
            ->fillForm(fn (array $arguments) => ($v = Vault::find($arguments['vault'])) ? ['float' => $this->amount($v->standard_float_minor), 'is_active' => $v->is_active] : [])
            ->schema([
                TextInput::make('float')->label('Standard float')->numeric()->required()->helperText('Suggested when the cashier issues this vault; it can be changed on the day.'),
                Toggle::make('is_active')->label('In use'),
            ])
            ->action(fn (array $data, array $arguments) => Erp::run(fn ($actor) => app(CashControl::class)->saveVault($actor, Vault::findOrFail($arguments['vault']),
                Money::toMinor((string) $data['float'], $this->currency()), (bool) $data['is_active']), 'Saved'));
    }

    /** Add or change a teller's own vault. */
    public function personalVaultAction(): Action
    {
        return Action::make('personalVault')->label(fn (array $arguments) => isset($arguments['vault']) ? 'Edit' : 'Add a vault')->size('sm')
            ->visible(fn () => $this->personal())
            ->fillForm(fn (array $arguments) => ($v = Vault::find($arguments['vault'] ?? null))
                ? ['name' => $v->name, 'branch_id' => $v->branch_id, 'till_id' => $v->till_id, 'holder_id' => $v->holder_id, 'float' => $this->amount($v->standard_float_minor), 'is_active' => $v->is_active]
                : ['name' => 'Vault '.(Vault::whereNull('shift_template_id')->count() + 1), 'branch_id' => $this->branches()->keys()->first(), 'float' => '0', 'is_active' => true])
            ->schema([
                TextInput::make('name')->label('Name')->required()->maxLength(80)->placeholder('Vault 1'),
                Select::make('branch_id')->label('Branch')->options(fn () => $this->branches())->required()->live(),
                Select::make('holder_id')->label('Teller who holds it')->searchable()->required()
                    ->options(fn ($get) => $this->sellers($get('branch_id')))->helperText('Only this person sees and opens this vault on a till. One vault per person.'),
                Select::make('till_id')->label('Till')->placeholder('Any till of the branch (removable tray)')
                    ->options(fn ($get) => Till::where('branch_id', $get('branch_id'))->where('is_active', true)->orderBy('code')->pluck('name', 'id'))
                    ->required(fn () => app(TellerVaults::class)->trays(Erp::user()->tenant_id) === 'till'),
                TextInput::make('float')->label('Standard float')->numeric()->required()->helperText('What is in the tray when the teller opens; she confirms it by counting.'),
                Toggle::make('is_active')->label('In use'),
            ])
            ->action(fn (array $data, array $arguments) => Erp::run(fn ($actor) => app(TellerVaults::class)->save($actor, Vault::find($arguments['vault'] ?? null) ?? new Vault, [
                'name' => $data['name'], 'branch_id' => $data['branch_id'], 'till_id' => $data['till_id'] ?? null, 'holder_id' => $data['holder_id'],
                'standard_float_minor' => Money::toMinor((string) $data['float'], $this->currency()), 'is_active' => (bool) $data['is_active'],
            ]), 'Saved'));
    }

    /** Lend a vault to someone else for today (its holder is away, or cover). */
    public function lendAction(): Action
    {
        return Action::make('lend')->label('Lend for today')->size('sm')->color('gray')
            ->visible(fn () => $this->personal() && Erp::canAnywhere('cash.vault.lend'))
            ->modalHeading(fn (array $arguments) => 'Lend '.Vault::find($arguments['vault'])?->name.' for today')
            ->modalDescription('Its holder cannot open it today; the person you choose can, until midnight. Recorded in the audit log.')
            ->schema(fn (array $arguments) => [
                Select::make('user_id')->label('Lend to')->searchable()->required()
                    ->options(fn () => collect($this->sellers(Vault::find($arguments['vault'])?->branch_id))->except([Vault::find($arguments['vault'])?->holder_id, Erp::user()->id])->all()),
                TextInput::make('reason')->label('Reason')->required()->maxLength(255)->placeholder('e.g. Ama is on leave; cover for the afternoon'),
            ])
            ->action(fn (array $data, array $arguments) => Erp::run(fn ($actor) => app(TellerVaults::class)->lend($actor, Vault::findOrFail($arguments['vault']),
                User::findOrFail($data['user_id']), $data['reason']), 'Lent for today'));
    }

    /** Branches where this person sets up cash. */
    private function branches(): Collection
    {
        $ids = Erp::branchIds('cash.setup');

        return Branch::where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get()
            ->mapWithKeys(fn (Branch $b) => [$b->id => "{$b->code} · {$b->name}"]);
    }

    /** People who may sell at the branch. */
    private function sellers(?string $branchId): array
    {
        $branch = $branchId ? Branch::find($branchId) : null;
        if (! $branch) {
            return [];
        }
        $vaults = app(TellerVaults::class);

        return User::where('is_active', true)->orderBy('name')->get()->filter(fn (User $u) => $vaults->canSellAt($u, $branch->id))
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name])->all();
    }

    protected function getHeaderActions(): array
    {
        return [$this->personalVaultAction()->label('Add a vault'), $this->templateAction()];
    }
}
