<?php

namespace App\Domain\Assets;

use App\Domain\Banking\BankingService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\AssetClass;
use App\Models\Branch;
use App\Models\FixedAsset;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The fixed asset register and straight-line monthly depreciation (owner decision 2026-10-13).
 *
 * Registering an asset does not post its cost: the purchase is already in the books (a supplier bill line to the
 * asset account, or the opening balances). Depreciation starts in the month the asset was acquired — or, for an
 * asset brought in at go-live with depreciation already in the opening balances, in the month it was registered —
 * a full month each, (cost − residual) ÷ life, the last month taking the rounding so the total is exact.
 */
final class AssetService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly DocumentNumbers $numbers) {}

    /** @param array{code: string, name: string, life_months: int, cost_account_id: string, accumulated_account_id: string, expense_account_id: string} $data */
    public function saveClass(Actor $actor, AssetClass $class, array $data): AssetClass
    {
        $this->require($actor);
        $accounts = [];
        foreach (['cost_account_id' => 'asset', 'accumulated_account_id' => 'asset', 'expense_account_id' => 'expense'] as $field => $type) {
            $a = Account::find($data[$field] ?? null);
            if (! $a || $a->type !== $type || $a->is_header || $a->control) {
                throw new DomainException('Choose the asset, accumulated depreciation and depreciation expense accounts.');
            }
            $accounts[$field] = $a->id;
        }
        if ((int) ($data['life_months'] ?? 0) < 1 || trim((string) ($data['code'] ?? '')) === '' || trim((string) ($data['name'] ?? '')) === '') {
            throw new DomainException('Give a code, a name and a useful life in months.');
        }
        $class->forceFill(['tenant_id' => $actor->tenantId, 'code' => mb_strtoupper(trim($data['code'])), 'name' => trim($data['name']),
            'life_months' => (int) $data['life_months']] + $accounts);

        return $this->recorder->save($actor, $class, $class->exists ? 'asset_class.updated' : 'asset_class.created');
    }

    /** @param array{name: string, asset_class_id: string, branch_id: string, acquired_on: string, cost_minor: int, residual_minor?: int, life_months?: ?int, serial_no?: ?string, location?: ?string, opening_depreciation_minor?: int} $data */
    public function register(Actor $actor, array $data): FixedAsset
    {
        $this->require($actor);
        $class = AssetClass::find($data['asset_class_id'] ?? null) ?? throw new DomainException('Choose the kind of asset.');
        $branch = Branch::find($data['branch_id'] ?? null) ?? throw new DomainException('Choose the branch that uses it.');
        $cost = (int) ($data['cost_minor'] ?? 0);
        $residual = (int) ($data['residual_minor'] ?? 0);
        $opening = (int) ($data['opening_depreciation_minor'] ?? 0);
        $life = (int) (($data['life_months'] ?? null) ?: $class->life_months);
        if ($cost <= 0 || $residual < 0 || $residual >= $cost || $life < 1 || $opening < 0 || $opening > $cost - $residual) {
            throw new DomainException('Cost must be above zero, the residual value below the cost, and depreciation to date no more than cost less residual.');
        }
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new DomainException('Name the asset.');
        }
        $acquired = Carbon::parse($data['acquired_on'] ?? now()->toDateString());
        if ($acquired->isAfter(now()->endOfDay())) {
            throw new DomainException('The acquisition date cannot be in the future.');
        }

        return $this->recorder->transaction(function () use ($actor, $data, $class, $branch, $cost, $residual, $opening, $life, $acquired) {
            $asset = (new FixedAsset)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'fixed_asset'),
                'name' => mb_substr(trim($data['name']), 0, 255), 'asset_class_id' => $class->id, 'branch_id' => $branch->id, 'serial_no' => ($data['serial_no'] ?? null) ?: null,
                'location' => ($data['location'] ?? null) ?: null, 'acquired_on' => $acquired->toDateString(), 'cost_minor' => $cost, 'residual_minor' => $residual,
                'life_months' => $life, 'opening_depreciation_minor' => $opening, 'status' => 'active']);

            return $this->recorder->save($actor, $asset, 'fixed_asset.registered', branchId: $branch->id);
        });
    }

    /** Sold, scrapped or lost: depreciated up to and including the month it went, then taken off the books (gain or loss to the disposal account). */
    public function dispose(Actor $actor, FixedAsset $asset, string $date, int $proceedsMinor, ?Account $proceedsTo, string $reason): FixedAsset
    {
        $this->require($actor);
        if ($asset->status !== 'active') {
            throw new DomainException('This asset is already disposed of.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Say why (sold, scrapped, stolen…).');
        }
        if ($proceedsMinor < 0 || ($proceedsMinor > 0 && (! $proceedsTo || ! BankingService::isMoneyAccount($proceedsTo)))) {
            throw new DomainException('Say which cash, bank or MoMo account the sale money went into.');
        }
        $day = Carbon::parse($date);
        if ($day->lt($asset->acquired_on) || $day->isAfter(now()->endOfDay())) {
            throw new DomainException('The disposal date must be between acquisition and today.');
        }

        return $this->recorder->transaction(function () use ($actor, $asset, $day, $proceedsMinor, $proceedsTo, $reason) {
            $asset->forceFill(['status' => 'disposed', 'disposed_on' => $day->toDateString(), 'disposal_proceeds_minor' => $proceedsMinor,
                'proceeds_account_id' => $proceedsMinor > 0 ? $proceedsTo->id : null, 'disposal_reason' => mb_substr(trim($reason), 0, 255)]);

            return $this->recorder->save($actor, $asset, 'fixed_asset.disposed', $reason, $asset->branch_id);
        });
    }

    /** First month the system depreciates. */
    public static function startMonth(FixedAsset $a): Carbon
    {
        return ($a->opening_depreciation_minor > 0 ? Carbon::parse($a->created_at) : $a->acquired_on->copy())->startOfMonth();
    }

    /**
     * Depreciation for one month (0 outside the asset's life), given what the system has already charged.
     */
    public static function monthly(FixedAsset $a, int $chargedSoFar, int $monthIndex): int
    {
        $depreciable = $a->cost_minor - $a->residual_minor - $a->opening_depreciation_minor;
        $remaining = $depreciable - $chargedSoFar;
        if ($remaining <= 0) {
            return 0;
        }
        $per = Qty::divRound($a->cost_minor - $a->residual_minor, $a->life_months);
        $monthsLeft = $a->life_months - (int) round($a->opening_depreciation_minor / max(1, $per)) - $monthIndex;

        return $monthsLeft <= 1 ? $remaining : min($per, $remaining);
    }

    /**
     * Work out depreciation for every month that has ended (and the disposal month) not yet charged.
     *
     * @return int rows added
     */
    public function calculate(string $tenantId, ?Carbon $today = null): int
    {
        $lastMonth = ($today ?? now())->copy()->startOfMonth()->subMonth();
        $added = 0;
        foreach (FixedAsset::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('number')->get() as $a) {
            $until = $a->status === 'disposed' ? $a->disposed_on->copy()->startOfMonth() : $lastMonth;
            $done = DB::table('asset_depreciation')->where('fixed_asset_id', $a->id)->orderBy('month')->get(['month', 'amount_minor']);
            $charged = (int) $done->sum('amount_minor');
            $month = $done->isEmpty() ? self::startMonth($a) : Carbon::parse($done->last()->month)->addMonth();
            $index = $done->count();
            $rows = [];
            while ($month->lte($until)) {
                $amount = self::monthly($a, $charged, $index);
                if ($amount <= 0) {
                    break;
                }
                $rows[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'fixed_asset_id' => $a->id, 'month' => $month->toDateString(),
                    'amount_minor' => $amount, 'created_at' => now()];
                $charged += $amount;
                $index++;
                $month = $month->copy()->addMonth();
            }
            if ($rows) {
                DB::table('asset_depreciation')->insert($rows);
                $added += count($rows);
            }
        }

        return $added;
    }

    /** Net book value: cost less depreciation to date (opening and system). */
    public static function bookValue(FixedAsset $a): int
    {
        return $a->cost_minor - $a->opening_depreciation_minor - (int) DB::table('asset_depreciation')->where('fixed_asset_id', $a->id)->sum('amount_minor');
    }

    private function require(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'assets.manage')) {
            throw new AccessDenied;
        }
    }
}
