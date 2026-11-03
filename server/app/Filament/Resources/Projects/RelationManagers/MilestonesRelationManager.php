<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Domain\Projects\Projects;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\ProjectUi;
use App\Models\Project;
use App\Models\ProjectMilestone;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** A fixed-price project's milestones: each invoiced when reached. */
class MilestonesRelationManager extends RelationManager
{
    protected static string $relationship = 'milestones';

    protected static ?string $title = 'Milestones';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Project && $ownerRecord->billing === 'milestones';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255)->placeholder('e.g. Deposit, Delivery, Handover')->columnSpanFull(),
            TextInput::make('amount')->label('Amount (before tax)')->numeric()->minValue(0.01)->required()->prefix(fn () => FinanceUi::currency()),
            DatePicker::make('due_on')->label('Expected'),
            Select::make('status')->options(['pending' => 'Not yet', 'ready' => 'Ready to invoice'])->default('pending')->selectablePlaceholder(false),
            TextInput::make('sort')->label('Order')->numeric()->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        $save = fn (?ProjectMilestone $record, array $data): Model => Erp::run(fn ($actor) => app(Projects::class)->saveMilestone($actor, $this->getOwnerRecord(),
            $record ?? new ProjectMilestone, ['amount_minor' => FinanceUi::toMinor($data['amount'] ?? 0)] + $data));
        $manage = fn () => $this->getOwnerRecord()->isOpen() && ProjectUi::can('projects.manage');

        return $table
            ->columns([
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('amount_minor')->label('Amount')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('due_on')->label('Expected')->date('j M Y')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ProjectMilestone::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'invoiced' => 'success', 'ready' => 'warning', default => 'gray'
                    }),
                TextColumn::make('invoice.number')->label('Invoice')->placeholder('—')
                    ->url(fn (ProjectMilestone $m) => $m->invoice_id ? InvoiceResource::getUrl('view', ['record' => $m->invoice_id]) : null),
            ])
            ->defaultSort('sort')
            ->emptyStateHeading('No milestones')
            ->emptyStateDescription('Split the agreed price into parts invoiced when reached, e.g. 30 % deposit, 50 % on delivery, 20 % at handover.')
            ->headerActions([CreateAction::make()->label('New milestone')->visible($manage)->using(fn (array $data) => $save(null, $data))])
            ->recordActions([
                EditAction::make()->visible(fn (ProjectMilestone $m) => $m->status !== 'invoiced' && $manage())
                    ->mutateRecordDataUsing(fn (array $data) => $data + ['amount' => ($data['amount_minor'] ?? 0) / 100])
                    ->using(fn (ProjectMilestone $record, array $data) => $save($record, $data)),
                Action::make('invoice')->label('Invoice')->icon(Heroicon::OutlinedDocumentText)->color('success')->requiresConfirmation()
                    ->visible(fn (ProjectMilestone $m) => $m->status !== 'invoiced' && ProjectUi::can('projects.bill'))
                    ->modalDescription('An invoice for this milestone goes to the customer\'s account; the project\'s costs so far move from work in progress to project costs.')
                    ->action(function (ProjectMilestone $record, $livewire) {
                        $invoice = Erp::run(fn ($actor) => app(Projects::class)->invoiceMilestone($actor, $record), 'Invoiced');
                        $livewire->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
                    }),
            ]);
    }
}
