<?php

namespace App\Filament\Pages;

use App\Domain\Pos\CashControl;
use App\Filament\Support\Erp;
use App\Models\ShiftTemplate;
use App\Models\Tenant;
use App\Models\Vault;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/** Shifts (Morning, Afternoon, …) and each till's vault per shift with its standard float. */
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
        app(CashControl::class)->ensureVaults(Erp::user()->tenant_id);
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

        return Vault::with(['till', 'branch', 'shiftTemplate'])->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->get()
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

    protected function getHeaderActions(): array
    {
        return [$this->templateAction()];
    }
}
