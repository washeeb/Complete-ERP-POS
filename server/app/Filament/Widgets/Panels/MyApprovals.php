<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Pages\PricesToReview;
use App\Filament\Pages\TimeApprovals;
use App\Filament\Resources\DirectPurchases\DirectPurchaseResource;
use App\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\OfficeReturns\OfficeReturnResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockCostRuns\StockCostRunResource;
use App\Filament\Support\Erp;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard: what waits for this person's decision — only kinds they may approve, only where they may approve them.
 * Their own requests are left out (nobody approves their own).
 */
class MyApprovals extends Widget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected string $view = 'filament.widgets.my-approvals';

    /** kind => [label, permission, table, waiting status, list page, branch column (null = company-wide), who asked] */
    private const KINDS = [
        'po' => ['Purchase orders', 'purchasing.approve', 'purchase_orders', 'pending_approval', PurchaseOrderResource::class, 'branch_id', 'created_by'],
        'adjustment' => ['Stock adjustments', 'stock.adjust.approve', 'stock_adjustments', 'submitted', StockAdjustmentResource::class, 'branch_id', 'submitted_by'],
        'return' => ['Returns', 'returns.office.approve', 'office_returns', 'submitted', OfficeReturnResource::class, 'branch_id', 'created_by'],
        'direct' => ['Direct purchases', 'purchasing.direct.approve', 'direct_purchases', 'submitted', DirectPurchaseResource::class, 'branch_id', 'submitted_by'],
        'cost_run' => ['Cost runs', 'stock.cost.approve', 'stock_cost_runs', 'submitted', StockCostRunResource::class, null, 'submitted_by'],
        'claim' => ['Expense claims', 'expenses.approve', 'expense_claims', 'submitted', ExpenseClaimResource::class, 'branch_id', 'claimant_id'],
        'leave' => ['Leave requests', 'leave.approve', 'leave_requests', 'requested', LeaveRequestResource::class, null, null],
        'prices' => ['Prices to review', 'pricing.manage', 'price_suggestions', 'pending', PricesToReview::class, null, null],
    ];

    /** @return list<array{label: string, count: int, url: ?string}> */
    public function items(): array
    {
        $me = Erp::user();
        $out = [];
        foreach (self::KINDS as [$label, $permission, $table, $status, $resource, $branchCol, $askedBy]) {
            if (! Erp::canAnywhere($permission)) {
                continue;
            }
            $branches = $branchCol ? self::branches($permission) : (Erp::branchIds($permission) === null ? null : []);
            if ($branches === []) {
                continue;
            }
            $n = DB::table($table)->where('tenant_id', self::tenantId())->where('status', $status)
                ->when($branchCol && $branches !== null, fn ($q) => $q->whereIn($branchCol, $branches))
                ->when($askedBy, fn ($q) => $q->where(fn ($w) => $w->whereNull($askedBy)->orWhere($askedBy, '<>', $me->id)))
                ->count();
            $isPage = ! method_exists($resource, 'canViewAny');
            $out[] = ['label' => $label, 'count' => $n, 'url' => $isPage ? ($resource::canAccess() ? $resource::getUrl() : null) : ($resource::canViewAny() ? $resource::getUrl('index') : null)];
        }
        if (TimeApprovals::canAccess()) { // time belongs to a branch through its project
            $out[] = ['label' => 'Timesheets', 'count' => TimeApprovals::waiting()->count(), 'url' => TimeApprovals::getUrl()];
        }

        return $out;
    }

    public static function canView(): bool
    {
        return collect(self::KINDS)->contains(fn ($k) => Erp::canAnywhere($k[1])) || TimeApprovals::canAccess();
    }
}
