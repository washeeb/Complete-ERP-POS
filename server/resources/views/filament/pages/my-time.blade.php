{{-- Projects → My time (App\Filament\Pages\MyTime): the running timer (if any) above the week's entries. --}}
@php($running = $this->running())
<x-filament-panels::page>
    @if ($running)
        <div class="zx-timer" wire:poll.60s>
            <span class="zx-timer-dot"></span>
            <div>
                <div class="zx-timer-what">{{ $running->project?->name }}@if ($running->task) · {{ $running->task->name }}@endif</div>
                <div class="zx-timer-since">Running since {{ $running->timer_started_at->timezone(\App\Filament\Support\Erp::user()?->tenant?->timezone ?: 'UTC')->format('H:i') }}
                    ({{ \App\Models\TimeEntry::hours((int) $running->timer_started_at->diffInMinutes(now())) }})@if ($running->note) — {{ $running->note }}@endif</div>
            </div>
        </div>
    @endif
    {{ $this->table }}
</x-filament-panels::page>
