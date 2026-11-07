"""Round 4 (owner 2026-11-07): other typefaces for "ZionEX", with the Modules mark C (teal diagonal). Fonts come from
Google Fonts for comparison only; the chosen one is turned into shapes for the final artwork.
Run: python branding/concepts/make-concepts-4.py, then open concepts-4.html (needs the internet for the fonts).
"""
from pathlib import Path

OUT = Path(__file__).parent
NAVY, TEAL, TEAL_LIGHT, MIST = '#031E4C', '#06AAAA', '#3FD0C9', '#BFEDEA'
Z = {(0, 0), (1, 0), (2, 0), (1, 1), (0, 2), (1, 2), (2, 2)}
DIAG = {(2, 0), (1, 1), (0, 2)}
DEFS = f'''<defs><linearGradient id="t" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{TEAL_LIGHT}"/><stop offset="1" stop-color="{TEAL}"/></linearGradient>
<linearGradient id="n" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0A3172"/><stop offset="1" stop-color="{NAVY}"/></linearGradient></defs>'''


def cells(on_dark):
    out = ''
    for r in range(3):
        for c in range(3):
            fill = 'url(#t)' if (c, r) in DIAG else (('#FFFFFF' if on_dark else NAVY) if (c, r) in Z else ('rgba(255,255,255,.16)' if on_dark else MIST))
            out += f'<rect x="{112 + c * 102}" y="{112 + r * 102}" width="84" height="84" rx="22" fill="{fill}"/>'
    return out


ICON = f'<svg viewBox="0 0 512 512" width="64" height="64">{DEFS}<rect x="8" y="8" width="496" height="496" rx="120" fill="url(#n)"/>{cells(True)}</svg>'
FLAT = f'<svg viewBox="96 96 320 320" width="58" height="58">{DEFS}{cells(False)}</svg>'

# (family, weight, note, letter-spacing)
FONTS = [
    ('Poppins', 700, 'Geometric and round; friendly, very common in African fintech', '-0.01em'),
    ('Montserrat', 800, 'Wide, confident, urban; strong on signs', '-0.01em'),
    ('Plus Jakarta Sans', 800, 'Modern grotesque, crisp and professional', '-0.02em'),
    ('Manrope', 800, 'Clean tech sans with soft corners', '-0.02em'),
    ('Outfit', 700, 'Geometric, airy, contemporary', '-0.01em'),
    ('Sora', 700, 'Technical, slightly wide, product-y', '-0.02em'),
    ('Space Grotesk', 700, 'Engineer\'s grotesque with character (quirky letters)', '-0.02em'),
    ('Lexend', 700, 'Built for readability; calm and clear', '-0.01em'),
    ('Urbanist', 800, 'Sharp geometric, fashionable', '-0.01em'),
    ('Nunito', 800, 'Rounded ends, warm and approachable (closest to Hubtel\'s feel)', '0em'),
    ('Red Hat Display', 800, 'Enterprise-friendly, a little playful', '-0.02em'),
    ('Exo 2', 800, 'Squarish, techy, futuristic', '0em'),
]
families = '&'.join('family=' + f.replace(' ', '+') + f':wght@{w}' for f, w, _, _ in FONTS)

cards = []
for i, (fam, w, note, ls) in enumerate(FONTS, 1):
    name = lambda zion, ex: f'<span class="wm" style="font-family:\'{fam}\';font-weight:{w};letter-spacing:{ls}"><b style="color:{zion}">Zion</b><b style="color:{ex}">EX</b></span>'
    cards.append(f'''<section><h2>{i}. {fam}</h2><p>{note}</p>
  <div class="lk lt">{FLAT}{name(NAVY, TEAL)}</div>
  <div class="lk dk">{ICON}{name('#FFFFFF', TEAL_LIGHT)}</div></section>''')

(OUT / 'concepts-4.html').write_text(f'''<!doctype html><meta charset="utf-8"><title>ZionEX lettering, round 4</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?{families}&display=block">
<style>body{{margin:0;font:14px system-ui;background:#eef3f5;color:#031E4C}} main{{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:12px}}
section{{background:#fff;padding:14px 16px;border-radius:14px}} h2{{margin:0;font-size:16px}} p{{margin:2px 0 10px;color:#4b5a6e;min-height:2.6em}}
.lk{{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:12px;margin-top:8px}} .lt{{border:1px solid #e1e8ec}} .dk{{background:#031E4C}}
.wm{{font-size:46px;line-height:1}} .wm b{{font-weight:inherit}} .dk svg{{border-radius:12px}}</style>
<main>{''.join(cards)}</main>
''', encoding='utf-8')
print('ok')
