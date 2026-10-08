<?php

namespace App\Filament\Pages;

use App\Domain\Hr\HrService;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Models\PayrollProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/** The PAYE bands and SSNIT rates payroll uses; an accountant checks and confirms them. */
class PayrollProfilePage extends Page
{
    use ExportsPage;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'payroll-rules';

    protected static ?string $title = 'Tax and SSNIT rules';

    protected string $view = 'filament.pages.payroll-profile';

    public static function canAccess(): bool
    {
        return (Erp::canAnywhere('payroll.prepare') || Erp::canAnywhere('payroll.approve')) && config('erp.node.role') !== 'branch';
    }

    public function profile(): ?PayrollProfile
    {
        return PayrollProfile::orderByDesc('effective_from')->first();
    }

    protected function getHeaderActions(): array
    {
        $pct = fn ($bp) => rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');

        return [$this->exportGroup(),
            Action::make('verify')->label('Check and confirm')->icon(Heroicon::OutlinedCheckBadge)->modalWidth('3xl')
                ->visible(fn () => Erp::canAnywhere('payroll.approve') && $this->profile() !== null)
                ->modalDescription('Compare with the current GRA PAYE bands and SSNIT rates. Saving records you as the person who checked them.')
                ->fillForm(function () use ($pct) {
                    $r = $this->profile()->rules;

                    return ['bands' => array_map(fn ($b) => ['width' => $b[0] === null ? null : number_format($b[0] / 100, 2, '.', ''), 'rate' => $pct($b[1])], $r['paye_bands']),
                        'employee' => $pct($r['ssnit']['employee_bp']), 'employer' => $pct($r['ssnit']['employer_bp']), 'tier1' => $pct($r['ssnit']['tier1_bp']),
                        'tier3_relief' => $pct($r['tier3_relief_bp'] ?? 0), 'ot_threshold' => $pct($r['overtime_junior']['threshold_bp']), 'ot_low' => $pct($r['overtime_junior']['low_bp']),
                        'ot_high' => $pct($r['overtime_junior']['high_bp']), 'hours' => $r['standard_day_hours'] ?? 8];
                })
                ->schema([
                    Repeater::make('bands')->label('Monthly PAYE bands (leave the last band\'s width empty)')->columns(2)->schema([
                        TextInput::make('width')->label('Band width (GHS)')->numeric()->minValue(0.01),
                        TextInput::make('rate')->label('Rate %')->required()->numeric()->minValue(0)->maxValue(100),
                    ])->minItems(1),
                    Section::make('SSNIT and pensions (% of basic)')->columns(4)->schema([
                        TextInput::make('employee')->label('Employee')->required()->numeric(),
                        TextInput::make('employer')->label('Employer')->required()->numeric(),
                        TextInput::make('tier1')->label('Of which Tier 1')->required()->numeric(),
                        TextInput::make('tier3_relief')->label('Tier 3 tax relief up to')->required()->numeric(),
                    ]),
                    Section::make('Overtime of qualifying junior staff')->columns(4)->schema([
                        TextInput::make('ot_threshold')->label('Lower rate up to (% of basic)')->required()->numeric(),
                        TextInput::make('ot_low')->label('Lower rate %')->required()->numeric(),
                        TextInput::make('ot_high')->label('Higher rate %')->required()->numeric(),
                        TextInput::make('hours')->label('Standard day (hours)')->required()->numeric()->minValue(1)->maxValue(24),
                    ]),
                ])
                ->action(function (array $data) {
                    $bp = fn ($v) => (int) round((float) $v * 100);
                    $rules = $this->profile()->rules;
                    $rules['paye_bands'] = array_values(array_map(fn ($b) => [($b['width'] ?? '') === '' || $b['width'] === null ? null : (int) round((float) $b['width'] * 100), $bp($b['rate'])], $data['bands']));
                    $rules['ssnit'] = ['employee_bp' => $bp($data['employee']), 'employer_bp' => $bp($data['employer']), 'tier1_bp' => $bp($data['tier1']),
                        'tier2_bp' => $bp($data['employee']) + $bp($data['employer']) - $bp($data['tier1'])];
                    $rules['tier3_relief_bp'] = $bp($data['tier3_relief']);
                    $rules['overtime_junior'] = ['threshold_bp' => $bp($data['ot_threshold']), 'low_bp' => $bp($data['ot_low']), 'high_bp' => $bp($data['ot_high'])];
                    $rules['standard_day_hours'] = (int) $data['hours'];
                    Erp::run(fn ($actor) => app(HrService::class)->verifyProfile($actor, $this->profile(), $rules), 'Rules confirmed');
                }),
        ];
    }
}
