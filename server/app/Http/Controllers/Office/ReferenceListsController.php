<?php

namespace App\Http\Controllers\Office;

use App\Domain\Catalog\CatalogService;
use App\Domain\Office\ReferenceListsScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\Actor;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Product;
use App\Models\Uom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Categories, brands, units, tax rates, tax groups and price lists in the new back office
 * (App\Domain\Office\ReferenceListsScreen), through CatalogService and ArchiveService, as the current screens. Each kind
 * saves only its own form's fields; every rule (names once, unit codes, price lists that already have prices, who may
 * change an industry's list) stays in CatalogService.
 */
class ReferenceListsController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $request, string $kind): ReferenceListsScreen
    {
        $s = new ReferenceListsScreen($request->user(), $kind);
        $this->allowed($s->canView());

        return $s;
    }

    public function index(Request $request, string $kind): JsonResponse
    {
        return response()->json($this->screen($request, $kind)->list($request->query()));
    }

    public function options(Request $request, string $kind): JsonResponse
    {
        return response()->json($this->screen($request, $kind)->options());
    }

    public function save(Request $request, string $kind, CatalogService $catalog, ?string $id = null): JsonResponse
    {
        $s = $this->screen($request, $kind);
        $model = $id ? ($s->find($id) ?? abort(404)) : new (ReferenceListsScreen::KINDS[$kind][1]);
        abort_unless($id ? $s->canEdit($model) : $s->canCreate(), 403, 'You cannot change this list.');
        $d = $request->validate(array_merge(['name' => ['required', 'string', 'max:120']], match ($kind) {
            'categories' => ['industry_id' => ['nullable', 'string', 'max:40'], 'parent_id' => ['nullable', 'uuid']],
            'brands' => ['industry_id' => ['nullable', 'string', 'max:40']],
            'units' => ['code' => ['required', 'string', 'max:10'], 'name' => ['required', 'string', 'max:60'], 'decimals' => ['required', 'integer', 'min:0', 'max:3']],
            'tax-rates' => ['code' => [$id ? 'prohibited' : 'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_]{1,20}$/'], 'name' => ['required', 'string', 'max:80'],
                'rate_bp' => ['required', 'integer', 'min:0', 'max:10000'], 'sort' => ['nullable', 'integer']],
            'tax-groups' => ['name' => ['required', 'string', 'max:80'], 'rate_ids' => ['array'], 'rate_ids.*' => ['uuid']],
            'price-lists' => ['name' => ['required', 'string', 'max:80'], 'prices_include_tax' => ['boolean'], 'is_default' => ['boolean'], 'pos_selectable' => ['boolean'],
                'industry_id' => ['nullable', 'uuid'], 'branch_id' => ['nullable', 'uuid']],
            default => [],
        }), ['name.required' => 'Give it a name.', 'code.prohibited' => 'The code stays as it is once added.', 'parent_id.uuid' => 'Choose the category it goes inside.']);
        if ($kind === 'categories' && $id && ($d['parent_id'] ?? null) === $id) {
            return response()->json(['error' => 'own_parent', 'detail' => 'A category cannot go inside itself.'], 422);
        }
        $data = Arr::only($d, ReferenceListsScreen::FIELDS[$kind]);

        return $this->act(function (Actor $actor) use ($catalog, $kind, $model, $data, $d, $s) {
            $saved = match ($kind) {
                'tax-groups' => $catalog->saveTaxGroup($actor, $model, $data, $d['rate_ids'] ?? []),
                'price-lists' => $catalog->savePriceList($actor, $model, $data),
                default => $catalog->saveSimple($actor, $model, $data, $s->managePermission()),
            };

            return ['id' => $saved->getKey(), 'message' => "{$saved->name} saved."];
        });
    }

    /** Delete (unused only), Move products, Merge into, Record accountant check, Archive, Restore. */
    public function action(Request $request, string $kind, string $id, string $action, CatalogService $catalog, ArchiveService $archive): JsonResponse
    {
        $s = $this->screen($request, $kind);
        $r = $s->find($id) ?? abort(404);
        $field = ReferenceListsScreen::KINDS[$kind][2];
        $ok = match ($action) {
            'delete' => ReferenceListsScreen::KINDS[$kind][3] && Erp::can($s->managePermission()),
            'move' => $field !== null && Erp::can($s->managePermission()),
            'merge' => $kind === 'units' && Erp::canAnywhere('catalog.manage'),
            'verify' => $kind === 'tax-groups',
            'archive', 'restore' => ReferenceListsScreen::KINDS[$kind][4],
            default => false,
        };
        abort_unless($ok, 403, 'You cannot do this here.');
        $d = $request->validate(['to' => [$action === 'merge' ? 'required' : 'nullable', 'uuid'], 'note' => [$action === 'verify' ? 'required' : 'nullable', 'string', 'max:255'],
            'reason' => [$action === 'archive' ? 'required' : 'nullable', 'string', 'min:3', 'max:255']],
            ['to.required' => 'Choose the unit to merge into.', 'note.required' => 'Say who checked it, and against what.', 'reason.*' => 'Say why (at least 3 letters).']);
        if ($action === 'merge' && $d['to'] === $id) {
            return response()->json(['error' => 'same', 'detail' => 'Choose another unit.'], 422);
        }
        @set_time_limit(1800); // moving thousands of products, as now

        return $this->act(function (Actor $actor) use ($action, $catalog, $archive, $s, $r, $field, $d) {
            return ['message' => match ($action) {
                'delete' => [$catalog->deleteSimple($actor, $r, $s->managePermission()), 'Deleted.'][1],
                'move' => $catalog->moveProducts($actor, Product::where($field, $r->getKey())->pluck('id')->all(), $field, $d['to'] ?? null).' product(s) moved.',
                'merge' => ($r->code ?? '').' merged: '.$catalog->mergeUom($actor, $r, Uom::findOrFail($d['to'])).' product(s) moved.',
                'verify' => [$catalog->verifyTaxGroup($actor, $r, (string) $d['note']), 'Accountant check recorded.'][1],
                'archive' => [$archive->archive($actor, $r, (string) $d['reason']), 'Archived.'][1],
                default => [$archive->restore($actor, $r), 'Restored.'][1],
            }];
        });
    }
}
