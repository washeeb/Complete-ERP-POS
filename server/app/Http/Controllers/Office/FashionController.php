<?php

namespace App\Http\Controllers\Office;

use App\Domain\Fashion\AlterationService;
use App\Domain\Fashion\ConsignmentService;
use App\Domain\Fashion\StyleService;
use App\Domain\Office\FashionScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\Actor;
use App\Filament\Resources\Alterations\AlterationResource;
use App\Filament\Resources\Styles\StyleResource;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Branch;
use App\Models\Style;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fashion in the new back office (App\Domain\Office\FashionScreen): styles (save, create sizes and colours, one price for
 * all, archive), alterations (new, update progress, hand over) and sell-through & consignment (settle a consignor) —
 * through the services the current pages use.
 */
class FashionController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): FashionScreen
    {
        $this->allowed($can);

        return new FashionScreen($r->user());
    }

    // -------------------------------------------------------------------------------------------- Styles

    public function styles(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, StyleResource::canViewAny())->styles($request->query()));
    }

    public function style(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, StyleResource::canViewAny());

        return response()->json($screen->style($screen->stylesQuery()->findOrFail($id)));
    }

    public function styleForm(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, StyleResource::canCreate());

        return response()->json($screen->styleForm($id ? $screen->stylesQuery()->findOrFail($id) : null));
    }

    public function styleSave(Request $request, StyleService $svc, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, StyleResource::canCreate());
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'industry_id' => ['nullable', 'string', 'max:40'], 'category_id' => ['nullable', 'uuid'],
            'brand_id' => ['nullable', 'uuid'], 'tax_group_id' => ['required', 'uuid'], 'season' => ['nullable', 'string', 'max:30'], 'collection' => ['nullable', 'string', 'max:60'],
            'price' => ['nullable', 'numeric', 'min:0'], 'sizes' => ['required', 'array', 'min:1'], 'sizes.*' => ['string', 'max:20'], 'colours' => ['required', 'array', 'min:1'],
            'colours.*' => ['string', 'max:40'], 'description' => ['nullable', 'string', 'max:5000'], 'is_active' => ['boolean']],
            ['name.*' => 'Name the style.', 'tax_group_id.*' => 'Choose the tax.', 'sizes.*' => 'Add at least one size.', 'colours.*' => 'Add at least one colour.']);
        $style = $id ? $screen->stylesQuery()->findOrFail($id) : new Style;
        $d['price'] = isset($d['price']) && $d['price'] !== '' ? (string) $d['price'] : null;

        return $this->act(fn (Actor $actor) => ['id' => $svc->save($actor, $style, $d)->id, 'message' => 'Saved.']);
    }

    /** generate (create the sizes and colours) · price (one price for all). */
    public function styleAction(Request $request, string $id, StyleService $svc): JsonResponse
    {
        $style = $this->screen($request, StyleResource::canCreate())->stylesQuery()->findOrFail($id);
        switch ((string) $request->input('action')) {
            case 'generate':
                return $this->act(function (Actor $actor) use ($svc, $style) {
                    $n = $svc->generate($actor, $style);

                    return ['message' => $n ? "{$n} new product(s) created; sizes and colours are up to date." : 'Sizes and colours are up to date.'];
                });
            case 'price':
                $this->allowed(Erp::can('pricing.manage'));
                $price = $request->validate(['price' => ['required', 'numeric', 'min:0']], ['price.*' => 'Enter the price.'])['price'];

                return $this->act(function (Actor $actor) use ($svc, $style, $price) {
                    $n = $svc->setPrice($actor, $style, (string) $price);

                    return ['message' => "Price set on {$n} size(s) and colour(s)."];
                });
        }
        abort(422, 'Unknown action.');
    }

    public function styleArchive(Request $request, string $id, ArchiveService $svc): JsonResponse
    {
        $style = $this->screen($request, StyleResource::canViewAny())->stylesQuery()->findOrFail($id);
        $d = $request->validate(['restore' => ['boolean'], 'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);
        $this->allowed($svc->allowed($request->user(), $style));

        return $this->act(function (Actor $actor) use ($svc, $style, $d) {
            ($d['restore'] ?? false) ? $svc->restore($actor, $style) : $svc->archive($actor, $style, $d['reason']);

            return ['message' => ($d['restore'] ?? false) ? 'Restored.' : 'Archived.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Alterations

    public function alterations(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, AlterationResource::canViewAny())->alterations($request->query()));
    }

    public function alterationStore(Request $request, AlterationService $svc): JsonResponse
    {
        $this->screen($request, AlterationResource::canCreate());
        $d = $request->validate(['branch_id' => ['required', 'uuid'], 'customer_name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:20'],
            'garment' => ['required', 'string', 'max:160'], 'charge' => ['nullable', 'numeric', 'min:0'], 'due_on' => ['nullable', 'date'], 'work' => ['required', 'string', 'max:2000']],
            ['branch_id.*' => 'Choose the branch.', 'customer_name.*' => 'Enter the customer.', 'garment.*' => 'Say which garment.', 'work.*' => 'Say what work to do.']);
        $d['charge_minor'] = Money::toMinor((string) ($d['charge'] ?? '0'), $request->user()->tenant->currency_code);
        $d['due_on'] = ($d['due_on'] ?? '') ?: null;

        return $this->act(fn (Actor $actor) => ['id' => $svc->open($actor, $d)->id, 'message' => 'Alteration taken in.']);
    }

    /** progress (status, tailor) · hand_over (a free one, ready). */
    public function alterationAction(Request $request, string $id, AlterationService $svc): JsonResponse
    {
        $a = $this->screen($request, AlterationResource::canViewAny())->alterationsQuery()->findOrFail($id);
        switch ((string) $request->input('action')) {
            case 'progress':
                $d = $request->validate(['status' => ['required', 'string'], 'tailor' => ['nullable', 'string', 'max:120']], ['status.*' => 'Choose where it stands.']);

                return $this->act(fn (Actor $actor) => ['message' => $svc->setStatus($actor, $a, $d['status'], ($d['tailor'] ?? '') ?: null) ? 'Updated.' : '']);
            case 'hand_over':
                return $this->act(fn (Actor $actor) => ['message' => $svc->handOver($actor, $a) ? 'Handed over.' : '']);
        }
        abort(422, 'Unknown action.');
    }

    // -------------------------------------------------------------------------------------------- Sell-through & consignment

    public function report(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, FashionScreen::canReport())->report($request->query()));
    }

    /** Settle what a consignor is owed at a branch up to the end date: one supplier bill. */
    public function settle(Request $request, ConsignmentService $svc): JsonResponse
    {
        $this->screen($request, FashionScreen::canReport() && Erp::canAnywhere('fashion.consignment') && Erp::canAnywhere('payables.manage'));
        $d = $request->validate(['consignor_id' => ['required', 'uuid'], 'branch_id' => ['required', 'uuid'], 'to' => ['required', 'date_format:Y-m-d'],
            'statement_no' => ['nullable', 'string', 'max:60']]);
        $consignor = Supplier::findOrFail($d['consignor_id']);
        $branch = Branch::findOrFail($d['branch_id']);

        return $this->act(function (Actor $actor) use ($svc, $consignor, $branch, $d) {
            $bill = $svc->settle($actor, $consignor, $branch, $d['to'], ($d['statement_no'] ?? '') ?: 'CONS-'.str_replace('-', '', $d['to']));

            return ['message' => "Bill {$bill->number} raised. It is in Supplier bills, to be approved and paid as usual.", 'open' => "/office/supplier-bills/{$bill->id}"];
        });
    }
}
