"""Builds the product logo files from the SES lettermark (ses-lettermark.svg): logo.svg (light backgrounds),
logo-dark.svg (dark backgrounds: navy becomes light), mark.svg (square icon: the full lettermark on a white tile).
Run after changing the lettermark, then `npm run brand` in pos/."""
import io, re, os
here = os.path.dirname(os.path.abspath(__file__))
src = io.open(os.path.join(here, 'ses-lettermark.svg'), encoding='utf-8').read()
inner = re.search(r'<svg[^>]*>(.*)</svg>', src, re.S).group(1)
inner = re.sub(r'<!--.*?-->', '', inner, flags=re.S).strip()
head = '<svg xmlns="http://www.w3.org/2000/svg" {attrs} role="img" aria-label="SES Labs">\n'

def save(name, body, attrs):
    io.open(os.path.join(here, name), 'w', encoding='utf-8', newline='\n').write(head.format(attrs=attrs) + body + '\n</svg>\n')

save('logo.svg', '  <!-- Generated from ses-lettermark.svg by compose.py -->\n  ' + inner, 'viewBox="0 640 1930 720"')
dark = inner
for navy in ['#031E4C', '#03204F']:
    dark = dark.replace(navy, '#E8EEF6')
dark = dark.replace('id="e"', 'id="e-d"').replace('url(#e)', 'url(#e-d)').replace('id="sweep"', 'id="sweep-d"').replace('url(#sweep)', 'url(#sweep-d)') \
    .replace('id="tail"', 'id="tail-d"').replace('url(#tail)', 'url(#tail-d)').replace('id="body"', 'id="body-d"').replace('url(#body)', 'url(#body-d)').replace('id="bodyPath"', 'id="bodyPath-d"')
dark = dark.replace('#0A5E80', '#5FB7C9').replace('#0A6A84', '#3FBFC4')  # the sweep stays light enough to read on dark
save('logo-dark.svg', '  <!-- Generated from ses-lettermark.svg by compose.py (for dark backgrounds) -->\n  ' + dark, 'viewBox="0 640 1930 720"')
# Square icon: white rounded tile, the lettermark 84 % wide, centred.
w = 512 * 0.84
s = w / 1930
tx = (512 - w) / 2
ty = (512 - 720 * s) / 2 - 640 * s
mark = ('  <!-- Generated from ses-lettermark.svg by compose.py: the full SES on a white tile -->\n'
        '  <rect x="4" y="4" width="504" height="504" rx="112" fill="#FFFFFF" stroke="#D7DEE8" stroke-width="8"/>\n'
        f'  <g transform="translate({tx:.2f} {ty:.2f}) scale({s:.5f})">\n  ' + inner.replace('id="', 'id="m-').replace('url(#', 'url(#m-') + '\n  </g>')
save('mark.svg', mark, 'viewBox="0 0 512 512"')
print('logo.svg, logo-dark.svg, mark.svg written')
