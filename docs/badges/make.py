"""Quiet Insignia — 創設メンバーの徽章（4案）を SVG で作る。"""
import base64
import math
import os

OUT = os.environ.get('BADGE_OUT', os.path.dirname(os.path.abspath(__file__)))
FONTS = '/root/.claude/skills/synced/e4dfc23c-6473-46e6-a92b-56390bdf3762_9f3746e8-d2dd-4b63-b2b0-f4bc8be1b228/canvas-design/canvas-fonts/'
C = 500.0

F_PATH = ('M28.5 35.2C28.5 31.2 31.6 28 35.6 28H60.6V25.2C60.6 23.6 62.4 22.8 63.6 23.8L73.6 31.8C74.8 32.8 74.8 34.4 73.6 35.4'
          'L63.6 43.2C62.4 44.1 60.6 43.3 60.6 41.8V39.5H43.9C42.6 39.5 41.6 40.5 41.6 41.8V48.3H56.3C58.4 48.3 60 50.4 60 53.1'
          'V54.2C60 56.9 58.4 59 56.3 59H43.8C42.6 59 41.6 60 41.6 61.2V71.9C41.6 75.2 39.4 77.3 36 77.3H34C30.8 77.3 28.5 74.8 28.5 71.8Z')
DOT = (66.4, 70.7, 6.6)

METALS = {
    'gold': ['#5c4210', '#9c7424', '#d9b45a', '#f6e3a3', '#e2c06d', '#a87b26', '#6e4e14'],
    'platinum': ['#5d6470', '#9aa3ae', '#d9dee4', '#f7f9fb', '#cfd5dc', '#8e98a4', '#565d68'],
}


from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
import json

_FONTS = {}


def _font(file):
    if file not in _FONTS:
        f = TTFont(FONTS + file)
        _FONTS[file] = (f, f.getGlyphSet(), f.getBestCmap(), f['head'].unitsPerEm)
    return _FONTS[file]


def glyph(file, ch):
    f, gs, cmap, upm = _font(file)
    name = cmap.get(ord(ch))
    if not name:
        return '', 0, upm
    pen = SVGPathPen(gs, lambda v: f'{v:.0f}')
    gs[name].draw(pen)
    return pen.getCommands(), gs[name].width, upm


def arc_text(text, file, size, tracking, r, fill, top=True, opacity=1.0):
    items = [glyph(file, ch) for ch in text]
    upm = items[0][2]
    sc = size / upm
    widths = [w * sc for _, w, _ in items]
    total = sum(widths) + tracking * (len(items) - 1)
    span = math.degrees(total / r)
    out = []
    pos = 0.0
    for (d, w, _), gw in zip(items, widths):
        mid = pos + gw / 2
        a = math.degrees(mid / r)
        theta = (-span / 2 + a) if top else (180 + span / 2 - a)
        x, y = pol(r, theta)
        rot = theta if top else theta - 180
        if d:
            out.append(f'<path d="{d}" transform="translate({x:.2f} {y:.2f}) rotate({rot:.2f}) scale({sc:.5f} {-sc:.5f}) translate({-w / 2:.0f} 0)"/>')
        pos += gw + tracking
    return f'<g fill="{fill}" opacity="{opacity}">' + ''.join(out) + '</g>'


def line_text(text, file, size, tracking, cx, baseline, fill):
    items = [glyph(file, ch) for ch in text]
    upm = items[0][2]
    sc = size / upm
    total = sum(w * sc for _, w, _ in items) + tracking * (len(items) - 1)
    x = cx - total / 2
    out = []
    for d, w, _ in items:
        if d:
            out.append(f'<path d="{d}" transform="translate({x:.2f} {baseline:.2f}) scale({sc:.5f} {-sc:.5f})"/>')
        x += w * sc + tracking
    return f'<g fill="{fill}">' + ''.join(out) + '</g>'


def font_face(name, file):
    data = base64.b64encode(open(FONTS + file, 'rb').read()).decode()
    return f"@font-face{{font-family:'{name}';src:url(data:font/ttf;base64,{data}) format('truetype');}}"


def metal_defs(metal, uid):
    s = METALS[metal]
    stops = ''.join(f'<stop offset="{i / (len(s) - 1):.3f}" stop-color="{c}"/>' for i, c in enumerate(s))
    rev = ''.join(f'<stop offset="{i / (len(s) - 1):.3f}" stop-color="{c}"/>' for i, c in enumerate(reversed(s)))
    return (f'<linearGradient id="{uid}m" x1="0.12" y1="0.04" x2="0.88" y2="0.96">{stops}</linearGradient>'
            f'<linearGradient id="{uid}r" x1="0.12" y1="0.04" x2="0.88" y2="0.96">{rev}</linearGradient>'
            f'<linearGradient id="{uid}s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{s[3]}"/><stop offset="0.5" stop-color="{s[2]}"/><stop offset="1" stop-color="{s[5]}"/></linearGradient>')


def pol(r, deg):
    a = math.radians(deg - 90)
    return C + r * math.cos(a), C + r * math.sin(a)


def ticks(r_out, color, opacity=1.0):
    out = []
    for i in range(120):
        deg = i * 3
        major = i % 10 == 0
        mid = i % 5 == 0
        length = 22 if major else (15 if mid else 9)
        width = 3.2 if major else (2.0 if mid else 1.1)
        x1, y1 = pol(r_out, deg)
        x2, y2 = pol(r_out - length, deg)
        out.append(f'<line x1="{x1:.2f}" y1="{y1:.2f}" x2="{x2:.2f}" y2="{y2:.2f}" stroke="{color}" stroke-width="{width}" stroke-linecap="round" opacity="{opacity}"/>')
    return ''.join(out)


def arc_paths(uid, r):
    # 上: 左から右へ（時計回り）、下: 左から右へ読めるよう反時計回り。
    top = f'<path id="{uid}t" d="M {C - r:.2f} {C:.2f} A {r} {r} 0 0 1 {C + r:.2f} {C:.2f}"/>'
    rb = r + 24
    bottom = f'<path id="{uid}b" d="M {C - rb:.2f} {C:.2f} A {rb} {rb} 0 0 0 {C + rb:.2f} {C:.2f}"/>'
    return top + bottom


def sunburst(r_in, r_out, color):
    out = []
    for i in range(360):
        deg = i
        x1, y1 = pol(r_in, deg)
        x2, y2 = pol(r_out, deg)
        op = 0.55 if i % 2 == 0 else 0.22
        out.append(f'<line x1="{x1:.2f}" y1="{y1:.2f}" x2="{x2:.2f}" y2="{y2:.2f}" stroke="{color}" stroke-width="0.9" opacity="{op}"/>')
    return ''.join(out)


def waves(r_in, r_out, color, n=14, amp=3.2, count=26):
    out = []
    for k in range(count):
        r = r_in + (r_out - r_in) * k / (count - 1)
        pts = []
        for j in range(0, 361, 2):
            t = math.radians(j)
            rr = r + amp * math.sin(n * t + k * 0.55)
            pts.append(f'{C + rr * math.cos(t):.1f},{C + rr * math.sin(t):.1f}')
        out.append(f'<polyline points="{" ".join(pts)}" fill="none" stroke="{color}" stroke-width="1" opacity="{0.25 + 0.3 * (k % 2)}"/>')
    return ''.join(out)


def lattice(r, color, step=22):
    out = []
    span = int(r * 1.5)
    for k in range(-span, span + 1, step):
        out.append(f'<line x1="{C + k - span:.1f}" y1="{C - span:.1f}" x2="{C + k + span:.1f}" y2="{C + span:.1f}" stroke="{color}" stroke-width="1" opacity="0.35"/>')
        out.append(f'<line x1="{C + k + span:.1f}" y1="{C - span:.1f}" x2="{C + k - span:.1f}" y2="{C + span:.1f}" stroke="{color}" stroke-width="1" opacity="0.35"/>')
    return ''.join(out)


def rings(r_in, r_out, color, step=4):
    return ''.join(f'<circle cx="{C}" cy="{C}" r="{r:.1f}" fill="none" stroke="{color}" stroke-width="0.7" opacity="{0.18 if (r // step) % 2 else 0.32}"/>' for r in [r_in + i * step for i in range(int((r_out - r_in) / step) + 1)])


def laurel(fill, r=228, start=204, end=302, leaves=8):
    """左右2本の枝。下の中央から左上・右上へ伸び、葉は枝の向きにそろえて外・内に交互に付く。"""
    out = []
    for side in (-1, 1):
        def ang(t):
            deg = start + (end - start) * t
            return deg if side < 0 else 360 - deg
        stem = ' '.join('%.2f,%.2f' % pol(r, ang(j / 48)) for j in range(49))
        out.append(f'<polyline points="{stem}" fill="none" stroke="{fill}" stroke-width="3.2" stroke-linecap="round"/>')
        for i in range(leaves):
            t = (i + 0.7) / (leaves + 0.4)
            d = ang(t)
            size = 1.05 - 0.4 * t
            heading = d if side < 0 else d + 180
            for off in (1, -1):
                rr = r + off * 15 * size
                x, y = pol(rr, d)
                tilt = heading - off * 32 * (1 if side < 0 else -1)
                out.append(f'<ellipse cx="{x:.2f}" cy="{y:.2f}" rx="{19 * size:.2f}" ry="{7.2 * size:.2f}" transform="rotate({tilt:.2f} {x:.2f} {y:.2f})" fill="{fill}"/>')
        tx, ty = pol(r, ang(1.02))
        out.append(f'<ellipse cx="{tx:.2f}" cy="{ty:.2f}" rx="13" ry="5.5" transform="rotate({(ang(1.02) if side < 0 else ang(1.02) + 180):.2f} {tx:.2f} {ty:.2f})" fill="{fill}"/>')
    return ''.join(out)


def mark(uid, fill, scale=3.2, cy=C, dot_fill=None):
    # F は 100x100 の中で中心が (51, 50) 付近。
    tx = C - 51 * scale
    ty = cy - 50 * scale
    dot = DOT
    dfill = dot_fill or f'url(#{uid}g)'
    return (f'<g transform="translate({tx:.2f} {ty:.2f}) scale({scale})">'
            f'<path d="{F_PATH}" fill="{fill}"/>'
            f'<circle cx="{dot[0]}" cy="{dot[1]}" r="{dot[2]}" fill="{dfill}"/>'
            f'<circle cx="{dot[0] - 2}" cy="{dot[1] - 2.2}" r="1.8" fill="#ffffff" opacity="0.75"/></g>')


GEM = '<radialGradient id="{u}g" cx="0.38" cy="0.32" r="0.75"><stop offset="0" stop-color="#b9ffe0"/><stop offset="0.45" stop-color="#45E39C"/><stop offset="1" stop-color="#0d7a4f"/></radialGradient>'

DESIGNS = {
    'noir': dict(title='NOIR SOVEREIGN', metal='gold', field=('#1b1a17', '#050505'), band='#0b0a09', texture='sunburst', text='#e7cd85', laurel=False),
    'midnight': dict(title='MIDNIGHT ROYAL', metal='platinum', field=('#2a36c4', '#0a0f45'), band='#070b33', texture='lattice', text='#e8edf3', laurel=True),
    'emerald': dict(title='EMERALD LAUREL', metal='gold', field=('#11553d', '#03231a'), band='#021a13', texture='waves', text='#e9cf86', laurel=True),
    'platinum': dict(title='PLATINUM QUIET', metal='platinum', field=('#ffffff', '#e7ebf0'), band='#f4f6f9', texture='rings', text='#2b3240', laurel=False),
}


def badge(key, full=True, serial='001'):
    d = DESIGNS[key]
    u = key[:2] + ('f' if full else 'm')
    light = key == 'platinum'
    defs = metal_defs(d['metal'], u) + GEM.format(u=u)
    defs += f'<radialGradient id="{u}e" cx="0.42" cy="0.36" r="0.8"><stop offset="0" stop-color="{d["field"][0]}"/><stop offset="1" stop-color="{d["field"][1]}"/></radialGradient>'
    defs += f'<clipPath id="{u}c"><circle cx="{C}" cy="{C}" r="{318 if full else 404}"/></clipPath>'
    defs += f'<radialGradient id="{u}h" cx="0.3" cy="0.22" r="0.7"><stop offset="0" stop-color="#ffffff" stop-opacity="{0.10 if not light else 0.0}"/><stop offset="1" stop-color="#ffffff" stop-opacity="0"/></radialGradient>'
    if full:
        defs += arc_paths(u, 372)
    metal = f'url(#{u}m)'
    rev = f'url(#{u}r)'
    tex_color = METALS[d['metal']][3] if not light else '#9aa3ae'
    field_r = 318 if full else 404
    body = []
    # 外側の縁（面取りに見せるため、向きの違う金属を重ねる）
    body.append(f'<circle cx="{C}" cy="{C}" r="486" fill="{metal}"/>')
    body.append(f'<circle cx="{C}" cy="{C}" r="470" fill="{rev}"/>')
    body.append(f'<circle cx="{C}" cy="{C}" r="458" fill="{d["band"]}"/>')
    body.append(f'<circle cx="{C}" cy="{C}" r="458" fill="none" stroke="{metal}" stroke-width="2"/>')
    body.append(ticks(452, f'url(#{u}s)'))
    if full:
        body.append(f'<circle cx="{C}" cy="{C}" r="418" fill="none" stroke="{metal}" stroke-width="2.5"/>')
        body.append(arc_text('FOUNDING MEMBER', 'Italiana-Regular.ttf', 50, 15, 372, d['text']))
        # 下の弧: 1.24 から「FOLLOFIND · Nº 001」（通し番号はサイトが発信者ごとに入れる <!--SERIAL-->。見本では 001）。
        body.append(arc_text('FOLLOFIND · Nº ' + serial, 'Jura-Light.ttf', 30, 11, 396, d['text'], top=False, opacity=0.92) if serial else '<!--SERIAL-->')
        for deg in (90, 270):
            x, y = pol(372, deg)
            body.append(f'<g transform="translate({x:.2f} {y:.2f}) rotate({deg})"><path d="M0 -10 L4 0 L0 10 L-4 0Z" fill="{metal}"/></g>')
        body.append(f'<circle cx="{C}" cy="{C}" r="330" fill="none" stroke="{metal}" stroke-width="6"/>')
        body.append(f'<circle cx="{C}" cy="{C}" r="322" fill="none" stroke="{rev}" stroke-width="2"/>')
    else:
        body.append(f'<circle cx="{C}" cy="{C}" r="412" fill="none" stroke="{metal}" stroke-width="7"/>')
    # 中の面（エナメル）と模様
    body.append(f'<circle cx="{C}" cy="{C}" r="{field_r}" fill="url(#{u}e)"/>')
    tex = ''
    if full or key in ('noir', 'platinum'):
        if d['texture'] == 'sunburst':
            tex = sunburst(40, field_r, tex_color)
        elif d['texture'] == 'lattice':
            tex = lattice(field_r, tex_color)
        elif d['texture'] == 'waves':
            tex = waves(60, field_r - 6, tex_color)
        else:
            tex = rings(30, field_r, tex_color, 5)
    body.append(f'<g clip-path="url(#{u}c)" opacity="{0.55 if full else 0.4}">{tex}</g>')
    body.append(f'<circle cx="{C}" cy="{C}" r="{field_r}" fill="url(#{u}h)"/>')
    # 月桂樹と中央の印
    if d['laurel'] and full:
        body.append(laurel(metal))
    mfill = '#1B27B0' if light else metal
    if full:
        body.append(f'<circle cx="{C}" cy="{C - 8}" r="168" fill="none" stroke="{metal}" stroke-width="1.6" opacity="0.8"/>')
        body.append(mark(u, mfill, 3.0, C - 18))
    else:
        body.append(mark(u, mfill, 6.2, C - 10))
    style = ''
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000" width="1000" height="1000" role="img" aria-label="FolloFind Founding Member">'
            f'{style}<defs>{defs}</defs>{"".join(body)}</svg>')


if __name__ == '__main__':
    for key in DESIGNS:
        open(os.path.join(OUT, f'{key}-full.svg'), 'w').write(badge(key, True))
        open(os.path.join(OUT, f'{key}-template.svg'), 'w').write(badge(key, True, serial=''))
        open(os.path.join(OUT, f'{key}-mini.svg'), 'w').write(badge(key, False))
    # 下の弧の文字（サイトの PHP が並べる）。light = 徽章（SVG）、medium = 記念メダル（写真風）の上に重ねる。
    fonts = {}
    for key, file in (('light', 'Jura-Light.ttf'), ('medium', 'Jura-Medium.ttf')):
        glyphs = {}
        for ch in 'FOLIND·Nº 0123456789':
            d, w, upm = glyph(file, ch)
            glyphs[ch] = {'d': d, 'w': w}
        fonts[key] = {'upm': upm, 'glyphs': glyphs}
    json.dump(fonts, open(os.path.join(OUT, 'serial-glyphs.json'), 'w'))
    print('ok')
