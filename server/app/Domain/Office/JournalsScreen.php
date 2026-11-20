<?php

namespace App\Domain\Office;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Journals in the new back office (release 8 of the rewrite): every journal in the ledger, newest first, with the
 * current screen's search and kind filter; a journal with its lines, what it reverses or was reversed by; New journal,
 * Opening balances, Reverse and Post now, through JournalService and AutoPoster (JournalsController). Posted journals
 * are never edited: a mistake is reversed.
 */
final class JournalsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['number', 'entry_date', 'total_minor'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return JournalEntryResource::canViewAny();
    }

    public static function canJournal(): bool
    {
        return Erp::canAnywhere('finance.journal');
    }

    public static function canPostNow(): bool
    {
        return Erp::canAnywhere('finance.journal') || Erp::canAnywhere('finance.close');
    }

    public function filtered(array $p): Builder
    {
        $q = JournalEntryResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('journal_entries.number', 'ilike', "%{$search}%")->orWhere('journal_entries.memo', 'ilike', "%{$search}%"));
        }
        if (isset(JournalEntry::KINDS[$p['kind'] ?? ''])) {
            $q->where('journal_entries.kind', $p['kind']);
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (is_string($p[$k] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p[$k])) {
                $q->where('journal_entries.entry_date', $op, $p[$k]);
            }
        }

        return $q;
    }

    /** @return array{0: string, 1: string} */
    public function order(array $p): array
    {
        return [in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'number', ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
    }

    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->order($p);

        return $this->filtered($p)->orderBy("journal_entries.{$sort}", $dir)->orderBy('journal_entries.id', $dir);
    }

    public function rowOf(JournalEntry $j): array
    {
        return ['id' => $j->id, 'number' => $j->number, 'date' => $j->entry_date?->toDateString(), 'kind' => $j->kind, 'kind_label' => JournalEntry::KINDS[$j->kind] ?? $j->kind,
            'memo' => $j->memo, 'amount' => (int) $j->total_minor, 'by' => $j->posted_by_name];
    }

    /** @param  array<string, mixed>  $p  q, kind, from, to, sort, dir, page, per */
    public function list(array $p): array
    {
        [$sort, $dir] = $this->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency), 'total' => (clone $this->filtered($p))->toBase()->getCountForPagination(),
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (JournalEntry $j) => $this->rowOf($j))->all(),
        ];
    }

    public function options(): array
    {
        $can = self::canJournal();

        return [
            'kinds' => PurchaseOrdersScreen::choices(JournalEntry::KINDS), 'per' => self::PER_PAGE, 'can_journal' => $can, 'can_post_now' => self::canPostNow(),
            'accounts' => $can ? PurchaseOrdersScreen::choices(FinanceUi::accounts(withControl: false)) : [],
            'branches' => $can ? PurchaseOrdersScreen::choices(FinanceUi::branches()) : [], 'hq' => (string) FinanceUi::hq(),
        ];
    }

    public function find(string $id): ?JournalEntry
    {
        return JournalEntryResource::getEloquentQuery()->with('lines.account', 'lines.branch', 'reverses')->find($id);
    }

    /** A journal with its lines (debit and credit), what it reverses, what reversed it, and whether it may be reversed now. */
    public function show(JournalEntry $j): array
    {
        $reversedBy = JournalEntry::where('reverses_id', $j->id)->first(['id', 'number']);
        $lines = $j->lines->sortBy('line_no')->values();

        return $this->rowOf($j) + [
            'currency' => $this->currency(), 'exponent' => Money::exponent($this->currency()),
            'reverses' => $j->reverses ? ['id' => $j->reverses->id, 'number' => $j->reverses->number] : null,
            'reversed_by' => $reversedBy ? ['id' => $reversedBy->id, 'number' => $reversedBy->number] : null,
            'can_reverse' => in_array($j->kind, ['manual', 'opening'], true) && self::canJournal() && ! $reversedBy,
            'lines' => $lines->map(fn ($l) => ['account' => $l->account?->label(), 'account_id' => $l->account_id, 'branch' => $l->branch?->code, 'memo' => $l->memo,
                'debit' => $l->amount_minor > 0 ? (int) $l->amount_minor : 0, 'credit' => $l->amount_minor < 0 ? -(int) $l->amount_minor : 0])->all(),
            'debits' => (int) $lines->where('amount_minor', '>', 0)->sum('amount_minor'), 'credits' => -(int) $lines->where('amount_minor', '<', 0)->sum('amount_minor'),
        ];
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }
}
