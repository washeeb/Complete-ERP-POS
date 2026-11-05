{{-- What the person is told when a request to the server fails (in place of Filament's error notifications, turned off
     in AdminPanelProvider). Pages refresh parts of themselves in the background (the bell every 30 seconds, lists that
     update themselves); on a shaky internet line one of those missing once is a blip nobody needs to hear about, so a
     background refresh says something only when it keeps failing, and once — then "Connected again" when it is back.
     Something the person clicked that did not reach the server is always said, as are server answers (signed out,
     being updated, an error). Requests cut off because the page is being left are not failures. In debug mode
     Livewire's own error window shows instead (as with Filament's). --}}
@unless (app()->hasDebugModeEnabled())
<script>
    (() => {
        const N = @js([
            'background' => ['title' => 'Could not reach the server', 'body' => 'The internet or network connection keeps dropping. The page carries on by itself when it is back; if this lasts, check the connection, or tell SyncLytics support.'],
            'clicked' => ['title' => 'Could not reach the server', 'body' => 'What you just did may not have gone through: the internet or network connection dropped. Check the connection and try again.'],
            'back' => ['title' => 'Connected again', 'body' => null],
            419 => ['title' => 'You were signed out', 'body' => 'After some time without use you are signed out, for safety. Reload the page (F5) to sign in again.'],
            429 => ['title' => 'Too many requests', 'body' => 'Wait a minute, then try again.'],
            503 => ['title' => 'The server is being updated', 'body' => 'Try again in a minute.'],
            500 => ['title' => 'Something went wrong on the server', 'body' => 'It has been noted in the server log. Try again; if it happens again, tell SyncLytics support what you were doing.'],
            'other' => ['title' => 'That did not work', 'body' => 'Reload the page (F5) and try again; if it happens again, tell SyncLytics support what you were doing.'],
        ]);
        let lastInput = 0, misses = 0, told = false, leaving = false;
        for (const e of ['pointerdown', 'keydown']) addEventListener(e, () => { lastInput = Date.now() }, true);
        // Leaving the page cuts off its requests; a download also starts this way but the page stays, so it ends after a moment.
        addEventListener('beforeunload', () => { leaving = true; setTimeout(() => { leaving = false }, 3000) });
        const say = (n, kind = 'danger') => new FilamentNotification().title(n.title).body(n.body)[kind]().send();
        // What really failed, from the server (bootstrap/app.php): it stays until closed, so the reference can be passed on.
        const problem = (body) => {
            try { return JSON.parse(body)?.problem ? JSON.parse(body) : null } catch { return null }
        };
        const onlyBell = (request) => {
            try {
                const c = request?.payload?.components;
                return c?.length === 1 && JSON.parse(c[0].snapshot).data.isFilamentNotificationsComponent === true;
            } catch { return false }
        };
        document.addEventListener('livewire:init', () => Livewire.interceptRequest(({ request, onError, onFailure, onSuccess }) => {
            const clicked = Date.now() - lastInput < 3000;
            onSuccess(() => {
                if (told) say(N.back, 'success');
                misses = 0;
                told = false;
            });
            onFailure(() => {
                if (leaving) return;
                misses++;
                if (clicked) { told = true; return say(N.clicked) }
                if (told || misses < 2) return;
                told = true;
                say(N.background);
            });
            onError(({ response, body, preventDefault }) => {
                if (onlyBell(request)) return;
                preventDefault();
                const told = problem(body);
                if (told) {
                    return new FilamentNotification().title('This did not go through').body(told.problem + (told.reference ? ' Tell SyncLytics support this reference: ' + told.reference : '')).danger().persistent().send();
                }
                const s = response?.status;
                say(N[s] ?? (s >= 500 ? N[500] : N.other));
            });
        }));
    })();
</script>
@endunless
