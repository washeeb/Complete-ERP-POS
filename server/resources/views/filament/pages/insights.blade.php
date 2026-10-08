<x-filament-panels::page>
    <x-filament::tabs>
        @foreach ($this->tabs() as $key => $label)
            <x-filament::tabs.item :active="$tab === $key" wire:click="$set('tab', '{{ $key }}')">{{ $label }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    @if (in_array($tab, ['forecast', 'stock', 'pricing'], true))
        <label class="ins-filter">Warehouse
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="warehouse">
                    @foreach ($this->warehouses() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    @endif

    @if ($tab === 'forecast')
        <x-filament::section heading="Demand forecast and reorder" description="From the last 12 weeks of till sales: recent level, weekday pattern, paydays (25th–3rd) and public holidays. Suggested order covers the supplier's lead time plus a week, with safety stock. Shortest cover first.">
            <div class="ins-scroll"><table class="ins">
                <thead><tr><th>SKU</th><th>Product</th><th class="num">Sold a day</th><th class="num">Next 7 days</th><th class="num">Next 28 days</th><th class="num">Trend</th><th class="num">On hand</th><th class="num">On order</th><th class="num">Days of cover</th><th>Runs out about</th><th class="num">Suggested order</th><th class="num">Suggested min / max</th></tr></thead>
                <tbody>
                @forelse ($this->forecast() as $f)
                    <tr><td>{{ $f['sku'] }}</td><td>{{ $f['name'] }}</td><td class="num">{{ $f['avg_daily'] }}</td><td class="num">{{ $f['forecast_7'] }}</td><td class="num">{{ $f['forecast_28'] }}</td>
                        <td class="num">{{ $f['trend_pct'] === null ? '—' : sprintf('%+d %%', $f['trend_pct']) }}</td><td class="num">{{ $this->q($f['on_hand']) }}</td><td class="num">{{ $this->q($f['on_order']) }}</td>
                        <td class="num">{{ $f['days_cover'] ?? '—' }}</td><td>{{ $f['stockout_on'] ?? '—' }}</td><td class="num"><strong>{{ $f['suggested_order'] ?: '' }}</strong></td><td class="num">{{ $f['suggested_min'] }} / {{ $f['suggested_max'] }}</td></tr>
                @empty
                    <tr><td colspan="12">No sales in the last 12 weeks at this warehouse.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </x-filament::section>
    @elseif ($tab === 'stock')
        @php($h = $this->stock())
        <x-filament::section heading="May expire unsold" description="Batches that will not sell before their expiry at the current rate (earliest expiry sold first). Mark down, or move to a branch that sells faster.">
            <div class="ins-scroll"><table class="ins"><thead><tr><th>Product</th><th>Batch</th><th>Expires</th><th class="num">Days left</th><th class="num">On hand</th><th class="num">At risk</th><th class="num">Value at cost</th><th class="num">Suggested markdown</th></tr></thead><tbody>
            @forelse ($h['expiry'] as $e)
                <tr><td>{{ $e['name'] }}</td><td>{{ $e['batch_no'] }}</td><td>{{ $e['expires_on'] }}</td><td class="num">{{ $e['days_left'] }}</td><td class="num">{{ $this->q($e['on_hand']) }}</td><td class="num">{{ $e['at_risk'] }}</td><td class="num">{{ $this->money($e['value_minor']) }}</td><td class="num">{{ $e['markdown_pct'] }} %</td></tr>
            @empty
                <tr><td colspan="8">Nothing at risk in the next 90 days.</td></tr>
            @endforelse
            </tbody></table></div>
        </x-filament::section>
        <x-filament::section heading="Dead stock" description="In stock but not sold for 90 days or more.">
            <div class="ins-scroll"><table class="ins"><thead><tr><th>SKU</th><th>Product</th><th class="num">On hand</th><th class="num">Value at cost</th><th>Last sold</th></tr></thead><tbody>
            @forelse ($h['dead'] as $d)
                <tr><td>{{ $d['sku'] }}</td><td>{{ $d['name'] }}</td><td class="num">{{ $this->q($d['on_hand']) }}</td><td class="num">{{ $this->money($d['value_minor']) }}</td><td>{{ $d['last_sold'] ?? 'Never here' }}</td></tr>
            @empty
                <tr><td colspan="5">No dead stock.</td></tr>
            @endforelse
            </tbody></table></div>
        </x-filament::section>
        <x-filament::section heading="Overstock" description="More than 120 days of stock at the current rate; the excess is what is above two months.">
            <div class="ins-scroll"><table class="ins"><thead><tr><th>SKU</th><th>Product</th><th class="num">On hand</th><th class="num">Days of stock</th><th class="num">Excess</th><th class="num">Excess value</th></tr></thead><tbody>
            @forelse ($h['overstock'] as $o)
                <tr><td>{{ $o['sku'] }}</td><td>{{ $o['name'] }}</td><td class="num">{{ $this->q($o['on_hand']) }}</td><td class="num">{{ $o['days_cover'] }}</td><td class="num">{{ $o['excess'] }}</td><td class="num">{{ $this->money($o['excess_value_minor']) }}</td></tr>
            @empty
                <tr><td colspan="6">No overstock.</td></tr>
            @endforelse
            </tbody></table></div>
        </x-filament::section>
        <x-filament::section heading="Transfers between branches" description="Stock sitting at one warehouse (over 60 days of cover) that another is about to run out of (under a week). Create the transfer under Stock → Transfers.">
            <div class="ins-scroll"><table class="ins"><thead><tr><th>Product</th><th>From</th><th>To</th><th class="num">Quantity</th><th class="num">Days of cover where needed</th></tr></thead><tbody>
            @forelse ($this->transfers() as $t)
                <tr><td>{{ $t['name'] }}</td><td>{{ $t['from'] }}</td><td>{{ $t['to'] }}</td><td class="num">{{ $t['qty'] }}</td><td class="num">{{ $t['to_days_cover'] }}</td></tr>
            @empty
                <tr><td colspan="5">No transfers suggested.</td></tr>
            @endforelse
            </tbody></table></div>
        </x-filament::section>
    @elseif ($tab === 'customers')
        @php($c = $this->customers())
        <x-filament::section heading="Segments (last 12 months)" description="By how recently, how often and how much each customer bought (till sales with a customer, and invoices).">
            <div class="ins-scroll"><table class="ins"><thead><tr><th>Segment</th><th class="num">Customers</th><th class="num">Spent</th></tr></thead><tbody>
            @foreach ($c['segments'] as $s)
                <tr><td>{{ $s['segment'] }}</td><td class="num">{{ $s['customers'] }}</td><td class="num">{{ $this->money($s['spend_minor']) }}</td></tr>
            @endforeach
            </tbody></table></div>
        </x-filament::section>
        <x-filament::section heading="Regulars who have not been back" description="Customers with 3 or more visits whose time since the last is more than twice their usual gap (and over 30 days). A call or SMS offer may bring them back.">
            <div class="ins-scroll"><table class="ins"><thead><tr><th>Customer</th><th>Code</th><th>Last visit</th><th class="num">Usual gap (days)</th><th class="num">Days since</th><th class="num">Visits</th><th class="num">Spent</th></tr></thead><tbody>
            @forelse ($c['churn'] as $r)
                <tr><td>{{ $r['name'] }}</td><td>{{ $r['code'] }}</td><td>{{ $r['last'] }}</td><td class="num">{{ $r['usual_gap_days'] }}</td><td class="num">{{ $r['days_since'] }}</td><td class="num">{{ $r['visits'] }}</td><td class="num">{{ $this->money($r['spend_minor']) }}</td></tr>
            @empty
                <tr><td colspan="7">No regular customer is overdue.</td></tr>
            @endforelse
            </tbody></table></div>
        </x-filament::section>
        <x-filament::section heading="Bought together (last 90 days)" description="Pairs on the same receipt. Lift above 1 means they go together more than chance: place them side by side or bundle them.">
            <div class="ins-scroll"><table class="ins"><thead><tr><th>Product</th><th>With</th><th class="num">Receipts with both</th><th class="num">Confidence</th><th class="num">Lift</th></tr></thead><tbody>
            @forelse ($c['baskets'] as $b)
                <tr><td>{{ $b['a'] }}</td><td>{{ $b['b'] }}</td><td class="num">{{ $b['together'] }}</td><td class="num">{{ $b['confidence_pct'] }} %</td><td class="num">{{ $b['lift'] }}</td></tr>
            @empty
                <tr><td colspan="5">Not enough receipts yet.</td></tr>
            @endforelse
            </tbody></table></div>
        </x-filament::section>
    @elseif ($tab === 'pricing')
        @php($p = $this->pricing())
        <x-filament::section heading="Low margins" description="Default price list, price before tax against average cost. Change prices under Catalog → Price lists.">
            <div class="ins-scroll"><table class="ins"><thead><tr><th>SKU</th><th>Product</th><th class="num">Price</th><th class="num">Average cost</th><th class="num">Margin</th><th class="num">Price for target margin</th></tr></thead><tbody>
            @forelse ($p['margins'] as $m)
                <tr><td>{{ $m['sku'] }}</td><td>{{ $m['name'] }}@if ($m['below_cost']) <span class="bad">below cost</span>@endif</td><td class="num">{{ $this->money($m['price_minor']) }}</td><td class="num">{{ $this->money($m['cost_minor']) }}</td><td class="num">{{ $m['margin_pct'] }} %</td><td class="num">{{ $this->money($m['price_for_target_minor']) }}</td></tr>
            @empty
                <tr><td colspan="6">Every priced product meets the margin target.</td></tr>
            @endforelse
            </tbody></table></div>
        </x-filament::section>
        <x-filament::section heading="Markdown suggestions" description="Slow movers and dead stock at this warehouse. Near-expiry stock is under Stock health.">
            <div class="ins-scroll"><table class="ins"><thead><tr><th>SKU</th><th>Product</th><th class="num">On hand</th><th>Why</th><th class="num">Suggested markdown</th></tr></thead><tbody>
            @forelse ($p['markdowns'] as $m)
                <tr><td>{{ $m['sku'] }}</td><td>{{ $m['name'] }}</td><td class="num">{{ $this->q($m['on_hand']) }}</td><td>{{ $m['reason'] }}</td><td class="num">{{ $m['markdown_pct'] }} %</td></tr>
            @empty
                <tr><td colspan="5">No slow movers.</td></tr>
            @endforelse
            </tbody></table></div>
        </x-filament::section>
        <x-filament::section heading="Promotion results (last 180 days)" description="Units sold a day during each promotion against the 28 days before, and the discount given.">
            <div class="ins-scroll"><table class="ins"><thead><tr><th>Promotion</th><th>From</th><th>To</th><th class="num">Before (a day)</th><th class="num">During (a day)</th><th class="num">Uplift</th><th class="num">Extra units</th><th class="num">Discount given</th></tr></thead><tbody>
            @forelse ($p['promotions'] as $r)
                <tr><td>{{ $r['promotion'] }}</td><td>{{ $r['from'] }}</td><td>{{ $r['to'] }}</td><td class="num">{{ $r['before_daily'] }}</td><td class="num">{{ $r['during_daily'] }}</td><td class="num">{{ $r['uplift_pct'] === null ? '—' : $r['uplift_pct'].' %' }}</td><td class="num">{{ $r['extra_units'] }}</td><td class="num">{{ $this->money($r['discount_minor']) }}</td></tr>
            @empty
                <tr><td colspan="8">No product promotions in the last 180 days.</td></tr>
            @endforelse
            </tbody></table></div>
        </x-filament::section>
    @elseif ($tab === 'cash')
        @php($f = $this->cash())
        <x-filament::section heading="Cash-flow forecast (8 weeks)" description="Money now, plus till takings at the recent weekly average and customer invoices by due date, less supplier bills by due date, payroll at month end (last run) and expense claims at their recent average. Overdue items fall in the first week. A planning guide, not a promise.">
            <div class="ins-scroll"><table class="ins"><thead><tr><th>Week of</th><th class="num">Start</th><th class="num">Till takings</th><th class="num">From customers</th><th class="num">To suppliers</th><th class="num">Payroll</th><th class="num">Expenses</th><th class="num">End</th></tr></thead><tbody>
            @foreach ($f['weeks'] as $w)
                <tr><td>{{ $w['week'] }}</td><td class="num">{{ $this->money($w['opening_minor']) }}</td><td class="num">{{ $this->money($w['till_minor']) }}</td><td class="num">{{ $this->money($w['customers_minor']) }}</td>
                    <td class="num">{{ $this->money($w['suppliers_minor']) }}</td><td class="num">{{ $this->money($w['payroll_minor']) }}</td><td class="num">{{ $this->money($w['expenses_minor']) }}</td>
                    <td class="num {{ $w['closing_minor'] < 0 ? 'bad' : '' }}"><strong>{{ $this->money($w['closing_minor']) }}</strong></td></tr>
            @endforeach
            </tbody></table></div>
        </x-filament::section>
    @elseif ($tab === 'loss')
        <x-filament::section heading="Loss prevention (last 30 days)" description="Each cashier compared with the others: refunds, refunds without a receipt, discounts, drawer over / short at close, kitchen voids. The risk score (0–100) says how unusual the pattern is — a reason to look at receipts and CCTV, not proof. Cashiers with fewer than 20 receipts are not scored.">
            <div class="ins-scroll"><table class="ins"><thead><tr><th>Cashier</th><th class="num">Receipts</th><th class="num">Takings</th><th class="num">Refunds</th><th class="num">Refund value</th><th class="num">No-receipt refunds</th><th class="num">Discounts</th><th class="num">Shifts (short)</th><th class="num">Over / short total</th><th class="num">Voids</th><th class="num">Risk</th><th>Why</th></tr></thead><tbody>
            @forelse ($this->loss() as $r)
                <tr><td>{{ $r['name'] }}</td><td class="num">{{ $r['receipts'] }}</td><td class="num">{{ $this->money($r['takings_minor']) }}</td><td class="num">{{ $r['refunds'] }}</td><td class="num">{{ $this->money($r['refund_value_minor']) }}</td>
                    <td class="num">{{ $r['no_receipt_refunds'] }}</td><td class="num">{{ $this->money($r['discount_minor']) }}</td><td class="num">{{ $r['shifts'] }} ({{ $r['short_shifts'] }})</td><td class="num">{{ $this->money($r['over_short_minor']) }}</td>
                    <td class="num">{{ $r['voids'] }}</td><td class="num {{ ($r['risk'] ?? 0) >= 50 ? 'bad' : '' }}"><strong>{{ $r['risk'] ?? '—' }}</strong></td><td>{{ implode(', ', $r['flags']) }}</td></tr>
            @empty
                <tr><td colspan="12">No till sales in the last 30 days.</td></tr>
            @endforelse
            </tbody></table></div>
        </x-filament::section>
    @endif
    <style>
        .ins-scroll { overflow-x: auto; }
        .ins-filter { display: grid; gap: 4px; font-size: .875rem; max-width: 320px; }
        .ins { width: 100%; border-collapse: collapse; font-size: .85rem; } .ins th, .ins td { padding: 6px 8px; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); text-align: left; vertical-align: top; }
        .ins .num { text-align: right; white-space: nowrap; } .ins .bad { color: rgb(220 38 38); font-weight: 600; }
    </style>
</x-filament-panels::page>
