"""Round 3 (owner 2026-11-07): the Modules direction, in variations, with the system's original lettering (the first
ZionEX wordmark: "Zion" navy, "EX" teal, rounded strokes). Navy and teal. Run: python branding/concepts/make-concepts-3.py
"""
from pathlib import Path

OUT = Path(__file__).parent
NAVY, TEAL, TEAL_LIGHT, MIST = '#031E4C', '#06AAAA', '#3FD0C9', '#BFEDEA'
DEFS = f'''<defs>
  <linearGradient id="t" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{TEAL_LIGHT}"/><stop offset="1" stop-color="{TEAL}"/></linearGradient>
  <linearGradient id="n" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0A3172"/><stop offset="1" stop-color="{NAVY}"/></linearGradient>
</defs>'''
Z = {(0, 0), (1, 0), (2, 0), (1, 1), (0, 2), (1, 2), (2, 2)}
DIAG = {(2, 0), (1, 1), (0, 2)}


def cell(c, r, fill, size=84, step=102, start=112, rx=22, extra=''):
    return f'<rect x="{start + c * step}" y="{start + r * step}" width="{size}" height="{size}" rx="{rx}" fill="{fill}"{extra}/>'


def grid(fill_for):
    return ''.join(cell(c, r, f) for r in range(3) for c in range(3) if (f := fill_for(c, r)))


# Each variation: (title, idea, shapes on the navy tile, shapes on a light background)
V = [
    ('A', 'Dashboard', 'All nine modules; the Z cells solid, the others faint; the core teal (round 2).',
     grid(lambda c, r: 'url(#t)' if (c, r) == (1, 1) else ('#FFFFFF' if (c, r) in Z else 'rgba(255,255,255,.16)')),
     grid(lambda c, r: 'url(#t)' if (c, r) == (1, 1) else (NAVY if (c, r) in Z else MIST))),
    ('B', 'Z of modules', 'Only the seven modules that make the Z; the core teal. The Z reads at once.',
     grid(lambda c, r: ('url(#t)' if (c, r) == (1, 1) else '#FFFFFF') if (c, r) in Z else None),
     grid(lambda c, r: ('url(#t)' if (c, r) == (1, 1) else NAVY) if (c, r) in Z else None)),
    ('C', 'Teal diagonal', 'The Z\'s diagonal is teal (the flow from top to bottom), the bars white; the two spare cells faint.',
     grid(lambda c, r: 'url(#t)' if (c, r) in DIAG else ('#FFFFFF' if (c, r) in Z else 'rgba(255,255,255,.16)')),
     grid(lambda c, r: 'url(#t)' if (c, r) in DIAG else (NAVY if (c, r) in Z else MIST))),
    ('D', 'Outlined spares', 'The Z solid, the two spare modules as outlines: room to add more of the business.',
     grid(lambda c, r: 'url(#t)' if (c, r) == (1, 1) else '#FFFFFF' if (c, r) in Z else None)
     + ''.join(cell(c, r, 'none', extra=' stroke="#FFFFFF" stroke-opacity=".55" stroke-width="10"') for c, r in [(0, 1), (2, 1)]),
     grid(lambda c, r: 'url(#t)' if (c, r) == (1, 1) else NAVY if (c, r) in Z else None)
     + ''.join(cell(c, r, 'none', extra=f' stroke="{TEAL}" stroke-width="10"') for c, r in [(0, 1), (2, 1)])),
    ('E', 'Bento', 'Modules of different sizes, like an app\'s dashboard; a wide top and bottom bar make the Z, the teal core between.',
     '<rect x="112" y="112" width="288" height="84" rx="24" fill="#FFFFFF"/><rect x="112" y="214" width="84" height="84" rx="22" fill="rgba(255,255,255,.16)"/>'
     '<rect x="214" y="214" width="84" height="84" rx="22" fill="url(#t)"/><rect x="316" y="214" width="84" height="84" rx="22" fill="rgba(255,255,255,.16)"/>'
     '<rect x="112" y="316" width="288" height="84" rx="24" fill="#FFFFFF"/>',
     f'<rect x="112" y="112" width="288" height="84" rx="24" fill="{NAVY}"/><rect x="112" y="214" width="84" height="84" rx="22" fill="{MIST}"/>'
     '<rect x="214" y="214" width="84" height="84" rx="22" fill="url(#t)"/>'
     f'<rect x="316" y="214" width="84" height="84" rx="22" fill="{MIST}"/><rect x="112" y="316" width="288" height="84" rx="24" fill="{NAVY}"/>'),
    ('F', 'Dots', 'Round modules (nodes) instead of squares: softer, like Hubtel; the Z in solid dots, the core teal and larger.',
     ''.join(f'<circle cx="{154 + c * 102}" cy="{154 + r * 102}" r="{50 if (c, r) == (1, 1) else 40}" fill="{"url(#t)" if (c, r) == (1, 1) else ("#FFFFFF" if (c, r) in Z else "rgba(255,255,255,.16)")}"/>' for r in range(3) for c in range(3)),
     ''.join(f'<circle cx="{154 + c * 102}" cy="{154 + r * 102}" r="{50 if (c, r) == (1, 1) else 40}" fill="{"url(#t)" if (c, r) == (1, 1) else (NAVY if (c, r) in Z else MIST)}"/>' for r in range(3) for c in range(3))),
]


def original_wordmark(zion, ex):
    # The system's original lettering, unchanged: rounded strokes, "Zion" and "EX".
    return (f'<g fill="none" stroke-width="17" stroke-linecap="round" stroke-linejoin="round"><g stroke="{zion}">'
            '<path d="M192 38 H262 L192 122 H264"/><path d="M296 70 V122"/><circle cx="350" cy="96" r="27"/>'
            '<path d="M408 70 V122 M408 96 A26 26 0 0 1 460 96 V122"/></g>'
            f'<g stroke="{ex}"><path d="M560 38 H502 V122 H560 M502 80 H550"/><path d="M590 38 L656 122 M656 38 L590 122"/></g></g>'
            f'<circle cx="296" cy="44" r="10" fill="{zion}"/>')


def tile(inner):
    return f'<rect x="8" y="8" width="496" height="496" rx="120" fill="url(#n)"/>{inner}'


cards = []
for key, title, why, on_tile, light in V:
    icon = lambda w: f'<svg viewBox="0 0 512 512" width="{w}" height="{w}">{DEFS}{tile(on_tile)}</svg>'
    lock = lambda inner, zion, ex: f'<svg viewBox="0 0 700 160" width="420">{DEFS}<g transform="translate(0 5) scale(0.293)">{inner}</g>{original_wordmark(zion, ex)}</svg>'
    (OUT / f'round3-{key}-mark.svg').write_text(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">{DEFS}{tile(on_tile)}</svg>\n', encoding='utf-8')
    cards.append(f'''<section><h2>{key}. {title}</h2><p>{why}</p>
  <div class="row">{icon(130)}{icon(48)}{icon(32)}{icon(16)}<span class="flat"><svg viewBox="96 96 320 320" width="100" height="100">{DEFS}{light}</svg></span></div>
  <div class="row"><span class="lt">{lock(tile(on_tile), NAVY, TEAL)}</span><span class="lt">{lock(light, NAVY, TEAL)}</span><span class="dk">{lock(tile(on_tile), "#FFFFFF", TEAL_LIGHT)}</span></div></section>''')

(OUT / 'concepts-3.html').write_text(f'''<!doctype html><meta charset="utf-8"><title>ZionEX modules, round 3</title>
<style>body{{margin:0;font:14px system-ui;background:#eef3f5;color:#031E4C}} main{{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:14px}}
section{{background:#fff;padding:16px 18px;border-radius:16px}} h2{{margin:0 0 2px;font-size:18px}} p{{margin:0 0 8px;color:#4b5a6e}}
.row{{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin:8px 0}} .flat{{border:1px dashed #cbd6de;border-radius:12px;padding:6px}}
.lt{{padding:6px;border:1px solid #e1e8ec;border-radius:12px}} .dk{{padding:6px;background:#031E4C;border-radius:12px}} .row svg[width="420"]{{width:300px;height:auto}}</style>
<main>{''.join(cards)}</main>
''', encoding='utf-8')
print('ok')
