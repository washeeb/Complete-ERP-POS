<?php

namespace App\Http\Controllers\Office;

use App\Domain\Branding\Branding;
use App\Domain\Modules\ModuleService;
use App\Domain\Org\OrgService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\SetupWizard;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * First-run setup in the new back office (part 38), as the current wizard: how the system runs, the company (and its
 * legal entity), the brand, and the branches with their tills and modules. Saved through OrgService::completeSetup; the
 * brand is saved after, and a logo problem never undoes the setup (it is reported, and fixed on Your brand).
 */
class SetupWizardController extends Controller
{
    use ActsOnAccess;

    public const COUNTRIES = ['GH' => 'Ghana', 'NG' => 'Nigeria', 'CI' => "Côte d'Ivoire", 'TG' => 'Togo', 'KE' => 'Kenya'];

    public const CURRENCIES = ['GHS' => 'GHS — Ghana cedi', 'NGN' => 'NGN — Naira', 'XOF' => 'XOF — CFA franc', 'KES' => 'KES — Kenyan shilling', 'USD' => 'USD — US dollar'];

    private static function pairs(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    /** The wizard, prefilled as the current one (the suggested layout, keeping only modules the licence covers). */
    public function show(Request $request): JsonResponse
    {
        $this->allowed(SetupWizard::canAccess());
        $tenant = Tenant::findOrFail($request->user()->tenant_id);
        if ($tenant->isSetUp()) {
            return response()->json(['done' => true]);
        }
        $modules = collect(ModuleService::licensed());
        $licensed = $modules->keys()->all();
        $branch = fn (string $name, bool $hq, string $module) => ['name' => $name, 'is_hq' => $hq, 'tills' => '2', 'modules' => array_values(array_intersect([$module], $licensed))];

        return response()->json(['done' => false, 'modes' => self::pairs(OrgService::DEPLOYMENT_MODES), 'countries' => self::pairs(self::COUNTRIES),
            'currencies' => self::pairs(self::CURRENCIES), 'timezones' => array_values(timezone_identifiers_list(\DateTimeZone::AFRICA)),
            'modules' => $modules->map(fn ($m) => ['id' => $m->key, 'label' => $m->name, 'detail' => $m->statusLabel().' — '.$m->description])->values()->all(),
            'values' => ['deployment_mode' => 'cloud', 'company' => ['name' => $tenant->name, 'country_code' => 'GH', 'currency_code' => 'GHS', 'timezone' => 'Africa/Accra'],
                'legal_entity' => ['name' => '', 'tin' => '', 'vat_no' => ''], 'brand' => ['color' => '', 'receipt_logo' => true],
                'branches' => [$branch('Head Office & Warehouse', true, 'retail_wholesale'), $branch('Branch A — Grocery', false, 'grocery'),
                    $branch('Branch B — Restaurant', false, 'restaurant'), $branch('Branch C — Pharmacy', false, 'pharmacy')]]]);
    }

    /** Finish setup: sent as a form (the values as JSON in "data", the logo and icon as files). */
    public function save(Request $request, OrgService $org, Branding $branding): JsonResponse
    {
        $this->allowed(SetupWizard::canAccess());
        $request->validate(['data' => ['required', 'json'], 'logo' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp,svg'],
            'icon' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp,svg']], ['logo.*' => 'The logo must be a PNG, JPEG, WebP or SVG image up to 2 MB.',
                'icon.*' => 'The icon must be a PNG, JPEG, WebP or SVG image up to 2 MB.']);
        $data = json_decode((string) $request->input('data'), true);
        $v = validator($data, ['deployment_mode' => ['required', 'string'], 'company.name' => ['required', 'string', 'max:120'],
            'company.country_code' => ['required', 'in:'.implode(',', array_keys(self::COUNTRIES))], 'company.currency_code' => ['required', 'in:'.implode(',', array_keys(self::CURRENCIES))],
            'company.timezone' => ['required', 'timezone'], 'legal_entity.name' => ['nullable', 'string', 'max:150'], 'legal_entity.tin' => ['nullable', 'string', 'max:30'],
            'legal_entity.vat_no' => ['nullable', 'string', 'max:30'], 'brand.color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'brand.receipt_logo' => ['boolean'],
            'branches' => ['required', 'array', 'min:1', 'max:50'], 'branches.*.name' => ['required', 'string', 'max:120'], 'branches.*.tills' => ['required', 'integer', 'min:0', 'max:50'],
            'branches.*.is_hq' => ['boolean'], 'branches.*.modules' => ['array'], 'branches.*.modules.*' => ['string']],
            ['company.name.*' => 'Enter the company or trading name.', 'company.timezone.*' => 'Choose the time zone.', 'brand.color.*' => 'A colour is # and six letters or digits, e.g. #B5471B.',
                'branches.*.name.*' => 'Name every branch.', 'branches.*.tills.*' => 'Tills: 0 to 50 for each branch.'])->validate();
        $brand = $v['brand'] ?? [];
        unset($v['brand']);
        $v['company']['locale'] = 'en';
        $v['branches'] = array_map(fn ($b) => ['name' => $b['name'], 'is_hq' => (bool) ($b['is_hq'] ?? false), 'tills' => (int) $b['tills'], 'modules' => array_values($b['modules'] ?? [])], $v['branches']);
        $out = $this->act(fn (Actor $actor) => ['message' => $org->completeSetup($actor, $v) ? 'Company set up.' : '', 'open' => '/office/']);
        if ($out->getStatusCode() !== 200) {
            return $out;
        }
        // The brand, after the company exists; a problem here is reported and never undoes the setup.
        try {
            DB::transaction(function () use ($request, $branding, $brand) {
                $actor = Actor::user($request->user(), 'web');
                foreach (['logo', 'icon'] as $kind) {
                    if ($request->hasFile($kind)) {
                        $branding->upload($actor, $kind, $request->file($kind)->getRealPath());
                    }
                }
                $branding->saveSettings($actor, ($brand['color'] ?? '') ?: Branding::suggestColor($actor->tenantId), (bool) ($brand['receipt_logo'] ?? true));
            });
        } catch (\Throwable $e) {
            if (! $e instanceof DomainException) {
                report($e);
            }

            return response()->json(['message' => 'Company set up.', 'open' => '/office/',
                'brand_problem' => ($e instanceof DomainException ? $e->getMessage().' ' : 'The logo or colour could not be saved. ').'Add them later under Company → Your brand.']);
        }

        return $out;
    }
}
