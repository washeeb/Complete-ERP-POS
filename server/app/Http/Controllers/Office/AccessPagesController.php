<?php

namespace App\Http\Controllers\Office;

use App\Domain\Access\AccessService;
use App\Domain\Office\AccessPagesScreen;
use App\Domain\Support\Actor;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Security\ScopeType;
use App\Support\StaffCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company-wide access, Who can do what, IT and support activity, and Staff cards in the new back office
 * (App\Domain\Office\AccessPagesScreen), as the current pages.
 */
class AccessPagesController extends Controller
{
    use ActsOnAccess;

    public function companyWide(Request $request): JsonResponse
    {
        $this->allowed(AccessPagesScreen::canCompanyWide());

        return response()->json((new AccessPagesScreen($request->user()))->companyWide($request->query()));
    }

    /** Give a branch (or an industry) instead of the whole company: the new grant and taking the old one away together. */
    public function narrow(Request $request, string $grant, AccessService $access): JsonResponse
    {
        $this->allowed(AccessPagesScreen::canCompanyWide());
        $g = AccessPagesScreen::companyWideQuery()->find($grant) ?? abort(404);
        $d = $request->validate(['scope_type' => ['required', Rule::in([ScopeType::Branch->value, ScopeType::Industry->value])], 'scope_id' => ['required', 'uuid']],
            ['scope_id.required' => 'Choose which one.']);

        return $this->act(function (Actor $actor) use ($access, $g, $d) {
            $access->narrow($actor, $g, ScopeType::from($d['scope_type']), $d['scope_id']);

            return ['message' => "{$g->user->name}'s {$g->role->name} now applies to one ".($d['scope_type'] === 'branch' ? 'branch' : 'industry').'.'];
        });
    }

    public function review(Request $request): JsonResponse
    {
        $this->allowed(AccessPagesScreen::canReview());

        return response()->json((new AccessPagesScreen($request->user()))->review());
    }

    public function reviewCsv(Request $request): StreamedResponse
    {
        $this->allowed(AccessPagesScreen::canReview());
        $rows = (new AccessPagesScreen($request->user()))->reviewRows();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_keys($rows[0] ?? ['Person' => '']), ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, array_values($r), ',', '"', '');
            }
            fclose($out);
        }, 'who-can-do-what-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function activity(Request $request): JsonResponse
    {
        $this->allowed(AccessPagesScreen::canActivity());

        return response()->json((new AccessPagesScreen($request->user()))->activity($request->query()));
    }

    public function cards(Request $request): JsonResponse
    {
        $this->allowed(AccessPagesScreen::canCards());

        return response()->json((new AccessPagesScreen($request->user()))->cards($request->query()));
    }

    /** Print new cards for the people ticked (a card they had stops at once); the print page opens once. */
    public function printCards(Request $request): JsonResponse
    {
        $this->allowed(AccessPagesScreen::canCards());
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['uuid'], 'layout' => ['required', Rule::in(array_keys(StaffCard::LAYOUTS))]],
            ['ids.required' => 'Tick the people first.']);
        $users = AccessPagesScreen::cardsQuery()->whereKey($d['ids'])->get();
        abort_if($users->count() !== count(array_unique($d['ids'])), 404);

        return $this->act(fn (Actor $actor) => ['print' => StaffCard::issue($actor, $users, $d['layout']), 'message' => count($users).' card(s) ready to print.']);
    }

    /** Cards lost: they stop at once. */
    public function stopCards(Request $request, AccessService $access): JsonResponse
    {
        $this->allowed(AccessPagesScreen::canCards());
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['uuid']], ['ids.required' => 'Tick the people first.']);
        $users = AccessPagesScreen::cardsQuery()->whereKey($d['ids'])->whereNotNull('badge_hash')->get();

        return $this->act(function (Actor $actor) use ($access, $users) {
            $users->each(fn ($u) => $access->revokeBadge($actor, $u));

            return ['message' => count($users).' card(s) stopped.'];
        });
    }
}
