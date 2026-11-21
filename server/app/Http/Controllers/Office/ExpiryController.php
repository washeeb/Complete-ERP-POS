<?php

namespace App\Http\Controllers\Office;

use App\Domain\Grocery\MarkdownService;
use App\Domain\Inventory\ExpiryService;
use App\Domain\Office\ExpiryScreen;
use App\Domain\Support\Actor;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Expiry & returns in the new back office (App\Domain\Office\ExpiryScreen): the list, then Dispose (with the evidence
 * the company asks for, a certificate as a file), Return to supplier and Mark down, through ExpiryService and
 * MarkdownService as the current page. Each checks the person may handle expiry in that store.
 */
class ExpiryController extends Controller
{
    use ActsOnAccess;

    private const KEYS = ['keys' => ['required', 'array', 'min:1', 'max:500'], 'keys.*' => ['string', 'regex:/^[0-9a-f-]{36}\|[0-9a-f-]{36}$/']];

    private const TICK = ['keys.*' => 'Tick the batches first.'];

    public function index(Request $request): JsonResponse
    {
        $this->allowed(ExpiryScreen::canView());

        return response()->json((new ExpiryScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed(ExpiryScreen::canView());

        return response()->json((new ExpiryScreen($request->user()))->options());
    }

    /** The disposal register for a store and period (the current print page). */
    public function register(Request $request): JsonResponse
    {
        $this->allowed(ExpiryScreen::canView());
        $d = $request->validate(['warehouse' => ['required', Rule::in(array_keys(ExpiryScreen::warehouses()))], 'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']], ['warehouse.*' => 'Choose the store.', 'to.after_or_equal' => 'The end is before the start.']);

        return response()->json(['url' => route('print.disposal-register', $d, false)]);
    }

    public function dispose(Request $request, ExpiryService $expiry): JsonResponse
    {
        $this->allowed(ExpiryScreen::canView());
        $d = $request->validate(self::KEYS + ['reason' => ['required', 'in:expired,damaged'], 'method' => ['nullable', 'string'], 'disposed_on' => ['required', 'date_format:Y-m-d'],
            'witness' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:500'],
            'certificate' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'max:5120']],
            self::TICK + ['disposed_on.*' => 'Enter the day it is disposed of.', 'certificate.mimetypes' => 'Attach a PDF or a picture (JPEG, PNG or WebP).',
                'certificate.max' => 'The certificate is larger than 5 MB.']);
        $path = $request->file('certificate')?->store('disposal-certificates', 'local');

        return $this->act(function (Actor $actor) use ($expiry, $d, $path) {
            $a = $expiry->dispose($actor, $d['keys'], ['reason' => $d['reason'], 'method' => $d['method'] ?? null, 'disposed_on' => $d['disposed_on'],
                'witness' => $d['witness'] ?? null, 'certificate_path' => $path, 'note' => $d['note'] ?? null]);

            return ['open' => "/office/adjustments/{$a->id}", 'message' => "Disposal {$a->number} prepared. It now waits for approval by someone else."];
        });
    }

    public function return(Request $request, ExpiryService $expiry): JsonResponse
    {
        $this->allowed(ExpiryScreen::canView());
        $d = $request->validate(self::KEYS + ['supplier' => ['required', 'uuid'], 'settlement' => ['required', Rule::in(array_keys(SupplierReturn::SETTLEMENTS))],
            'reason' => ['required', Rule::in(array_keys(SupplierReturn::REASONS))], 'note' => ['nullable', 'string', 'max:500']],
            self::TICK + ['supplier.*' => 'Choose the supplier.', 'settlement.*' => 'Choose what the supplier gives.', 'reason.*' => 'Choose the reason.']);

        return $this->act(function (Actor $actor) use ($expiry, $d) {
            $r = $expiry->prepareReturn($actor, $d['keys'], $d['supplier'], $d['settlement'], $d['reason'], $d['note'] ?? null);

            return ['open' => ExpiryScreen::returnUrl($r), 'message' => "Return {$r->number} prepared. It now waits for approval by someone else."];
        });
    }

    /** Mark down the ticked batches (those already expired are skipped), as the current page. */
    public function markdown(Request $request, MarkdownService $markdowns): JsonResponse
    {
        $this->allowed(ExpiryScreen::canView());
        $d = $request->validate(self::KEYS + ['percent' => ['required', 'numeric', 'min:1', 'max:90']], self::TICK + ['percent.*' => 'Type the % off (1 to 90).']);
        $mine = array_keys(ExpiryScreen::warehouses());
        $wanted = array_flip($d['keys']);
        $rows = array_filter((new ExpiryScreen($request->user()))->rows(['status' => 'all']), fn ($r) => isset($wanted[$r['key']]) && in_array($r['warehouse_id'], $mine, true));

        return $this->act(function (Actor $actor) use ($markdowns, $rows, $d) {
            $n = 0;
            foreach ($rows as $r) {
                if ($r['days_left'] >= 0) {
                    $markdowns->apply($actor, Warehouse::findOrFail($r['warehouse_id']), $r['batch_id'], (int) round((float) $d['percent'] * 100));
                    $n++;
                }
            }

            return ['message' => "{$n} batch(es) marked down. Print the stickers from Batches & expiry."];
        });
    }
}
