<x-filament-panels::page>
    @php($p = $this->profile())
    @if (! $p)
        <x-filament::section>No payroll profile is set up.</x-filament::section>
    @else
        @php($r = $p->rules)
        <x-filament::section :heading="$p->name" :description="$p->verified_at ? 'Checked by '.$p->verified_by_name.' on '.$p->verified_at->format('j M Y') : 'NOT YET CHECKED — an accountant must confirm these against current GRA and SSNIT rules before the first real payroll.'">
            <table class="pp">
                <thead><tr><th>Monthly taxable income</th><th class="num">Rate</th></tr></thead>
                <tbody>
                @php($from = 0)
                @foreach ($r['paye_bands'] as [$w, $bp])
                    <tr><td>{{ $w === null ? 'Above GHS '.number_format($from / 100, 2) : ($loop->first ? 'First' : 'Next').' GHS '.number_format($w / 100, 2) }}</td><td class="num">{{ $bp / 100 }} %</td></tr>
                    @php($from += (int) $w)
                @endforeach
                </tbody>
            </table>
            <p class="pp-note">SSNIT: employee {{ $r['ssnit']['employee_bp'] / 100 }} %, employer {{ $r['ssnit']['employer_bp'] / 100 }} % of basic — Tier 1 {{ $r['ssnit']['tier1_bp'] / 100 }} %, Tier 2 {{ $r['ssnit']['tier2_bp'] / 100 }} %.
                Tier 3 relieved up to {{ ($r['tier3_relief_bp'] ?? 0) / 100 }} % of basic. Junior staff overtime: {{ $r['overtime_junior']['low_bp'] / 100 }} % up to {{ $r['overtime_junior']['threshold_bp'] / 100 }} % of basic, {{ $r['overtime_junior']['high_bp'] / 100 }} % above.
                Standard day {{ $r['standard_day_hours'] ?? 8 }} hours (time beyond it is overtime).</p>
        </x-filament::section>
    @endif
    <style>.pp { width: 100%; max-width: 480px; border-collapse: collapse; font-size: .875rem } .pp td, .pp th { padding: 4px 6px; border-bottom: 1px solid rgb(0 0 0 / .06); text-align: left } .pp .num { text-align: right } .pp-note { font-size: .85rem; margin-top: 10px }</style>
</x-filament-panels::page>
