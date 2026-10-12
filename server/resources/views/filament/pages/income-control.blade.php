<x-filament-panels::page>
    <x-filament::section heading="Handovers to receive" description="Count each bundle blind. If your count differs from the cashier's you are asked to count once more; the second count stands.">
        <table class="ic">
            <thead><tr><th>Handover</th><th>Branch</th><th>Cashier</th><th>Handed over</th><th></th></tr></thead>
            <tbody>
            @forelse ($this->waiting() as $h)
                <tr>
                    <td><strong>{{ $h->number }}</strong>@if (isset($this->recounts[$h->id]))<span class="ic-warn"> · count again</span>@endif</td>
                    <td>{{ $h->branch->code }}</td>
                    <td>{{ $h->cashier_name }}</td>
                    <td>{{ $h->handed_at->format('j M H:i') }}</td>
                    <td class="ic-act">{{ ($this->receiveAction)(['handover' => $h->id]) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="ic-muted">Nothing waiting.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Differences to decide" description="Short or over at a count, against the person responsible. Nobody decides a difference on their own count.">
        <table class="ic">
            <thead><tr><th>When</th><th>Branch</th><th>Where</th><th>Person</th><th class="ic-num">Amount</th><th></th></tr></thead>
            <tbody>
            @forelse ($this->differences() as $v)
                <tr>
                    <td>{{ $v->created_at->format('j M H:i') }}</td>
                    <td>{{ $v->branch->code }}</td>
                    <td>{{ \App\Models\CashVariance::STAGES[$v->stage] }}</td>
                    <td>{{ $v->person_name }}</td>
                    <td class="ic-num {{ $v->amount_minor < 0 ? 'ic-bad' : 'ic-good' }}">{{ $v->amount_minor < 0 ? 'short' : 'over' }} {{ $this->money(abs($v->amount_minor)) }}</td>
                    <td class="ic-act">{{ ($this->decideAction)(['variance' => $v->id]) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="ic-muted">No open differences.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Received: reconciliation" description="For each vault: what the system expected, what the teller counted, what the cashier counted; and for the bundle, what you counted.">
        @forelse ($this->received() as $h)
            <div class="ic-h">
                <div class="ic-head"><strong>{{ $h->number }}</strong> · {{ $h->branch->code }} · {{ $h->cashier_name }} → {{ $h->controller_name }} · {{ $h->received_at->format('j M H:i') }}
                    <span class="ic-act">@if ($h->deposit_transfer_id)<span class="ic-good">Deposited</span>@else{{ ($this->depositAction)(['handover' => $h->id]) }}@endif</span></div>
                <table class="ic">
                    <thead><tr><th>Vault</th><th>Teller</th><th class="ic-num">System</th><th class="ic-num">Teller</th><th class="ic-num">Cashier</th></tr></thead>
                    <tbody>
                    @foreach ($h->sessions as $s)
                        <tr>
                            <td>{{ $s->vault->name }} · {{ $s->business_date->format('j M') }}</td>
                            <td>{{ $s->teller_name }}</td>
                            <td class="ic-num">{{ $this->money($s->expected_minor) }}</td>
                            <td class="ic-num {{ $s->teller_count_minor !== $s->expected_minor ? 'ic-warn' : '' }}">{{ $this->money($s->teller_count_minor) }}</td>
                            <td class="ic-num {{ $s->cashier_count_minor !== $s->expected_minor ? 'ic-warn' : '' }}">{{ $this->money($s->cashier_count_minor) }}</td>
                        </tr>
                    @endforeach
                    <tr class="ic-total"><td colspan="4">Bundle: declared by the cashier {{ $this->money($h->declared_minor) }}; you counted</td>
                        <td class="ic-num {{ $h->controller_count_minor !== $h->declared_minor ? 'ic-warn' : '' }}">{{ $this->money($h->controller_count_minor) }}</td></tr>
                    </tbody>
                </table>
            </div>
        @empty
            <p class="ic-muted">Nothing received yet.</p>
        @endforelse
    </x-filament::section>

    <style>
        .ic { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .ic th { text-align: left; font-weight: 600; padding: 6px 8px; border-bottom: 1px solid rgb(0 0 0 / .1); }
        .ic td { padding: 7px 8px; border-bottom: 1px solid rgb(0 0 0 / .06); }
        .dark .ic th { border-color: rgb(255 255 255 / .12); } .dark .ic td { border-color: rgb(255 255 255 / .06); }
        .ic-num { text-align: right; font-variant-numeric: tabular-nums; } .ic-act { text-align: right; white-space: nowrap; float: right; }
        .ic-muted { opacity: .7; } .ic-warn { color: #b45309; } .ic-bad { color: #b91c1c; } .ic-good { color: #047857; }
        .dark .ic-warn { color: #fbbf24; } .dark .ic-bad { color: #f87171; } .dark .ic-good { color: #34d399; }
        .ic-h { margin-bottom: 18px; } .ic-head { margin-bottom: 6px; } .ic-total td { font-weight: 600; }
    </style>
</x-filament-panels::page>
