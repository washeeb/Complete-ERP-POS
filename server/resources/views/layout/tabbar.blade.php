{{-- The new look's menu (App\Support\Look::tabs): tabs across the top, each opening a panel with its sections side by
     side, like a desktop program. Click (or Enter) opens a tab; once one is open, moving to another opens that one;
     ←/→ move between tabs, ↓ into the panel, Esc closes. Built from the same menu as the side menu, so access rules are
     the same. Hidden on phones (the side menu is used there). --}}
<nav class="zx-tabbar" aria-label="Main menu" wire:ignore
     x-data="{ open: null,
               toggle(i) { this.open = this.open === i ? null : i },
               hover(i) { if (this.open !== null) this.open = i },
               move(d) { const t = [...$el.querySelectorAll('.zx-tab')]; const i = t.indexOf(document.activeElement); const n = t[(i + d + t.length) % t.length]; n?.focus(); if (this.open !== null) this.open = Number(n.dataset.i) },
               into() { this.$nextTick(() => $el.querySelector('.zx-mega:not([hidden]) a')?.focus()) } }"
     x-on:keydown.escape.window="if (open !== null) { const i = open; open = null; $el.querySelector(`.zx-tab[data-i='${i}']`)?.focus() }"
     x-on:click.outside="open = null">
    <ul class="zx-tabs" role="menubar">
        @foreach ($tabs as $i => $tab)
            <li role="none" class="zx-tab-li">
                <button type="button" role="menuitem" aria-haspopup="true" data-i="{{ $i }}"
                        class="zx-tab @if ($tab['active']) zx-active @endif"
                        x-bind:aria-expanded="open === {{ $i }}"
                        x-on:click="toggle({{ $i }})" x-on:mouseenter="hover({{ $i }})"
                        x-on:keydown.arrow-right.prevent="move(1)" x-on:keydown.arrow-left.prevent="move(-1)"
                        x-on:keydown.arrow-down.prevent="open = {{ $i }}; into()">
                    {{ $tab['label'] }}
                    <svg class="zx-caret" viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 7.5 10 12l4.5-4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <div class="zx-mega" role="menu" aria-label="{{ $tab['label'] }}" x-show="open === {{ $i }}" x-cloak x-transition.opacity.duration.120ms hidden x-bind:hidden="open !== {{ $i }}" x-bind:class="{ 'zx-right': $el.parentElement.offsetLeft > window.innerWidth / 2 }">
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
