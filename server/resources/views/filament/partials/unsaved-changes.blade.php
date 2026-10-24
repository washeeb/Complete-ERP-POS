{{--
    "You have unsaved changes" for back-office pages that are not ordinary create / edit forms (owner request 2026-10-24).
    $watch: the page's properties a person types into. Leaving the page (a menu link, reload, closing the tab) with any of
    them changed since the page loaded or was last saved asks first. The page calls $this->dispatch('ses-unsaved-reset')
    after saving, or after loading other values (e.g. another branch), so those do not count as changes.
--}}
@script
<script>
    const watch = @js($watch);
    const snap = () => JSON.stringify(watch.map((p) => $wire.get(p)));
    let saved = snap();
    $wire.on('ses-unsaved-reset', () => setTimeout(() => { saved = snap() }, 0));
    const ask = (event) => {
        if (snap() === saved || $wire?.__instance?.effects?.redirect) return // unchanged, or the page itself moves on after saving
        event.preventDefault()
        event.returnValue = true
    }
    window.addEventListener('beforeunload', ask)
    $wire.__instance.addCleanup(() => window.removeEventListener('beforeunload', ask))
</script>
@endscript
