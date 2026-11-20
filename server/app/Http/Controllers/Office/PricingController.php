<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\PricingScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Pricing\PricingRules;
use App\Domain\Support\Actor;
use App\Filament\Resources\PricingRules\PricingRuleResource;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\PricingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pricing rules and Prices to review in the new back office (App\Domain\Office\PricingScreen), through PricingRules and
 * ArchiveService, as the current screens. Every rule (values, one active rule per place and list, who may price which
 * industry) stays in PricingRules.
 */
class PricingController extends Controller
{
    use ActsOnAccess;

    public function rules(Request $request): JsonResponse
    {
        $this->allowed(PricingScreen::canRules());

        return response()->json((new PricingScreen($request->user()))->rules($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed(PricingScreen::canRules());

        return response()->json((new PricingScreen($request->user()))->options());
    }

    public function products(Request $request): JsonResponse
    {
        $this->allowed(PricingScreen::canRules());

        return response()->json(['rows' => (new PricingScreen($request->user()))->products((string) $request->query('q', ''))]);
    }

    public function save(Request $request, PricingRules $pricing, ?string $rule = null): JsonResponse
    {
        $this->allowed(PricingScreen::canRules());
        $r = $rule ? (PricingRuleResource::getEloquentQuery()->find($rule) ?? abort(404)) : new PricingRule;
        abort_unless($rule ? PricingRuleResource::canEdit($r) : PricingRuleResource::canCreate(), 403, 'You cannot change pricing rules.');
        $d = $request->validate(['scope' => ['required', 'string'], 'category_id' => ['nullable', 'uuid'], 'product_id' => ['nullable', 'uuid'], 'price_list_id' => ['required', 'uuid'],
            'method' => ['required', 'string'], 'value' => ['nullable', 'numeric'], 'amount' => ['nullable', 'numeric'], 'tax_mode' => ['nullable', 'string'],
            'round_minor' => ['nullable', 'integer'], 'round_mode' => ['nullable', 'string'], 'on_change' => ['nullable', 'string'], 'is_active' => ['boolean']],
            ['price_list_id.required' => 'Choose the price list the rule sets.']);

        return $this->act(fn (Actor $actor) => ['id' => $pricing->saveRule($actor, $r, $d)->id, 'message' => 'Rule saved.']);
    }

    /** Every product with a rule gets the price its latest cost gives (rises at once where the rule says; the rest wait). */
    public function applyAll(PricingRules $pricing): JsonResponse
    {
        $this->allowed(PricingScreen::canRules());
        abort_unless(PricingRuleResource::canCreate(), 403, 'You cannot apply pricing rules.');
        @set_time_limit(1800);

        return $this->act(function (Actor $actor) use ($pricing) {
            $r = $pricing->followAll($actor);

            return ['message' => "{$r['applied']} price(s) set, {$r['suggested']} waiting for review."];
        });
    }

    public function archive(Request $request, string $rule, string $step, ArchiveService $archive): JsonResponse
    {
        $this->allowed(PricingScreen::canRules());
        $r = PricingRuleResource::getEloquentQuery()->find($rule) ?? abort(404);
        $d = $request->validate(['reason' => [$step === 'archive' ? 'required' : 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);

        return $this->act(function (Actor $actor) use ($archive, $r, $step, $d) {
            $step === 'archive' ? $archive->archive($actor, $r, (string) $d['reason']) : $archive->restore($actor, $r);

            return ['message' => $step === 'archive' ? 'Rule archived.' : 'Rule restored.'];
        });
    }

    public function suggestions(Request $request): JsonResponse
    {
        $this->allowed(PricingScreen::canReview());

        return response()->json((new PricingScreen($request->user()))->suggestions($request->query()));
    }

    /** Approve, or keep the current prices, for the suggestions ticked (only ones this person may see). */
    public function decide(Request $request, string $step, PricingRules $pricing): JsonResponse
    {
        $this->allowed(PricingScreen::canReview());
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['uuid']], ['ids.required' => 'Tick the prices first.']);
        $rows = (new PricingScreen($request->user()))->suggestionsQuery()->whereKey($d['ids'])->get();

        return $this->act(function (Actor $actor) use ($pricing, $rows, $step) {
            $n = $step === 'approve' ? $pricing->approve($actor, $rows) : $pricing->dismiss($actor, $rows);

            return ['message' => "{$n} price(s) ".($step === 'approve' ? 'approved.' : 'kept as they are.')];
        });
    }
}
