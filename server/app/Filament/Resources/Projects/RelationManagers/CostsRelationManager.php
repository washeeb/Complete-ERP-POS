<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Domain\Projects\Projects;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\ProjectUi;
use App\Models\ProjectCost;
use Filament\Actions\BulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/** Everything the project cost, as it was collected (stock, bills, expenses, time), with what is billed and invoiced. */
class CostsRelationManager extends RelationManager
{
    protected static string $relationship = 'costs';

    protected static ?string $title = 'Costs';

    public function table(Table $table): Table
    {
        $p = $this->getOwnerRecord();

        return $table
            ->columns([
                TextColumn::make('occurred_on')->label('Date')->date('j M Y')->sortable(),
                TextColumn::make('kind')->label('What')->formatStateUsing(fn ($state) => ProjectCost::KINDS[$state] ?? $state),
                TextColumn::make('description')->wrap(),
                TextColumn::make('qty')->label('Qty / hours')->alignEnd()->formatStateUsing(fn ($state) => rtrim(rtrim((string) $state, '0'), '.')),
                TextColumn::make('cost_minor')->label('Cost')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('bill_minor')->label('Billed at')->alignEnd()->visible($p->billing === 'time')
                    ->formatStateUsing(fn ($state, ProjectCost $c) => $c->billable ? FinanceUi::money((int) $state) : 'Not billed'),
                TextColumn::make('invoice.number')->label('Invoice')->placeholder($p->billing === 'time' ? 'Not yet' : '—')
                    ->url(fn (ProjectCost $c) => $c->invoice_id ? InvoiceResource::getUrl('view', ['record' => $c->invoice_id]) : null),
            ])
            ->defaultSort('occurred_on', 'desc')
            ->filters([
                SelectFilter::make('kind')->label('What')->options(ProjectCost::KINDS),
                TernaryFilter::make('unbilled')->label('Not invoiced yet')->visible($p->billing === 'time')
                    ->queries(true: fn ($q) => $q->where('billable', true)->whereNull('invoice_id'), false: fn ($q) => $q->whereNotNull('invoice_id'), blank: fn ($q) => $q),
            ])
            ->emptyStateHeading('No costs yet')
            ->emptyStateDescription('Costs arrive by themselves: stock issued to the project, bills and expense claims charged to it, approved time.')
            ->toolbarActions([
                BulkAction::make('invoiceSelected')->label('Invoice the selected')->icon(Heroicon::OutlinedDocumentText)->color('success')->requiresConfirmation()
                    ->visible(fn () => $p->billing === 'time' && ProjectUi::can('projects.bill'))
                    ->action(function (Collection $records, $livewire) use ($p) {
                        $invoice = Erp::run(fn ($actor) => app(Projects::class)->invoiceTime($actor, $p, $records->pluck('id')->all()), 'Invoiced');
                        $livewire->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
                    }),
            ]);
    }
}
