{{-- New look, top bar: "Find a page" (or "/") — type part of a page's name and press Enter, with the last pages opened
     on this computer listed first — and "+ New", every record this person may create. Ctrl+K still searches records. --}}
@php
    $pages = collect($tabs)->flatMap(fn ($t) => collect($t['sections'])->flatMap(fn ($s) => collect($s['items'])->map(fn ($i) => [
        'label' => $i['label'], 'where' => $t['label'] === $s['label'] ? $t['label'] : $t['label'].' › '.$s['label'], 'url' => $i['url'],
    ])))->unique('url')->values();
@endphp
<div class="zx-actions" x-data="zxFinder(@js($pages), @js(\App\Support\Look::MENU_WORDS))" x-on:zx-find.window="show()"
     x-on:keydown.window="if ($event.key === '/' && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) && ! document.activeElement?.isContentEditable) { $event.preventDefault(); show() }">
    @unless (\App\Support\Look::hasTopTabs()) {{-- with the tabs, the clock icon on the band opens it --}}
    <button type="button" class="zx-find-btn" x-on:click="show()" aria-haspopup="dialog">
        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
        <span>Find a page</span><kbd>/</kbd>
    </button>
    @endunless

    @if ($create !== [])
        <div class="zx-new-wrap" x-data="{ o: false }" x-on:click.outside="o = false" x-on:keydown.escape="o = false">
            <button type="button" class="zx-new-btn" x-on:click="o = ! o" x-bind:aria-expanded="o" aria-haspopup="true">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 4v12M4 10h12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>New</span>
            </button>
            <div class="zx-new-menu" x-show="o" x-cloak x-transition.opacity.duration.120ms role="menu" aria-label="Create new">
                @foreach ($create as $group => $items)
                    <section>
                        <h3>{{ $group }}</h3>
                        @foreach ($items as $item)
                            <a role="menuitem" href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                        @endforeach
                    </section>
                @endforeach
            </div>
        </div>
    @endif

    <template x-teleport="body">
        <div class="zx-finder-back" x-show="isOpen" x-cloak x-transition.opacity.duration.100ms x-on:click.self="hide()" x-on:keydown.escape="hide()">
            <div class="zx-finder" role="dialog" aria-modal="true" aria-label="Find a page">
                <input x-ref="q" x-model="q" type="search" autocomplete="off" placeholder="Type part of a page's name — e.g. order, expiry, momo"
                       aria-controls="zx-finder-list" x-bind:aria-activedescendant="'zx-f-' + sel"
                       x-on:keydown.arrow-down.prevent="sel = Math.min(sel + 1, results.length - 1)"
                       x-on:keydown.arrow-up.prevent="sel = Math.max(sel - 1, 0)"
                       x-on:keydown.enter.prevent="go(results[sel])">
                <p class="zx-finder-h" x-text="q.trim() ? 'Pages' : (recent.length ? 'Opened recently' : 'All pages')"></p>
                <ul id="zx-finder-list" role="listbox">
                    <template x-for="(p, i) in results" x-bind:key="p.url">
                        <li role="option" x-bind:id="'zx-f-' + i" x-bind:aria-selected="i === sel" x-bind:class="{ 'zx-sel': i === sel }"
                            x-on:mousemove="sel = i" x-on:click="go(p)">
                            <span x-text="p.label"></span><small x-text="p.where"></small>
                        </li>
                    </template>
                </ul>
                <p class="zx-finder-none" x-show="! results.length">No page matches. Ctrl+K searches records (products, customers, orders…).</p>
                <p class="zx-finder-keys"><kbd>↑</kbd><kbd>↓</kbd> choose · <kbd>Enter</kbd> open · <kbd>Esc</kbd> close</p>
            </div>
        </div>
    </template>
</div>

@once
    <script>
        // Recently opened pages are kept in this browser only (a convenience; nothing is sent anywhere).
        window.zxFinder = (pages, words) => ({
            pages, words, q: '', sel: 0, isOpen: false, recent: [],
            init() {
                try { this.recent = JSON.parse(localStorage.getItem('zx.recent') || '[]') } catch { this.recent = [] }
                const here = this.pages.find((p) => location.href.split('?')[0] === p.url);
                if (here) {
                    this.recent = [here.url, ...this.recent.filter((u) => u !== here.url)].slice(0, 8);
                    try { localStorage.setItem('zx.recent', JSON.stringify(this.recent)) } catch {}
                }
                this.$watch('q', () => (this.sel = 0));
            },
            get results() {
                const w = this.q.toLowerCase().trim().split(/\s+/).filter(Boolean);
                if (!w.length) {
                    const r = this.recent.map((u) => this.pages.find((p) => p.url === u)).filter(Boolean);
                    return r.length ? r : this.pages.slice(0, 12);
                }
                const hit = (p) => { const t = `${p.where} ${p.label}`.toLowerCase(); return w.every((x) => t.includes(x) || (this.words[x] ?? []).some((s) => t.includes(s))) };
                const starts = (p) => p.label.toLowerCase().startsWith(w[0]) ? 0 : 1;
                return this.pages.filter(hit).sort((a, b) => starts(a) - starts(b)).slice(0, 30);
            },
            show() { this.isOpen = true; this.q = ''; this.$nextTick(() => (this.$refs.q ?? document.querySelector('.zx-finder input'))?.focus()) },
            hide() { this.isOpen = false },
            go(p) { if (p) location.href = p.url },
        });
    </script>
@endonce
