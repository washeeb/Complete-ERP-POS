<?php

namespace App\Domain\Office;

use App\Domain\Branding\Branding;
use App\Domain\Modules\ModuleService;
use App\Domain\Org\ArchiveService;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Resources\Industries\IndustryResource;
use App\Filament\Resources\LegalEntities\LegalEntityResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\BrandAsset;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\ModuleRegistry;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Company in the new back office (part 20 of the rewrite), as the current pages: Branches (each with its stores, tills
 * and the settings a branch may set for itself), Industries, Legal entities and Branding. Same queries and rules:
 * OrgService, IndustryService, ArchiveService and Branding (CompanyController).
 */
final class CompanyScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    /** The branch settings shown on its page, as the current page's sections. */
    public const SECTIONS = ['pharmacy.' => 'Pharmacy rules for this branch', 'pos.' => 'Tills at this branch', 'remote.' => 'Remote connections at this branch'];

    public const SECTION_ABOUT = [
        'pharmacy.' => 'Empty = the company setting (System → Settings → Pharmacy). Set a value only where this branch works differently, e.g. a shop without a pharmacist on duty.',
        'pos.' => 'Empty = the company setting (System → Settings → Tills and cash).',
        'remote.' => 'Empty = the company setting (System → Settings → Remote access).',
    ];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    /** In use (the default), archived only, or both, as the current lists' filter. */
    private static function show(Builder $q, array $p): Builder
    {
        $col = $q->qualifyColumn('archived_at');

        return match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull($col),
            'both' => $q,
            default => $q->whereNull($col),
        };
    }

    /** What a row needs for Archive and Restore: may this person, and what must happen first. */
    public function archiveOf(Model $r): array
    {
        $svc = app(ArchiveService::class);
        $may = $svc->allowed($this->user, $r);

        return ['archived' => (bool) $r->archived_at, 'archived_note' => $r->archived_at ? 'Archived '.$r->archived_at->format('j M Y')
            .(($by = User::find($r->archived_by)?->name) ? " by {$by}" : '').($r->archive_reason ? ": {$r->archive_reason}" : '') : null,
            'can_archive' => $may && ! $r->archived_at, 'archive_blockers' => $may && ! $r->archived_at ? $svc->blockers($r) : [], 'can_restore' => $may && (bool) $r->archived_at];
    }

    private static function moduleName(string $key): string
    {
        $reg = app(ModuleRegistry::class);

        return $reg->has($key) ? $reg->get($key)->name : $key;
    }

    // -------------------------------------------------------------------------------------------- Branches

    public static function canBranches(): bool
    {
        return BranchResource::canViewAny();
    }

    public function branchesQuery(): Builder
    {
        return BranchResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q (code or name), show, page, per */
    public function branches(array $p): array
    {
        $q = self::show($this->branchesQuery(), $p);
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('branches.code', 'ilike', "%{$s}%")->orWhere('branches.name', 'ilike', "%{$s}%"));
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'can_create' => BranchResource::canCreate(),
            'rows' => $q->with('modules', 'industry')->withCount('tills')->orderBy('branches.code')->forPage($page, $per)->get()->map(fn (Branch $b) => ['id' => $b->id,
                'code' => $b->code, 'name' => $b->name, 'hq' => (bool) $b->is_hq, 'industry' => $b->industry?->name, 'tills' => (int) $b->tills_count,
                'modules' => $b->modules->where('status', '!=', 'disabled')->map(fn ($m) => self::moduleName($m->module_key).($m->status === 'reserved' ? ' (reserved)' : ''))->implode(', '),
                'archived' => (bool) $b->archived_at])->all()];
    }

    /** The settings a branch may set for itself, in the current page's sections (Pharmacy only where it runs Pharmacy). */
    private function ownSettings(Branch $b): array
    {
        $settings = app(Settings::class);
        $out = [];
        foreach (self::SECTIONS as $prefix => $title) {
            if ($prefix === 'pharmacy.' && ! app(ModuleService::class)->isEnabled($b, 'pharmacy')) {
                continue;
            }
            $fields = [];
            foreach (Settings::branchKeys($prefix) as $key) {
                $d = Settings::DEFINITIONS[$key];
                $company = $settings->get($key, $this->user->tenant_id);
                $own = $b->settings[$key] ?? null;
                $may = Settings::mayChange($key, $this->user);
                $fields[] = ['key' => $key, 'label' => $d['label'], 'help' => $d['help'], 'type' => $d['type'],
                    'options' => match ($d['type']) {
                        'choice' => self::choices($d['options']),
                        'bool' => [['id' => '1', 'label' => 'Yes'], ['id' => '0', 'label' => 'No']],
                        default => null,
                    },
                    'min' => $d['min'] ?? null, 'max' => $d['max'] ?? null,
                    'company' => 'Company: '.match ($d['type']) {
                        'choice' => $d['options'][$company] ?? $company, 'bool' => $company ? 'Yes' : 'No', default => (string) $company
                    },
                    'value' => $own === null ? '' : (is_bool($own) ? ($own ? '1' : '0') : (string) $own), 'may_change' => $may,
                    'who' => $may ? null : (Settings::who($key) === 'superadmin' ? 'Changed by SyncLytics support' : 'Changed by IT')];
            }
            if ($fields) {
                $out[] = ['prefix' => $prefix, 'title' => $title, 'about' => self::SECTION_ABOUT[$prefix], 'fields' => $fields];
            }
        }

        return $out;
    }

    /** One branch (or a new one): its details, its own settings, its stores and tills. */
    public function branch(?Branch $b): array
    {
        $choices = ['legal_entities' => self::choices(LegalEntity::choices($b?->legal_entity_id)),
            'industries' => self::choices(Industry::licensed()->orderBy('sort')->pluck('name', 'id')->all())];
        if (! $b) {
            return $choices + ['id' => null, 'values' => ['name' => '', 'legal_entity_id' => '', 'phone' => '', 'address' => '', 'tills' => '1']];
        }
        $edit = BranchResource::canEdit($b);

        return $choices + ['id' => $b->id, 'code' => $b->code, 'hq' => (bool) $b->is_hq, 'can_edit' => $edit,
            'values' => ['name' => $b->name, 'legal_entity_id' => (string) $b->legal_entity_id, 'industry_id' => (string) $b->industry_id, 'phone' => (string) $b->phone,
                'address' => (string) $b->address],
            'sections' => $this->ownSettings($b),
            'stores' => Warehouse::where('branch_id', $b->id)->orderBy('code')->get()->map(fn (Warehouse $w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name] + $this->archiveOf($w))->all(),
            'tills' => Till::with('warehouse')->where('branch_id', $b->id)->orderBy('code')->get()
                ->map(fn (Till $t) => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'warehouse_id' => $t->warehouse_id, 'store' => $t->warehouse?->name] + $this->archiveOf($t))->all(),
        ] + $this->archiveOf($b);
    }

    // -------------------------------------------------------------------------------------------- Industries, legal entities

    public static function canIndustries(): bool
    {
        return IndustryResource::canViewAny();
    }

    public function industries(array $p): array
    {
        $q = self::show(Industry::query(), $p);
        $modules = collect(ModuleService::licensed())->filter(fn ($m) => $m->definesIndustry() && $m->isAvailable())->mapWithKeys(fn ($m) => [$m->key => $m->name])->all();

        return ['can_create' => IndustryResource::canCreate(), 'modules' => self::choices($modules),
            'legal_entities' => self::choices(LegalEntity::choices(Industry::pluck('legal_entity_id')->filter()->all(), withHeadOffice: false)),
            'rows' => $q->with('legalEntity')->withCount('branches')->orderBy('sort')->orderBy('name')->get()->map(fn (Industry $i) => ['id' => $i->id, 'code' => $i->code,
                'name' => $i->name, 'module_key' => $i->module_key, 'module' => self::moduleName($i->module_key), 'branches' => (int) $i->branches_count,
                'legal_entity_id' => (string) $i->legal_entity_id, 'books' => $i->legalEntity?->name, 'sort' => (int) $i->sort] + $this->archiveOf($i))->all()];
    }

    public static function canEntities(): bool
    {
        return LegalEntityResource::canViewAny();
    }

    public function entities(array $p): array
    {
        $q = self::show(LegalEntity::query(), $p);
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where('name', 'ilike', "%{$s}%");
        }

        return ['can_create' => LegalEntityResource::canCreate(), 'can_edit' => LegalEntityResource::canCreate(),
            'rows' => $q->orderBy('name')->get()->map(fn (LegalEntity $e) => ['id' => $e->id, 'name' => $e->name, 'registration_no' => (string) $e->registration_no,
                'tin' => (string) $e->tin, 'vat_no' => (string) $e->vat_no, 'address' => (string) $e->address, 'primary' => (bool) $e->is_primary] + $this->archiveOf($e))->all()];
    }

    // -------------------------------------------------------------------------------------------- Branding

    public static function canBranding(): bool
    {
        return Erp::can('setup.company.manage');
    }

    public const ASSET_HINTS = ['logo' => 'Wide logo with the name, at least 600 pixels wide.', 'logo_dark' => 'A light version for dark mode and dark headers (optional).',
        'icon' => 'The symbol alone, square, at least 256 × 256. Until you add one, your initials are used.'];

    public function branding(): array
    {
        $t = Tenant::find($this->user->tenant_id);
        $b = Branding::forTenant($t);
        $have = BrandAsset::where('bytes', '>', 0)->get(['kind', 'mime', 'width', 'height', 'bytes', 'sha256'])->keyBy('kind');
        $type = ['image/svg+xml' => 'SVG', 'image/png' => 'PNG', 'image/jpeg' => 'JPEG', 'image/webp' => 'WebP'];

        return ['company' => $b['company'], 'color' => (string) ($t->brand_color ?? ''), 'menu_color' => (string) ($t->brand_menu_color ?? ''),
            'default_color' => (string) config('brand.color'), 'shown_color' => $b['color'], 'receipt_logo' => (bool) $t->brand_receipt_logo,
            'suggestion' => Branding::suggestColor($t->id), 'logo_url' => $b['logo_url'], 'logo_dark_url' => $b['logo_dark_url'], 'icon_url' => $b['icon_url'],
            'assets' => collect(BrandAsset::KINDS)->map(fn ($label, $kind) => ['kind' => $kind, 'label' => $label, 'hint' => self::ASSET_HINTS[$kind] ?? '',
                'url' => isset($have[$kind]) ? "/brand/{$t->slug}/{$kind}?v=".substr($have[$kind]->sha256, 0, 12) : null,
                'about' => isset($have[$kind]) ? ($type[$have[$kind]->mime] ?? $have[$kind]->mime).' · '.$have[$kind]->width.'×'.$have[$kind]->height.' · '.number_format($have[$kind]->bytes / 1024).' KB' : null])
                ->values()->all()];
    }
}
