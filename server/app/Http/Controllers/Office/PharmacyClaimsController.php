<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\NhisClaimsScreen;
use App\Domain\Pharmacy\NhisClaims;
use App\Domain\Pharmacy\PharmacySignoffs;
use App\Domain\Support\Actor;
use App\Filament\Pages\MedicineSignoffs;
use App\Filament\Resources\NhisClaims\NhisClaimResource;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Branch;
use App\Models\NhisClaim;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * NHIS claims and medicines sold without a pharmacist in the new back office (App\Domain\Office\NhisClaimsScreen),
 * through NhisClaims and PharmacySignoffs as the current screens.
 */
class PharmacyClaimsController extends Controller
{
    use ActsOnAccess;

    private function claimOf(string $id): NhisClaim
    {
        return NhisClaimResource::getEloquentQuery()->find($id) ?? abort(404);
    }

    /** A branch the person may claim for (as the current form offers). */
    private function branchOf(string $id): Branch
    {
        abort_unless(array_key_exists($id, NhisClaimResource::branches()), 422, 'Choose a branch you may claim for.');

        return Branch::findOrFail($id);
    }

    public function claims(Request $request): JsonResponse
    {
        $this->allowed(NhisClaimResource::canViewAny());

        return response()->json((new NhisClaimsScreen($request->user()))->claims($request->query()));
    }

    public function unclaimed(Request $request): JsonResponse
    {
        $this->allowed(NhisClaimResource::canViewAny());
        $d = $request->validate(['branch' => ['required', 'uuid'], 'up_to' => ['required', 'date_format:Y-m-d']]);

        return response()->json((new NhisClaimsScreen($request->user()))->unclaimed($this->branchOf($d['branch']), $d['up_to']));
    }

    public function claim(Request $request, string $claim): JsonResponse
    {
        $this->allowed(NhisClaimResource::canViewAny());

        return response()->json((new NhisClaimsScreen($request->user()))->claim($this->claimOf($claim), $request->query()));
    }

    public function prepare(Request $request, NhisClaims $claims): JsonResponse
    {
        $this->allowed(NhisClaimResource::canViewAny());
        $d = $request->validate(['branch' => ['required', 'uuid'], 'up_to' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']],
            ['branch.*' => 'Choose the branch.', 'up_to.*' => 'Choose the last day of sales to claim (not after today).']);
        $branch = $this->branchOf($d['branch']);

        return $this->act(function (Actor $actor) use ($claims, $branch, $d) {
            $c = $claims->prepare($actor, $branch, $d['up_to']);

            return ['id' => $c->id, 'message' => "Claim {$c->number} prepared."];
        });
    }

    public function step(Request $request, string $claim, string $step, NhisClaims $claims): JsonResponse
    {
        $this->allowed(NhisClaimResource::canViewAny());
        $c = $this->claimOf($claim);
        $currency = FinanceUi::currency();
        $d = match ($step) {
            'submit' => $request->validate(['reference' => ['nullable', 'string', 'max:80']]),
            'settle' => $request->validate(['paid' => ['required', 'numeric', 'min:0'], 'paid_on' => ['required', 'date_format:Y-m-d'],
                'method' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:255']],
                ['paid.*' => 'Enter what the NHIA paid (0 or more).', 'paid_on.*' => 'Enter the day it was paid.', 'method.*' => 'Choose how the NHIA paid.']),
            default => $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']),
        };

        return $this->act(function (Actor $actor) use ($claims, $c, $step, $d, $currency) {
            match ($step) {
                'submit' => $claims->submit($actor, $c, (string) ($d['reference'] ?? '')),
                'settle' => $claims->settle($actor, $c, Money::toMinor((string) $d['paid'], $currency), $d['paid_on'], $d['method'], $d['note'] ?? null),
                default => $claims->withdraw($actor, $c, $d['reason']),
            };

            return ['message' => match ($step) {
                'submit' => 'Marked as sent to the NHIA.', 'settle' => 'Payment recorded.', default => 'Claim withdrawn. Its sales go on the next claim.'
            }];
        });
    }

    public function signoffs(Request $request): JsonResponse
    {
        $this->allowed(MedicineSignoffs::canAccess());

        return response()->json((new NhisClaimsScreen($request->user()))->signoffs($request->query()));
    }

    public function sign(Request $request, PharmacySignoffs $signoffs): JsonResponse
    {
        $this->allowed(MedicineSignoffs::canAccess());
        $d = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['uuid'], 'note' => ['nullable', 'string', 'max:255']],
            ['ids.*' => 'Tick the sales to sign off.']);

        return $this->act(function (Actor $actor) use ($signoffs, $d) {
            $n = $signoffs->sign($actor, $d['ids'], $d['note'] ?? null);

            return ['message' => "{$n} sale(s) signed off."];
        });
    }

    /** The list as now filtered, for an inspection (as the current Download (CSV)). */
    public function signoffsCsv(Request $request): StreamedResponse
    {
        $this->allowed(MedicineSignoffs::canAccess());
        $rows = (new NhisClaimsScreen($request->user()))->signoffRows($request->query());
        $branches = Branch::pluck('name', 'id');

        return response()->streamDownload(function () use ($rows, $branches) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Receipt', 'Branch', 'Sold by', 'Medicines', 'Controlled', 'Signed off by', 'Signed off', 'Note'], ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, [Carbon::parse($r->occurred_at)->toDateTimeString(), $r->receipt_no, $branches[$r->branch_id] ?? '', $r->cashier_name, $r->medicines,
                    $r->controlled ? 'yes' : 'no', $r->signed_by_name, $r->signed_at, $r->note], ',', '"', '');
            }
            fclose($out);
        }, 'medicines-sold-without-a-pharmacist.csv', ['Content-Type' => 'text/csv']);
    }
}
