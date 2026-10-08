<x-filament-panels::page>
    @if ($status = $this->aiStatus())
        <div class="cp-status">{{ $status }} The quick questions below always work.</div>
    @endif

    <div class="cp-quick">
        @foreach ($this->quickQuestions() as $key => $label)
            <x-filament::button size="sm" color="gray" wire:click="quick('{{ $key }}')">{{ $label }}</x-filament::button>
        @endforeach
    </div>

    <div class="cp-chat">
        @forelse ($chat as $m)
            <div class="cp-msg cp-{{ $m['role'] }}">
                @if ($m['content'] !== '')
                    <div class="cp-text">{!! nl2br(e($m['content'])) !!}</div>
                @endif
                @if (! empty($m['notice']))
                    <div class="cp-notice">{{ $m['notice'] }}</div>
                @endif
                @foreach ($m['tables'] ?? [] as $t)
                    @php($share = array_search('Share', $t['columns'], true))
                    <div class="cp-table">
                        <div class="cp-title">{{ $t['title'] }}</div>
                        <table>
                            <thead><tr>@foreach ($t['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
                            <tbody>
                            @forelse ($t['rows'] as $row)
                                <tr>@foreach ($row as $i => $v)
                                    <td>@if ($share !== false && $i === $share)<span class="cp-bar" style="width: {{ min(100, (float) $v) }}%"></span>@endif{{ $v }}</td>
                                @endforeach</tr>
                            @empty
                                <tr><td colspan="{{ count($t['columns']) }}">Nothing to show.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                        @if (! empty($t['note']))<div class="cp-note">{{ $t['note'] }}</div>@endif
                    </div>
                @endforeach
            </div>
        @empty
            <p class="cp-empty">Ask about sales, stock, customers, suppliers or cash — for example “Which branch sold most this month?” or “What will run out at HQ this week?”. Answers use only what you are allowed to see, and show the figures they came from.</p>
        @endforelse
        <div wire:loading wire:target="ask, quick" class="cp-empty">Working…</div>
    </div>

    <form wire:submit="ask" class="cp-ask">
        <x-filament::input.wrapper class="cp-input">
            <x-filament::input type="text" wire:model="question" placeholder="Ask a question about the business…" maxlength="1000" />
        </x-filament::input.wrapper>
        <x-filament::button type="submit">Ask</x-filament::button>
        @if ($chat)
            <x-filament::button type="button" color="gray" wire:click="clear">New chat</x-filament::button>
        @endif
    </form>
    <style>
        .cp-status { padding: 10px 12px; border-radius: 8px; font-size: .875rem; background: color-mix(in srgb, rgb(234 179 8) 15%, transparent); }
        .cp-quick { display: flex; flex-wrap: wrap; gap: 8px; }
        .cp-chat { display: grid; gap: 12px; }
        .cp-msg { padding: 10px 12px; border-radius: 10px; max-width: 100%; overflow-x: auto; }
        .cp-user { justify-self: end; background: color-mix(in srgb, currentColor 8%, transparent); font-weight: 500; }
        .cp-assistant { border: 1px solid color-mix(in srgb, currentColor 10%, transparent); }
        .cp-text { margin-bottom: 6px; line-height: 1.5; } .cp-notice { font-size: .8rem; opacity: .75; margin-bottom: 6px; }
        .cp-table { margin-top: 8px; } .cp-title { font-weight: 600; font-size: .875rem; margin-bottom: 4px; }
        .cp-table table { width: 100%; border-collapse: collapse; font-size: .82rem; } .cp-table th, .cp-table td { padding: 4px 6px; text-align: left; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); position: relative; }
        .cp-bar { position: absolute; left: 0; top: 20%; height: 60%; background: color-mix(in srgb, rgb(59 130 246) 25%, transparent); border-radius: 3px; z-index: 0; }
        .cp-note { font-size: .78rem; opacity: .7; margin-top: 4px; } .cp-empty { opacity: .7; font-size: .875rem; }
        .cp-ask { display: flex; gap: 8px; align-items: center; } .cp-input { flex: 1; }
    </style>
</x-filament-panels::page>
