<?php

namespace App\Filament\Pages;

use App\Domain\Catalog\CatalogService;
use App\Domain\Pharmacy\HealthReadings;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\ListFilters;
use App\Models\Customer;
use App\Models\HealthReading;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\TaxGroup;
use App\Models\Uom;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Pharmacy → Health checks: every reading taken, by person, kind and result, with a patient's history and trend, and
 * setting up a fee service such as a blood pressure check. Health data: only people who may see patients.
 */
class HealthReadingsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'Pharmacy';

    protected static ?string $navigationLabel = 'Health checks';

    protected static ?string $title = 'Health checks';

    protected static ?string $slug = 'health-checks';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('pharmacy.patients');
    }

    public function getSubheading(): ?string
    {
        return 'Flags follow common screening limits, to prompt a referral. They are not a diagnosis.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('service')->label('Add a fee service')->icon(Heroicon::OutlinedPlus)->color('gray')->visible(fn () => Erp::canAnywhere('catalog.manage') && Erp::canAnywhere('pricing.manage'))
                ->modalDescription('A service sold at the till like a product, with no stock. If it has a reading, the till asks for it.')
                ->schema([
                    TextInput::make('name')->label('Name')->required()->maxLength(160)->placeholder('Blood pressure check'),
                    TextInput::make('price')->label('Fee')->required()->numeric()->minValue(0),
                    Select::make('reading_kind')->label('Reading taken')->placeholder('None')->options(HealthReading::KINDS),
                    Select::make('tax_group_id')->label('Tax')->required()->options(fn () => TaxGroup::orderBy('name')->pluck('name', 'id')),
                ])
                ->action(fn (array $data) => Erp::run(fn ($actor) => self::addService($actor, $data), 'Service added: find it at the till by name')),
            Action::make('take')->label('Record a reading')->icon(Heroicon::OutlinedHeart)
                ->schema([
                    Select::make('customer_id')->label('Patient')->searchable()->getSearchResultsUsing(fn (string $search) => Customer::where('name', 'ilike', "%{$search}%")->limit(30)->pluck('name', 'id'))
                        ->getOptionLabelUsing(fn ($value) => Customer::find($value)?->name)->helperText('Empty for a walk-in.'),
                    Select::make('branch_id')->label('Branch')->required()->selectablePlaceholder(false)->options(fn () => FinanceUi::branches())->default(fn () => FinanceUi::hq()),
                    Select::make('kind')->label('Measured')->required()->selectablePlaceholder(false)->live()->options(HealthReading::KINDS)->default('bp'),
                    TextInput::make('systolic')->label('Top number (systolic)')->numeric()->visible(fn (Get $get) => $get('kind') === 'bp'),
                    TextInput::make('diastolic')->label('Bottom number (diastolic)')->numeric()->visible(fn (Get $get) => $get('kind') === 'bp'),
                    TextInput::make('pulse')->label('Pulse')->numeric()->visible(fn (Get $get) => $get('kind') === 'bp'),
                    TextInput::make('value')->label(fn (Get $get) => ['sugar' => 'Reading (mmol/L)', 'weight' => 'Weight (kg)', 'temperature' => 'Temperature (°C)'][$get('kind')] ?? 'Reading')->numeric()->visible(fn (Get $get) => $get('kind') !== 'bp'),
                    Textarea::make('note')->label('Note')->rows(2)->maxLength(255),
                ])
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(HealthReadings::class)->record($actor, $data), 'Reading saved')),
        ];
    }

    private static function addService($actor, array $data): Product
    {
        $list = PriceList::where('is_default', true)->firstOrFail();
        $uom = Uom::firstOrCreate(['code' => 'SVC'], ['name' => 'Service', 'decimals' => 0]);
        $product = app(CatalogService::class)->saveProduct($actor, new Product, ['name' => $data['name'], 'base_uom_id' => $uom->id, 'tax_group_id' => $data['tax_group_id'], 'track_stock' => false, 'is_active' => true,
            'reading_kind' => ($data['reading_kind'] ?? null) ?: null, 'prices' => [['price_list_id' => $list->id, 'uom_id' => $uom->id, 'min_qty' => '1', 'amount' => (string) $data['price']]]]);

        return $product;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => HealthReading::query()->with('customer', 'branch'))
            ->columns([
                TextColumn::make('taken_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('person')->label('Person')->state(fn (HealthReading $r) => $r->customer?->name ?? 'Walk-in'),
                TextColumn::make('kind')->label('Measured')->formatStateUsing(fn ($state) => HealthReading::KINDS[$state] ?? $state),
                TextColumn::make('reading')->label('Reading')->weight('bold')->state(fn (HealthReading $r) => $r->summary()),
                TextColumn::make('flag')->label('Result')->badge()->formatStateUsing(fn ($state) => HealthReading::FLAGS[$state] ?? $state)
                    ->color(fn ($state) => ['urgent' => 'danger', 'high' => 'warning', 'low' => 'warning', 'normal' => 'success'][$state] ?? 'gray'),
                TextColumn::make('note')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('branch.code')->label('Branch')->toggleable(),
                TextColumn::make('taken_by_name')->asPerson('taken_by')->label('Taken by'),
            ])
            ->filters([
                ListFilters::dates('taken_at', 'Date'),
                SelectFilter::make('kind')->label('Measured')->options(HealthReading::KINDS),
                SelectFilter::make('flag')->label('Result')->multiple()->options(HealthReading::FLAGS),
                SelectFilter::make('customer_id')->label('Patient')->searchable()->getSearchResultsUsing(fn (string $search) => Customer::where('name', 'ilike', "%{$search}%")->limit(30)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Customer::find($value)?->name)->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))),
            ])
            ->defaultSort('taken_at', 'desc')
            ->emptyStateHeading('No readings yet');
    }
}
