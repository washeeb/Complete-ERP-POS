<?php

namespace App\Domain\Office;

use App\Domain\Assets\AssetService;
use App\Filament\Resources\AssetClasses\AssetClassResource;
use App\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\AssetClass;
use App\Models\FixedAsset;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fixed assets in the new back office (release 8 of the rewrite), as the current screens: the register (search, status,
 * kind; cost and book value), one asset with the depreciation charged month by month, Register an asset, Dispose of,
 * and the kinds of asset (useful life and the three accounts each posts to), through AssetService (AssetsController).
 * Registering does not post the cost: that is a supplier bill or the opening balances, as now.
 */
final class AssetsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['number', 'acquired_on', 'cost_minor', 'name'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return FixedAssetResource::canViewAny();
    }

    public static function canManage(): bool
    {
        return Erp::canAnywhere('assets.manage') && config('erp.node.role') !== 'branch';
    }

    public static function canClasses(): bool
    {
        return AssetClassResource::canViewAny();
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    public function filtered(array $p): Builder
    {
        $q = FixedAssetResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('fixed_assets.number', 'ilike', "%{$search}%")->orWhere('fixed_assets.name', 'ilike', "%{$search}%")
                ->orWhere('fixed_assets.serial_no', 'ilike', "%{$search}%"));
        }
        if (in_array($p['status'] ?? '', ['active', 'disposed'], true)) {
            $q->where('fixed_assets.status', $p['status']);
        }
        if (filled($p['kind'] ?? null)) {
            $q->where('fixed_assets.asset_class_id', (string) $p['kind']);
        }

        return $q;
    }

    public function rowOf(FixedAsset $a): array
    {
        return ['id' => $a->id, 'number' => $a->number, 'name' => $a->name, 'serial_no' => $a->serial_no, 'kind' => $a->assetClass?->name, 'branch' => $a->branch?->code,
            'acquired_on' => $a->acquired_on?->toDateString(), 'cost' => (int) $a->cost_minor, 'book' => $a->status === 'disposed' ? 0 : AssetService::bookValue($a), 'status' => $a->status];
    }

    /** @param  array<string, mixed>  $p  q, status, kind, sort, dir, page, per */
    public function list(array $p): array
    {
        $sort = in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'number';
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $t = (clone $this->filtered($p))->toBase()->reorder()->selectRaw('COUNT(*) AS n, COALESCE(SUM(cost_minor), 0) AS cost')->first();
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency), 'total' => (int) $t->n, 'cost' => (int) $t->cost, 'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->filtered($p)->orderBy("fixed_assets.{$sort}", $dir)->orderBy('fixed_assets.id', $dir)->forPage($page, $per)->get()->map(fn (FixedAsset $a) => $this->rowOf($a))->all(),
        ];
    }

    public function options(): array
    {
        $manage = self::canManage();

        return [
            'per' => self::PER_PAGE, 'can_manage' => $manage, 'can_classes' => self::canClasses(),
            'kinds' => AssetClass::orderBy('code')->get()->map(fn ($c) => ['id' => $c->id, 'label' => $c->name, 'life_months' => $c->life_months])->all(),
            'branches' => $manage ? PurchaseOrdersScreen::choices(FinanceUi::branches()) : [], 'hq' => (string) FinanceUi::hq(),
            'money_accounts' => $manage ? PurchaseOrdersScreen::choices(FinanceUi::moneyAccounts()) : [],
        ];
    }

    public function find(string $id): ?FixedAsset
    {
        return FixedAssetResource::getEloquentQuery()->find($id);
    }

    /** One asset: its facts, its book value now, how it was disposed of, and the depreciation charged month by month. */
    public function show(FixedAsset $a): array
    {
        $currency = $this->currency();

        return $this->rowOf($a) + [
            'currency' => $currency, 'exponent' => Money::exponent($currency), 'location' => $a->location, 'residual' => (int) $a->residual_minor, 'life_months' => $a->life_months,
            'opening_depreciation' => (int) $a->opening_depreciation_minor, 'book_now' => AssetService::bookValue($a),
            'disposal' => $a->disposal_reason ? ['date' => $a->disposed_on?->format('j M Y'), 'reason' => $a->disposal_reason, 'proceeds' => (int) $a->disposal_proceeds_minor] : null,
            'can_dispose' => $a->status === 'active' && self::canManage(),
            'schedule' => DB::table('asset_depreciation')->where('fixed_asset_id', $a->id)->orderBy('month')->get()
                ->map(fn ($d) => ['month' => Carbon::parse($d->month)->format('M Y'), 'amount' => (int) $d->amount_minor])->all(),
        ];
    }

    /** Kinds of asset: their code, name, useful life and the accounts each posts to. */
    public function classes(): array
    {
        $manage = Erp::canAnywhere('assets.manage');
        $classes = AssetClass::orderBy('code')->get();
        $labels = Account::whereIn('id', $classes->flatMap(fn ($c) => [$c->cost_account_id, $c->accumulated_account_id, $c->expense_account_id])->filter()->unique())
            ->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()]);

        return [
            'can_manage' => $manage,
            'accounts' => $manage ? ['asset' => PurchaseOrdersScreen::choices(FinanceUi::accounts(withControl: false, types: ['asset'])),
                'expense' => PurchaseOrdersScreen::choices(FinanceUi::accounts(withControl: false, types: ['expense']))] : null,
            'rows' => $classes->map(fn (AssetClass $c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'life_months' => $c->life_months, 'cost_account_id' => $c->cost_account_id,
                'accumulated_account_id' => $c->accumulated_account_id, 'expense_account_id' => $c->expense_account_id, 'cost_account' => $labels[$c->cost_account_id] ?? null,
                'accumulated_account' => $labels[$c->accumulated_account_id] ?? null, 'expense_account' => $labels[$c->expense_account_id] ?? null])->all(),
        ];
    }
}
