"""記念メダル（写真風）の下の弧の文字（FOLLOFIND · MMXXVI）を消す。

使い方: python3 erase_arc.py 元のメダルのフォルダ 出力フォルダ [デザイン...]
文字のある帯を、文字の無い左右の角度の色で埋める（同心円の線は半径ごとに写すので、そのまま残る）。
通し番号入りの文字は、サイトが上に重ねる（SNSCS_Founder::medal_text）。
"""
import sys, numpy as np
from PIL import Image
A, OUT = sys.argv[1], sys.argv[2]
GEO = {'noir': (220, 259, 42, (46, 54)), 'midnight': (220, 259, 42, (46, 54)), 'emerald': (206, 265, 54, (58, 66)), 'platinum': (214, 262, 54, (58, 66))}
for dn in (sys.argv[3:] or GEO):
    R0, R1, PHI, REF = GEO[dn]
    src = Image.open(f'{A}/founder-{dn}-medal.webp').convert('RGBA')
    S = 4  # 細かく計算するため一度大きくする
    big = src.resize((640*S, 640*S), Image.LANCZOS)
    a = np.asarray(big).astype(np.float32)
    n = a.shape[0]; c = (n-1)/2
    yy, xx = np.mgrid[0:n, 0:n]
    dx, dy = (xx-c)/S, (yy-c)/S
    r = np.hypot(dx, dy)
    phi = np.degrees(np.arctan2(-dx, dy))   # 0 = 真下、左へ正
    def sample(rr, ph):
        x = c + (-np.sin(np.radians(ph))*rr)*S; y = c + (np.cos(np.radians(ph))*rr)*S
        return a[np.clip(np.round(y).astype(int),0,n-1), np.clip(np.round(x).astype(int),0,n-1)]
    m = (r >= R0) & (r <= R1) & (np.abs(phi) <= PHI)
    rs, ps = r[m], phi[m]
    def prof(sign):
        acc = [sample(rs, sign*p) for p in np.linspace(REF[0], REF[1], 9)]
        return np.median(np.stack(acc), axis=0)
    L, Rr = prof(1), prof(-1)
    t = ((ps + PHI) / (2*PHI))[:, None]   # -PHI(右)→0, +PHI(左)→1
    fill = Rr*(1-t) + L*t
    out = a.copy(); out[m] = fill
    # 縁をなじませる
    w = np.clip(np.minimum.reduce([ (r-R0)/1.5, (R1-r)/1.5, (PHI-np.abs(phi))/1.0 ]), 0, 1)[..., None]
    out = a*(1-w) + out*w
    Image.fromarray(np.clip(out,0,255).astype(np.uint8),'RGBA').resize((640,640), Image.LANCZOS).save(f'{OUT}/{dn}.png')
print('ok')
