<?php

namespace App\Http\Controllers\Office;

use App\Domain\Branding\Branding;
use App\Domain\Office\CompanyScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Org\IndustryService;
use App\Domain\Org\OrgService;
use App\Domain\Support\Actor;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Resources\Industries\IndustryResource;
use App\Filament\Resources\LegalEntities\LegalEntityResource;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Branch;
use App\Models\BrandAsset;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\Till;
use App\Models\Warehouse;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Company in the new back office (App\Domain\Office\CompanyScreen): Branches (details, own settings, stores, tills),
 * Industries, Legal entities and Branding — each change through the same service as the current pages, and Archive
 * and Restore through ArchiveService. A branch is found only within the resource's query (the person's branches).
 */
class CompanyController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): CompanyScreen
    {
        $this->allowed($can);

        return new CompanyScreen($r->user());
    }

    private function findBranch(CompanyScreen $screen, string $id): Branch
    {
        return $screen->branchesQuery()->findOrFail($id);
    }

    // -------------------------------------------------------------------------------------------- Branches

    public function branches(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CompanyScreen::canBranches())->branches($request->query()));
    }

    public function branchForm(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, BranchResource::canCreate())->branch(null));
    }

    public function branchShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, CompanyScreen::canBranches());

        return response()->json($screen->branch($this->findBranch($screen, $id)));
    }

    public function branchCreate(Request $request, OrgService $org): JsonResponse
    {
        $this->screen($request, BranchResource::canCreate());
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'legal_entity_id' => ['nullable', 'uuid'], 'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'], 'tills' => ['required', 'integer', 'min:0', 'max:50']],
            ['name.*' => 'Give the branch a name.', 'tills.*' => 'A branch can start with 0 to 50 tills.']);

        return $this->act(fn (Actor $actor) => ['id' => $org->createBranch($actor, ['name' => $d['name'], 'legal_entity_id' => ($d['legal_entity_id'] ?? '') ?: null,
            'phone' => ($d['phone'] ?? '') ?: null, 'address' => ($d['address'] ?? '') ?: null], (int) $d['tills'])->id]);
    }

    /** The details and the branch's own settings: a setting left empty follows the company; one hidden keeps what it had. */
    public function branchSave(Request $request, string $id, OrgService $org): JsonResponse
    {
        $screen = $this->screen($request, CompanyScreen::canBranches());
        $b = $this->findBranch($screen, $id);
        $this->allowed(BranchResource::canEdit($b));
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'legal_entity_id' => ['nullable', 'uuid'], 'industry_id' => ['nullable', 'uuid'],
            'phone' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:255'], 'reason' => ['nullable', 'string', 'max:255'],
            'settings' => ['nullable', 'array'], 'settings.*' => ['nullable', 'string', 'max:40']], ['name.*' => 'Give the branch a name.']);
        $own = [];
        foreach (Settings::branchKeys() as $key) {
            $own[$key] = array_key_exists($key, $d['settings'] ?? []) ? (($v = $d['settings'][$key]) === null || $v === '' ? null
                : (Settings::DEFINITIONS[$key]['type'] === 'bool' ? $v === '1' : $v)) : ($b->settings[$key] ?? null);
        }

        return $this->act(function (Actor $actor) use ($org, $b, $d, $own, $screen) {
            $org->updateBranch($actor, $b, ['name' => $d['name'], 'legal_entity_id' => ($d['legal_entity_id'] ?? '') ?: null, 'industry_id' => ($d['industry_id'] ?? '') ?: null,
                'phone' => ($d['phone'] ?? '') ?: null, 'address' => ($d['address'] ?? '') ?: null, 'settings' => $own], ($d['reason'] ?? '') ?: null);

            return ['message' => 'Saved.', 'branch' => $screen->branch($b->fresh())];
        });
    }

    /** Add or rename a store of the branch. */
    public function storeSave(Request $request, string $id, OrgService $org): JsonResponse
    {
        $screen = $this->screen($request, CompanyScreen::canBranches());
        $b = $this->findBranch($screen, $id);
        $d = $request->validate(['id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:120']], ['name.*' => 'Give the store a name.']);
        $w = ($d['id'] ?? null) ? Warehouse::where('branch_id', $b->id)->findOrFail($d['id']) : new Warehouse;

        return $this->act(function (Actor $actor) use ($org, $w, $b, $d, $screen) {
            $org->saveWarehouse($actor, $w, ['branch_id' => $b->id, 'name' => $d['name']]);

            return ['message' => 'Saved.', 'branch' => $screen->branch($b->fresh())];
        });
    }

    /** Add or change a till of the branch: its name and the store it sells from. */
    public function tillSave(Request $request, string $id, OrgService $org): JsonResponse
    {
        $screen = $this->screen($request, CompanyScreen::canBranches());
        $b = $this->findBranch($screen, $id);
        $d = $request->validate(['id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:120'], 'warehouse_id' => ['required', 'uuid']],
            ['name.*' => 'Give the till a name.', 'warehouse_id.*' => 'Choose the store it sells from.']);
        $t = ($d['id'] ?? null) ? Till::where('branch_id', $b->id)->findOrFail($d['id']) : new Till;

        return $this->act(function (Actor $actor) use ($org, $t, $b, $d, $screen) {
            $org->saveTill($actor, $t, ['branch_id' => $b->id, 'name' => $d['name'], 'warehouse_id' => $d['warehouse_id']]);

            return ['message' => 'Saved.', 'branch' => $screen->branch($b->fresh())];
        });
    }

    /** Archive (with the reason) or Restore a branch, store, till, industry or legal entity. */
    public function archive(Request $request, ArchiveService $svc): JsonResponse
    {
        $d = $request->validate(['kind' => ['required', Rule::in(['branch', 'store', 'till', 'industry', 'legal_entity'])], 'id' => ['required', 'uuid'], 'restore' => ['boolean'],
            'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);
        $screen = $this->screen($request, match ($d['kind']) {
            'industry' => CompanyScreen::canIndustries(), 'legal_entity' => CompanyScreen::canEntities(), default => CompanyScreen::canBranches(),
        });
        $branches = $screen->branchesQuery()->pluck('id');
        $r = match ($d['kind']) {
            'branch' => Branch::whereIn('id', $branches)->findOrFail($d['id']),
            'store' => Warehouse::whereIn('branch_id', $branches)->findOrFail($d['id']),
            'till' => Till::whereIn('branch_id', $branches)->findOrFail($d['id']),
            'industry' => Industry::findOrFail($d['id']),
            default => LegalEntity::findOrFail($d['id']),
        };
        $this->allowed($svc->allowed($request->user(), $r));

        return $this->act(function (Actor $actor) use ($svc, $r, $d) {
            ($d['restore'] ?? false) ? $svc->restore($actor, $r) : $svc->archive($actor, $r, $d['reason']);

            return ['message' => ($d['restore'] ?? false) ? 'Restored.' : 'Archived.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Industries, legal entities

    public function industries(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CompanyScreen::canIndustries())->industries($request->query()));
    }

    public function industrySave(Request $request, IndustryService $svc, ?string $id = null): JsonResponse
    {
        $this->screen($request, IndustryResource::canCreate());
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'module_key' => ['required', 'string', 'max:40'], 'legal_entity_id' => ['nullable', 'uuid'],
            'sort' => ['nullable', 'integer', 'min:-1000', 'max:1000']], ['name.*' => 'Give the industry a name.', 'module_key.*' => 'Choose the industry module its branches run.']);
        $i = $id ? Industry::findOrFail($id) : new Industry;

        return $this->act(fn (Actor $actor) => ['id' => $svc->save($actor, $i, ['name' => $d['name'], 'module_key' => $d['module_key'],
            'legal_entity_id' => ($d['legal_entity_id'] ?? '') ?: null, 'sort' => (int) ($d['sort'] ?? 0)])->id, 'message' => 'Saved.']);
    }

    public function entities(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CompanyScreen::canEntities())->entities($request->query()));
    }

    public function entitySave(Request $request, OrgService $org, ?string $id = null): JsonResponse
    {
        $this->screen($request, LegalEntityResource::canCreate());
        $d = $request->validate(['name' => ['required', 'string', 'max:150'], 'registration_no' => ['nullable', 'string', 'max:40'], 'tin' => ['nullable', 'string', 'max:15'],
            'vat_no' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:255'], 'is_primary' => ['boolean']], ['name.*' => 'Enter the registered name.']);
        $e = $id ? LegalEntity::findOrFail($id) : new LegalEntity;

        return $this->act(fn (Actor $actor) => ['id' => $org->saveLegalEntity($actor, $e, ['name' => $d['name'], 'registration_no' => ($d['registration_no'] ?? '') ?: null,
            'tin' => ($d['tin'] ?? '') ?: null, 'vat_no' => ($d['vat_no'] ?? '') ?: null, 'address' => ($d['address'] ?? '') ?: null,
            'is_primary' => (bool) ($d['is_primary'] ?? false)])->id, 'message' => 'Saved.']);
    }

    // -------------------------------------------------------------------------------------------- Branding

    public function branding(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CompanyScreen::canBranding())->branding());
    }

    public function brandUpload(Request $request, string $kind, Branding $brand): JsonResponse
    {
        $screen = $this->screen($request, CompanyScreen::canBranding());
        abort_unless(isset(BrandAsset::KINDS[$kind]), 404);
        $d = $request->validate(['file' => ['required', 'file', 'max:2048']], ['file.required' => 'Choose the image.', 'file.max' => 'The image is larger than 2 MB.']);

        return $this->act(function (Actor $actor) use ($brand, $kind, $d, $screen) {
            $brand->upload($actor, $kind, $d['file']->getRealPath());

            return ['message' => BrandAsset::KINDS[$kind].' saved.', 'branding' => $screen->branding()];
        });
    }

    public function brandRemove(Request $request, string $kind, Branding $brand): JsonResponse
    {
        $screen = $this->screen($request, CompanyScreen::canBranding());
        abort_unless(isset(BrandAsset::KINDS[$kind]), 404);

        return $this->act(function (Actor $actor) use ($brand, $kind, $screen) {
            $brand->remove($actor, $kind);

            return ['message' => 'Removed.', 'branding' => $screen->branding()];
        });
    }

    public function brandSave(Request $request, Branding $brand): JsonResponse
    {
        $screen = $this->screen($request, CompanyScreen::canBranding());
        $d = $request->validate(['color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'menu_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'receipt_logo' => ['boolean']],
            ['color.*' => 'A colour is # and six letters or digits, e.g. #B5471B.', 'menu_color.*' => 'A colour is # and six letters or digits, e.g. #1C2A3A.']);

        return $this->act(function (Actor $actor) use ($brand, $d, $screen) {
            $brand->saveSettings($actor, ($d['color'] ?? '') ?: null, (bool) ($d['receipt_logo'] ?? false), ($d['menu_color'] ?? '') ?: null);

            return ['message' => 'Saved — refresh the page to see the new colour everywhere.', 'branding' => $screen->branding()];
        });
    }
}
