#!/usr/bin/env python3
"""FolloFind の画像を OpenAI の画像API で作る・直す。

APIキーはコードにもコマンドにも書かない。クラウド環境の Network secrets（api.openai.com 宛て）で
自動で付く。手元で使うときは環境変数 OPENAI_API_KEY を入れておく。

  作る:  ffimage.py gen  "指示文" -o out.png [--size 1024x1024] [--quality high] [--transparent] [-n 2]
  直す:  ffimage.py edit "指示文" -i ref.png [-i ref2.png] -o out.png [...同上]

-n が2以上なら out-1.png, out-2.png ... と保存する。長くかかる高画質でも切れないよう、ストリーミングで受け取る。使った量（トークン）を表示する。
"""
import argparse
import base64
import json
import mimetypes
import os
import sys
import urllib.request
import uuid

API = "https://api.openai.com/v1/images/"
BRAND = (
    "Brand palette: deep royal blue #1B27B0, indigo #2020D2, mint green accent #45E39C, white. "
)


def headers(content_type):
    h = {"Content-Type": content_type}
    key = os.environ.get("OPENAI_API_KEY")
    if key:  # ローカル用。クラウドでは proxy が付ける
        h["Authorization"] = "Bearer " + key
    return h


def post(url, body, content_type):
    """ストリーミングで受け取る（途中経過が届き続けるので、長くかかっても接続が切れにくい）。"""
    req = urllib.request.Request(url, data=body, headers=headers(content_type), method="POST")
    final = None
    try:
        with urllib.request.urlopen(req, timeout=600) as r:
            for raw in r:
                line = raw.decode("utf-8", "replace").strip()
                if not line.startswith("data:"):
                    continue
                ev = json.loads(line[5:].strip() or "{}")
                kind = ev.get("type", "")
                if kind.endswith(".partial_image"):
                    print("  ...partial %s" % ev.get("partial_image_index"), file=sys.stderr)
                elif kind.endswith(".completed"):
                    final = ev
                elif "error" in ev:
                    sys.exit("API error: %s" % json.dumps(ev)[:800])
    except urllib.error.HTTPError as e:
        sys.exit("API error %s: %s" % (e.code, e.read().decode("utf-8", "replace")[:800]))
    if not final:
        sys.exit("API error: no image returned")
    return {"data": [{"b64_json": final["b64_json"]}], "usage": final.get("usage")}


def multipart(fields, files):
    boundary = uuid.uuid4().hex
    out = b""
    for k, v in fields.items():
        out += ("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n" % (boundary, k, v)).encode()
    for k, path in files:
        mime = mimetypes.guess_type(path)[0] or "image/png"
        out += ("--%s\r\nContent-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n"
                % (boundary, k, os.path.basename(path), mime)).encode()
        out += open(path, "rb").read() + b"\r\n"
    out += ("--%s--\r\n" % boundary).encode()
    return out, "multipart/form-data; boundary=" + boundary


def save(data, out):
    with open(out, "wb") as f:
        f.write(base64.b64decode(data["data"][0]["b64_json"]))
    print("saved:", out)
    if data.get("usage"):
        print("usage:", json.dumps(data["usage"]))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("mode", choices=["gen", "edit"])
    ap.add_argument("prompt")
    ap.add_argument("-o", "--out", required=True)
    ap.add_argument("-i", "--image", action="append", default=[])
    ap.add_argument("--model", default="gpt-image-2")
    ap.add_argument("--size", default="1024x1024")
    ap.add_argument("--quality", default="high", choices=["low", "medium", "high", "auto"])
    ap.add_argument("--transparent", action="store_true")
    ap.add_argument("--brand", action="store_true", help="ブランドの色を指示文に足す")
    ap.add_argument("-n", type=int, default=1, help="枚数（1枚ずつ順に作る）")
    a = ap.parse_args()

    prompt = (BRAND if a.brand else "") + a.prompt
    opts = {"model": a.model, "prompt": prompt, "size": a.size, "quality": a.quality,
            "stream": True, "partial_images": 3}
    if a.transparent:
        opts["background"] = "transparent"
        opts["output_format"] = "png"
    os.makedirs(os.path.dirname(os.path.abspath(a.out)), exist_ok=True)
    if a.mode == "edit" and not a.image:
        sys.exit("edit には -i で元の画像が必要です")
    root, ext = os.path.splitext(a.out)
    for i in range(a.n):
        out = a.out if a.n == 1 else "%s-%d%s" % (root, i + 1, ext or ".png")
        if a.mode == "gen":
            data = post(API + "generations", json.dumps(opts).encode(), "application/json")
        else:
            fields = {k: ("true" if v is True else str(v)) for k, v in opts.items()}
            body, ct = multipart(fields, [("image[]", p) for p in a.image])
            data = post(API + "edits", body, ct)
        save(data, out)


if __name__ == "__main__":
    main()
