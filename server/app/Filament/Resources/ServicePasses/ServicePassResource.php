<?php

namespace App\Filament\Resources\ServicePasses;

use App\Domain\Modules\ModuleService;
use App\Domain\Services\PassService;
use App\Filament\Resources\ServicePasses\Pages\ListServicePasses;
use App\Filament\Support\Erp;
use App\Models\ServicePass;
use App\Models\ServicePassUse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Customers' prepaid passes (packs and membership periods): sessions left, history, void. Sessions are used at the till. */
class ServicePassResource extends Resource
{
    protected static ?string $model = ServicePass::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static ?string $navigationLabel = 'Prepaid passes';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('services.passes') && ModuleService::enabledAnywhere('services');
    }

    public static function canCreate(): bool
    {
        return false; // passes come from packs sold at the till and from memberships
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
        return parent::getEloquentQuery()->with('customer:id,name,phone');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('customer.name')->label('Customer')->searchable()->description(fn (ServicePass $r) => $r->customer?->phone),
                TextColumn::make('name')->label('Pass')->wrap(),
                TextColumn::make('left')->label('Sessions left')->state(fn (ServicePass $r) => collect(PassService::remaining($r))
                    ->map(fn ($n, $i) => ($n === null ? 'Unlimited' : $n).' '.$r->items[$i]['label'])->implode(' · ')),
                TextColumn::make('expires_on')->label('Expires')->date()->placeholder('Never'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state, ServicePass $r) => $state === 'active' && $r->expires_on?->isPast() ? 'Expired' : (ServicePass::STATUSES[$state] ?? $state))
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'gray')->description(fn (ServicePass $r) => $r->void_reason),
            ])
            ->filters([SelectFilter::make('status')->options(ServicePass::STATUSES)->default('active'), SelectFilter::make('source')->options(['pack' => 'Pack', 'membership' => 'Membership'])])
            ->recordActions([
                Action::make('history')->label('History')->color('gray')->modalSubmitAction(false)->modalHeading(fn (ServicePass $r) => "{$r->number} · {$r->name}")
                    ->modalContent(fn (ServicePass $r) => new HtmlString('<table style="width:100%;font-size:.875rem">'
                        .ServicePassUse::where('service_pass_id', $r->id)->orderBy('occurred_at')->get()->map(fn ($u) => '<tr><td>'.e($u->occurred_at->format('j M Y H:i')).'</td><td>'
                            .e($r->items[$u->item]['label'] ?? '').'</td><td style="text-align:right">'.($u->sessions > 0 ? '−'.$u->sessions : '+'.(-$u->sessions)).'</td><td>'.e($u->by_name).'</td></tr>')->implode('')
                        .'</table>'.(ServicePassUse::where('service_pass_id', $r->id)->exists() ? '' : '<p>No sessions used yet.</p>'))),
                Action::make('void')->color('danger')->visible(fn (ServicePass $r) => $r->status === 'active')
                    ->modalDescription('The customer can no longer use it. Refund anything owed separately (e.g. a refund without receipt at the till).')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (ServicePass $r, array $data) => Erp::run(fn ($actor) => app(PassService::class)->void($actor, $r, $data['reason']), 'Pass voided')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListServicePasses::route('/')];
    }
}
