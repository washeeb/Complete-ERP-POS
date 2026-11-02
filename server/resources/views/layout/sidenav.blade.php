{{-- The side look's menu (App\Support\Look::tabs): one row per area with its icon — Dashboard, Insights, Sales,
     Purchasing… — each opening to its pages. The area holding the current page starts open. Built from the same menu as
     every other look, so access rules are the same. Folded to icons, clicking an area unfolds the menu first. --}}
@php
    $tabs = \App\Support\Look::tabs();
    $homeUrl = \App\Support\Look::homeUrl();
    $onHome = rtrim(url()->current(), '/') === rtrim($homeUrl, '/');
    $openTab = collect($tabs)->search(fn ($t) => $t['active']);
@endphp
<nav class="zx-sidenav" aria-label="Main menu" x-data="{ open: @js($openTab === false ? null : $openTab) }">
    <a href="{{ $homeUrl }}" @class(['zx-sn-item', 'zx-active' => $onHome]) title="Dashboard" @if ($onHome) aria-current="page" @endif>
        <x-filament::icon icon="heroicon-o-home" class="zx-sn-ic" />
        <span class="zx-sn-label">Dashboard</span>
    </a>
    @foreach ($tabs as $i => $tab)
        <div class="zx-sn-group">
            <button type="button" @class(['zx-sn-item', 'zx-sn-parent' => $tab['active']]) title="{{ $tab['label'] }}"
                    x-bind:aria-expanded="open === {{ $i }}"
                    x-on:click="if (! $store.sidebar.isOpen) { $store.sidebar.open(); open = {{ $i }} } else { open = open === {{ $i }} ? null : {{ $i }} }">
                <x-filament::icon :icon="\App\Support\Look::tabIcon($tab['label'])" class="zx-sn-ic" />
                <span class="zx-sn-label">{{ $tab['label'] }}</span>
                <svg class="zx-sn-chev" viewBox="0 0 20 20" aria-hidden="true"><path d="M7.5 5.5 12 10l-4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="zx-sn-children" x-show="open === {{ $i }}" x-collapse x-cloak>
                @foreach ($tab['sections'] as $section)
                    @if (count($tab['sections']) > 1)
                        <p class="zx-sn-h">{{ $section['label'] }}</p>
                    @endif
                    @foreach ($section['items'] as $item)
                        <a href="{{ $item['url'] }}" @class(['zx-sn-sub', 'zx-active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>
                            @if ($item['icon'])
                                <x-filament::icon :icon="$item['icon']" class="zx-sn-subic" />
                            @endif
                            <span>{{ $item['label'] }}</span>
                            @if (filled($item['badge']))
                                <span class="zx-badge">{{ $item['badge'] }}</span>
                            @endif
                        </a>
                    @endforeach
                @endforeach
            </div>
        </div>
    @endforeach
</nav>
