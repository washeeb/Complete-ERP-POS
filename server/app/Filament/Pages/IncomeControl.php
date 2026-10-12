<?php

namespace App\Filament\Pages;

use App\Domain\Pos\CashControl;
use App\Filament\Support\CashCountForm;
use App\Filament\Support\Erp;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashHandover;
use App\Models\CashVariance;
use App\Models\Tenant;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The Income Controller's page (cash control): receive each cashier's handover and count it blind, see the day's
 * reconciliation (system → teller → cashier → income controller), decide each difference and record the bank deposit.
 */
class IncomeControl extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Cash';

    protected static ?string $navigationLabel = 'Income control';

    protected static ?string $title = 'Income control';

    protected static ?string $slug = 'income-control';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.income-control';

    /** Handovers whose first blind count differed: the next count stands. */
    public array $recounts = [];

    public static function canAccess(): bool
    {
        $u = Erp::user();

        return $u && app(CashControl::class)->enabled($u->tenant_id) && (Erp::canAnywhere('cash.control') || Erp::canAnywhere('cash.view'));
    }

    public function currency(): string
    {
        return Tenant::find(Erp::user()->tenant_id)->currency_code;
    }

    public function money(?int $minor): string
    {
        return $minor === null ? '—' : Money::format($minor, $this->currency());
    }

    private function scoped($q, string $perm = 'cash.view')
    {
        $ids = Erp::branchIds($perm) ?? Erp::branchIds('cash.control');

        return $ids === null ? $q : $q->whereIn('branch_id', $ids);
    }

    public function waiting(): Collection
    {
        return $this->scoped(CashHandover::with('branch')->where('status', 'handed_over'))->orderBy('handed_at')->get();
    }

    public function received(): Collection
    {
        return $this->scoped(CashHandover::with(['branch', 'sessions.vault'])->where('status', 'received'))->orderByDesc('received_at')->limit(30)->get();
    }

    public function differences(): Collection
    {
        return $this->scoped(CashVariance::with('branch')->where('status', 'open'))->orderBy('created_at')->get();
    }

    public function receiveAction(): Action
    {
        return Action::make('receive')->label('Receive and count')->size('sm')
            ->visible(fn () => Erp::canAnywhere('cash.control'))
            ->modalHeading(fn (array $arguments) => 'Count handover '.CashHandover::find($arguments['handover'])?->number)
            ->modalDescription('Count all the cash in the bundle. Your count is compared with the cashier\'s afterwards.')
            ->schema(fn () => CashCountForm::schema($this->currency()))
            ->action(function (array $data, array $arguments) {
                $id = $arguments['handover'];
                [$denoms, $amount] = CashCountForm::read($data, $this->currency());
                $r = Erp::run(fn ($actor) => app(CashControl::class)->receiveHandover($actor, CashHandover::findOrFail($id), $denoms, $amount, (int) ($this->recounts[$id] ?? 0)));
                if (($r['status'] ?? null) === 'recount') {
                    $this->recounts[$id] = 1;
                    Notification::make()->title('Your count does not match the cashier\'s')->body('Count the bundle again from the start and enter it once more. The second count stands.')->warning()->persistent()->send();
                } elseif (($r['status'] ?? null) === 'received') {
                    unset($this->recounts[$id]);
                    Notification::make()->title('Handover received')->success()->send();
                }
            });
    }

    public function decideAction(): Action
    {
        return Action::make('decide')->label('Decide')->size('sm')
            ->visible(fn () => Erp::canAnywhere('cash.variance.approve'))
            ->modalHeading(fn (array $arguments) => (($v = CashVariance::find($arguments['variance'])) && $v->amount_minor < 0 ? 'Short ' : 'Over ').$this->money(abs($v?->amount_minor ?? 0)).' · '.$v?->person_name)
            ->schema(fn (array $arguments) => [
                Radio::make('treatment')->label('Treat it as')->required()->default('over_short')
                    ->options(fn () => CashVariance::find($arguments['variance'])?->amount_minor < 0 ? CashVariance::TREATMENTS : ['over_short' => 'Cash over (kept by the business)'])
                    ->descriptions(['charge_staff' => 'Recovered from the person\'s pay as a staff loan (needs their employee record under HR).']),
                TextInput::make('months')->label('Recover over (months)')->numeric()->integer()->minValue(1)->maxValue(12)->default(1)
                    ->visible(fn ($get) => $get('treatment') === 'charge_staff'),
                TextInput::make('reason')->label('Reason')->required()->maxLength(255),
            ])
            ->action(fn (array $data, array $arguments) => Erp::run(fn ($actor) => app(CashControl::class)->decide($actor, CashVariance::findOrFail($arguments['variance']),
                $data['treatment'], $data['reason'], (int) ($data['months'] ?? 1)), 'Decided'));
    }

    public function depositAction(): Action
    {
        return Action::make('deposit')->label('Record deposit')->size('sm')
            ->visible(fn () => Erp::canAnywhere('cash.deposit'))
            ->fillForm(fn (array $arguments) => ['amount' => number_format((CashHandover::find($arguments['handover'])?->controller_count_minor ?? 0) / (10 ** Money::exponent($this->currency())),
                Money::exponent($this->currency()), '.', ''), 'date' => now()->toDateString()])
            ->schema([
                Select::make('bank')->label('Bank account')->required()->options(fn () => $this->banks()),
                TextInput::make('amount')->label('Amount deposited')->numeric()->required(),
                DatePicker::make('date')->label('Date')->required()->maxDate(now()),
                TextInput::make('slip')->label('Deposit slip number')->required()->maxLength(40),
            ])
            ->action(fn (array $data, array $arguments) => Erp::run(fn ($actor) => app(CashControl::class)->deposit($actor, CashHandover::findOrFail($arguments['handover']),
                Account::findOrFail($data['bank']), Money::toMinor((string) $data['amount'], $this->currency()), $data['date'], $data['slip']), 'Deposit recorded'));
    }

    /** Bank accounts (by their ledger account), and the default bank account. */
    private function banks(): array
    {
        $out = BankAccount::with('account')->where('kind', 'bank')->where('is_active', true)->get()->filter(fn ($b) => $b->account)
            ->mapWithKeys(fn ($b) => [$b->account_id => $b->name])->all();
        if ($bank = Account::where('system_key', 'bank')->first()) {
            $out += [$bank->id => $bank->name];
        }

        return $out;
    }

    public function branchName(?string $id): string
    {
        return $id ? (Branch::find($id)?->code ?? '') : '';
    }
}
