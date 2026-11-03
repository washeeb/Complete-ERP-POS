<?php

namespace App\Filament\Resources\CrmLeads;

use App\Domain\Crm\Crm;
use App\Filament\Resources\CrmDeals\CrmDealResource;
use App\Filament\Resources\CrmLeads\Pages\CreateCrmLead;
use App\Filament\Resources\CrmLeads\Pages\EditCrmLead;
use App\Filament\Resources\CrmLeads\Pages\ListCrmLeads;
use App\Filament\Resources\CrmLeads\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\CrmUi;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\CrmLead;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** CRM → Leads: people or businesses who may buy, worked until they become customers or are let go. */
class CrmLeadResource extends Resource
{
    protected static ?string $model = CrmLead::class;

    protected static ?string $slug = 'crm-leads';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $navigationLabel = 'Leads';

    protected static ?string $modelLabel = 'lead';

    protected static ?int $navigationSort = 2;

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
        return CrmUi::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        return false; // closed as converted or lost, with the reason
    }

    public static function getEloquentQuery(): Builder
    {
        return CrmUi::scope(parent::getEloquentQuery()->with(['owner', 'branch', 'customer']));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                TextInput::make('name')->label('Name')->required()->maxLength(255),
                TextInput::make('company_name')->label('Business (if any)')->maxLength(255),
                Select::make('source')->label('Where they came from')->options(CrmLead::SOURCES)->default('walk_in')->required()->selectablePlaceholder(false),
                TextInput::make('phone')->tel()->maxLength(30)->helperText('Ghana numbers are stored as +233…'),
                TextInput::make('email')->email()->maxLength(255),
                Select::make('status')->options(array_slice(CrmLead::STATUSES, 0, 3, true))->default('new')->required()->selectablePlaceholder(false),
                TextInput::make('interest')->label('What they are interested in')->maxLength(255)->columnSpan(2),
                TextInput::make('value')->label('What it may be worth')->numeric()->minValue(0)->prefix(fn () => FinanceUi::currency()),
                Select::make('branch_id')->label('Branch')->options(fn () => CrmUi::branches())->default(fn () => array_key_first(CrmUi::branches()))->required(),
                Select::make('owner_id')->label('Who follows it up')->options(fn () => CrmUi::people())->default(fn () => Erp::user()?->id),
                Textarea::make('notes')->columnSpanFull(),
            ]),
            Section::make('Marketing consent')->description('Only tick what they agreed to. Campaigns go only to people who agreed to that channel.')
                ->columns(3)->schema([
                    Toggle::make('consents.sms')->label('SMS'),
                    Toggle::make('consents.whatsapp')->label('WhatsApp'),
                    Toggle::make('consents.email')->label('E-mail'),
                ]),
        ]);
    }

    /** Form data → domain data. */
    public static function toDomain(array $data): array
    {
        $data['value_minor'] = ($data['value'] ?? '') === '' || $data['value'] === null ? null : FinanceUi::toMinor($data['value']);
        $data['consents'] = array_map(fn ($v) => (bool) $v, $data['consents'] ?? []);
        unset($data['value']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('No.')->searchable()->color('gray'),
                TextColumn::make('name')->searchable(['name', 'company_name', 'phone', 'email'])->weight('bold')->description(fn (CrmLead $l) => $l->company_name),
                TextColumn::make('phone')->placeholder('—')->description(fn (CrmLead $l) => $l->email),
                TextColumn::make('interest')->label('Interested in')->wrap()->limit(60)->placeholder('—')
                    ->description(fn (CrmLead $l) => 'From: '.(CrmLead::SOURCES[$l->source] ?? $l->source)),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => CrmLead::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'converted' => 'success', 'lost' => 'gray', 'qualified' => 'info', default => 'warning'
                    }),
                TextColumn::make('value_minor')->label('Worth')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money($state === null ? null : (int) $state))->placeholder('—'),
                TextColumn::make('owner.name')->label('Follows up')->placeholder('—'),
                TextColumn::make('created_at')->label('Added')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(CrmLead::STATUSES),
                SelectFilter::make('source')->label('From')->options(CrmLead::SOURCES),
                SelectFilter::make('owner_id')->label('Follows up')->options(fn () => CrmUi::people()),
                TernaryFilter::make('open')->label('Open only')->default(true)
                    ->queries(true: fn ($q) => $q->whereIn('status', ['new', 'contacted', 'qualified']), false: fn ($q) => $q->whereIn('status', ['converted', 'lost']), blank: fn ($q) => $q),
            ])
            ->emptyStateHeading('No leads yet')
            ->emptyStateDescription('Add everyone who asks about buying — walk-ins, calls, WhatsApp messages — so no one is forgotten.')
            ->recordUrl(fn (CrmLead $l) => $l->isOpen() && CrmUi::canManage() ? self::getUrl('edit', ['record' => $l]) : null)
            ->recordActions([
                CrmUi::followUpAction('lead_id'),
                Action::make('convert')->label('Make customer')->icon(Heroicon::OutlinedUserGroup)->color('success')
                    ->visible(fn (CrmLead $l) => $l->isOpen() && CrmUi::canManage() && Erp::canAnywhere('customers.manage'))
                    ->modalDescription('They become a customer (or are linked to the customer with the same phone number), with their consents and follow-ups.')
                    ->schema([TextInput::make('deal')->label('Also open a deal for (optional)')->placeholder('e.g. Monthly supply of baby milk')->maxLength(255)])
                    ->action(function (CrmLead $record, array $data, $livewire) {
                        $r = Erp::run(fn ($actor) => app(Crm::class)->convertLead($actor, $record, $data['deal'] ?? null), 'Now a customer');
                        if ($r) {
                            $livewire->redirect($r['deal'] ? CrmDealResource::getUrl('edit', ['record' => $r['deal']]) : CustomerResource::getUrl('view', ['record' => $r['customer']]));
                        }
                    }),
                ActionGroup::make([EditAction::make(), Action::make('lose')->label('Lost')->icon(Heroicon::OutlinedXCircle)->color('gray')
                    ->visible(fn (CrmLead $l) => $l->isOpen() && CrmUi::canManage())
                    ->schema([TextInput::make('reason')->label('Why')->required()->maxLength(255)->placeholder('e.g. Too far, bought elsewhere, only asking')])
                    ->action(fn (CrmLead $record, array $data) => Erp::run(fn ($actor) => app(Crm::class)->loseLead($actor, $record, $data['reason']), 'Lead closed'))]),
            ]);
    }

    public static function getRelations(): array
    {
        return [ActivitiesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCrmLeads::route('/'),
            'create' => CreateCrmLead::route('/create'),
            'edit' => EditCrmLead::route('/{record}/edit'),
        ];
    }
}
