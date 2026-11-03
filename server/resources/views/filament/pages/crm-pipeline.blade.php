{{-- CRM → Pipeline (App\Filament\Pages\CrmPipeline): a column per stage; cards are dragged to move a deal (on a phone,
     open the card and change its stage). --}}
@php($columns = $this->columns())
@php($month = $this->month())
@php($canMove = $this->canMove())
<x-filament-panels::page>
    <div class="zx-pipe-sum">
        <span>Open: <b>{{ $this->money(collect($columns)->sum('total')) }}</b></span>
        <span>Weighted: <b>{{ $this->money(collect($columns)->sum('weighted')) }}</b></span>
        <span>Won this month: <b>{{ $month['won'] }}</b> ({{ $this->money($month['won_value']) }})</span>
        <span>Lost this month: <b>{{ $month['lost'] }}</b></span>
    </div>
    <div x-data="{ drag: null, over: null }" class="zx-pipe-wrap">
        @if ($canMove)
            <div class="zx-pipe-ends">
                <div class="zx-pipe-end zx-pipe-won" x-on:dragover.prevent="over = 'won'" x-on:dragleave="over = null"
                     x-on:drop.prevent="if (drag) { $wire.move(drag, 'won') } drag = null; over = null" x-bind:class="over === 'won' && 'zx-pipe-over'">Won</div>
                <div class="zx-pipe-end zx-pipe-lost" x-on:dragover.prevent="over = 'lost'" x-on:dragleave="over = null"
                     x-on:drop.prevent="if (drag) { $wire.mountAction('lose', { deal: drag }) } drag = null; over = null" x-bind:class="over === 'lost' && 'zx-pipe-over'">Lost</div>
            </div>
        @endif
    <div class="zx-pipe">
        @foreach ($columns as $col)
            <section class="zx-pipe-col" wire:key="col-{{ $col['stage']->id }}"
                     @if ($canMove) x-on:dragover.prevent="over = '{{ $col['stage']->id }}'" x-on:dragleave="over = null"
                     x-on:drop.prevent="if (drag) { $wire.move(drag, '{{ $col['stage']->id }}') } drag = null; over = null" @endif
                     x-bind:class="over === '{{ $col['stage']->id }}' && 'zx-pipe-over'">
                <header>
                    <h2>{{ $col['stage']->name }} <span class="zx-pipe-n">{{ $col['deals']->count() }}</span></h2>
                    <div class="zx-pipe-tot">{{ $this->money($col['total']) }} · {{ $col['stage']->probability }} % → {{ $this->money($col['weighted']) }}</div>
                </header>
                <div class="zx-pipe-cards">
                    @forelse ($col['deals'] as $d)
                        <a href="{{ $this->editUrl($d) }}" class="zx-pipe-card" wire:key="deal-{{ $d->id }}"
                           @if ($canMove) draggable="true" x-on:dragstart="drag = '{{ $d->id }}'" x-on:dragend="drag = null; over = null" @endif>
                            <div class="zx-pipe-title">{{ $d->title }}</div>
                            <div class="zx-pipe-who">{{ $d->contactName() }}</div>
                            <div class="zx-pipe-meta">
                                <b>{{ $this->money((int) $d->value_minor) }}</b>
                                @if ($d->expected_close_on)
                                    <span @class(['zx-pipe-late' => $d->expected_close_on->isPast()])>{{ $d->expected_close_on->format('j M') }}</span>
                                @endif
                            </div>
                            <div class="zx-pipe-foot">
                                <span>{{ $d->number }}@if ($d->order) · {{ $d->order->quote_number }}@endif</span>
                                <span>{{ $d->owner?->name }}</span>
                            </div>
                        </a>
                    @empty
                        <p class="zx-pipe-empty">No deals here.</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
    </div>
</x-filament-panels::page>
