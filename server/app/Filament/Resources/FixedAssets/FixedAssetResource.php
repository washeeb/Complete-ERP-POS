<?php

namespace App\Filament\Resources\FixedAssets;

use App\Domain\Assets\AssetService;
use App\Filament\Resources\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Filament\Resources\FixedAssets\Pages\ViewFixedAsset;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\AssetClass;
use App\Models\FixedAsset;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** The fixed asset register: straight-line monthly depreciation (owner decision 2026-10-13). */
class FixedAssetResource extends Resource
{
    protected static ?string $model = FixedAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Fixed assets';

    protected static ?string $modelLabel = 'fixed asset';

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('assets.manage') || Erp::canAnywhere('finance.view')) && config('erp.node.role') !== 'branch';
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('assets.manage') && config('erp.node.role') !== 'branch';
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('assetClass', 'branch');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make()->columns(2)->columnSpanFull()
                ->description('Registering an asset does not post its cost: record the purchase as a supplier bill (Other charges → the asset account) or in the opening balances.')
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    Select::make('asset_class_id')->label('Kind')->required()->options(fn () => AssetClass::orderBy('code')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->name} ({$c->life_months} months)"])),
                    Select::make('branch_id')->label('Used at')->required()->options(fn () => FinanceUi::branches())->default(fn () => FinanceUi::hq()),
                    DatePicker::make('acquired_on')->label('Bought on')->required()->maxDate(now()),
                    TextInput::make('cost')->required()->numeric()->minValue(0.01),
                    TextInput::make('residual')->label('Worth at end of life')->numeric()->minValue(0)->default('0'),
                    TextInput::make('life_months')->label('Useful life (months)')->numeric()->minValue(1)->helperText('Blank = the default for its kind.'),
                    TextInput::make('opening_depreciation')->label('Depreciation already in the opening balances')->numeric()->minValue(0)->default('0')
                        ->helperText('Only for assets brought over at go-live.'),
                    TextInput::make('serial_no')->label('Serial / registration no.')->maxLength(80),
                    TextInput::make('location')->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('name')->searchable()->description(fn (FixedAsset $r) => $r->serial_no),
                TextColumn::make('assetClass.name')->label('Kind'),
                TextColumn::make('branch.code')->label('At'),
                TextColumn::make('acquired_on')->label('Bought')->date()->sortable(),
                TextColumn::make('cost_minor')->label('Cost')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('book')->label('Book value')->alignEnd()->state(fn (FixedAsset $r) => $r->status === 'disposed' ? 0 : AssetService::bookValue($r))
                    ->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('status')->badge()->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('status')->options(['active' => 'Active', 'disposed' => 'Disposed']), SelectFilter::make('asset_class_id')->label('Kind')
                ->options(fn () => AssetClass::orderBy('code')->pluck('name', 'id'))])
            ->defaultSort('number', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $m = fn ($state) => FinanceUi::money((int) $state);

        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('name'),
                TextEntry::make('assetClass.name')->label('Kind'),
                TextEntry::make('status')->badge(),
                TextEntry::make('acquired_on')->label('Bought on')->date(),
                TextEntry::make('cost_minor')->label('Cost')->formatStateUsing($m),
                TextEntry::make('residual_minor')->label('Worth at end of life')->formatStateUsing($m),
                TextEntry::make('life_months')->label('Life')->suffix(' months'),
                TextEntry::make('book')->label('Book value now')->weight('bold')->state(fn (FixedAsset $r) => AssetService::bookValue($r))->formatStateUsing($m),
                TextEntry::make('serial_no')->label('Serial')->placeholder('—'),
                TextEntry::make('location')->placeholder('—'),
                TextEntry::make('disposal_reason')->label('Disposed')->placeholder('—')
                    ->formatStateUsing(fn ($state, FixedAsset $r) => $state ? "{$r->disposed_on?->format('j M Y')}: {$state} (".FinanceUi::money((int) $r->disposal_proceeds_minor).')' : null),
            ]),
            Section::make('Depreciation charged')->collapsible()->schema([
                RepeatableEntry::make('schedule')->hiddenLabel()->columns(2)
                    ->state(fn (FixedAsset $r) => DB::table('asset_depreciation')->where('fixed_asset_id', $r->id)->orderBy('month')->get()
                        ->map(fn ($d) => ['month' => Carbon::parse($d->month)->format('M Y'), 'amount' => FinanceUi::money((int) $d->amount_minor)])->all())
                    ->schema([TextEntry::make('month')->hiddenLabel(), TextEntry::make('amount')->hiddenLabel()]),
            ]),
        ]);
    }

    public static function disposeAction(): Action
    {
        return Action::make('dispose')->label('Dispose of')->icon(Heroicon::OutlinedTrash)->color('danger')
            ->visible(fn (FixedAsset $r) => $r->status === 'active' && Erp::canAnywhere('assets.manage'))
            ->schema([
                DatePicker::make('date')->required()->default(now())->maxDate(now()),
                TextInput::make('reason')->label('Sold, scrapped, stolen…')->required()->maxLength(255),
                TextInput::make('proceeds')->label('Money received')->numeric()->minValue(0)->default('0'),
                Select::make('account_id')->label('Money went into')->options(fn () => FinanceUi::moneyAccounts()),
            ])
            ->action(fn (FixedAsset $record, array $data) => Erp::run(fn ($actor) => app(AssetService::class)->dispose($actor, $record, $data['date'],
                FinanceUi::toMinor($data['proceeds'] ?? 0), ! empty($data['account_id']) ? Account::find($data['account_id']) : null, $data['reason']), 'Asset disposed of'));
    }

    public static function getPages(): array
    {
        return ['index' => ListFixedAssets::route('/'), 'create' => CreateFixedAsset::route('/create'), 'view' => ViewFixedAsset::route('/{record}')];
    }
}
