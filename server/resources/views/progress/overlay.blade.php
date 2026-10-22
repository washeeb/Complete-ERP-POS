{{-- Progress of long work (imports, bulk changes): shown when a request runs for more than a second and reports a count. --}}
<div id="ses-progress" hidden role="status" aria-live="polite"
    style="position:fixed;right:16px;bottom:16px;z-index:60;min-width:280px;max-width:min(420px,calc(100vw - 32px));padding:12px 14px;border-radius:12px;background:#031E4C;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.25);font-size:.875rem">
    <div class="ses-progress-label" style="font-weight:600;margin-bottom:8px">Working…</div>
    <div style="height:8px;border-radius:999px;background:rgba(255,255,255,.2);overflow:hidden">
        <span class="ses-progress-bar" style="display:block;height:100%;width:0;background:#06AAAA;transition:width .4s"></span>
    </div>
    <div class="ses-progress-eta" style="margin-top:6px;font-size:.75rem;opacity:.8"></div>
</div>
<script>
    document.addEventListener('livewire:init', () => {
        const box = document.getElementById('ses-progress');
        if (!box) return;
        const label = box.querySelector('.ses-progress-label'), bar = box.querySelector('.ses-progress-bar'), eta = box.querySelector('.ses-progress-eta');
        let inflight = 0, wait = null, poll = null, started = 0;
        const n = (v) => Number(v).toLocaleString();
        async function check() {
            try {
                const r = await fetch(@js(route('progress.status')), { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                const p = await r.json();
                if (!p || !p.total) return;
                box.hidden = false;
                label.textContent = `${p.label} — ${n(p.done)} of ${n(p.total)}`;
                bar.style.width = Math.min(100, Math.round((p.done * 100) / p.total)) + '%';
                const secs = (Date.now() - started) / 1000;
                eta.textContent = p.done > 0 && p.done < p.total ? `About ${Math.max(1, Math.round((secs / p.done) * (p.total - p.done)))} s left. Please keep this page open.` : 'Please keep this page open.';
            } catch (e) { /* the next check tries again */ }
        }
        function done() {
            if (--inflight > 0) return;
            inflight = 0; clearTimeout(wait); clearInterval(poll); wait = poll = null; box.hidden = true;
        }
        Livewire.hook('request', ({ respond, fail }) => {
            if (inflight++ === 0) { started = Date.now(); wait = setTimeout(() => { check(); poll = setInterval(check, 1000) }, 900) }
            respond(done);
            fail(() => { inflight = 1; done() });
        });
    });
</script>
