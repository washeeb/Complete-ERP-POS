{{-- Dashboard panel: the person's favourite pages and quick "new" links (App\Filament\Widgets\Panels\Shortcuts). --}}
@php($favourites = $this->favourites())
@php($creates = $this->creates())
<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-bolt" heading="Shortcuts">
        @if ($favourites)
            <p class="zx-panel-h">Favourites</p>
            <ul class="zx-panel-list">
                @foreach ($favourites as $f)
                    <li><a href="{{ $f['url'] }}" class="zx-panel-row"><span>★ {{ $f['label'] }}</span></a></li>
                @endforeach
            </ul>
        @else
            <p class="zx-panel-note">Open a page you use often and press the star on the menu band to keep it here.</p>
        @endif
        @if ($creates)
            <p class="zx-panel-h">Create</p>
            <div class="zx-chips">
                @foreach ($creates as $c)
                    <a href="{{ $c['url'] }}" class="zx-chip">+ {{ $c['label'] }}</a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
