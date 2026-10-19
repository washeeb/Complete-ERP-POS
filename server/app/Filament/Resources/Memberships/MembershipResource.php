<?php

namespace App\Filament\Resources\Memberships;

use App\Domain\Modules\ModuleService;
use App\Domain\Services\MembershipService;
use App\Filament\Resources\Memberships\Pages\ListMemberships;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\QuickCreate;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Customers' memberships and subscriptions: invoiced every period (Receivables), paused or cancelled here. */
class MembershipResource extends Resource
{
    protected static ?string $model = Membership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static ?string $navigationLabel = 'Memberships';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('services.memberships') && ModuleService::enabledAnywhere('services');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('services.memberships');
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
        $ids = Erp::branchIds('services.memberships');

        return parent::getEloquentQuery()->with('plan:id,name,interval_months', 'customer:id,name,phone')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function form(Schema $schema): Schema
    {
        $ids = Erp::branchIds('services.memberships');

        return $schema->columns(3)->components([
            QuickCreate::on(Select::make('customer_id')->label('Customer')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => Customer::where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                    ->orderBy('name')->limit(20)->get()->mapWithKeys(fn ($c) => [$c->id => trim("{$c->name} {$c->phone}")]))
                ->getOptionLabelUsing(fn ($value) => Customer::whereKey($value)->value('name')), 'customer'),
            Select::make('membership_plan_id')->label('Plan')->required()
                ->options(fn () => MembershipPlan::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->name} · ".FinanceUi::money($p->price_minor).' '.strtolower(MembershipService::INTERVALS[$p->interval_months])])),
            Select::make('branch_id')->label('Invoiced from (branch)')->required()
                ->options(fn () => Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->pluck('name', 'id')),
            DatePicker::make('starts_on')->label('Starts')->required()->default(now()),
            TextInput::make('price')->label('Agreed price per period (optional)')->numeric()->minValue(0.01)->helperText('Empty = the plan price.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $svc = fn () => app(MembershipService::class);

        return $table->defaultSort('next_bill_on')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('customer.name')->label('Customer')->searchable()->description(fn (Membership $r) => $r->customer?->phone),
                TextColumn::make('plan.name')->label('Plan'),
                TextColumn::make('price_minor')->label('Price')->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('starts_on')->label('Since')->date(),
                TextColumn::make('next_bill_on')->label('Next invoice')->date()->description(fn (Membership $r) => $r->ends_on ? 'Ends '.$r->ends_on->format('j M Y') : null),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Membership::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['active' => 'success', 'paused' => 'warning'][$state] ?? 'gray'),
            ])
            ->filters([SelectFilter::make('status')->options(Membership::STATUSES)->default('active')])
            ->recordActions([
                Action::make('billNow')->label('Invoice due periods')->visible(fn (Membership $r) => $r->status === 'active' && $r->next_bill_on->lte(now()) && config('erp.node.role') !== 'branch')
                    ->action(fn (Membership $r) => Erp::run(fn ($actor) => $svc()->billDue($actor, null, $r), 'Invoiced')),
                Action::make('pause')->color('warning')->visible(fn (Membership $r) => $r->status === 'active')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (Membership $r, array $data) => Erp::run(fn ($actor) => $svc()->pause($actor, $r, $data['reason']), 'Paused: no invoices until resumed')),
                Action::make('resume')->color('success')->visible(fn (Membership $r) => $r->status === 'paused')
                    ->action(fn (Membership $r) => Erp::run(fn ($actor) => $svc()->resume($actor, $r), 'Resumed')),
                Action::make('cancel')->color('danger')->visible(fn (Membership $r) => $r->status !== 'cancelled')
                    ->modalDescription('No more invoices. The membership runs to the end of the period already invoiced.')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (Membership $r, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $r, $data['reason']), 'Cancelled')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMemberships::route('/')];
    }
}
