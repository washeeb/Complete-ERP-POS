"""Round 2 of ZionEX concepts (owner 2026-11-07): friendly like Hubtel's lowercase wordmark, saying business / ERP
(modules working together around one core), in navy and teal. Shapes only. Run: python branding/concepts/make-concepts-2.py
"""
from pathlib import Path

OUT = Path(__file__).parent
NAVY, TEAL, TEAL_LIGHT, MIST = '#031E4C', '#06AAAA', '#3FD0C9', '#BFEDEA'

DEFS = f'''<defs>
  <linearGradient id="t" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{TEAL_LIGHT}"/><stop offset="1" stop-color="{TEAL}"/></linearGradient>
  <linearGradient id="n" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0A3172"/><stop offset="1" stop-color="{NAVY}"/></linearGradient>
</defs>'''


def tile(inner, bg='url(#n)'):
    return f'<rect x="8" y="8" width="496" height="496" rx="120" fill="{bg}"/>{inner}'


# 1. Modules: a 3 x 3 grid of rounded squares, like the dashboard of an ERP. The cells of a Z are solid, the rest faint;
#    the centre (the core every module shares) is teal.
Z_CELLS = {(0, 0), (1, 0), (2, 0), (1, 1), (0, 2), (1, 2), (2, 2)}
def modules(solid, faint):
    out = ''
    for r in range(3):
        for c in range(3):
            fill = 'url(#t)' if (c, r) == (1, 1) else (solid if (c, r) in Z_CELLS else faint)
            out += f'<rect x="{112 + c * 102}" y="{112 + r * 102}" width="84" height="84" rx="22" fill="{fill}"/>'
    return out

# 2. Ledger: three rounded slabs stacked into a Z (operations, money, people), the middle one teal: the books that
#    connect them.
def ledger(top, mid, bottom):
    return (f'<rect x="120" y="128" width="272" height="64" rx="32" fill="{top}"/>'
            f'<rect x="96" y="224" width="320" height="64" rx="32" fill="{mid}" transform="rotate(-38 256 256)"/>'
            f'<rect x="120" y="320" width="272" height="64" rx="32" fill="{bottom}"/>')

# 3. Hub: four arcs (sales, stock, money, people) around a lowercase z at the centre: everything connected to one core.
def hub(ring, core, accent):
    arcs = ''
    for i, start in enumerate([-80, 10, 100, 190]):
        import math
        a0, a1 = math.radians(start), math.radians(start + 70)
        x0, y0, x1, y1 = 256 + 170 * math.cos(a0), 256 + 170 * math.sin(a0), 256 + 170 * math.cos(a1), 256 + 170 * math.sin(a1)
        arcs += f'<path d="M{x0:.1f} {y0:.1f} A170 170 0 0 1 {x1:.1f} {y1:.1f}" fill="none" stroke="{accent if i == 0 else ring}" stroke-width="46" stroke-linecap="round"/>'
    return arcs + f'<path d="M206 200 H306 L206 312 H306" fill="none" stroke="{core}" stroke-width="44" stroke-linecap="round" stroke-linejoin="round"/>'


# The name: lowercase, rounded strokes (friendly), "zion" in navy, "ex" in teal, the i's dot teal. x-height 60.
def wordmark(zion, ex):
    s = 'fill="none" stroke-width="20" stroke-linecap="round" stroke-linejoin="round"'
    return (f'<g {s} stroke="{zion}">'
            '<path d="M24 64 H68 L24 124 H70"/>'
            '<path d="M98 66 V124"/>'
            '<circle cx="150" cy="94" r="28"/>'
            '<path d="M206 124 V66 M206 96 C206 60 260 60 260 96 V124"/></g>'
            f'<circle cx="98" cy="36" r="12" fill="{TEAL}"/>'
            f'<g {s} stroke="{ex}" transform="translate(-4 0)">'
            '<path d="M296 96 H350 C350 58 296 58 296 94 C296 130 340 132 350 116"/>'
            '<path d="M374 66 L420 124 M420 66 L374 124"/></g>')


OPTIONS = [
    ('1', 'Modules', 'A grid of modules, like an ERP dashboard. The solid cells spell a Z; the teal centre is the core every module shares.', modules('#FFFFFF', 'rgba(255,255,255,.18)'), modules(NAVY, MIST)),
    ('2', 'Ledger', 'Three rounded slabs stacked into a Z: operations, money and people, joined by the books in the middle (teal). Solid, like a business should be.', ledger('#FFFFFF', 'url(#t)', '#FFFFFF'), ledger(NAVY, 'url(#t)', NAVY)),
    ('3', 'Hub', 'Four modules (sales, stock, money, people) around one core, the z. Everything connected: what an ERP is.', hub('#FFFFFF', '#FFFFFF', 'url(#t)'), hub(NAVY, NAVY, 'url(#t)')),
]

cards = []
for key, title, why, on_tile, flat in OPTIONS:
    icon = lambda w: f'<svg viewBox="0 0 512 512" width="{w}" height="{w}">{DEFS}{tile(on_tile)}</svg>'
    lock_light = f'<svg viewBox="0 0 640 160" width="420">{DEFS}<g transform="translate(-14 4) scale(0.3)">{flat}</g><g transform="translate(140 0)">{wordmark(NAVY, TEAL)}</g></svg>'
    lock_dark = f'<svg viewBox="0 0 640 160" width="420">{DEFS}<g transform="translate(0 16) scale(0.25)">{tile(on_tile)}</g><g transform="translate(150 0)">{wordmark("#FFFFFF", TEAL_LIGHT)}</g></svg>'
    (OUT / f'round2-{key}-mark.svg').write_text(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">{DEFS}{tile(on_tile)}</svg>\n', encoding='utf-8')
    cards.append(f'''<section><h2>{key}. {title}</h2><p>{why}</p>
  <div class="row">{icon(150)}{icon(48)}{icon(32)}{icon(16)}<span class="flat"><svg viewBox="70 70 372 372" width="120" height="120">{DEFS}{flat}</svg></span></div>
  <div class="row"><span class="lt">{lock_light}</span><span class="dk">{lock_dark}</span></div></section>''')

(OUT / 'concepts-2.html').write_text(f'''<!doctype html><meta charset="utf-8"><title>ZionEX concepts, round 2</title>
<style>body{{margin:0;font:15px system-ui;background:#eef3f5;color:#031E4C}} section{{background:#fff;margin:18px;padding:20px 24px;border-radius:16px}}
h2{{margin:0 0 4px}} p{{margin:0 0 14px;color:#4b5a6e;max-width:70ch}} .row{{display:flex;gap:22px;align-items:center;flex-wrap:wrap;margin:10px 0}}
.flat{{border:1px dashed #cbd6de;border-radius:12px;padding:6px}} .lt{{padding:10px;border:1px solid #e1e8ec;border-radius:12px}}
.dk{{padding:10px;background:#031E4C;border-radius:12px}}</style>
{''.join(cards)}
''', encoding='utf-8')
print('ok')
