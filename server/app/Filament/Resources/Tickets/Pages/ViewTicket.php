<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/** A ticket: its details and deadlines, and the conversation (messages in and out, staff notes). */
class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function getTitle(): string
    {
        return "{$this->record->number} · {$this->record->subject}";
    }

    protected function getHeaderActions(): array
    {
        return TicketResource::ticketActions();
    }

    public function infolist(Schema $schema): Schema
    {
        $tz = fn () => auth()->user()?->tenant?->timezone ?: 'UTC';
        $when = fn ($t) => $t ? $t->timezone($tz())->format('j M H:i') : '—';

        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => Ticket::STATUSES[$state] ?? $state),
                TextEntry::make('priority')->formatStateUsing(fn ($state) => Ticket::PRIORITIES[$state] ?? $state),
                TextEntry::make('kind')->formatStateUsing(fn ($state) => Ticket::KINDS[$state] ?? $state),
                TextEntry::make('source')->label('Came by')->formatStateUsing(fn ($state) => Ticket::SOURCES[$state] ?? $state),
                TextEntry::make('who')->label('From')->state(fn (Ticket $t) => $t->customer?->name ?? $t->contact_name ?? '—')
                    ->helperText(fn (Ticket $t) => implode(' · ', array_filter([$t->contact_phone, $t->contact_email]))),
                TextEntry::make('assignee.name')->label('With')->placeholder('Nobody yet'),
                TextEntry::make('respond_by')->label('Answer by')->state(fn (Ticket $t) => $t->first_response_at ? 'Answered '.$when($t->first_response_at) : $when($t->respond_by))
                    ->color(fn (Ticket $t) => ! $t->first_response_at && $t->respond_by?->isPast() && $t->isOpen() ? 'danger' : null),
                TextEntry::make('resolve_by')->label('Fix by')->state(fn (Ticket $t) => $t->resolved_at ? 'Resolved '.$when($t->resolved_at) : ($t->status === 'waiting' ? 'Clock stopped: waiting for them' : $when($t->resolve_by)))
                    ->color(fn (Ticket $t) => $t->late() ? 'danger' : null),
                TextEntry::make('receipt_no')->label('Receipt')->placeholder('—')
                    ->helperText(fn (Ticket $t) => match ($t->under_warranty) {
                        true => 'Under warranty', false => 'Out of warranty', default => null
                    }),
                TextEntry::make('asset.name')->label('Equipment')->placeholder('—'),
                TextEntry::make('resolution')->label('Resolution')->placeholder('—')->columnSpan(2),
                TextEntry::make('rating')->label('Their rating')->placeholder('—')->formatStateUsing(fn ($state) => $state ? "{$state} / 5" : null),
            ]),
            Section::make('Conversation')->schema([Text::make(fn (Ticket $record) => new HtmlString(view('filament.tickets.conversation', ['ticket' => $record, 'tz' => $tz()])->render()))]),
        ]);
    }
}
