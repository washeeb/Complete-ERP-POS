<?php

namespace App\Filament\Pages;

use App\Filament\Support\ExportsPage;
use App\Domain\Banking\BankingService;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Branch;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/** Tick statement lines and ledger lines that are the same money, and see what is left to explain. */
class BankReconciliation extends Page
{
    use ExportsPage;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'bank-reconciliation';

    protected static ?string $title = 'Reconcile';

    protected string $view = 'filament.pages.bank-reconciliation';

    #[Url]
    public string $bank = '';

    #[Url]
    public string $asAt = '';

    public array $pickStatement = [];

    public array $pickLedger = [];

    public string $postAccount = '';

    public string $postMemo = '';

    public string $ignoreReason = '';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('banking.manage') && config('erp.node.role') !== 'branch';
    }

    public function mount(): void
    {
        $this->asAt = $this->asAt ?: now()->toDateString();
        abort_unless(BankAccount::whereKey($this->bank)->exists(), 404);
    }

    public function getTitle(): string
    {
        return 'Reconcile '.$this->account()->name;
    }

    public function account(): BankAccount
    {
        return BankAccount::with('account')->findOrFail($this->bank);
    }

    public function money(int $v): string
    {
        return FinanceUi::money($v);
    }

    public function data(): array
    {
        $svc = app(BankingService::class);
        $bank = $this->account();

        return [
            'statement' => BankStatementLine::where('bank_account_id', $bank->id)->where('status', 'open')->orderBy('line_date')->orderBy('id')->get(),
            'ledger' => $svc->unmatchedLedger($bank),
            'suggested' => $svc->suggestions($bank),
            'summary' => $svc->reconciliation($bank, $this->asAt),
            'matches' => DB::table('bank_matches')->where('bank_account_id', $bank->id)->orderByDesc('created_at')->limit(10)->get(),
            'accounts' => FinanceUi::accounts(withControl: false),
        ];
    }

    public function match(): void
    {
        $this->attempt(fn ($actor) => app(BankingService::class)->match($actor, $this->account(), $this->pickStatement, $this->pickLedger), 'Matched');
    }

    public function autoMatch(): void
    {
        $n = $this->attempt(fn ($actor) => app(BankingService::class)->autoMatch($actor, $this->account()));
        Notification::make()->success()->title($n ? "{$n} line(s) matched" : 'Nothing to match automatically')->send();
    }

    public function post(): void
    {
        if (count($this->pickStatement) !== 1 || ! $this->postAccount) {
            Notification::make()->warning()->title('Tick one statement line and choose the account to post it to.')->send();

            return;
        }
        $this->attempt(fn ($actor) => app(BankingService::class)->postFromStatement($actor, $this->account(), BankStatementLine::findOrFail($this->pickStatement[0]),
            Account::findOrFail($this->postAccount), Branch::where('is_hq', true)->firstOrFail(), $this->postMemo ?: null), 'Posted and matched');
    }

    public function ignore(): void
    {
        $this->attempt(function ($actor) {
            foreach ($this->pickStatement as $id) {
                app(BankingService::class)->ignore($actor, BankStatementLine::where('bank_account_id', $this->bank)->findOrFail($id), $this->ignoreReason);
            }
        }, 'Ignored');
    }

    public function clearEarlier(): void
    {
        $this->attempt(fn ($actor) => app(BankingService::class)->clearAsEarlier($actor, $this->account(), $this->pickLedger), 'Cleared as on an earlier statement');
    }

    public function unmatch(string $matchId): void
    {
        $this->attempt(fn ($actor) => app(BankingService::class)->unmatch($actor, $this->account(), $matchId), 'Match undone');
    }

    private function attempt(callable $call, ?string $ok = null): mixed
    {
        try {
            $r = $call(Erp::actor());
        } catch (DomainException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();

            return null;
        }
        $this->pickStatement = [];
        $this->pickLedger = [];
        $this->postMemo = '';
        if ($ok) {
            Notification::make()->success()->title($ok)->send();
        }

        return $r;
    }
}
