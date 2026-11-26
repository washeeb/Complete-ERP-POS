<?php

namespace App\Http\Controllers\Office;

use App\Domain\Hr\HrService;
use App\Domain\Office\CompanyScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\PayrollProfilePage;
use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Department;
use App\Models\PayrollProfile;
use App\Support\Exports\Exporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The last two HR pages in the new back office (part 38): Departments (reached from Employees: new, rename, archive,
 * restore) and the Tax and SSNIT rules payroll uses (reached from Payroll: read, Check and confirm, export) — through the
 * HrService the current pages use.
 */
class HrSetupController extends Controller
{
    use ActsOnAccess;

    // -------------------------------------------------------------------------------------------- Departments

    public function departments(Request $request): JsonResponse
    {
        $this->allowed(DepartmentResource::canViewAny());
        $q = DepartmentResource::getEloquentQuery();
        match ((string) $request->query('show', '')) {
            'archived' => $q->whereNotNull('archived_at'), 'both' => null, default => $q->whereNull('archived_at')
        };
        $archive = new CompanyScreen($request->user());

        return response()->json(['can_create' => DepartmentResource::canCreate(),
            'rows' => $q->orderBy('code')->get()->map(fn (Department $d) => ['id' => $d->id, 'code' => $d->code, 'name' => $d->name,
                'can_edit' => DepartmentResource::canEdit($d) && ! $d->archived_at] + $archive->archiveOf($d))->all()]);
    }

    public function departmentSave(Request $request, HrService $svc, ?string $id = null): JsonResponse
    {
        $this->allowed(DepartmentResource::canCreate());
        $name = $request->validate(['name' => ['required', 'string', 'max:255']], ['name.*' => 'Name the department.'])['name'];
        $d = $id ? DepartmentResource::getEloquentQuery()->findOrFail($id) : new Department;

        return $this->act(fn (Actor $actor) => ['id' => $svc->saveDepartment($actor, $d, '', $name)->id, 'message' => 'Saved.']);
    }

    public function departmentArchive(Request $request, string $id, ArchiveService $svc): JsonResponse
    {
        $this->allowed(DepartmentResource::canViewAny());
        $d = DepartmentResource::getEloquentQuery()->findOrFail($id);
        $v = $request->validate(['restore' => ['boolean'], 'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);
        $this->allowed($svc->allowed($request->user(), $d));

        return $this->act(function (Actor $actor) use ($svc, $d, $v) {
            ($v['restore'] ?? false) ? $svc->restore($actor, $d) : $svc->archive($actor, $d, $v['reason']);

            return ['message' => ($v['restore'] ?? false) ? 'Restored.' : 'Archived.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Tax and SSNIT rules

    private static function profile(): ?PayrollProfile
    {
        return PayrollProfile::orderByDesc('effective_from')->first();
    }

    private static function pct(int $bp): string
    {
        $v = number_format($bp / 100, 2, '.', '');

        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    /** The PAYE bands as the current page lists them: "First GHS 490.00", "Next GHS 110.00"… "Above GHS 50,000.00". */
    private static function bands(array $r): array
    {
        $from = 0;
        $out = [];
        foreach ($r['paye_bands'] as $i => [$w, $bp]) {
            $out[] = ['band' => $w === null ? 'Above GHS '.number_format($from / 100, 2) : ($i === 0 ? 'First' : 'Next').' GHS '.number_format($w / 100, 2),
                'rate' => self::pct((int) $bp).' %'];
            $from += (int) $w;
        }

        return $out;
    }

    private static function note(array $r): string
    {
        $p = fn ($bp) => self::pct((int) $bp);

        return "SSNIT: employee {$p($r['ssnit']['employee_bp'])} %, employer {$p($r['ssnit']['employer_bp'])} % of basic — Tier 1 {$p($r['ssnit']['tier1_bp'])} %, Tier 2 {$p($r['ssnit']['tier2_bp'])} %. "
            ."Tier 3 relieved up to {$p($r['tier3_relief_bp'] ?? 0)} % of basic. Junior staff overtime: {$p($r['overtime_junior']['low_bp'])} % up to {$p($r['overtime_junior']['threshold_bp'])} % of basic, "
            ."{$p($r['overtime_junior']['high_bp'])} % above. Standard day ".($r['standard_day_hours'] ?? 8).' hours (time beyond it is overtime).';
    }

    public function rules(Request $request): JsonResponse
    {
        $this->allowed(PayrollProfilePage::canAccess());
        $p = self::profile();
        if (! $p) {
            return response()->json(['profile' => null, 'can_verify' => false]);
        }
        $r = $p->rules;

        return response()->json(['can_verify' => Erp::canAnywhere('payroll.approve'), 'profile' => ['name' => $p->name,
            'verified' => $p->verified_at ? 'Checked by '.$p->verified_by_name.' on '.$p->verified_at->format('j M Y') : null,
            'bands' => self::bands($r), 'note' => self::note($r),
            'values' => ['bands' => array_map(fn ($b) => ['width' => $b[0] === null ? '' : number_format($b[0] / 100, 2, '.', ''), 'rate' => self::pct((int) $b[1])], $r['paye_bands']),
                'employee' => self::pct($r['ssnit']['employee_bp']), 'employer' => self::pct($r['ssnit']['employer_bp']), 'tier1' => self::pct($r['ssnit']['tier1_bp']),
                'tier3_relief' => self::pct($r['tier3_relief_bp'] ?? 0), 'ot_threshold' => self::pct($r['overtime_junior']['threshold_bp']),
                'ot_low' => self::pct($r['overtime_junior']['low_bp']), 'ot_high' => self::pct($r['overtime_junior']['high_bp']), 'hours' => (string) ($r['standard_day_hours'] ?? 8)]]]);
    }

    /** Check and confirm: the accountant's figures replace the rules, and they are recorded as the person who checked them. */
    public function verify(Request $request, HrService $svc): JsonResponse
    {
        $this->allowed(PayrollProfilePage::canAccess() && Erp::canAnywhere('payroll.approve'));
        $p = self::profile() ?? abort(404);
        $n = ['required', 'numeric', 'min:0', 'max:100'];
        $d = $request->validate(['bands' => ['required', 'array', 'min:1'], 'bands.*.width' => ['nullable', 'numeric', 'min:0.01'], 'bands.*.rate' => $n,
            'employee' => $n, 'employer' => $n, 'tier1' => $n, 'tier3_relief' => $n, 'ot_threshold' => ['required', 'numeric', 'min:0'], 'ot_low' => $n, 'ot_high' => $n,
            'hours' => ['required', 'integer', 'min:1', 'max:24']], ['bands.*' => 'Add the PAYE bands.', 'bands.*.rate.*' => 'Each band needs a rate from 0 to 100 %.']);
        $bp = fn ($v) => (int) round((float) $v * 100);
        $rules = $p->rules;
        $rules['paye_bands'] = array_values(array_map(fn ($b) => [($b['width'] ?? '') === '' || $b['width'] === null ? null : (int) round((float) $b['width'] * 100), $bp($b['rate'])], $d['bands']));
        $rules['ssnit'] = ['employee_bp' => $bp($d['employee']), 'employer_bp' => $bp($d['employer']), 'tier1_bp' => $bp($d['tier1']),
            'tier2_bp' => $bp($d['employee']) + $bp($d['employer']) - $bp($d['tier1'])];
        $rules['tier3_relief_bp'] = $bp($d['tier3_relief']);
        $rules['overtime_junior'] = ['threshold_bp' => $bp($d['ot_threshold']), 'low_bp' => $bp($d['ot_low']), 'high_bp' => $bp($d['ot_high'])];
        $rules['standard_day_hours'] = (int) $d['hours'];

        return $this->act(fn (Actor $actor) => ['message' => $svc->verifyProfile($actor, $p, $rules) ? 'Rules confirmed.' : '']);
    }

    public function rulesExport(Request $request, Exporter $exporter): StreamedResponse|JsonResponse
    {
        $this->allowed(PayrollProfilePage::canAccess());
        $p = self::profile() ?? abort(404);
        $format = (string) $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 422, 'Choose Excel, CSV or PDF.');
        $sheets = [['title' => $p->name, 'headings' => ['Monthly taxable income', 'Rate'], 'rows' => array_map(fn ($b) => [$b['band'], $b['rate']], self::bands($p->rules)),
            'note' => self::note($p->rules)]];
        $actor = Actor::user($request->user(), 'web');
        try {
            return match ($format) {
                'pdf' => $exporter->pdf($actor, 'Tax and SSNIT rules', $sheets, []),
                'xlsx' => $exporter->xlsx($actor, 'Tax and SSNIT rules', $sheets, []),
                default => $exporter->csv($actor, 'Tax and SSNIT rules', $sheets, []),
            };
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }
}
