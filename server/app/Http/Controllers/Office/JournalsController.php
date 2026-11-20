<?php

namespace App\Http\Controllers\Office;

use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\JournalService;
use App\Domain\Office\JournalsScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Http\Controllers\Controller;
use App\Security\ReconfirmNeeded;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Journals in the new back office (App\Domain\Office\JournalsScreen): the list and one journal, and New journal,
 * Opening balances, Reverse and Post now through JournalService and AutoPoster.
 */
class JournalsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new JournalsScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new JournalsScreen($request->user()))->options());
    }

    public function show(Request $request, string $journal): JsonResponse
    {
        $this->allowed();
        $screen = new JournalsScreen($request->user());

        return response()->json(['journal' => $screen->show($screen->find($journal) ?? abort(404))]);
    }

    /** New journal (or, with opening = true, Opening balances): date, description and debit/credit lines. */
    public function store(Request $request, JournalService $journals): JsonResponse
    {
        $this->allowed();
        abort_unless(JournalsScreen::canJournal(), 403, 'You cannot post journals.');
        $opening = $request->boolean('opening');
        $d = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'], 'memo' => [$opening ? 'nullable' : 'required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:'.($opening ? 1 : 2)], 'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'], 'lines.*.credit' => ['nullable', 'numeric', 'min:0'], 'lines.*.branch_id' => ['nullable', 'uuid'],
        ], ['memo.required' => 'Describe the journal.', 'lines.min' => 'A journal needs at least two lines.', 'lines.*.account_id.required' => 'Choose the account on each line.',
            'date.before_or_equal' => 'The date cannot be in the future.']);
        $lines = JournalEntryResource::toLines(['lines' => array_map(fn ($l) => ['debit' => isset($l['debit']) ? (string) $l['debit'] : null,
            'credit' => isset($l['credit']) ? (string) $l['credit'] : null] + $l, $d['lines'])]);

        return $this->act(function () use ($journals, $request, $d, $lines, $opening) {
            $actor = Actor::user($request->user(), 'web');
            $j = $opening ? $journals->opening($actor, $d['date'], $lines) : $journals->manual($actor, $d['date'], $d['memo'], $lines);

            return ['journal' => ['id' => $j->id, 'number' => $j->number, 'open' => "/office/journals/{$j->id}"]];
        });
    }

    public function reverse(Request $request, string $journal, JournalService $journals): JsonResponse
    {
        $this->allowed();
        abort_unless(JournalsScreen::canJournal(), 403, 'You cannot post journals.');
        $d = $request->validate(['date' => ['required', 'date', 'before_or_equal:today'], 'reason' => ['required', 'string', 'max:200']],
            ['reason.required' => 'Say why it is reversed.', 'date.before_or_equal' => 'The date cannot be in the future.']);
        $j = (new JournalsScreen($request->user()))->find($journal) ?? abort(404);

        return $this->act(function () use ($journals, $request, $j, $d) {
            $r = $journals->reverse(Actor::user($request->user(), 'web'), $j, $d['date'], $d['reason']);

            return ['journal' => ['id' => $r->id, 'number' => $r->number, 'open' => "/office/journals/{$r->id}"]];
        });
    }

    /** Post now: records not yet in the ledger (sales, bills, payments…) are posted, as the scheduler does every few minutes. */
    public function postNow(Request $request, AutoPoster $poster): JsonResponse
    {
        $this->allowed();
        abort_unless(JournalsScreen::canPostNow(), 403, 'You cannot post.');
        $r = $poster->run($request->user()->tenant_id);
        $n = array_sum($r['posted']);

        return response()->json(['posted' => $n, 'errors' => $r['errors'],
            'message' => $r['errors'] ? 'Some records could not be posted: '.implode(' ', $r['errors']) : ($n ? "{$n} record(s) posted." : 'Everything is already posted.')]);
    }

    private function act(callable $fn): JsonResponse
    {
        try {
            return response()->json(DB::transaction($fn));
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => ConfirmItsYou::getUrl()], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(JournalsScreen::canView(), 403, 'You cannot see the journals.');
    }
}
