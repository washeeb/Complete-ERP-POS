<?php

namespace App\Filament\Pages;

use App\Domain\Org\OrgService;
use App\Filament\Support\Erp;
use App\Models\Tenant;
use App\Modules\ModuleRegistry;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * First-run setup. Steps for tax profile, chart of accounts, hardware and sync settings
 * join this wizard in the phases that build those features (see docs/DECISIONS.md).
 */
class SetupWizard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $title = 'Set up your company';

    protected static ?string $slug = 'setup-wizard';

    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        $user = Erp::user();

        return $user && ! Tenant::find($user->tenant_id)?->isSetUp() && Erp::can('setup.company.manage');
    }

    public static function canAccess(): bool
    {
        return Erp::can('setup.company.manage');
    }

    public function mount(): void
    {
        $tenant = Tenant::find(Erp::user()->tenant_id);
        if ($tenant->isSetUp()) {
            $this->redirect(Dashboard::getUrl());

            return;
        }
        // Prefilled with the demo layout agreed in Phase 0; every value is editable.
        $this->form->fill([
            'deployment_mode' => 'cloud',
            'company' => ['name' => $tenant->name, 'country_code' => 'GH', 'currency_code' => 'GHS', 'timezone' => 'Africa/Accra', 'locale' => 'en'],
            'branches' => [
                ['code' => 'HQ', 'name' => 'Head Office & Warehouse', 'is_hq' => true, 'tills' => 2, 'modules' => ['retail_wholesale']],
                ['code' => 'A', 'name' => 'Branch A — Grocery', 'is_hq' => false, 'tills' => 2, 'modules' => ['grocery']],
                ['code' => 'B', 'name' => 'Branch B — Restaurant', 'is_hq' => false, 'tills' => 2, 'modules' => ['restaurant']],
                ['code' => 'C', 'name' => 'Branch C — Pharmacy', 'is_hq' => false, 'tills' => 2, 'modules' => ['pharmacy']],
            ],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $modules = collect(app(ModuleRegistry::class)->selectable());

        return $schema->statePath('data')->components([
            Wizard::make([
                Step::make('Deployment')->icon(Heroicon::OutlinedServerStack)->schema([
                    Radio::make('deployment_mode')->label('How will this system run?')->required()
                        ->options(OrgService::DEPLOYMENT_MODES)
                        ->helperText('You can add branch servers or move to the cloud later; this records the starting layout.'),
                ]),
                Step::make('Company')->icon(Heroicon::OutlinedBuildingOffice2)->schema([
                    Grid::make(2)->schema([
                        TextInput::make('company.name')->label('Company / trading name')->required()->maxLength(120),
                        Select::make('company.country_code')->label('Country')->options(['GH' => 'Ghana', 'NG' => 'Nigeria', 'CI' => "Côte d'Ivoire", 'TG' => 'Togo', 'KE' => 'Kenya'])->required(),
                        Select::make('company.currency_code')->label('Base currency')->options(['GHS' => 'GHS — Ghana cedi', 'NGN' => 'NGN — Naira', 'XOF' => 'XOF — CFA franc', 'KES' => 'KES — Kenyan shilling', 'USD' => 'USD — US dollar'])->required(),
                        Select::make('company.timezone')->label('Time zone')->options(collect(timezone_identifiers_list(\DateTimeZone::AFRICA))->mapWithKeys(fn ($z) => [$z => $z]))->searchable()->required(),
                    ]),
                    Text::make('Legal entity (optional — needed for VAT invoices; can be added later)'),
                    Grid::make(3)->schema([
                        TextInput::make('legal_entity.name')->label('Registered name')->maxLength(150),
                        TextInput::make('legal_entity.tin')->label('TIN')->maxLength(30),
                        TextInput::make('legal_entity.vat_no')->label('VAT number')->maxLength(30),
                    ]),
                ]),
                Step::make('Branches & modules')->icon(Heroicon::OutlinedBuildingStorefront)->schema([
                    Text::make(new HtmlString('Each branch gets a main warehouse and the tills you ask for. <strong>Industry modules</strong> are chosen per branch; ones not released yet are <em>reserved</em> and switch on when their phase ships — they are never shown as working before then.')),
                    Repeater::make('branches')->label('Branches')->minItems(1)->maxItems(50)->reorderable(false)
                        ->itemLabel(fn (array $state) => trim(($state['code'] ?? '').' · '.($state['name'] ?? '')))
                        ->schema([
                            Grid::make(4)->schema([
                                TextInput::make('code')->required()->maxLength(6)->regex('/^[A-Za-z0-9]{1,6}$/')
                                    ->helperText('1–6 letters/digits, used in receipt numbers'),
                                TextInput::make('name')->required()->maxLength(120)->columnSpan(2),
                                TextInput::make('tills')->label('Tills')->numeric()->minValue(0)->maxValue(50)->default(1)->required(),
                            ]),
                            Toggle::make('is_hq')->label('Head office (exactly one)'),
                            CheckboxList::make('modules')->label('Industry & optional modules')->columns(2)
                                ->options($modules->mapWithKeys(fn ($m) => [$m->key => $m->name]))
                                ->descriptions($modules->mapWithKeys(fn ($m) => [$m->key => $m->statusLabel().' — '.$m->description])),
                        ]),
                ]),
            ])->submitAction(new HtmlString(Blade::render('<x-filament::button type="submit" size="lg">Finish setup</x-filament::button>'))),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save'),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        Erp::run(fn ($actor) => app(OrgService::class)->completeSetup($actor, $data), 'Company set up');
        $this->redirect(Dashboard::getUrl());
    }
}
