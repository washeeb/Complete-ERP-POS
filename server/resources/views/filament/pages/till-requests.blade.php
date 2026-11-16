<x-filament-panels::page>
    <div x-data="{ s: window.zxPush ? window.zxPush.state() : 'unsupported', busy: false, has: @js($this->hasDevice) }">
        <template x-if="s !== 'unsupported' && !(has && s === 'granted')">
            <x-filament::section>
                <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;justify-content:space-between">
                    <p style="margin:0;max-width:44rem"><strong>Get till requests on this phone.</strong> A notification pops up even when the back office is closed; press it to approve. Do this once on each phone or computer you use.
                        <span x-show="s === 'denied'" style="display:block;color:var(--danger-600,#dc2626)">Notifications are blocked for this site: allow them in the browser's site settings, then press the button again.</span>
                        <span style="display:block;font-size:.85rem;opacity:.75">iPhone: first add this site to the home screen (Share → Add to Home Screen) and open it from there.</span></p>
                    <x-filament::button icon="heroicon-o-bell-alert" x-bind:disabled="busy"
                        x-on:click="busy = true; window.zxPush.enable(@js($this->pushKey), $wire).then((r) => { s = r; has = r === 'granted' }).catch(() => {}).finally(() => busy = false)">
                        Turn on notifications
                    </x-filament::button>
                </div>
            </x-filament::section>
        </template>
    </div>
    {{ $this->table }}
</x-filament-panels::page>
