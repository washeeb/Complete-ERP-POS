<?php

namespace App\Filament\Resources\CrmDeals;

use App\Domain\Crm\Crm;
use App\Filament\Resources\CrmDeals\Pages\CreateCrmDeal;
use App\Filament\Resources\CrmDeals\Pages\EditCrmDeal;
use App\Filament\Resources\CrmDeals\Pages\ListCrmDeals;
use App\Filament\Resources\CrmLeads\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\CustomerOrders\CustomerOrderResource;
use App\Filament\Support\CrmUi;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\IndustryScope;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmStage;
use App\Models\Customer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

/** CRM → Deals: sales being worked on, with their stage, value, expected close and quotation. */
class CrmDealResource extends Resource
{
    protected static ?string $model = CrmDeal::class;

    protected static ?string $slug = 'crm-deals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $navigationLabel = 'Deals';

    protected static ?string $modelLabel = 'deal';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return CrmUi::canView();
    }

    public static function canCreate(): bool
    {
        return CrmUi::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return CrmUi::canManage() && $record->status === 'open';
    }

    public static function canDelete(Model $record): bool
    {
        return false; // closed as won or lost
    }

    public static function getEloquentQuery(): Builder
    {
        return IndustryScope::branches(CrmUi::scope(parent::getEloquentQuery()->with(['stage', 'customer', 'lead', 'owner', 'order'])));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                TextInput::make('title')->label('What they want')->required()->maxLength(255)->columnSpan(2)->placeholder('e.g. Monthly supply for a clinic'),
                Select::make('stage_id')->label('Stage')->required()->selectablePlaceholder(false)
                    ->options(fn () => CrmStage::where('is_active', true)->where('kind', 'open')->orderBy('sort')->pluck('name', 'id'))
                    ->default(fn () => CrmStage::where('is_active', true)->where('kind', 'open')->orderBy('sort')->value('id')),
                Select::make('customer_id')->label('Customer')->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Customer::where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('phone', 'ilike', "%{$search}%"))->limit(30)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Customer::find($value)?->name)->helperText('Or a lead, for someone not yet a customer.')
                    ->default(fn () => request()->query('customer')), // "New deal" on a customer's page
                Select::make('lead_id')->label('Lead')->searchable()
                    ->getSearchResultsUsing(fn (string $search) => CrmLead::whereIn('status', ['new', 'contacted', 'qualified'])->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('company_name', 'ilike', "%{$search}%"))->limit(30)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => CrmLead::find($value)?->name),
                TextInput::make('value')->label('Value')->numeric()->minValue(0)->prefix(fn () => FinanceUi::currency())->default(0),
                DatePicker::make('expected_close_on')->label('Expected to close'),
                Select::make('branch_id')->label('Branch')->options(fn () => CrmUi::branches())->default(fn () => array_key_first(CrmUi::branches()))->required(),
                Select::make('owner_id')->label('Who works it')->options(fn () => CrmUi::people())->default(fn () => Erp::user()?->id),
                Textarea::make('notes')->columnSpanFull(),
            ]),
        ]);
    }

    public static function toDomain(array $data): array
    {
        $data['value_minor'] = FinanceUi::toMinor($data['value'] ?? 0);
        unset($data['value']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('No.')->searchable()->color('gray'),
                TextColumn::make('title')->searchable()->weight('bold')->wrap()->description(fn (CrmDeal $d) => $d->contactName()),
                TextColumn::make('stage.name')->label('Stage')->badge()
                    ->color(fn (CrmDeal $d) => match ($d->status) {
                        'won' => 'success', 'lost' => 'gray', default => 'info'
                    }),
                TextColumn::make('value_minor')->label('Value')->alignEnd()->sortable()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state))
                    ->description(fn (CrmDeal $d) => $d->status === 'open' ? 'Weighted '.FinanceUi::money(intdiv($d->value_minor * (int) $d->stage?->probability, 100)) : null),
                TextColumn::make('expected_close_on')->label('Closes')->date('j M Y')->sortable()->placeholder('—')
                    ->color(fn (CrmDeal $d) => $d->status === 'open' && $d->expected_close_on?->isPast() ? 'danger' : null),
                TextColumn::make('order.quote_number')->label('Quotation')->placeholder('—')
                    ->url(fn (CrmDeal $d) => $d->customer_order_id && CustomerOrderResource::canViewAny() ? CustomerOrderResource::getUrl('view', ['record' => $d->customer_order_id]) : null),
                TextColumn::make('owner.name')->label('Works it')->placeholder('—'),
                TextColumn::make('lost_reason')->label('Why lost')->placeholder('')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(CrmDeal::STATUSES)->default('open'),
                SelectFilter::make('stage_id')->label('Stage')->options(fn () => CrmStage::orderBy('sort')->pluck('name', 'id')),
                SelectFilter::make('owner_id')->label('Works it')->options(fn () => CrmUi::people()),
            ])
            ->emptyStateHeading('No deals')
            ->emptyStateDescription('A deal is a sale you are working on. Add one here, from a lead (“Make customer”), or on the pipeline.')
            ->recordUrl(fn (CrmDeal $d) => self::canEdit($d) ? self::getUrl('edit', ['record' => $d]) : null)
            ->recordActions((function () {
                [$edit, $followUp, $quote, $win, $lose, $reopen] = self::dealActions();

                return [$followUp, $quote, ActionGroup::make([$edit, $win, $lose, $reopen])];
            })());
    }

    /** The row actions, also on the deal's own page. */
    public static function dealActions(): array
    {
        return [
            EditAction::make(),
            CrmUi::followUpAction('deal_id'),
            Action::make('quote')->label(fn (CrmDeal $d) => $d->customer_order_id ? 'Open quotation' : 'Make quotation')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->visible(fn (CrmDeal $d) => $d->status === 'open' && CrmUi::canManage() && Erp::canAnywhere('sales.orders.manage'))
                ->action(function (CrmDeal $record, $livewire) {
                    $order = Erp::run(fn ($actor) => app(Crm::class)->quoteForDeal($actor, $record));
                    $livewire->redirect(CustomerOrderResource::getUrl($order->status === 'quotation' ? 'edit' : 'view', ['record' => $order]));
                }),
            Action::make('win')->label('Won')->icon(Heroicon::OutlinedTrophy)->color('success')->requiresConfirmation()
                ->modalDescription('Usually a deal is won by itself when its quotation is confirmed as an order. Mark it won by hand only if it was sold another way.')
                ->visible(fn (CrmDeal $d) => $d->status === 'open' && CrmUi::canManage())
                ->action(fn (CrmDeal $record) => Erp::run(fn ($actor) => app(Crm::class)->winDeal($actor, $record, 'marked won by hand'), 'Deal won')),
            Action::make('lose')->label('Lost')->icon(Heroicon::OutlinedXCircle)->color('gray')
                ->visible(fn (CrmDeal $d) => $d->status === 'open' && CrmUi::canManage())
                ->schema([TextInput::make('reason')->label('Why')->required()->maxLength(255)->placeholder('e.g. Price, went to a competitor, no longer needed')])
                ->action(fn (CrmDeal $record, array $data) => Erp::run(fn ($actor) => app(Crm::class)->loseDeal($actor, $record, $data['reason']), 'Deal closed')),
            Action::make('reopen')->label('Reopen')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')->requiresConfirmation()
                ->visible(fn (CrmDeal $d) => $d->status !== 'open' && CrmUi::canManage())
                ->action(fn (CrmDeal $record) => Erp::run(fn ($actor) => app(Crm::class)->reopenDeal($actor, $record), 'Deal reopened')),
        ];
    }

    public static function getRelations(): array
    {
        return [ActivitiesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCrmDeals::route('/'),
            'create' => CreateCrmDeal::route('/create'),
            'edit' => EditCrmDeal::route('/{record}/edit'),
        ];
    }
}
