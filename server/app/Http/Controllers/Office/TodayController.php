<?php

namespace App\Http\Controllers\Office;

use App\Domain\Messaging\DailySummary;
use App\Domain\Reports\OwnerToday;
use App\Filament\Pages\BatchesExpiry;
use App\Filament\Pages\ExpiryReturns;
use App\Filament\Pages\OwnerTodayPage;
use App\Filament\Pages\SystemHealthPage;
use App\Filament\Resources\ReviewItems\ReviewItemResource;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Today in the new back office (part 37): the owner's phone view of the business today (or yesterday), as the current
 * page — the same OwnerToday figures, with each "needs attention" item linked to the new page that deals with it.
 */
class TodayController extends Controller
{
    use ActsOnAccess;

    public function show(Request $request, OwnerToday $today): JsonResponse
    {
        $this->allowed(OwnerTodayPage::canAccess());
        $tenant = Tenant::findOrFail($request->user()->tenant_id);
        $yesterday = $request->boolean('yesterday');
        $day = $yesterday ? Carbon::now($tenant->timezone ?: 'UTC')->subDay() : null;
        $d = $today->build($tenant, Erp::branchIds(DailySummary::PERMISSION), $day);
        $d['attention'] = array_map(fn ($a) => $a + ['open' => self::open($a['link'] ?? null)], $d['attention']);
        $d['payments'] = collect($d['payments'])->map(fn ($v, $k) => ['label' => $k, 'value' => $v])->values()->all();

        return response()->json(['yesterday' => $yesterday] + $d);
    }

    /** Where each kind of attention item is dealt with (only the pages this person may open), as the current page. */
    private static function open(?string $kind): ?string
    {
        return match ($kind) {
            'health' => SystemHealthPage::canAccess() ? '/office/system-health' : null,
            'review' => ReviewItemResource::canViewAny() ? '/office/review-queue' : null,
            'expiry' => ExpiryReturns::canAccess() ? '/office/expiry' : (BatchesExpiry::canAccess() ? '/office/batches' : null),
            default => null,
        };
    }
}
