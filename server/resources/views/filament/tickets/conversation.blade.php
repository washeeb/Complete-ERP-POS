{{-- A ticket's conversation (App\Filament\Resources\Tickets\Pages\ViewTicket): theirs on the left, ours on the right, notes highlighted. --}}
<div class="zx-thread">
    @forelse ($ticket->messages as $m)
        <div @class(['zx-msg', 'zx-msg-'.$m->direction])>
            <div class="zx-msg-head">
                <b>{{ $m->direction === 'note' ? 'Note' : $m->author_name }}</b>
                @if ($m->direction === 'note') · {{ $m->author_name }} @endif
                @if ($m->channel) · {{ \App\Models\Ticket::SOURCES[$m->channel] ?? ucfirst($m->channel) }} @endif
                · {{ $m->created_at->timezone($tz)->format('j M H:i') }}
                @if ($m->message) · {{ \App\Models\Message::STATUSES[$m->message->status] ?? $m->message->status }} @endif
            </div>
            <div class="zx-msg-body">{{ $m->body }}</div>
        </div>
    @empty
        <p class="text-sm text-gray-500">No messages yet.</p>
    @endforelse
</div>
