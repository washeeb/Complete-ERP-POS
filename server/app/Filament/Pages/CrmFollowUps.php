<?php

namespace App\Filament\Pages;

use App\Domain\Crm\Crm;
use App\Filament\Resources\CrmDeals\CrmDealResource;
use App\Filament\Resources\CrmLeads\CrmLeadResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\CrmUi;
use App\Filament\Support\Erp;
use App\Models\CrmActivity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * CRM → Follow-ups: calls, visits and tasks to do — the person's own by default, overdue first — with Done (and what
 * came of it) and Move to another time. Plans are made from a lead, deal or customer ("Follow-up").
 */
class CrmFollowUps extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $navigationLabel = 'Follow-ups';

    protected static ?string $title = 'Follow-ups';

    protected static ?string $slug = 'crm-follow-ups';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return CrmUi::canView();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! CrmUi::canView()) {
            return null;
        }
        $n = CrmActivity::whereNull('done_at')->where('assigned_to', Erp::user()?->id)->where('due_at', '<=', now()->endOfDay())->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function getSubheading(): ?string
    {
        return 'What to do and when. Plan new ones from a lead, a deal or a customer with “Follow-up”; you are reminded when one is due.';
    }

    public function table(Table $table): Table
    {
        $crm = app(Crm::class);

        return $table
            ->query(fn () => $this->visible())
            ->columns([
                TextColumn::make('due_at')->label('When')->dateTime('D j M, H:i')->sortable()
                    ->color(fn (CrmActivity $a) => ! $a->done_at && $a->due_at?->isPast() ? 'danger' : null)
                    ->description(fn (CrmActivity $a) => ! $a->done_at && $a->due_at?->isPast() ? 'Overdue' : null),
                TextColumn::make('type')->label('What')->formatStateUsing(fn ($state) => CrmActivity::TYPES[$state] ?? $state),
                TextColumn::make('subject')->label('About')->wrap()->weight('bold')->description(fn (CrmActivity $a) => $a->details),
                TextColumn::make('about')->label('With')->state(fn (CrmActivity $a) => $a->about())->url(fn (CrmActivity $a) => $this->linkFor($a)),
                TextColumn::make('assignee.name')->label('Who')->placeholder('—'),
                TextColumn::make('done_at')->label('Done')->since()->placeholder('To do')->description(fn (CrmActivity $a) => $a->outcome),
            ])
            ->defaultSort('due_at')
            ->filters([
                TernaryFilter::make('mine')->label('Mine only')->default(true)
                    ->queries(true: fn ($q) => $q->where('assigned_to', Erp::user()?->id), false: fn ($q) => $q, blank: fn ($q) => $q),
                TernaryFilter::make('open')->label('To do only')->default(true)
                    ->queries(true: fn ($q) => $q->whereNull('done_at'), false: fn ($q) => $q->whereNotNull('done_at'), blank: fn ($q) => $q),
                SelectFilter::make('assigned_to')->label('Who')->options(fn () => CrmUi::people()),
                SelectFilter::make('type')->label('What')->options(CrmActivity::TYPES),
            ])
            ->emptyStateHeading('Nothing to follow up')
            ->emptyStateDescription('Plan a call or visit from a lead, deal or customer with “Follow-up”.')
            ->recordActions([
                Action::make('done')->label('Done')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (CrmActivity $a) => ! $a->done_at && CrmUi::canManage())
                    ->schema([TextInput::make('outcome')->label('What came of it')->maxLength(255)])
                    ->action(fn (CrmActivity $record, array $data) => Erp::run(fn ($actor) => $crm->completeActivity($actor, $record, $data['outcome'] ?? null), 'Done')),
                Action::make('move')->label('Another time')->icon(Heroicon::OutlinedClock)->color('gray')
                    ->visible(fn (CrmActivity $a) => ! $a->done_at && CrmUi::canManage())
                    ->schema([DateTimePicker::make('due_at')->label('When')->seconds(false)->required()->default(now()->addDay()->setTime(9, 0))])
                    ->action(fn (CrmActivity $record, array $data) => Erp::run(fn ($actor) => $crm->rescheduleActivity($actor, $record, $data['due_at']), 'Moved')),
            ]);
    }

    /** Follow-ups on records of the branches the person sees (or on customers). */
    private function visible(): Builder
    {
        $b = Erp::branchIds('crm.view');

        return CrmActivity::query()->whereNotNull('due_at')->with(['lead', 'deal', 'customer', 'assignee'])
            ->when($b !== null, fn ($q) => $q->where(fn ($w) => $w->whereHas('deal', fn ($d) => $d->whereIn('branch_id', $b))
                ->orWhereHas('lead', fn ($l) => $l->whereIn('branch_id', $b))->orWhere(fn ($c) => $c->whereNull('deal_id')->whereNull('lead_id'))));
    }

    private function linkFor(CrmActivity $a): ?string
    {
        return match (true) {
            $a->deal_id !== null && $a->deal?->status === 'open' => CrmDealResource::getUrl('edit', ['record' => $a->deal_id]),
            $a->lead_id !== null && $a->lead?->isOpen() => CrmLeadResource::getUrl('edit', ['record' => $a->lead_id]),
            $a->customer_id !== null && CustomerResource::canViewAny() => CustomerResource::getUrl('view', ['record' => $a->customer_id]),
            default => null,
        };
    }
}
