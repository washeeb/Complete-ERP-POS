{{-- Setup → Integrations: one card per connection (App\Domain\Integrations\IntegrationsOverview). --}}
@php($cards = $this->cards())
@php($counts = collect($cards)->reject(fn ($c) => $c['always'])->countBy('state'))
<x-filament-panels::page>
    <div class="zx-int-sum">
        <span class="zx-int-pill zx-int-ok">{{ $counts['ok'] ?? 0 }} working</span>
        <span class="zx-int-pill zx-int-attention">{{ $counts['attention'] ?? 0 }} need attention</span>
        <span class="zx-int-pill zx-int-off">{{ $counts['off'] ?? 0 }} not set up</span>
    </div>
    <div class="zx-int-grid">
        @foreach ($cards as $c)
            <section @class(['zx-int', 'zx-int-'.$c['state']])>
                <header>
                    <h2>{{ $c['title'] }}</h2>
                    <span @class(['zx-int-state', 'zx-int-'.$c['state']])>{{ $c['always'] ? 'Available' : \App\Domain\Integrations\IntegrationsOverview::STATES[$c['state']] }}</span>
                </header>
                <p class="zx-int-what">{{ $c['what'] }}</p>
                <dl>
                    <div><dt>Now</dt><dd>{{ $c['detail'] }}</dd></div>
                    @unless ($c['always'])
                        <div><dt>Last worked</dt><dd>{{ $c['last_ok'] ? $c['last_ok']->timezone(\App\Filament\Support\Erp::user()?->tenant?->timezone ?: 'UTC')->format(\App\Support\UserPrefs::dateTimeFormat()).' ('.$c['last_ok']->diffForHumans().')' : 'Not yet' }}</dd></div>
                        <div><dt>Failed this week</dt><dd @class(['zx-int-bad' => $c['failed'] > 0])>{{ $c['failed'] }}</dd></div>
                    @endunless
                </dl>
                @if ($c['url'])
                    <a href="{{ $c['url'] }}" class="zx-int-link">{{ $c['link'] }} →</a>
                @endif
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
