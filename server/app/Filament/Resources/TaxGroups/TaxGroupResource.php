<?php

namespace App\Filament\Resources\TaxGroups;

use App\Domain\Catalog\CatalogService;
use App\Filament\Resources\TaxGroups\Pages\ListTaxGroups;
use App\Filament\Support\Erp;
use App\Filament\Support\SimpleCatalogResource;
use App\Models\TaxGroup;
use App\Models\TaxRate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class TaxGroupResource extends SimpleCatalogResource
{
    protected static ?string $model = TaxGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Pricing & tax';

    protected static ?string $navigationLabel = 'Tax groups';

    protected static ?int $navigationSort = 2;

    protected static string $managePermission = 'pricing.manage';

    public static function getNavigationBadge(): ?string
    {
        $unverified = TaxGroup::whereNull('verified_at')->count();

        return $unverified ? (string) $unverified : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Tax groups not yet checked by an accountant';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(20)->regex('/^[A-Za-z0-9_]{1,20}$/')->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(80),
            CheckboxList::make('rate_ids')->label('Taxes charged')
                ->options(fn () => TaxRate::orderBy('sort')->get()->mapWithKeys(fn ($r) => [$r->id => "{$r->name} (".number_format($r->rate_bp / 100, 2).'%)']))
                ->helperText('Each is calculated on the price before tax (no tax on tax). Changing this clears the accountant sign-off.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $save = fn (?TaxGroup $record, array $data): Model => Erp::run(fn ($actor) => app(CatalogService::class)
            ->saveTaxGroup($actor, $record ?? new TaxGroup, $data, $data['rate_ids'] ?? []));

        return $table
            ->columns([
                TextColumn::make('code'),
                TextColumn::make('name'),
                TextColumn::make('rates_list')->label('Taxes')->state(fn (TaxGroup $r) => $r->rates->map(fn ($x) => $x->code.' '.number_format($x->rate_bp / 100, 2).'%')->implode(' + ') ?: 'none'),
                TextColumn::make('verified')->label('Accountant check')->badge()
                    ->state(fn (TaxGroup $r) => $r->verified_at ? "Verified by {$r->verified_by_name}, {$r->verified_at->format('j M Y')}" : 'Not verified')
                    ->color(fn (TaxGroup $r) => $r->verified_at ? 'success' : 'warning'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('rates'))
            ->pushHeaderActions([CreateAction::make()->using(fn (array $data) => $save(null, $data))])
            ->recordActions([
                EditAction::make()
                    ->fillForm(fn (TaxGroup $record) => $record->only('code', 'name') + ['rate_ids' => $record->rates->pluck('id')->all()])
                    ->using(fn (TaxGroup $record, array $data) => $save($record, $data)),
                Action::make('verify')->label('Record accountant check')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                    ->visible(fn (TaxGroup $r) => ! $r->verified_at && Erp::can('pricing.manage'))
                    ->modalDescription('Confirm that a qualified accountant has checked these rates and the calculation method (tax-inclusive prices split per line, no tax on tax) against current GRA rules.')
                    ->schema([Textarea::make('note')->label('Who checked it, and against what')->required()->maxLength(255)])
                    ->action(fn (TaxGroup $record, array $data) => Erp::run(fn ($actor) => app(CatalogService::class)
                        ->verifyTaxGroup($actor, $record, $data['note']), 'Accountant check recorded')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTaxGroups::route('/')];
    }
}
