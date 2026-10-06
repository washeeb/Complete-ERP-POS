<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ExpenseClaim;
use App\Models\User;
use App\Security\Authorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The receipt photo of an expense claim: for the claimant and anyone who may approve, pay or audit it. */
class ExpenseReceiptController extends Controller
{
    public function __invoke(Request $request, ExpenseClaim $claim, Authorizer $authz): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->tenant_id === $claim->tenant_id, 404);
        $branch = Branch::findOrFail($claim->branch_id);
        abort_unless($claim->claimant_id === $user->id || $authz->allows($user, 'expenses.approve', $branch) || $authz->allows($user, 'expenses.pay', $branch)
            || $authz->allows($user, 'finance.view'), 403);
        abort_unless($claim->receipt_path && Storage::disk('local')->exists($claim->receipt_path), 404);

        return Storage::disk('local')->response($claim->receipt_path);
    }
}
