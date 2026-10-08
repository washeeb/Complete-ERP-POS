<?php

namespace App\Domain\Finance;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Security\Authorizer;
use App\Support\Exports\Exporter;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The general ledger for a period as journal files other accounting programs import (owner decision 2026-10-28:
 * QuickBooks, Xero and Sage), for an accountant who keeps the books there. One journal per entry, every line with the
 * account code and name; amounts in the company currency. Accounts must exist in the other program with the same codes
 * (or be mapped there when importing).
 */
final class AccountingExport
{
    public const FORMATS = [
        'quickbooks' => 'QuickBooks Online — journal entries (CSV)',
        'xero' => 'Xero — manual journals (CSV)',
        'sage' => 'Sage 50 / Pastel — audit trail journals (CSV)',
    ];

    public function __construct(private readonly Authorizer $authz, private readonly Exporter $exporter) {}

    /** @return array{headings: list<string>, rows: list<list<string>>} */
    public function sheet(string $tenantId, string $format, string $from, string $to): array
    {
        $lines = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')->where('e.tenant_id', $tenantId)->whereBetween('e.entry_date', [$from, $to])
            ->orderBy('e.entry_date')->orderBy('e.number')->orderBy('l.line_no')
            ->get(['e.number', 'e.entry_date', 'e.memo', 'e.currency_code', 'a.code', 'a.name', 'l.amount_minor', 'l.memo as line_memo', 'b.code as branch']);
        $dp = Money::exponent((string) (DB::table('tenants')->where('id', $tenantId)->value('currency_code') ?? 'GHS'));
        $amount = fn (int $minor) => number_format(abs($minor) / 10 ** $dp, $dp, '.', '');
        $date = fn (string $d, string $f) => Carbon::parse($d)->format($f);
        $memo = fn ($l) => mb_substr(trim($l->line_memo ?: $l->memo), 0, 200);

        return match ($format) {
            'quickbooks' => ['headings' => ['Journal No', 'Journal Date', 'Currency Code', 'Memo', 'Account', 'Debits', 'Credits', 'Description', 'Location'],
                'rows' => $lines->map(fn ($l) => [$l->number, $date($l->entry_date, 'd/m/Y'), $l->currency_code, mb_substr((string) $l->memo, 0, 200), "{$l->code} {$l->name}",
                    $l->amount_minor > 0 ? $amount($l->amount_minor) : '', $l->amount_minor < 0 ? $amount($l->amount_minor) : '', $memo($l), (string) $l->branch])->all()],
            'xero' => ['headings' => ['*Narration', '*Date', 'Description', '*AccountCode', '*TaxRate', '*Amount', 'TrackingName1', 'TrackingOption1'],
                'rows' => $lines->map(fn ($l) => ["{$l->number} ".mb_substr((string) $l->memo, 0, 180), $date($l->entry_date, 'd/m/Y'), $memo($l), $l->code, 'Tax Exempt',
                    ($l->amount_minor < 0 ? '-' : '').$amount($l->amount_minor), $l->branch ? 'Branch' : '', (string) $l->branch])->all()],
            'sage' => ['headings' => ['Type', 'Account Reference', 'Nominal A/C Ref', 'Department Code', 'Date', 'Reference', 'Details', 'Net Amount', 'Tax Code', 'Tax Amount'],
                'rows' => $lines->map(fn ($l) => [$l->amount_minor >= 0 ? 'JD' : 'JC', '', $l->code, '', $date($l->entry_date, 'd/m/Y'), mb_substr($l->number, 0, 30), $memo($l),
                    $amount($l->amount_minor), 'T9', '0.00'])->all()],
            default => throw new DomainException('Choose QuickBooks, Xero or Sage.'),
        };
    }

    public function download(Actor $actor, string $format, string $from, string $to): StreamedResponse
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'finance.view') || $this->authz->branchIds($actor->user, 'finance.view') !== null) {
            throw new AccessDenied; // the whole company's books: a company-wide finance role
        }
        if ($from > $to) {
            throw new DomainException('The start date is after the end date.');
        }
        $sheet = $this->sheet($actor->tenantId, $format, $from, $to);
        if (! $sheet['rows']) {
            throw new DomainException('No journal entries in that period. Post to the accounts first (Finance → Posting).');
        }

        return $this->exporter->csv($actor, ucfirst($format)." journals {$from} to {$to}", [['title' => 'Journals'] + $sheet], ["From {$from}", "To {$to}", self::FORMATS[$format]]);
    }
}
