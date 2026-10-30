<?php

namespace App\Domain\Pos;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Shift;
use App\Models\Till;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultCover;
use App\Security\Authorizer;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * Personal vaults (owner request 2026-10-30): each teller holds her own vault (a removable cash tray by default) and a
 * till opens only on a vault she holds, or one a manager lends her for the day, so nobody sells in someone else's name.
 *
 *   Light flow ("manager"): the vault opens with its standard float, the teller counts blind at close, and a manager
 *   recounts the tray blind (one recount when the two counts differ); the shift's over/short is posted once checked.
 *   Teller flow ("teller"): the teller's count at close stands, as without vaults.
 *
 * The full Cashier → Income Controller chain stays the separate "Cash control with vaults" setting (CashControl).
 */
final class TellerVaults
{
    public const FLOWS = ['off', 'manager', 'teller'];

    public function __construct(private readonly Settings $settings, private readonly Recorder $recorder, private readonly Authorizer $authz,
        private readonly CashControl $cash, private readonly SupervisorApproval $approval) {}

    /** 'off', 'manager' or 'teller'. Off while the full cashier chain is on: that chain issues its own vaults. */
    public function flow(string $tenantId): string
    {
        if ($this->cash->enabled($tenantId)) {
            return 'off';
        }
        $f = (string) $this->settings->get('cash.personal_vaults', $tenantId);

        return in_array($f, self::FLOWS, true) ? $f : 'off';
    }

    public function enabled(string $tenantId): bool
    {
        return $this->flow($tenantId) !== 'off';
    }

    /** 'tray' (removable tray, any till of the branch), 'till' (the vault is one till's drawer) or 'shared' (one drawer, a record of whose cash is whose). */
    public function trays(string $tenantId): string
    {
        return (string) $this->settings->get('cash.vault_trays', $tenantId);
    }

    /** Add or change a personal vault (Cash → Shifts and vaults). */
    public function save(Actor $actor, Vault $vault, array $data): Vault
    {
        $branchId = (string) ($data['branch_id'] ?? $vault->branch_id);
        $till = filled($data['till_id'] ?? null) ? Till::findOrFail($data['till_id']) : null;
        if (! $actor->user || ! $this->authz->allows($actor->user, 'cash.setup', $till ?? Branch::find($branchId))) {
            throw new AccessDenied;
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new DomainException('Give the vault a name, e.g. Vault 1.');
        }
        if ($till && $till->branch_id !== $branchId) {
            throw new DomainException('That till is in another branch.');
        }
        if (! $till && $this->trays($actor->tenantId) === 'till') {
            throw new DomainException('Each teller works on her own till: choose the till this vault belongs to.');
        }
        $holder = filled($data['holder_id'] ?? null) ? User::findOrFail($data['holder_id']) : null;
        if ($holder && ! $this->canSellAt($holder, $branchId, $till)) {
            throw new DomainException("{$holder->name} is not set up to sell at this branch.");
        }
        if ($holder && Vault::where('holder_id', $holder->id)->where('is_active', true)->when($vault->exists, fn ($q) => $q->where('id', '<>', $vault->id))
            ->where('branch_id', $branchId)->exists()
            && ($data['is_active'] ?? true)) {
            throw new DomainException("{$holder->name} already holds a vault at this branch. One vault per person keeps each person's cash apart.");
        }
        if (Vault::where('name', $name)->where('branch_id', $branchId)->when($vault->exists, fn ($q) => $q->where('id', '<>', $vault->id))->exists()) {
            throw new DomainException('Another vault of this branch has that name.');
        }
        $float = (int) ($data['standard_float_minor'] ?? 0);
        if ($float < 0) {
            throw new DomainException('The float cannot be negative.');
        }
        $vault->fill(['name' => mb_substr($name, 0, 80), 'standard_float_minor' => $float, 'is_active' => (bool) ($data['is_active'] ?? true)]);
        $vault->forceFill(['tenant_id' => $actor->tenantId, 'branch_id' => $branchId, 'till_id' => $till?->id, 'holder_id' => $holder?->id]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $vault, $vault->exists ? 'vault.updated' : 'vault.created', branchId: $branchId));
    }

    /** May this person sell on that till, or (a tray for any till) on at least one till of the branch? Tellers are often given one till only. */
    public function canSellAt(User $user, string $branchId, ?Till $till = null): bool
    {
        if (! $user->is_active) {
            return false;
        }
        $tills = $till ? collect([$till]) : Till::where('branch_id', $branchId)->where('is_active', true)->get();

        return $tills->contains(fn (Till $t) => $this->authz->allows($user, 'pos.access', $t));
    }

    /** Personal vaults of a branch (not the per-till-per-shift vaults of the cashier chain). */
    public function ofBranch(string $branchId): Collection
    {
        return Vault::with(['holder', 'till'])->where('branch_id', $branchId)->whereNull('shift_template_id')->orderBy('name')->get();
    }

    /**
     * The vaults this person may open a shift on at this till today: the one she holds (unless lent to someone else
     * today) and any lent to her today. Each with the till it is open on, when it is in use.
     *
     * @return Collection<int, array{vault: Vault, lent_by: ?string, in_use: ?string}>
     */
    public function availableFor(User $user, Till $till): Collection
    {
        $day = $this->cash->today($till->tenant_id);
        $covers = VaultCover::where('business_date', $day)->where('branch_id', $till->branch_id)->get();
        $lentAway = $covers->where('user_id', '<>', $user->id)->pluck('vault_id')->all();
        $lentToMe = $covers->where('user_id', $user->id)->keyBy('vault_id');
        $tillOnly = $this->trays($till->tenant_id) === 'till';

        return Vault::with('till')->where('branch_id', $till->branch_id)->whereNull('shift_template_id')->where('is_active', true)
            ->where(fn ($q) => $q->where('holder_id', $user->id)->orWhereIn('id', $lentToMe->keys()->all()))
            ->orderBy('name')->get()
            ->reject(fn (Vault $v) => in_array($v->id, $lentAway, true) && ! $lentToMe->has($v->id))
            ->filter(fn (Vault $v) => ! $tillOnly || $v->till_id === null || $v->till_id === $till->id)
            ->map(fn (Vault $v) => ['vault' => $v, 'lent_by' => $lentToMe->get($v->id)?->granted_by_name, 'in_use' => $this->inUse($v)])
            ->values();
    }

    /** "T02 (Ama)" when the vault is open on a till, else null. */
    public function inUse(Vault $vault): ?string
    {
        $s = Shift::with('till')->where('vault_id', $vault->id)->where('status', 'open')->first();

        return $s ? ($s->till?->code ?? 'a till')." ({$s->opened_by_name})" : null;
    }

    /** The vault a shift opens on (online it must be hers, free and allowed at this till; an offline replay is taken as it was). */
    public function forOpen(User $user, Till $till, ?string $vaultId, bool $offline): ?Vault
    {
        if (! $this->enabled($till->tenant_id)) {
            return null;
        }
        if (! $vaultId) {
            if ($offline) {
                return null;
            }
            throw new DomainException('Choose your vault: each person sells only from her own vault. No vault yet? A manager can lend you one for today.', 'no_vault');
        }
        $vault = Vault::find($vaultId);
        if ($offline) {
            return $vault;
        }
        $mine = $vault ? $this->availableFor($user, $till)->firstWhere('vault.id', $vault->id) : null;
        if (! $mine) {
            throw new DomainException('That vault is not yours. Choose your own vault, or ask a manager to lend you one for today.', 'not_your_vault');
        }
        if ($mine['in_use']) {
            throw new DomainException("{$vault->name} is already open on {$mine['in_use']}. Close it there first.", 'vault_in_use');
        }

        return $vault;
    }

    /** A manager lends a vault to someone for today (the holder is away, or cover). Replaces an earlier loan of that vault today. */
    public function lend(Actor $actor, Vault $vault, User $to, string $reason): VaultCover
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'cash.vault.lend', $vault->till ?? $vault->branch)) {
            throw new AccessDenied;
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new DomainException('Give the reason.');
        }
        if ($to->id === $actor->id) {
            throw new DomainException('Another manager must lend a vault to you.');
        }
        if ($to->id === $vault->holder_id) {
            throw new DomainException("{$vault->name} is already {$to->name}'s.");
        }
        if (! $this->canSellAt($to, $vault->branch_id, $vault->till)) {
            throw new DomainException("{$to->name} is not set up to sell at this branch.");
        }
        if ($this->inUse($vault)) {
            throw new DomainException("{$vault->name} is open on {$this->inUse($vault)}: it must be closed before it is lent.");
        }
        $day = $this->cash->today($actor->tenantId);
        $cover = VaultCover::where('vault_id', $vault->id)->where('business_date', $day)->first() ?? new VaultCover;
        $cover->forceFill(['tenant_id' => $actor->tenantId, 'branch_id' => $vault->branch_id, 'vault_id' => $vault->id, 'user_id' => $to->id, 'user_name' => $to->name,
            'business_date' => $day, 'granted_by' => $actor->id, 'granted_by_name' => $actor->name, 'reason' => mb_substr(trim($reason), 0, 255)]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $cover, 'vault.lent', "{$vault->name} to {$to->name}: ".trim($reason), $vault->branch_id));
    }

    /** At the till: a manager lends a vault to the person signed in, with their username and PIN. */
    public function lendByPin(Device $device, User $to, Vault $vault, array $approval, string $reason): VaultCover
    {
        if ($vault->branch_id !== $device->branch_id) {
            throw new DomainException('That vault is in another branch.');
        }
        $manager = $this->approval->verify($device, $approval, 'cash.vault.lend', $vault->till ?? $device->till, null, 0, $to->id);

        return $this->lend(Actor::user($manager, 'pos', $device->id), $vault, $to, $reason);
    }

    /**
     * Light flow: a manager recounts a closed vault blind. A count that differs from the teller's is not taken the first
     * time (one recount, still blind); the second stands. The shift's over/short becomes the manager's count less expected.
     *
     * @return array{status: 'recount'|'checked', shift: Shift}
     */
    public function check(Actor $actor, Shift $shift, ?array $denoms, ?int $amountMinor = null): array
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'cash.vault.check', $shift->till ?? $shift->branch)) {
            throw new AccessDenied;
        }
        if (! $shift->vault_id || $shift->status !== 'closed') {
            throw new DomainException('Only a closed shift on a vault is recounted.');
        }
        if ($shift->checked_at) {
            throw new DomainException('This vault has been counted already.');
        }
        if (in_array($actor->id, [$shift->opened_by, $shift->closed_by], true)) {
            throw new DomainException('Someone else must count your own vault.');
        }
        [$count, $clean] = CashControl::total($shift->currency_code, $denoms, $amountMinor);
        if ($count !== (int) $shift->counted_cash_minor && $shift->check_recounts < 1) {
            $shift->forceFill(['check_recounts' => $shift->check_recounts + 1]);
            $this->recorder->save($actor, $shift, 'shift.vault_recount_requested', branchId: $shift->branch_id);

            return ['status' => 'recount', 'shift' => $shift];
        }
        $shift->forceFill(['checked_count_minor' => $count, 'checked_denoms' => $clean, 'checked_by' => $actor->id, 'checked_by_name' => $actor->name,
            'checked_at' => now(), 'over_short_minor' => $count - (int) $shift->expected_cash_minor]);

        return ['status' => 'checked', 'shift' => $this->recorder->transaction(fn () => $this->recorder->save($actor, $shift, 'shift.vault_checked', branchId: $shift->branch_id))];
    }

    /** Closed vault shifts of a branch still waiting for the manager's count (light flow). */
    public function waiting(?array $branchIds): Collection
    {
        return Shift::with('vault')->whereNotNull('vault_id')->where('status', 'closed')->whereNull('checked_at')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->orderBy('closed_at')->get();
    }
}
