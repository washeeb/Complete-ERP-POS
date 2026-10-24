<?php

namespace App\Filament\Resources\NhisClaims;

use App\Domain\Modules\ModuleService;
use App\Domain\Pharmacy\NhisClaims;
use App\Filament\Resources\NhisClaims\Pages\ListNhisClaims;
use App\Filament\Resources\NhisClaims\Pages\ViewNhisClaim;
use App\Filament\Resources\NhisClaims\RelationManagers\LinesRelationManager;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Branch;
use App\Models\NhisClaim;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
use UnitEnum;

/** Pharmacy → NHIS claims: the NHIS parts of sales claimed from the NHIA, sent, and settled with what it paid. */
class NhisClaimResource extends Resource
{
    protected static ?string $model = NhisClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Pharmacy';

    protected static ?string $navigationLabel = 'NHIS claims';

    protected static ?string $modelLabel = 'NHIS claim';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere(NhisClaims::PERMISSION) && ModuleService::enabledAnywhere('pharmacy');
    }

    public static function canCreate(): bool
    {
        return false; // "Prepare a claim"
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Only the branches the person may claim for. */
    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds(NhisClaims::PERMISSION);

        return parent::getEloquentQuery()->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    /** @return array<string, string> */
    public static function branches(): array
    {
        $ids = Erp::branchIds(NhisClaims::PERMISSION);

        return Branch::where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function prepareAction(): Action
    {
        return Action::make('prepare')->label('Prepare a claim')->icon(Heroicon::OutlinedDocumentPlus)
            ->modalDescription('Puts together the NHIS parts of the branch\'s sales not claimed yet, up to the day you choose (returns come off). Then download the list, send it to the NHIA, and mark it sent.')
            ->fillForm(fn () => ['branch_id' => count($b = self::branches()) === 1 ? array_key_first($b) : null, 'up_to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString()])
            ->schema([
                Select::make('branch_id')->label('Branch')->options(fn () => self::branches())->required()->live(),
                DatePicker::make('up_to')->label('Sales up to and including')->required()->maxDate(now())->live()
                    ->helperText(function ($get) {
                        $b = $get('branch_id') ? Branch::find($get('branch_id')) : null;
                        if (! $b || ! $get('up_to')) {
                            return null;
                        }
                        $q = NhisClaims::unclaimed($b, $get('up_to'));

                        return 'Not claimed yet: '.(clone $q)->distinct()->count('p.sale_id').' sale(s), '.FinanceUi::money((int) (clone $q)->sum('p.amount_minor')).'.';
                    }),
            ])
            ->action(function (array $data, $livewire) {
                $claim = Erp::run(fn ($actor) => app(NhisClaims::class)->prepare($actor, Branch::findOrFail($data['branch_id']), $data['up_to']), 'Claim prepared');
                if ($claim) {
                    $livewire->redirect(self::getUrl('view', ['record' => $claim->id]));
                }
            });
    }

    /** @return list<Action> */
    public static function claimActions(): array
    {
        $svc = fn () => app(NhisClaims::class);

        return [
            Action::make('download')->label('Download the list (CSV)')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn (NhisClaim $record) => $record->status !== 'withdrawn')
                ->url(fn (NhisClaim $record) => route('print.nhis-claim', ['claim' => $record->id])),
            Action::make('submit')->label('Mark as sent to the NHIA')->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (NhisClaim $record) => $record->status === 'open')
                ->schema([TextInput::make('reference')->label('NHIA claim reference (optional)')->maxLength(80)])
                ->action(fn (NhisClaim $record, array $data) => Erp::run(fn ($actor) => $svc()->submit($actor, $record, (string) ($data['reference'] ?? '')), 'Marked as sent')),
            Action::make('settle')->label('Record what the NHIA paid')->icon(Heroicon::OutlinedBanknotes)->color('success')
                ->visible(fn (NhisClaim $record) => $record->status === 'submitted')
                ->modalDescription(fn (NhisClaim $record) => 'Claimed '.FinanceUi::money($record->claimed_minor).'. What the NHIA did not pay is written off as "NHIS claims not paid".')
                ->fillForm(fn (NhisClaim $record) => ['paid' => number_format($record->claimed_minor / 10 ** Money::exponent(FinanceUi::currency()), Money::exponent(FinanceUi::currency()), '.', ''),
                    'paid_on' => now()->toDateString(), 'method' => 'bank'])
                ->schema([
                    TextInput::make('paid')->label('Amount paid')->numeric()->minValue(0)->required(),
                    DatePicker::make('paid_on')->label('Paid on')->maxDate(now())->required(),
                    Select::make('method')->label('Paid by')->options(NhisClaim::METHODS)->required(),
                    TextInput::make('note')->label('Note (e.g. why some was not paid)')->maxLength(255),
                ])
                ->action(fn (NhisClaim $record, array $data) => Erp::run(fn ($actor) => $svc()->settle($actor, $record, Money::toMinor((string) $data['paid'], FinanceUi::currency()),
                    $data['paid_on'], $data['method'], $data['note'] ?? null), 'Payment recorded')),
            Action::make('withdraw')->label('Withdraw')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (NhisClaim $record) => $record->status === 'open')
                ->modalDescription('Its sales go back to "not claimed yet", for the next claim.')
                ->schema([TextInput::make('reason')->label('Why')->required()->maxLength(255)])
                ->action(fn (NhisClaim $record, array $data) => Erp::run(fn ($actor) => $svc()->withdraw($actor, $record, $data['reason']), 'Withdrawn')),
        ];
    }

    private static function badge(TextColumn|TextEntry $c): TextColumn|TextEntry
    {
        return $c->badge()->formatStateUsing(fn ($state) => NhisClaim::STATUSES[$state] ?? $state)
            ->color(fn ($state) => match ($state) {
                'open' => 'warning', 'submitted' => 'info', 'settled' => 'success', default => 'gray'
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Claim'),
                self::badge(TextEntry::make('status')),
                TextEntry::make('branch.name')->label('Branch'),
                TextEntry::make('period_from')->label('Sales from')->state(fn (NhisClaim $record) => $record->period_from->format('j M Y').' to '.$record->period_to->format('j M Y')),
                TextEntry::make('claimed_minor')->label('Claimed')->formatStateUsing(fn ($state) => FinanceUi::money($state)),
                TextEntry::make('paid_minor')->label('Paid by the NHIA')->placeholder('—')->formatStateUsing(fn ($state) => FinanceUi::money($state)),
                TextEntry::make('rejected_minor')->label('Not paid (written off)')->placeholder('—')->formatStateUsing(fn ($state) => FinanceUi::money($state)),
                TextEntry::make('reference')->label('NHIA reference')->placeholder('—'),
                TextEntry::make('prepared_by_name')->label('Prepared by'),
                TextEntry::make('submitted_by_name')->label('Sent by')->placeholder('—')
                    ->formatStateUsing(fn ($state, NhisClaim $record) => $state.($record->submitted_at ? ', '.$record->submitted_at->format('j M Y') : '')),
                TextEntry::make('settled_by_name')->label('Payment recorded by')->placeholder('—')
                    ->formatStateUsing(fn ($state, NhisClaim $record) => $state.($record->paid_on ? ', paid '.$record->paid_on->format('j M Y').' ('.(NhisClaim::METHODS[$record->paid_method] ?? '').')' : '')),
                TextEntry::make('note')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('branch'))
            ->columns([
                TextColumn::make('number')->label('Claim')->searchable(),
                TextColumn::make('branch.name')->label('Branch'),
                TextColumn::make('period_to')->label('Sales up to')->date()->sortable(),
                TextColumn::make('claimed_minor')->label('Claimed')->formatStateUsing(fn ($state) => FinanceUi::money($state))->alignEnd(),
                TextColumn::make('paid_minor')->label('Paid')->formatStateUsing(fn ($state) => FinanceUi::money($state))->placeholder('—')->alignEnd(),
                TextColumn::make('rejected_minor')->label('Not paid')->formatStateUsing(fn ($state) => FinanceUi::money($state))->placeholder('—')->alignEnd(),
                self::badge(TextColumn::make('status')),
            ])
            ->filters([SelectFilter::make('status')->options(NhisClaim::STATUSES)->multiple()])
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make()->label('Open')])
            ->emptyStateHeading('No NHIS claims yet')
            ->emptyStateDescription('Sales the NHIS paid part of at the till are claimed here: Prepare a claim.');
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNhisClaims::route('/'),
            'view' => ViewNhisClaim::route('/{record}'),
        ];
    }
}
