<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\User;
use App\Security\Authorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The photo on a stock adjustment line: for anyone who may see that store's stock (or adjust or approve there). */
class StockPhotoController extends Controller
{
    public function __invoke(Request $request, string $line, Authorizer $authz): StreamedResponse
    {
        $l = StockAdjustmentLine::findOrFail($line);
        $a = StockAdjustment::findOrFail($l->stock_adjustment_id);
        $user = $request->user();
        abort_unless($user instanceof User && $user->tenant_id === $a->tenant_id, 404);
        abort_unless($authz->allowsAny($user, ['stock.view', 'stock.adjust', 'stock.adjust.approve'], Branch::findOrFail($a->branch_id)), 403);
        abort_unless($l->photo_path && Storage::disk('local')->exists($l->photo_path), 404);

        return Storage::disk('local')->response($l->photo_path);
    }
}
