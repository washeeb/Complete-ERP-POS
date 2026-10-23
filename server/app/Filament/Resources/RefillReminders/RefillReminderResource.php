<?php

namespace App\Filament\Resources\RefillReminders;

use App\Domain\Modules\ModuleService;
use App\Domain\Pharmacy\RefillReminders;
use App\Filament\Resources\RefillReminders\Pages\ListRefillReminders;
use App\Filament\Support\Erp;
use App\Models\RefillReminder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Pharmacy → Refill reminders: who runs out of a daily medicine soon, and what was sent (owner request 2026-10-23). */
class RefillReminderResource extends Resource
{
    protected static ?string $model = RefillReminder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Pharmacy';

    protected static ?string $navigationLabel = 'Refill reminders';

    protected static ?string $modelLabel = 'refill reminder';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('customers.view') && ModuleService::enabledAnywhere('pharmacy');
    }

    public static function canCreate(): bool
    {
        return false; // made from sales of medicines with "Used a day"
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
        $ids = Erp::branchIds('customers.view');

        return parent::getEloquentQuery()->with(['customer', 'branch'])->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => RefillReminder::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'waiting' => 'warning', 'sent', 'bought' => 'success', 'no_contact' => 'danger', default => 'gray'
                    }),
                TextColumn::make('customer.name')->label('Customer')->searchable(),
                TextColumn::make('customer.phone')->label('Phone')->placeholder('—'),
                TextColumn::make('product_name')->label('Medicine')->searchable()->wrap(),
                TextColumn::make('qty_base')->label('Sold')->numeric(decimalPlaces: 0),
                TextColumn::make('runs_out_on')->label('Runs out')->date('j M Y')->sortable(),
                TextColumn::make('remind_on')->label('Remind on')->date('j M Y')->sortable(),
                TextColumn::make('branch.name')->label('Branch')->toggleable(),
                TextColumn::make('sent_at')->label('Sent')->dateTime('j M H:i')->placeholder('—')
                    ->description(fn (RefillReminder $r) => $r->channel ? ($r->channel === 'whatsapp' ? 'WhatsApp' : strtoupper($r->channel)) : null),
                TextColumn::make('closed_by_name')->label('Cancelled by')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(RefillReminder::STATUSES)->default('waiting'),
            ])
            ->recordActions([
                Action::make('send')->label('Send now')->icon(Heroicon::OutlinedPaperAirplane)->color('gray')->requiresConfirmation()
                    ->modalDescription('Sends the reminder to the customer now by WhatsApp (or SMS).')
                    ->visible(fn (RefillReminder $r) => $r->status === 'waiting' && Erp::can('customers.manage', $r->branch))
                    ->action(fn (RefillReminder $r) => Erp::run(fn ($actor) => app(RefillReminders::class)->sendNow($actor, $r), 'Reminder sent')),
                Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedXMark)->color('danger')->requiresConfirmation()
                    ->modalDescription('No reminder will be sent for this sale (for example: the patient changed medicine or asked not to be reminded).')
                    ->visible(fn (RefillReminder $r) => $r->status === 'waiting' && Erp::can('customers.manage', $r->branch))
                    ->action(fn (RefillReminder $r) => Erp::run(fn ($actor) => app(RefillReminders::class)->cancel($actor, $r), 'Reminder cancelled')),
            ])
            ->defaultSort('remind_on');
    }

    public static function getPages(): array
    {
        return ['index' => ListRefillReminders::route('/')];
    }
}
