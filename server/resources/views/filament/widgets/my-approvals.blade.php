{{-- Dashboard panel: what waits for this person's decision (App\Filament\Widgets\Panels\MyApprovals). --}}
@php($items = $this->items())
@php($total = array_sum(array_column($items, 'count')))
<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-check-badge" heading="Waiting for you"
        :description="$total ? $total.' to decide' : 'Nothing waiting for your decision'">
        <ul class="zx-panel-list">
            @foreach ($items as $item)
                <li>
                    @if ($item['url'])
                        <a href="{{ $item['url'] }}" class="zx-panel-row">
                    @else
                        <span class="zx-panel-row">
                    @endif
                        <span>{{ $item['label'] }}</span>
                        <span @class(['zx-count', 'zx-count-on' => $item['count'] > 0])>{{ $item['count'] }}</span>
                    @if ($item['url'])
                        </a>
                    @else
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
