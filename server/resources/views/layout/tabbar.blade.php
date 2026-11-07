{{-- The new look's menu band (App\Support\Look), laid out like NetSuite's: Recent, Favourites and Home icons, then the
     tabs. Pointing at a tab opens its panel (sections side by side); a click or Enter opens it too. ←/→ move between
     tabs, ↓ into the panel, Esc closes. Built from the same menu as the side menu, so access rules are the same. Hidden
     on phones (the side menu is used there). --}}
@php
    $homeUrl = \App\Support\UserPrefs::homeUrl(auth()->user()); // the person's own home page (Profile → Preferences)
    $onHome = rtrim(url()->current(), '/') === rtrim($homeUrl, '/');
@endphp
<nav class="zx-tabbar" aria-label="Main menu"
     x-data="{ open: null, t: null,
               show(i, wait) { clearTimeout(this.t); if (this.open === i) return; this.t = setTimeout(() => this.open = i, this.open === null ? wait : 0) },
               leave() { clearTimeout(this.t); this.t = setTimeout(() => this.open = null, 250) },
               toggle(i) { clearTimeout(this.t); this.open = this.open === i ? null : i },
               move(d) { const t = [...$el.querySelectorAll('.zx-tab')]; const i = t.indexOf(document.activeElement); const n = t[(i + d + t.length) % t.length]; n?.focus(); if (this.open !== null && n.dataset.i !== undefined) this.open = n.dataset.i },
               into() { this.$nextTick(() => $el.querySelector('.zx-mega:not([hidden]) a, .zx-mega:not([hidden]) button')?.focus()) },
               {{-- A panel opens under its tab and is moved sideways just enough to stay inside the window (never cut off). --}}
               place(p) { p.style.left = '0px'; const r = p.getBoundingClientRect(), m = 8; let dx = 0;
                   if (r.right > innerWidth - m) dx = innerWidth - m - r.right;
                   if (r.left + dx < m) dx = m - r.left;
                   p.style.left = dx + 'px' } }"
     x-on:mouseleave="leave()"
     x-on:keydown.escape.window="if (open !== null) { const i = open; open = null; $el.querySelector(`[data-i='${i}']`)?.focus() }"
     x-on:click.outside="open = null"
     x-on:resize.window.debounce.100ms="$el.querySelectorAll('.zx-mega').forEach((p) => p.offsetParent && place(p))">
    <ul class="zx-tabs" role="menubar">
        {{-- Recent pages: the page finder, which lists the pages opened last --}}
        <li role="none" class="zx-tab-li">
            <button type="button" role="menuitem" class="zx-tab zx-ico" title="Recent pages and find a page ( / )" aria-label="Recent pages and find a page"
                    x-on:click="open = null; $dispatch('zx-find')" x-on:mouseenter="leave()"
                    x-on:keydown.arrow-right.prevent="move(1)" x-on:keydown.arrow-left.prevent="move(-1)">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7M3 4v4h4M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </li>
        {{-- Favourites: the person's own list, kept on the server (route ui.favourite) --}}
        <li role="none" class="zx-tab-li"
            x-data="zxFavourites(@js(\App\Support\Look::favourites()), @js(route('ui.favourite')))">
            <button type="button" role="menuitem" aria-haspopup="true" data-i="fav" class="zx-tab zx-ico" title="Favourites" aria-label="Favourites"
                    x-bind:aria-expanded="open === 'fav'" x-on:click="toggle('fav')" x-on:mouseenter="show('fav', 150)"
                    x-on:keydown.arrow-right.prevent="move(1)" x-on:keydown.arrow-left.prevent="move(-1)" x-on:keydown.arrow-down.prevent="open = 'fav'; into()">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z" x-bind:fill="isFav ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
            </button>
            <div class="zx-mega zx-fav" role="menu" aria-label="Favourites" x-show="open === 'fav'" x-cloak x-transition.opacity.duration.120ms x-on:mouseenter="show('fav', 0)" x-effect="open === 'fav' && $nextTick(() => place($el))">
                <button type="button" role="menuitem" class="zx-fav-toggle" x-on:click="flip()" x-bind:disabled="busy">
                    <span x-text="isFav ? '★  Remove this page from favourites' : '☆  Add this page to favourites'"></span>
                </button>
                <p class="zx-fav-msg" x-show="msg" x-text="msg"></p>
                <ul>
                    <template x-for="f in list" x-bind:key="f.url">
                        <li role="none"><a role="menuitem" class="zx-mega-a" x-bind:href="f.url" x-text="f.label"></a></li>
                    </template>
                </ul>
                <p class="zx-fav-msg" x-show="! list.length">No favourites yet. Open a page you use often, then add it here.</p>
            </div>
        </li>
        {{-- Home --}}
        <li role="none" class="zx-tab-li">
            <a role="menuitem" href="{{ $homeUrl }}" @class(['zx-tab', 'zx-ico', 'zx-home', 'zx-active' => $onHome]) title="Home" aria-label="Home" @if ($onHome) aria-current="page" @endif
               x-on:mouseenter="leave()" x-on:keydown.arrow-right.prevent="move(1)" x-on:keydown.arrow-left.prevent="move(-1)">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 11 12 4l8.5 7M6 9.5V20h4.5v-5.5h3V20H18V9.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        </li>

        @foreach ($tabs as $i => $tab)
            <li role="none" class="zx-tab-li">
                <button type="button" role="menuitem" aria-haspopup="true" data-i="{{ $i }}"
                        class="zx-tab @if ($tab['active']) zx-active @endif"
                        x-bind:aria-expanded="open === '{{ $i }}'"
                        x-on:click="toggle('{{ $i }}')" x-on:mouseenter="show('{{ $i }}', 150)"
                        x-on:keydown.arrow-right.prevent="move(1)" x-on:keydown.arrow-left.prevent="move(-1)"
                        x-on:keydown.arrow-down.prevent="open = '{{ $i }}'; into()">
                    {{ $tab['label'] }}
                </button>
                <div class="zx-mega" role="menu" aria-label="{{ $tab['label'] }}" x-show="open === '{{ $i }}'" x-cloak x-transition.opacity.duration.120ms
                     x-on:mouseenter="show('{{ $i }}', 0)" x-effect="open === '{{ $i }}' && $nextTick(() => place($el))">
                    <div class="zx-mega-cols" style="--cols: {{ min(4, count($tab['sections'])) }}">
                        @foreach ($tab['sections'] as $section)
                            <section class="zx-mega-sec">
                                @if (count($tab['sections']) > 1 || $section['label'] !== $tab['label'])
                                    <h3 class="zx-mega-h">{{ $section['label'] }}</h3>
                                @endif
                                <ul>
                                    @foreach ($section['items'] as $item)
                                        <li role="none">
                                            <a role="menuitem" href="{{ $item['url'] }}" @class(['zx-mega-a', 'zx-active' => $item['active']]) @if ($item['active']) aria-current="page" @endif
                                               x-on:click="open = null">
                                                @if ($item['icon'])
                                                    <x-filament::icon :icon="$item['icon']" class="zx-mega-ic" />
                                                @endif
                                                <span>{{ $item['label'] }}</span>
                                                @if (filled($item['badge']))
                                                    <span class="zx-badge">{{ $item['badge'] }}</span>
                                                @endif
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endforeach
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
</nav>

@once
    <script>
        // Favourites: toggled on the server so they follow the person to any computer.
        window.zxFavourites = (list, endpoint) => ({
            list, busy: false, msg: '',
            here() { return location.pathname + location.search },
            get isFav() { return this.list.some((f) => f.url === this.here()) },
            async flip() {
                this.busy = true; this.msg = ''
                const label = (document.querySelector('.fi-header-heading')?.textContent || document.title.split(' - ')[0]).trim()
                try {
                    const r = await fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' }, body: JSON.stringify({ url: this.here(), label }) })
                    const j = await r.json().catch(() => ({}))
                    if (r.ok) this.list = j.favourites
                    else this.msg = j.message || 'Could not save. Try again.'
                } catch { this.msg = 'Could not reach the server. Try again.' }
                this.busy = false
            },
        })
    </script>
@endonce
