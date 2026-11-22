<?php

namespace App\Http\Controllers\Office;

use App\Domain\Ai\AiUnavailable;
use App\Domain\Ai\Copilot;
use App\Domain\Analytics\InsightAlerts;
use App\Domain\Office\InsightsAiScreen;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Resources\AiRequests\AiRequestResource;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Insights in the new back office (App\Domain\Office\InsightsAiScreen): Ask your business (a question, with the chat so
 * far, or a quick question), Alerts (dealt with, not a problem, refresh now) and the AI usage log — through the Copilot
 * and InsightAlerts services as the current pages.
 */
class InsightsAiController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): InsightsAiScreen
    {
        $this->allowed($can);

        return new InsightsAiScreen($r->user());
    }

    // -------------------------------------------------------------------------------------------- Ask your business

    public function ask(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, InsightsAiScreen::canAsk())->ask());
    }

    /**
     * A question with the chat so far. As the current page, an answer that cannot be given (AI off or over budget, or not
     * a question it can answer) comes back as a note in the chat, not an error.
     */
    public function question(Request $request, Copilot $copilot): JsonResponse
    {
        $this->screen($request, InsightsAiScreen::canAsk());
        $d = $request->validate(['question' => ['required', 'string', 'max:1000'], 'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required', 'in:user,assistant'], 'history.*.content' => ['required', 'string', 'max:20000']], ['question.*' => 'Type a question.']);
        $history = array_values(array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $d['history'] ?? []));
        try {
            $r = $copilot->ask(Actor::user($request->user(), 'web'), $d['question'], $history);

            return response()->json(['content' => (string) $r['answer'], 'tables' => $r['tables'] ?? [], 'notice' => $r['notice'] ?? null]);
        } catch (AiUnavailable $e) {
            return response()->json(['content' => '', 'tables' => [], 'notice' => $e->getMessage().' Try one of the quick questions — they work without AI.']);
        } catch (DomainException $e) {
            return response()->json(['content' => '', 'tables' => [], 'notice' => $e->getMessage()]);
        }
    }

    public function quick(Request $request, Copilot $copilot): JsonResponse
    {
        $this->screen($request, InsightsAiScreen::canAsk());
        $key = (string) $request->validate(['key' => ['required', 'string', 'max:60']])['key'];
        abort_unless(isset(Copilot::QUICK[$key]), 422, 'Unknown question.');
        try {
            return response()->json(['content' => '', 'tables' => [$copilot->quick(Actor::user($request->user(), 'web'), $key)], 'notice' => null]);
        } catch (DomainException $e) {
            return response()->json(['content' => '', 'tables' => [], 'notice' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------------------------- Alerts

    public function alerts(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, InsightsAiScreen::canAlerts())->alerts($request->query()));
    }

    /** resolved ("Dealt with") · dismissed ("Not a problem", quiet for 30 days), with an optional note. */
    public function alertHandle(Request $request, string $id, InsightAlerts $alerts): JsonResponse
    {
        $screen = $this->screen($request, InsightsAiScreen::canAlerts());
        $a = $screen->alertsQuery()->findOrFail($id);
        $d = $request->validate(['status' => ['required', 'in:resolved,dismissed'], 'note' => ['nullable', 'string', 'max:255']]);

        return $this->act(fn (Actor $actor) => ['message' => $alerts->handle($actor, $a, $d['status'], ($d['note'] ?? '') ?: null)
            ? ($d['status'] === 'resolved' ? 'Marked as dealt with.' : 'Dismissed.') : '']);
    }

    /** Refresh now: run the nightly insights for the company (company-wide only, as the current page). */
    public function alertsRefresh(Request $request, InsightAlerts $alerts): JsonResponse
    {
        $this->screen($request, InsightsAiScreen::canAlerts());
        abort_unless(Erp::branchIds('insights.view') === null, 403, 'Refreshing needs company-wide insights.');

        return response()->json(['message' => array_sum($alerts->run($request->user()->tenant_id)).' open alert(s).']);
    }

    // -------------------------------------------------------------------------------------------- AI usage log

    public function usage(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, InsightsAiScreen::canUsage())->usage($request->query()));
    }

    public function usageShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, InsightsAiScreen::canUsage());

        return response()->json($screen->request(AiRequestResource::getEloquentQuery()->findOrFail($id)));
    }
}
