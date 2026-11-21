<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\PromotionsScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Pricing\PromotionService;
use App\Domain\Support\Actor;
use App\Filament\Resources\Promotions\PromotionResource;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Promotions in the new back office (App\Domain\Office\PromotionsScreen), through PromotionService and ArchiveService,
 * as the current screen. Every rule (the values each kind needs, unique coupon codes, happy hours, who may change
 * promotions, branch servers only receive them) stays in PromotionService and PromotionResource.
 */
class PromotionsController extends Controller
{
    use ActsOnAccess;

    /** The fields each kind of promotion asks for (the current form shows only these). */
    private const FIELDS = [
        'percent' => ['percent'], 'amount_off' => ['amount'], 'buy_x_get_y' => ['percent', 'buy_qty', 'get_qty'], 'bundle' => ['amount', 'bundle_qty'],
        'threshold' => ['percent', 'amount', 'min_spend'], 'coupon' => ['coupon_code', 'percent', 'amount', 'min_spend'],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->allowed(PromotionsScreen::canView());

        return response()->json((new PromotionsScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed(PromotionsScreen::canView());

        return response()->json((new PromotionsScreen($request->user()))->options());
    }

    public function products(Request $request): JsonResponse
    {
        $this->allowed(PromotionsScreen::canView());

        return response()->json(['rows' => (new PromotionsScreen($request->user()))->products((string) $request->query('q', ''))]);
    }

    public function show(Request $request, string $promotion): JsonResponse
    {
        $this->allowed(PromotionsScreen::canView());
        $p = PromotionResource::getEloquentQuery()->find($promotion) ?? abort(404);

        return response()->json(['id' => $p->id, 'values' => (new PromotionsScreen($request->user()))->values($p)]);
    }

    public function save(Request $request, PromotionService $promotions, ?string $promotion = null): JsonResponse
    {
        $this->allowed(PromotionsScreen::canView());
        $p = $promotion ? (PromotionResource::getEloquentQuery()->find($promotion) ?? abort(404)) : new Promotion;
        abort_unless($promotion ? PromotionResource::canEdit($p) : PromotionResource::canCreate(), 403, 'You cannot change promotions.');
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'industry_id' => ['nullable', 'string'], 'is_active' => ['boolean'], 'type' => ['required', 'string'],
            'coupon_code' => ['nullable', 'string', 'max:30'], 'percent' => ['nullable', 'numeric', 'min:0.01', 'max:100'], 'amount' => ['nullable', 'numeric', 'min:0.01'],
            'min_spend' => ['nullable', 'numeric', 'min:0'], 'buy_qty' => ['nullable', 'integer', 'min:1'], 'get_qty' => ['nullable', 'integer', 'min:1'],
            'bundle_qty' => ['nullable', 'integer', 'min:2'], 'product_ids' => ['array'], 'product_ids.*' => ['uuid'], 'category_ids' => ['array'], 'category_ids.*' => ['uuid'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date'], 'days' => ['array'], 'days.*' => ['integer', 'between:1,7'],
            'time_from' => ['nullable', 'date_format:H:i'], 'time_to' => ['nullable', 'date_format:H:i'], 'branch_ids' => ['array'], 'branch_ids.*' => ['uuid'],
            'price_list_ids' => ['array'], 'price_list_ids.*' => ['uuid']],
            ['name.required' => 'Give the promotion a name customers will see on the receipt.', 'type.required' => 'Choose the kind of promotion.',
                'percent.*' => 'Enter a percentage between 0.01 and 100.', 'time_from.*' => 'Times are like 17:00.', 'time_to.*' => 'Times are like 17:00.']);
        $asked = self::FIELDS[$d['type']] ?? [];
        foreach (['coupon_code', 'percent', 'amount', 'min_spend', 'buy_qty', 'get_qty', 'bundle_qty'] as $f) {
            $d[$f] = in_array($f, $asked, true) && ($d[$f] ?? '') !== '' ? $d[$f] : null; // only what the kind asks for, as the current form
        }
        foreach (['starts_at', 'ends_at', 'time_from', 'time_to', 'industry_id'] as $f) {
            $d[$f] = ($d[$f] ?? '') === '' ? null : $d[$f];
        }
        if ($d['industry_id'] === null) {
            unset($d['industry_id']); // not asked: the person's own industry, as the current form
        }
        foreach (['product_ids', 'category_ids', 'days', 'branch_ids', 'price_list_ids'] as $f) {
            $d[$f] = array_values($d[$f] ?? []);
        }
        $d['days'] = array_map('intval', $d['days']);

        return $this->act(fn (Actor $actor) => ['id' => $promotions->save($actor, $p, PromotionResource::toDomain($d))->id,
            'message' => $promotion ? 'Promotion saved.' : 'Promotion added. Tills pick it up at their next sync.']);
    }

    public function archive(Request $request, string $promotion, string $step, ArchiveService $archive): JsonResponse
    {
        $this->allowed(PromotionsScreen::canView());
        $p = PromotionResource::getEloquentQuery()->find($promotion) ?? abort(404);
        $d = $request->validate(['reason' => [$step === 'archive' ? 'required' : 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);

        return $this->act(function (Actor $actor) use ($archive, $p, $step, $d) {
            $step === 'archive' ? $archive->archive($actor, $p, (string) $d['reason']) : $archive->restore($actor, $p);

            return ['message' => $step === 'archive' ? 'Promotion archived.' : 'Promotion restored.'];
        });
    }
}
