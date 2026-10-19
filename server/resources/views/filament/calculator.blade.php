{{-- Back-office calculator in the top bar: + − × ÷, brackets and percent (200 + 10% = 220), without eval. --}}
<div x-data="sesCalc()" class="ses-calc" @keydown.escape.window="open = false">
    <button type="button" class="ses-calc-btn" @click="open = !open; $nextTick(() => open && $refs.input.focus())" title="Calculator" aria-label="Calculator">
        <x-filament::icon icon="heroicon-o-calculator" class="h-5 w-5" />
    </button>
    <div x-show="open" x-cloak @click.outside="open = false" class="ses-calc-panel" role="dialog" aria-label="Calculator">
        <input x-ref="input" x-model="expr" @keydown.enter.prevent="equals()" class="ses-calc-input" inputmode="decimal" placeholder="0" aria-label="Sum">
        <div class="ses-calc-result" x-text="error || (result() !== null ? '= ' + result().toLocaleString('en-GB', { maximumFractionDigits: 6 }) : '')"></div>
        <div class="ses-calc-keys">
            <template x-for="k in ['C','(',')','⌫','7','8','9','÷','4','5','6','×','1','2','3','−','0','.','%','+']" :key="k">
                <button type="button" @click="press(k)" x-text="k"></button>
            </template>
            <button type="button" class="ses-calc-eq" @click="equals()">=</button>
        </div>
        <button type="button" class="ses-calc-copy" x-show="result() !== null" @click="navigator.clipboard?.writeText(String(result()))">Copy result</button>
    </div>
</div>
<style>
    .ses-calc { position: relative; }
    .ses-calc-btn { display: inline-flex; padding: .4rem; border-radius: .5rem; color: inherit; opacity: .8; }
    .ses-calc-btn:hover { opacity: 1; background: color-mix(in srgb, currentColor 8%, transparent); }
    .ses-calc-panel { position: absolute; right: 0; top: 2.5rem; z-index: 50; width: 16rem; padding: .75rem; border-radius: .75rem;
        background: var(--color-white, #fff); color: #111; box-shadow: 0 10px 30px rgba(0,0,0,.25); }
    .dark .ses-calc-panel { background: #18181b; color: #f4f4f5; }
    .ses-calc-input { width: 100%; text-align: right; font: 600 1.1rem ui-monospace, monospace; padding: .4rem .5rem; border-radius: .4rem;
        border: 1px solid color-mix(in srgb, currentColor 20%, transparent); background: transparent; color: inherit; }
    .ses-calc-result { text-align: right; min-height: 1.4em; margin: .3rem 0; font: 1rem ui-monospace, monospace; }
    .ses-calc-keys { display: grid; grid-template-columns: repeat(4, 1fr); gap: .3rem; }
    .ses-calc-keys button { padding: .5rem 0; border-radius: .4rem; background: color-mix(in srgb, currentColor 7%, transparent); }
    .ses-calc-keys button:hover { background: color-mix(in srgb, currentColor 14%, transparent); }
    .ses-calc-eq { grid-column: span 4; background: rgb(var(--primary-600, 79 70 229)) !important; color: #fff; }
    .ses-calc-copy { margin-top: .4rem; font-size: .8rem; text-decoration: underline; }
</style>
<script>
    function sesCalc() {
        const calc = (input) => {
            const s = input.replace(/×/g, '*').replace(/÷/g, '/').replace(/−/g, '-').replace(/,/g, '').replace(/\s+/g, '');
            let i = 0;
            const fail = () => { throw new Error('Check the sum'); };
            const num = () => { const m = /^\d*\.?\d+|^\d+\./.exec(s.slice(i)); if (!m) fail(); i += m[0].length; return Number(m[0]); };
            const factor = () => {
                if (s[i] === '-') { i++; return -factor(); }
                if (s[i] === '+') { i++; return factor(); }
                let v;
                if (s[i] === '(') { i++; v = expr(); if (s[i] !== ')') fail(); i++; } else v = num();
                while (s[i] === '%') { i++; v = v / 100; }
                return v;
            };
            const term = () => {
                let v = factor();
                while (s[i] === '*' || s[i] === '/') { const op = s[i++]; const r = factor(); if (op === '/' && r === 0) throw new Error('Cannot divide by zero'); v = op === '*' ? v * r : v / r; }
                return v;
            };
            const expr = () => {
                let v = term();
                while (s[i] === '+' || s[i] === '-') { const op = s[i++]; const start = i; let r = term(); if (s[i - 1] === '%' && !s.slice(start, i).includes('(')) r = v * r; v = op === '+' ? v + r : v - r; }
                return v;
            };
            if (s === '') return null;
            const v = expr();
            if (i !== s.length || !Number.isFinite(v)) fail();
            return Math.round(v * 1e10) / 1e10;
        };
        return {
            open: false, expr: '', error: '',
            result() { try { return calc(this.expr); } catch (e) { return null; } },
            press(k) {
                this.error = '';
                if (k === 'C') return this.expr = '';
                if (k === '⌫') return this.expr = this.expr.slice(0, -1);
                this.expr += k;
            },
            equals() { try { const r = calc(this.expr); this.expr = r === null ? '' : String(r); this.error = ''; } catch (e) { this.error = e.message; } },
        };
    }
</script>
