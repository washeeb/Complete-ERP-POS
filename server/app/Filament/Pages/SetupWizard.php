<?php

namespace App\Filament\Pages;

use App\Domain\Branding\Branding;
use App\Domain\Modules\ModuleService;
use App\Domain\Org\OrgService;
use App\Filament\Support\Erp;
use App\Models\Tenant;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
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
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
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
        // Prefilled with the demo layout agreed in Phase 0, keeping only modules the licence covers; every value is editable.
        $licensed = array_keys(ModuleService::licensed());
        $branch = fn (string $code, string $name, bool $hq, string $module) => ['code' => $code, 'name' => $name, 'is_hq' => $hq, 'tills' => 2,
            'modules' => array_values(array_intersect([$module], $licensed))];
        $this->form->fill([
            'deployment_mode' => 'cloud',
            'company' => ['name' => $tenant->name, 'country_code' => 'GH', 'currency_code' => 'GHS', 'timezone' => 'Africa/Accra', 'locale' => 'en'],
            'branches' => [
                $branch('HQ', 'Head Office & Warehouse', true, 'retail_wholesale'),
                $branch('A', 'Branch A — Grocery', false, 'grocery'),
                $branch('B', 'Branch B — Restaurant', false, 'restaurant'),
                $branch('C', 'Branch C — Pharmacy', false, 'pharmacy'),
            ],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $modules = collect(ModuleService::licensed()); // only modules the licence covers

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
                Step::make('Your brand')->icon(Heroicon::OutlinedSwatch)->schema([
                    Text::make(new HtmlString('Your logo and colour appear on the back office, the tills, receipts, invoices and your online shop. <strong>Optional</strong> — you can add or change them later under Company → Branding.')),
                    Grid::make(2)->schema([
                        FileUpload::make('brand.logo')->label('Logo (PNG with transparent background, or SVG)')->disk('local')->directory('brand-uploads')->visibility('private')
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])->maxSize(2048)->image()->imagePreviewHeight('90'),
                        FileUpload::make('brand.icon')->label('Square icon (optional — your initials are used until you add one)')->disk('local')->directory('brand-uploads')->visibility('private')
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])->maxSize(2048)->image()->imagePreviewHeight('90'),
                        ColorPicker::make('brand.color')->label('Brand colour (leave empty to take it from your logo)')->regex('/^#[0-9A-Fa-f]{6}$/'),
                        Toggle::make('brand.receipt_logo')->label('Print the logo on till receipts')->default(true),
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
        $brand = $data['brand'] ?? [];
        unset($data['brand']);
        Erp::run(fn ($actor) => app(OrgService::class)->completeSetup($actor, $data), 'Company set up');
        $this->saveBrand($brand);
        $this->redirect(Dashboard::getUrl());
    }

    /** The brand step: after the company exists. A logo problem never undoes the setup — it is reported, and fixed on Company → Branding. */
    private function saveBrand(array $brand): void
    {
        $disk = Storage::disk('local');
        try {
            Erp::run(function ($actor) use ($brand, $disk) {
                $branding = app(Branding::class);
                foreach (['logo', 'icon'] as $kind) {
                    if (! empty($brand[$kind])) {
                        $branding->upload($actor, $kind, $disk->path($brand[$kind]));
                    }
                }
                $color = ($brand['color'] ?? null) ?: Branding::suggestColor($actor->tenantId);
                $branding->saveSettings($actor, $color, (bool) ($brand['receipt_logo'] ?? true));

                return true;
            });
        } catch (Halt) {
            // shown by Erp::run; the company is set up either way
        } finally {
            foreach (['logo', 'icon'] as $kind) {
                if (! empty($brand[$kind])) {
                    $disk->delete($brand[$kind]);
                }
            }
        }
    }
}
