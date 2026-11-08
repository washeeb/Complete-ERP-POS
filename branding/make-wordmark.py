"""Turns "ZionEX" set in Sora Bold (owner's choice, 2026-11-07) into SVG shapes, saved in branding/wordmark.json for
make-artwork.py. Run only when the lettering changes; it needs the Sora font file and fonttools:

    pip install fonttools
    python branding/make-wordmark.py path/to/Sora[wght].ttf

Sora is under the SIL Open Font License (github.com/google/fonts, ofl/sora), which allows it in a logo. The font file
itself is not kept in the repository: only these shapes are.
"""
import json
import sys
from pathlib import Path

from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont

WEIGHT = 700
TRACKING = -0.02  # em, as on the comparison sheet

font = TTFont(sys.argv[1])
if 'fvar' in font:
    font = instantiateVariableFont(font, {'wght': WEIGHT})
glyphs = font.getGlyphSet()
cmap = font.getBestCmap()
upm = font['head'].unitsPerEm
cap = font['OS/2'].sCapHeight

parts, x = {}, 0
for part in ('Zion', 'EX'):
    d = []
    for ch in part:
        name = cmap[ord(ch)]
        pen = SVGPathPen(glyphs)
        # Font units to SVG: y points down, the baseline at the cap height (so the caps sit from 0 to `cap`).
        glyphs[name].draw(TransformPen(pen, (1, 0, 0, -1, x, cap)))
        d.append(pen.getCommands())
        x += glyphs[name].width + TRACKING * upm
    parts[part] = ' '.join(d)

out = {'font': f'Sora {WEIGHT}', 'units_per_em': upm, 'cap_height': cap, 'width': round(x - TRACKING * upm), **parts}
(Path(__file__).parent / 'wordmark.json').write_text(json.dumps(out, indent=1) + '\n', encoding='utf-8')
print(f"cap {cap}, width {out['width']}, Zion {len(parts['Zion'])} chars, EX {len(parts['EX'])} chars")
