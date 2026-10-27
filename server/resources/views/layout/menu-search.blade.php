{{-- Menu search at the top of the side menu (owner request 2026-10-22): type part of a name — "adjust", "momo", "po" —
     and only the matching pages stay, with their sections opened; Enter opens the first, Esc clears. "/" jumps here. --}}
<div class="ses-menu-search" wire:ignore x-data x-on:keydown.window="if ($event.key === '/' && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) && ! document.activeElement?.isContentEditable) { $event.preventDefault(); $refs.q.focus() }">
    <label class="sr-only" for="ses-menu-q">Search the menu</label>
    <input id="ses-menu-q" x-ref="q" type="search" autocomplete="off" placeholder="Search the menu  ( / )" class="ses-menu-q"
        x-on:input="window.sesMenuSearch($el.value)"
        x-on:keydown.escape="$el.value = ''; window.sesMenuSearch('')"
        x-on:keydown.enter.prevent="window.sesMenuSearch($el.value, true)">
    <p class="ses-menu-none" hidden>No page matches. Try another word, or Ctrl+K to search records.</p>
</div>
<style>
    .ses-menu-q { width: 100%; padding: .45rem .7rem; border-radius: .5rem; font-size: .875rem; color: inherit;
        border: 1px solid color-mix(in srgb, currentColor 18%, transparent); }
    .ses-menu-q:focus { outline: 2px solid var(--primary-500); outline-offset: 0; }
    .ses-menu-none { font-size: .8rem; opacity: .7; padding: .4rem .2rem 0; }
    .fi-sidebar-item.ses-hit .fi-sidebar-item-label { text-decoration: underline; text-underline-offset: 3px; }
    .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
</style>
<script>
    // Common words people type for pages named otherwise.
    window.sesMenuWords ??= { po: ['purchase order'], grn: ['goods received'], momo: ['mobile money', 'till shifts'], adjust: ['stock adjustment'],
        expiry: ['batches & expiry', 'expiry & returns'], staff: ['users'], password: ['users'], pin: ['users'], vat: ['tax', 'e-vat'],
        reorder: ['what to order', 'reorder levels'], count: ['stock count'], transfer: ['stock transfer'], receipt: ['sales'] };
    window.sesMenuSearch = function (raw, open = false) {
        const nav = document.querySelector('.fi-sidebar-nav');
        if (!nav) return;
        const words = raw.toLowerCase().trim().split(/\s+/).filter(Boolean);
        const groups = nav.querySelectorAll('.fi-sidebar-group');
        let first = null;
        let hits = 0;
        groups.forEach((g) => {
            const groupLabel = (g.querySelector('.fi-sidebar-group-label')?.textContent ?? '').toLowerCase();
            const box = g.querySelector('.fi-sidebar-group-items');
            let groupHits = 0;
            g.querySelectorAll('.fi-sidebar-item').forEach((item) => {
                const label = (item.querySelector('.fi-sidebar-item-label')?.textContent ?? '').toLowerCase().trim();
                const text = `${groupLabel} ${label}`;
                const match = words.length === 0 || words.every((w) => text.includes(w) || (window.sesMenuWords[w] ?? []).some((p) => text.includes(p)));
                item.style.display = match ? '' : 'none';
                item.classList.toggle('ses-hit', words.length > 0 && match);
                if (match) {
                    groupHits++;
                    first ??= item.querySelector('a');
                }
            });
            g.style.display = groupHits ? '' : 'none';
            if (box) box.style.display = words.length && groupHits ? 'block' : ''; // open collapsed sections while searching
            hits += groupHits;
        });
        const none = document.querySelector('.ses-menu-none');
        if (none) none.hidden = words.length === 0 || hits > 0;
        if (open && first && words.length) first.click();
    };
    // The page you are on stays in view: the list under the search box is what scrolls (admin.css), so Filament's own
    // scroll-to-active (which moves the whole menu) has no effect; centre the active item in the list instead.
    window.sesMenuToActive = function () {
        const list = document.querySelector('.fi-main-sidebar .fi-sidebar-nav-groups');
        let active = document.querySelector('.fi-main-sidebar .fi-sidebar-item.fi-active');
        if (!active || active.offsetParent === null) active = document.querySelector('.fi-main-sidebar .fi-sidebar-group.fi-active');
        if (!list || !active || active.offsetParent === null) return;
        const a = active.getBoundingClientRect();
        const l = list.getBoundingClientRect();
        if (a.top >= l.top && a.bottom <= l.bottom) return; // already visible: leave the menu where the person put it
        list.scrollTop += a.top - l.top - (l.height - a.height) / 2;
    };
    document.addEventListener('DOMContentLoaded', () => setTimeout(window.sesMenuToActive, 20));
    document.addEventListener('livewire:navigated', () => {
        const q = document.getElementById('ses-menu-q');
        if (q?.value) window.sesMenuSearch(q.value);
        setTimeout(window.sesMenuToActive, 20);
    });
</script>
