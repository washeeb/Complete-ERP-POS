<x-filament-panels::page>
    @php($plan = $this->plan())
    @php($steps = $this->steps())
    @php($fig = $this->figures())
    @php($done = count(array_filter($steps, fn ($s) => $s['done'])))

    <x-filament::section>
        <div class="gl-head">
            <div><span class="gl-label">Status</span><strong class="gl-status gl-{{ $plan->status }}">{{ \App\Models\GoLivePlan::STATUSES[$plan->status] }}</strong>
                @if ($plan->live_at)<span class="gl-muted">since {{ $plan->live_at->format('j M Y') }}</span>@endif</div>
            <div><span class="gl-label">Data from</span>{{ collect($plan->sources)->map(fn ($s) => \App\Models\GoLivePlan::SOURCES[$s] ?? $s)->implode(', ') }}</div>
            <div><span class="gl-label">Switch-over</span>{{ \App\Models\GoLivePlan::CUTOVER[$plan->cutover_mode] }}</div>
            <div><span class="gl-label">Pilot</span>{{ \App\Models\GoLivePlan::PILOT[$plan->pilot_mode] }} · {{ $this->branchNames($plan->pilot_branch_ids ?? []) }}</div>
            <div><span class="gl-label">Go-live date</span>{{ $plan->go_live_on?->format('j M Y') ?? 'not set' }}@if ($plan->parallel_until) · parallel until {{ $plan->parallel_until->format('j M') }}@endif</div>
            <div><span class="gl-label">Progress</span>{{ $done }} of {{ count($steps) }} steps</div>
        </div>
        @unless ($plan->exists)<p class="gl-warn">Start with <strong>Plan</strong> (top right): choose where the data comes from, how you switch over and how the pilot runs. The recommended choices are already selected.</p>@endunless
        @if ($plan->status === 'rolled_back')<p class="gl-bad">Rolled back: {{ $plan->rollback_reason }}</p>@endif
    </x-filament::section>

    <x-filament::section heading="1. Checklist" description="In this order. Green steps were found done in the data; others are ticked by hand. Steps marked optional can be left.">
        <table class="gl">
            <tbody>
            @foreach ($steps as $i => $s)
                <tr class="{{ $s['done'] ? 'gl-done' : '' }}">
                    <td class="gl-n">{{ $s['done'] ? ($s['skipped'] ? '–' : '✓') : $i + 1 }}</td>
                    <td><strong>{{ $s['title'] }}</strong>@if ($s['optional'])<span class="gl-muted"> · optional</span>@endif
                        <div class="gl-muted">{{ $s['why'] }}@if ($s['by']) — {{ $s['skipped'] ? 'not needed' : 'ticked' }} by {{ $s['by'] }}@if ($s['note']): {{ $s['note'] }}@endif @endif</div></td>
                    <td class="gl-act">
                        @if ($s['link'] && ! $s['done'])<x-filament::link :href="$s['link']" size="sm">Open</x-filament::link>@endif
                        @if (! $s['auto'] && ! in_array($s['key'], ['go_live', 'reconcile', 'parallel'], true))
                            @if ($s['by'])
                                {{ ($this->markStepAction)(['key' => $s['key'], 'do' => 'undo']) }}
                            @else
                                {{ ($this->markStepAction)(['key' => $s['key'], 'do' => 'done']) }}
                                @if ($s['optional']){{ ($this->markStepAction)(['key' => $s['key'], 'do' => 'skip']) }}@endif
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="2. Check the figures" description="What was loaded, next to the old system's figures on the go-live date. The accounts must also tie: every sub-ledger equals its control account, and “Opening balances (to clear)” comes to zero.">
        <div class="gl-buttons">
            {{ $this->oldTotalsAction }}
            <x-filament::button color="gray" icon="heroicon-o-arrow-path" wire:click="postAndCheck" wire:loading.attr="disabled">Post and check again</x-filament::button>
        </div>
        <table class="gl">
            <thead><tr><th>Figure</th><th class="gl-r">This system</th><th class="gl-r">Old system</th><th class="gl-r">Difference</th></tr></thead>
            <tbody>
            @foreach ($fig['rows'] as $r)
                <tr><td>{{ $r['label'] }}</td>
                    <td class="gl-r">{{ $r['money'] ? $this->money($r['ours']) : number_format($r['ours']) }}</td>
                    <td class="gl-r">{{ $r['old'] === null ? '—' : ($r['money'] ? $this->money($r['old']) : number_format($r['old'])) }}</td>
                    <td class="gl-r {{ $r['difference'] ? 'gl-bad' : 'gl-ok' }}">{{ $r['difference'] === null ? '' : ($r['difference'] === 0 ? '✓' : ($r['money'] ? $this->money($r['difference']) : number_format($r['difference']))) }}</td></tr>
            @endforeach
                <tr><td>Opening balances (to clear)</td><td class="gl-r">{{ $this->money($fig['opening_left']) }}</td><td></td><td class="gl-r {{ $fig['opening_left'] ? 'gl-bad' : 'gl-ok' }}">{{ $fig['opening_left'] ? 'should be 0' : '✓' }}</td></tr>
            </tbody>
        </table>
        @if ($fig['problems'])
            <ul class="gl-problems">@foreach ($fig['problems'] as $p)<li>{{ $p }}</li>@endforeach</ul>
        @else
            <p class="gl-ok">Everything matches.</p>
        @endif
    </x-filament::section>

    @if ($plan->cutover_mode === 'parallel')
        <x-filament::section heading="3. Parallel run" description="Each evening, the old system's totals for each branch. A difference above 1.00 needs an explanation (a sale rung up in only one system, a price that differs…).">
            <div class="gl-buttons">{{ $this->parallelDayAction }}</div>
            <table class="gl">
                <thead><tr><th>Day</th><th>Branch</th><th class="gl-r">Old system</th><th class="gl-r">This system</th><th class="gl-r">Difference</th><th class="gl-r">Cash old / new</th><th>Note</th></tr></thead>
                <tbody>
                @forelse ($this->parallelDays() as $d)
                    @php($diff = $d->differenceMinor())
                    <tr><td>{{ $d->day->format('D j M') }}</td><td>{{ $d->branch->code }}</td><td class="gl-r">{{ $this->money($d->old_sales_minor) }}</td><td class="gl-r">{{ $this->money($d->new_sales_minor) }}</td>
                        <td class="gl-r {{ abs($diff) > \App\Domain\Launch\GoLiveService::PARALLEL_TOLERANCE_MINOR && ! $d->note ? 'gl-bad' : 'gl-ok' }}">{{ $diff === 0 ? '✓' : $this->money($diff) }}</td>
                        <td class="gl-r">{{ $this->money($d->old_cash_minor) }} / {{ $this->money($d->new_cash_minor) }}</td><td class="gl-muted">{{ $d->note }}</td></tr>
                @empty
                    <tr><td colspan="7" class="gl-muted">No day entered yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @endif

    <x-filament::section heading="Practice company" description="Staff practise on a copy with made-up customers. Nothing done there is real: no GRA reporting, no SMS or e-mail, no online-store updates, and its books never mix with yours.">
        @if ($pc = $this->practice())
            <p><strong>{{ $pc->name }}</strong> — staff sign in to the back office with their usual password at the e-mail address with “+practice” (e.g. kofi+practice@yourshop.com); at a till enrolled to the practice company they use their usual username and PIN.</p>
            <div class="gl-buttons">{{ $this->archivePracticeAction }}</div>
        @else
            <div class="gl-buttons">{{ $this->createPracticeAction }}</div>
        @endif
        @if ($practiceResult)
            <div class="gl-box">
                <p><strong>{{ $practiceResult['name'] }}</strong>: {{ $practiceResult['copied']['products'] }} products, {{ $practiceResult['copied']['prices'] }} prices, {{ $practiceResult['copied']['stock'] }} stock lines, {{ $practiceResult['copied']['customers'] }} made-up customers.</p>
                <table class="gl"><thead><tr><th>Name</th><th>Back-office sign-in</th><th>Till username</th></tr></thead><tbody>
                @foreach ($practiceResult['logins'] as $l)<tr><td>{{ $l['name'] }}</td><td>{{ $l['email'] }}</td><td>{{ $l['username'] }}</td></tr>@endforeach
                </tbody></table>
                @if ($practiceResult['problems'])<details><summary>{{ count($practiceResult['problems']) }} item(s) were not copied</summary><ul>@foreach ($practiceResult['problems'] as $p)<li>{{ $p }}</li>@endforeach</ul></details>@endif
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Pilot report" description="E-mailed to the support address every evening at the plan's time during the parallel run and the first 60 days live: sales per pilot branch, cash over/short, tills that were offline, the parallel-run difference, problems staff reported, and health warnings.">
        <div class="gl-buttons">
            <x-filament::button color="gray" icon="heroicon-o-eye" wire:click="previewReport">Show today's report</x-filament::button>
            <x-filament::button color="gray" icon="heroicon-o-paper-airplane" wire:click="sendReport">Send it now</x-filament::button>
        </div>
        @if ($reportPreview)
            <div class="gl-box"><strong>{{ $reportPreview['title'] }}</strong>
                <ul>@foreach ($reportPreview['lines'] as $l)<li>{{ $l }}</li>@endforeach</ul>
                @if ($reportPreview['warnings'])<p class="gl-bad">Needs attention:</p><ul>@foreach ($reportPreview['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>@else<p class="gl-ok">Nothing needs attention.</p>@endif
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Go live — or go back" description="The go-live backup is the point you can return to. The rollback plan (installation guide, chapter 17.8) says who does what if you have to go back.">
        @php($snap = $this->snapshot())
        <p>Go-live backup: @if ($snap)<strong>{{ $snap->file_name }}</strong> ({{ $snap->started_at->diffForHumans() }}, {{ $snap->status }})@else<span class="gl-muted">not taken yet</span>@endif</p>
        <div class="gl-buttons">
            @if ($plan->status !== 'live'){{ $this->snapshotAction }}@endif
            @if ($plan->cutover_mode === 'parallel' && in_array($plan->status, ['planning', 'rolled_back'], true)){{ $this->startParallelAction }}@endif
            @if ($plan->status !== 'live'){{ $this->goLiveAction }}@endif
            @if (in_array($plan->status, ['parallel', 'live'], true)){{ $this->rollBackAction }}@endif
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
    <style>
        .gl { width: 100%; border-collapse: collapse; font-size: .85rem; } .gl th, .gl td { padding: 6px 8px; text-align: left; vertical-align: top; border-bottom: 1px solid color-mix(in srgb, currentColor 7%, transparent); }
        .gl-r { text-align: right !important; white-space: nowrap; } .gl-n { width: 2rem; font-weight: 600; } .gl-act { white-space: nowrap; text-align: right; }
        .gl-done td { opacity: .75; } .gl-done .gl-n { color: rgb(22 163 74); opacity: 1; }
        .gl-muted { opacity: .65; font-size: .8rem; } .gl-bad { color: rgb(220 38 38); } .gl-ok { color: rgb(22 163 74); }
        .gl-head { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px 18px; font-size: .9rem; }
        .gl-label { display: block; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; opacity: .6; }
        .gl-status { font-size: 1.05rem; } .gl-live { color: rgb(22 163 74); } .gl-rolled_back { color: rgb(220 38 38); } .gl-parallel { color: rgb(37 99 235); }
        .gl-buttons { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
        .gl-warn { margin-top: 12px; padding: 10px 12px; border-radius: 8px; background: color-mix(in srgb, rgb(234 179 8) 18%, transparent); }
        .gl-box { margin-top: 10px; padding: 10px 12px; border-radius: 8px; background: color-mix(in srgb, currentColor 4%, transparent); font-size: .88rem; }
        .gl-box ul, .gl-problems { margin: 6px 0 0 18px; list-style: disc; }
    </style>
</x-filament-panels::page>
