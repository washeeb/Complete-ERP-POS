<?php

namespace App\Domain\Access;

use App\Models\Permission;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who can do what (owner request 2026-10-24): every person with access, what their roles let them do and where, and
 * the risky combinations a fraud usually needs, such as ordering from suppliers and also receiving the goods. Read only. The
 * owner (and SyncLytics support) are left out of the risks: they hold everything by design.
 */
final class AccessReview
{
    /** Days without signing in after which access still held is worth a second look. */
    public const IDLE_DAYS = 60;

    /**
     * A risk is a person who holds at least one permission from every set.
     * key => [severity, what they can do, why it matters, what to do]
     */
    public const RULES = [
        'order_receive' => ['high', [['purchasing.manage'], ['stock.receive']], 'Orders from suppliers and also receives the goods',
            'They could order more than needed and keep the difference, or accept a short delivery as full.',
            'Let a storekeeper receive deliveries, or have receipts approved by a third person.'],
        'supplier_pay' => ['high', [['suppliers.manage'], ['payables.pay']], 'Adds or changes suppliers and also pays them',
            'They could add a made-up supplier (or change a supplier\'s bank details) and pay it.',
            'Keep supplier set-up and paying suppliers with different people.'],
        'collect_writeoff' => ['high', [['receivables.collect'], ['receivables.credit_note']], 'Collects customer payments and can reduce what customers owe',
            'They could keep a payment and cancel the debt with a credit note.',
            'Give credit notes to someone who does not collect money.'],
        'till_shortage' => ['high', [['pos.access'], ['cash.variance.approve']], 'Sells at a till and approves till shortages',
            'They could cover a shortage at their own till.',
            'Have shortages approved by someone who does not sell.'],
        'ghost_staff' => ['high', [['hr.manage'], ['payroll.pay']], 'Adds staff and pays wages',
            'They could pay someone who does not work here.',
            'Keep staff records and paying wages with different people.'],
        'role_editor' => ['high', [['admin.roles.manage']], 'Changes what each role allows',
            'They cannot give themselves a role, but they can add rights to a role they already hold.',
            'Keep this with the owner, or with an IT person who holds no business role.'],
        'bill_pay' => ['medium', [['payables.manage'], ['payables.pay']], 'Enters supplier bills and pays them',
            'Bills still need someone else\'s approval, but entering and paying sit with one person.',
            'Where staff numbers allow, split entering and paying.'],
        'expense_pay' => ['medium', [['expenses.approve'], ['expenses.pay']], 'Approves expenses and pays them',
            'Own claims need someone else, but they approve and pay everyone else\'s.',
            'Where staff numbers allow, split approving and paying.'],
        'stock_adjust' => ['medium', [['stock.adjust'], ['stock.adjust.approve']], 'Changes stock quantities and approves the changes',
            'Missing stock could be written off without a second person seeing it.',
            'Have stock changes approved by someone who does not make them.'],
        'stock_count' => ['medium', [['stock.count'], ['stock.count.approve']], 'Counts stock and approves the count',
            'A count could be made to match what is missing.',
            'Have counts approved by someone who did not count.'],
        'refund_close' => ['medium', [['pos.return.no_receipt'], ['cash.control', 'day.close']], 'Refunds without a receipt and checks the day\'s cash',
            'A made-up refund could take cash out and be closed off by the same person.',
            'Keep refunds without a receipt with supervisors who do not close the day.'],
        'payroll_prepare' => ['medium', [['payroll.prepare'], ['payroll.approve']], 'Prepares the payroll and approves it',
            'Pay could be changed without a second person seeing it.',
            'Have the payroll approved by someone who did not prepare it.'],
        'bank_books' => ['medium', [['banking.manage'], ['finance.journal']], 'Handles the bank accounts and writes journal entries',
            'A missing amount could be hidden in the books.',
            'Have journal entries reviewed by someone who does not handle the bank.'],
        'deposit_bank' => ['medium', [['cash.deposit'], ['banking.manage']], 'Takes cash to the bank and checks the bank statement',
            'A short deposit could go unnoticed.',
            'Have deposits checked against the bank by someone else.'],
        'price_sell' => ['medium', [['pricing.manage'], ['pos.access']], 'Changes prices and sells at a till',
            'A price could be lowered for a sale and put back afterwards.',
            'Keep price changes with people who do not sell, or check the audit log for price changes.'],
    ];

    /**
     * @return array{people: list<array>, risks: list<array>, notes: list<array>, counts: array<string, int>}
     */
    public function build(string $tenantId): array
    {
        $grants = RoleAssignment::withoutGlobalScopes()->where('tenant_id', $tenantId)->active()
            ->with(['role.permissions', 'user'])->get()
            ->filter(fn (RoleAssignment $a) => $a->user && $a->role && $a->role->tenant_id === $tenantId && ! $a->user->isSupport());
        $levels = Permission::pluck('min_level', 'key');
        $labels = Permission::pluck('label', 'key');
        $scopes = [];
        $people = [];
        foreach ($grants->groupBy('user_id') as $userGrants) {
            /** @var User $user */
            $user = $userGrants->first()->user;
            $held = []; // permission => places
            $roles = [];
            foreach ($userGrants as $g) {
                $where = $scopes[$g->scope_type->value.':'.$g->scope_id] ??= $g->scopeLabel();
                $roles[] = ['role' => $g->role->name, 'where' => $where, 'until' => $g->expires_at?->toDateString(), 'reason' => $g->reason];
                if ($g->role->security_level > $user->security_level) {
                    continue; // above their clearance: the system ignores it
                }
                foreach ($g->role->permissions as $p) {
                    if (($levels[$p->key] ?? 99) <= $user->security_level) {
                        $held[$p->key][$where] = true;
                    }
                }
            }
            $owner = $userGrants->contains(fn ($g) => $g->role->key === 'owner');
            $people[] = ['user' => $user, 'owner' => $owner, 'roles' => $roles, 'held' => $held,
                'risks' => $owner || ! $user->is_active ? [] : $this->risksFor($held)];
        }
        usort($people, fn ($a, $b) => [$b['owner'], count($b['risks'])] <=> [$a['owner'], count($a['risks'])] ?: strcmp($a['user']->name, $b['user']->name));

        $risks = [];
        foreach (self::RULES as $key => [$severity, $sets, $title, $why, $fix]) {
            $who = collect($people)->filter(fn ($p) => in_array($key, $p['risks'], true))
                ->map(fn ($p) => ['name' => $p['user']->name, 'id' => $p['user']->id, 'where' => $this->places($p['held'], array_merge(...$sets)),
                    'via' => $this->via($p['roles'])])->values()->all();
            if ($who) {
                $risks[] = compact('key', 'severity', 'title', 'why', 'fix', 'who') + ['needs' => array_map(fn ($set) => implode(' or ', array_map(fn ($k) => $labels[$k] ?? $k, $set)), $sets)];
            }
        }

        $notes = [];
        $idle = now()->subDays(self::IDLE_DAYS);
        foreach ($people as $p) {
            $u = $p['user'];
            if (! $u->is_active) {
                $notes[] = ['tone' => 'warning', 'id' => $u->id, 'text' => "{$u->name} is switched off but still has ".count($p['roles']).' role(s) given: take them away so they are not forgotten if the account is switched back on.'];
            } elseif ($u->last_login_at === null || $u->last_login_at->lt($idle)) {
                $notes[] = ['tone' => 'warning', 'id' => $u->id, 'text' => $u->name.($u->last_login_at ? ' has not signed in since '.$u->last_login_at->toDateString() : ' has never signed in').
                    ' but still has access: take it away if they no longer need it.'];
            }
            foreach ($p['roles'] as $r) {
                if ($r['until']) {
                    $notes[] = ['tone' => 'info', 'id' => $u->id, 'text' => "{$u->name}: {$r['role']} ({$r['where']}) is temporary, until {$r['until']}".($r['reason'] ? " — {$r['reason']}" : '').'.'];
                }
            }
        }

        return ['people' => $people, 'risks' => $risks, 'notes' => $notes, 'counts' => [
            'people' => count($people),
            'high' => collect($risks)->where('severity', 'high')->sum(fn ($r) => count($r['who'])),
            'medium' => collect($risks)->where('severity', 'medium')->sum(fn ($r) => count($r['who'])),
        ]];
    }

    /** @param array<string, array<string, true>> $held @return list<string> rule keys this person trips */
    public function risksFor(array $held): array
    {
        $out = [];
        foreach (self::RULES as $key => [, $sets]) {
            if (collect($sets)->every(fn ($set) => collect($set)->contains(fn ($k) => isset($held[$k])))) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** Where the person holds the permissions of a rule (e.g. "Branch: Osu"). */
    private function places(array $held, array $keys): string
    {
        return collect($keys)->flatMap(fn ($k) => array_keys($held[$k] ?? []))->unique()->implode(', ');
    }

    private function via(array $roles): string
    {
        return collect($roles)->pluck('role')->unique()->implode(', ');
    }

    /** One row per person for the export: name, roles, where, risk count. */
    public function rows(array $review): Collection
    {
        return collect($review['people'])->map(fn ($p) => [
            'Person' => $p['user']->name, 'Username' => $p['user']->username, 'Active' => $p['user']->is_active ? 'Yes' : 'No',
            'Last signed in' => $p['user']->last_login_at?->toDateTimeString() ?? 'Never',
            'Roles' => collect($p['roles'])->map(fn ($r) => "{$r['role']} ({$r['where']})".($r['until'] ? " until {$r['until']}" : ''))->implode('; '),
            'Risky combinations' => $p['owner'] ? 'Owner' : collect($p['risks'])->map(fn ($k) => self::RULES[$k][2])->implode('; '),
        ]);
    }
}
