<?php

namespace App\Filament\Resources\CrmLeads\RelationManagers;

use App\Domain\Crm\Crm;
use App\Filament\Support\CrmUi;
use App\Filament\Support\Erp;
use App\Models\CrmActivity;
use App\Models\CrmDeal;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Follow-ups and notes on a lead or a deal (the same list on both). */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Follow-ups and notes';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return CrmUi::canView();
    }

    public function table(Table $table): Table
    {
        $field = $this->getOwnerRecord() instanceof CrmDeal ? 'deal_id' : 'lead_id';

        return $table
            ->columns([
                TextColumn::make('type')->label('What')->formatStateUsing(fn ($state) => CrmActivity::TYPES[$state] ?? $state),
                TextColumn::make('subject')->label('About')->wrap()->description(fn (CrmActivity $a) => $a->details),
                TextColumn::make('due_at')->label('When')->dateTime('j M Y H:i')->placeholder('—')
                    ->color(fn (CrmActivity $a) => ! $a->done_at && $a->due_at?->isPast() ? 'danger' : null),
                TextColumn::make('assignee.name')->label('Who')->placeholder('—'),
                TextColumn::make('done_at')->label('Done')->since()->placeholder('To do')->description(fn (CrmActivity $a) => $a->outcome),
                TextColumn::make('created_by_name')->label('By')->color('gray'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nothing yet')
            ->emptyStateDescription('Plan the next call or visit, or note what was said, with “Follow-up”.')
            ->headerActions([CrmUi::followUpAction($field)->record($this->getOwnerRecord())])
            ->recordActions([
                Action::make('done')->label('Done')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (CrmActivity $a) => ! $a->done_at && CrmUi::canManage())
                    ->schema([TextInput::make('outcome')->label('What came of it')->maxLength(255)])
                    ->action(fn (CrmActivity $record, array $data) => Erp::run(fn ($actor) => app(Crm::class)->completeActivity($actor, $record, $data['outcome'] ?? null), 'Done')),
            ]);
    }
}
