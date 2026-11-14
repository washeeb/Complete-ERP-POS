{{-- IT Admin and SyncLytics support: the open page signs itself out after 15 minutes without a click or key, in every tab
     (owner decision 2026-11-13). The server checks too (SignOutWhenIdle); this just does not leave the screen showing. --}}
@php($u = auth()->user())
@if ($u instanceof \App\Models\User && \App\Domain\Access\SignInGuard::isStrict($u))
<script>
    (() => {
        const LIMIT = {{ \App\Domain\Access\SignInGuard::IDLE_MINUTES }} * 60 * 1000, KEY = 'zx-last-input';
        const mark = () => { try { localStorage.setItem(KEY, String(Date.now())) } catch {} };
        const last = () => { try { return Number(localStorage.getItem(KEY)) || 0 } catch { return 0 } };
        mark();
        for (const e of ['pointerdown', 'keydown', 'wheel', 'touchstart']) addEventListener(e, mark, { capture: true, passive: true });
        setInterval(() => {
            if (Date.now() - last() < LIMIT) return;
            const f = Object.assign(document.createElement('form'), { method: 'post', action: @js(route('filament.admin.auth.logout')) });
            f.append(Object.assign(document.createElement('input'), { type: 'hidden', name: '_token', value: document.querySelector('meta[name="csrf-token"]')?.content ?? '' }));
            document.body.append(f);
            f.submit();
        }, 30000);
    })();
</script>
@endif
