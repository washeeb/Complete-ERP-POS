"""Three directions for the ZionEX mark (owner 2026-11-07: "authentic and tech vibe"). Each is drawn as shapes on a
512 grid, with a matching wordmark, and shown on one sheet (concepts.html) to choose from. The chosen one then becomes
branding/make-artwork.py. Run: python branding/concepts/make-concepts.py
"""
from pathlib import Path

OUT = Path(__file__).parent
NAVY, INK, BLUE, CYAN, ORANGE = '#0B1F5C', '#0A1024', '#1D63FF', '#22D3EE', '#FF8A00'

DEFS = f'''<defs>
  <linearGradient id="g-elec" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{CYAN}"/><stop offset="1" stop-color="{BLUE}"/></linearGradient>
  <linearGradient id="g-tile" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#14245E"/><stop offset="1" stop-color="{INK}"/></linearGradient>
  <radialGradient id="g-glow"><stop offset="0" stop-color="{ORANGE}" stop-opacity=".55"/><stop offset="1" stop-color="{ORANGE}" stop-opacity="0"/></radialGradient>
</defs>'''


def tile(inner):
    return f'<rect x="8" y="8" width="496" height="496" rx="112" fill="url(#g-tile)"/>{inner}'


# A. Circuit: the Z is one copper trace with 45-degree bends, pads at both ends and a live signal (orange) on it.
A = ('<path d="M120 150 H330 L390 150 L170 370 H392" fill="none" stroke="url(#g-elec)" stroke-width="44" stroke-linecap="round" stroke-linejoin="round"/>'
     '<circle cx="120" cy="150" r="34" fill="#0A1024" stroke="url(#g-elec)" stroke-width="18"/>'
     '<circle cx="392" cy="370" r="34" fill="#0A1024" stroke="url(#g-elec)" stroke-width="18"/>'
     '<circle cx="280" cy="260" r="60" fill="url(#g-glow)"/><circle cx="280" cy="260" r="20" fill="#FF8A00"/>')

# B. Data blocks: the Z built from a 5 x 5 grid of cells, one cell lit orange where the diagonal meets the base.
CELLS = [(0, 0), (1, 0), (2, 0), (3, 0), (4, 0), (3, 1), (2, 2), (1, 3), (0, 4), (1, 4), (2, 4), (3, 4), (4, 4)]
B = ''.join(f'<rect x="{106 + c * 62}" y="{106 + r * 62}" width="52" height="52" rx="10" fill="{ORANGE if (c, r) == (1, 3) else "url(#g-elec)"}"/>'
            for c, r in CELLS)

# C. Command line: a bold Z cut by a slash, with an orange cursor block, as typed at a prompt.
C = ('<path d="M118 136 H360 L190 360 H330" fill="none" stroke="url(#g-elec)" stroke-width="54" stroke-linejoin="miter" stroke-linecap="butt"/>'
     '<rect x="352" y="318" width="54" height="64" rx="6" fill="#FF8A00"/>')


def wordmark(fill, accent, style):
    # Shared geometric letters (no font), with each direction's own touch on the "i" and after the name.
    z = '<polygon points="20,40 94,40 94,58 46,106 96,106 96,124 20,124 20,106 68,58 20,58"/>'
    i_stem = '<rect x="112" y="64" width="20" height="60"/>'
    o = f'<circle cx="180" cy="94" r="21" fill="none" stroke="{fill}" stroke-width="18"/>'
    n = f'<path d="M233 124 V64 M233 92 A23 23 0 0 1 279 92 V124" fill="none" stroke="{fill}" stroke-width="18"/>'
    e = '<rect x="308" y="40" width="20" height="84"/><rect x="308" y="40" width="68" height="18"/><rect x="308" y="73" width="60" height="18"/><rect x="308" y="106" width="68" height="18"/>'
    x = '<polygon points="388,40 412,40 474,124 450,124"/><polygon points="450,40 474,40 412,124 388,124"/>'
    dot = {'A': f'<circle cx="122" cy="46" r="11" fill="{accent}"/>',
           'B': f'<rect x="112" y="36" width="20" height="20" rx="4" fill="{accent}"/>',
           'C': f'<rect x="112" y="36" width="20" height="20" fill="{accent}"/><rect x="488" y="108" width="34" height="16" fill="{accent}"/>'}[style]
    return f'<g fill="{fill}">{z}{i_stem}</g>{o}{n}<g fill="url(#g-elec)">{e}{x}</g>{dot}'


ROWS = [('A', 'Circuit', 'The Z as one copper trace with pads at both ends; the orange dot is the live signal. Engineering, connected, "the system that runs the business".', A),
        ('B', 'Data blocks', 'The Z built from data cells, one lit orange. Digital, structured, modular: every part of the business as a block.', B),
        ('C', 'Command line', 'A bold Z with an orange cursor, as typed at a prompt. Software-native, fast, made by engineers.', C)]

cards = []
for key, title, why, shapes in ROWS:
    mark = f'<svg viewBox="0 0 512 512" width="{{w}}" height="{{w}}">{DEFS}{tile(shapes)}</svg>'
    flat = f'<svg viewBox="70 70 372 372" width="120" height="120">{DEFS}{shapes}</svg>'
    light = f'<svg viewBox="0 0 540 160" width="360">{DEFS}{wordmark(NAVY, ORANGE, key)}</svg>'
    dark = f'<svg viewBox="0 0 540 160" width="360">{DEFS}{wordmark("#FFFFFF", ORANGE, key)}</svg>'
    (OUT / f'concept-{key}-mark.svg').write_text(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">{DEFS}{tile(shapes)}</svg>\n', encoding='utf-8')
    cards.append(f'''<section><h2>{key}. {title}</h2><p>{why}</p>
  <div class="row">{mark.replace('{w}', '150')}{mark.replace('{w}', '48')}{mark.replace('{w}', '32')}{mark.replace('{w}', '16')}<span class="flat">{flat}</span></div>
  <div class="row"><span class="lt">{light}</span><span class="dk">{dark}</span></div></section>''')

(OUT / 'concepts.html').write_text(f'''<!doctype html><meta charset="utf-8"><title>ZionEX concepts</title>
<style>body{{margin:0;font:15px system-ui;background:#eef1f7;color:#0A1024}} section{{background:#fff;margin:18px;padding:20px 24px;border-radius:16px}}
h2{{margin:0 0 4px}} p{{margin:0 0 14px;color:#4b5570;max-width:70ch}} .row{{display:flex;gap:22px;align-items:center;flex-wrap:wrap;margin:10px 0}}
.flat{{background:#fff;border:1px dashed #cbd2e1;border-radius:12px;padding:6px}} .lt{{padding:10px;border:1px solid #e3e7f0;border-radius:12px}}
.dk{{padding:10px;background:#0A1024;border-radius:12px}}</style>
{''.join(cards)}
''', encoding='utf-8')
print('ok')
