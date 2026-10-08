<x-filament-panels::page>
    @php($f = $this->fields())
    <x-filament::section>
        <div class="rb-grid">
            <label>Data
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="dataset">
                    @foreach ($this->datasets() as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            @if (\App\Domain\Reports\ReportEngine::datasets()[$dataset][3] ?? null)
                <label>Period
                    <x-filament::input.wrapper><x-filament::input.select wire:model.live="period">
                        @foreach (\App\Domain\Reports\ReportEngine::PERIODS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                </label>
                @if ($period === 'custom')
                    <label>From <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
                    <label>To <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
                @endif
            @endif
            <label>Branch
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="branch_id">
                    <option value="">All you may see</option>
                    @foreach ($this->branches() as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            <label>Filter
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="filterDim">
                    <option value="">—</option>
                    @foreach ($f['dims'] as $k => [$label])<option value="{{ $k }}">{{ $label }} contains…</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            @if ($filterDim)
                <label>Text <x-filament::input.wrapper><x-filament::input type="text" wire:model.live.debounce.500ms="filterValue" /></x-filament::input.wrapper></label>
            @endif
        </div>
        <div class="rb-pick">
            <div><strong>Group by</strong>
                @foreach ($f['dims'] as $k => [$label])
                    <label class="rb-check"><input type="checkbox" value="{{ $k }}" wire:model.live="dimensions"> {{ $label }}</label>
                @endforeach
            </div>
            <div><strong>Figures</strong> <span class="rb-hint">(none = list the rows themselves)</span>
                @foreach ($f['measures'] as $k => [$label])
                    <label class="rb-check"><input type="checkbox" value="{{ $k }}" wire:model.live="measures"> {{ $label }}</label>
                @endforeach
            </div>
            <div><strong>Sort by</strong>
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="sort">
                    <option value="">Default</option>
                    @foreach ($dimensions as $k)<option value="{{ $k }}">{{ $f['dims'][$k][0] ?? $k }}</option>@endforeach
                    @foreach ($measures as $k)<option value="{{ $k }}">{{ $f['measures'][$k][0] ?? $k }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="direction">
                    <option value="desc">Largest / latest first</option><option value="asc">Smallest / earliest first</option>
                </x-filament::input.select></x-filament::input.wrapper>
            </div>
        </div>
        <div class="rb-run"><x-filament::button wire:click="runReport" icon="heroicon-o-play">Run</x-filament::button></div>
    </x-filament::section>

    @if ($error)
        <div class="rb-error">{{ $error }}</div>
    @elseif ($result)
        <x-filament::section :heading="$result['title'].' · '.$result['period']" :description="$drill ? 'Rows behind: '.implode(' · ', $drill) : ($measures && $dimensions ? 'Click a line to see the rows behind it.' : null)">
            @if ($drill)
                <x-filament::button size="sm" color="gray" wire:click="back" icon="heroicon-o-arrow-left">Back to the summary</x-filament::button>
            @endif
            <div class="rb-scroll"><table class="rb">
                <thead><tr>@foreach ($result['headings'] as $h)<th>{{ $h }}</th>@endforeach @if (array_filter($result['links']))<th></th>@endif</tr></thead>
                <tbody>
                @forelse ($result['rows'] as $i => $row)
                    <tr @if (! $drill && $result['raw'][$i] && $measures && $dimensions) wire:click="drillInto({{ $i }})" class="rb-click" @endif @if (! $result['raw'][$i] && $measures) class="rb-total" @endif>
                        @foreach ($row as $v)<td>{{ $v }}</td>@endforeach
                        @if (array_filter($result['links']))<td>@if ($result['links'][$i])<a href="{{ $result['links'][$i] }}" class="rb-link">Open</a>@endif</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="{{ count($result['headings']) + 1 }}">Nothing in this period.</td></tr>
                @endforelse
                </tbody>
            </table></div>
            @if ($result['truncated'])<p class="rb-hint">Only the first {{ \App\Domain\Reports\ReportEngine::MAX_ROWS }} rows are shown; narrow the period or add a filter.</p>@endif
        </x-filament::section>
    @endif
    <style>
        .rb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; } .rb-grid label { display: grid; gap: 4px; font-size: .875rem; }
        .rb-pick { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-top: 16px; font-size: .875rem; }
        .rb-pick > div { display: grid; gap: 4px; align-content: start; } .rb-check { display: flex; gap: 6px; align-items: center; } .rb-hint { opacity: .65; font-size: .8rem; }
        .rb-run { margin-top: 12px; } .rb-error { padding: 10px 12px; border-radius: 8px; background: color-mix(in srgb, rgb(220 38 38) 12%, transparent); }
        .rb-scroll { overflow-x: auto; margin-top: 8px; } .rb { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .rb th, .rb td { padding: 6px 8px; text-align: left; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); white-space: nowrap; }
        .rb-click { cursor: pointer; } .rb-click:hover { background: color-mix(in srgb, currentColor 5%, transparent); } .rb-total td { font-weight: 600; } .rb-link { text-decoration: underline; }
    </style>
</x-filament-panels::page>
