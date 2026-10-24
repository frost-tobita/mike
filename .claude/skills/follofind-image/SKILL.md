---
name: follofind-image
description: FolloFind の画像（写真風の素材・背景・メダル・挿絵・ストア用画像など）を OpenAI の画像API で作る・仕上げる。画像を作って、と頼まれたときに使う。
---

# FolloFind 画像スキル

## 前提
- APIキーはクラウド環境の **Network secrets**（Allowed websites: `api.openai.com`、Bearer）に登録済み。
  キーはコード・コマンド・チャットに書かない（proxy が自動で付ける）。手元で使うときだけ `OPENAI_API_KEY`。
- 呼び出しは必ず `scripts/ffimage.py` を使う。ストリーミングで受け取るので、高画質（1〜2分）でも接続が切れない。

## 使い方
```bash
F=.claude/skills/follofind-image/scripts/ffimage.py
python3 -I $F gen  "指示文" -o out.png [--size 1024x1024|1536x1024|1024x1536] [--quality low|medium|high] [--brand] [-n 2]
python3 -I $F edit "指示文" -i 元の画像.png -o out.png      # 元の画像の形・配置を保ったまま仕上げ直す
```
- 試すときは `--quality low`（1円未満）。本番は `high`（1枚あたり数十円）。
- `--brand` でブランドの色（#1B27B0 / #2020D2 / #45E39C）を指示文に足す。
- `gpt-image-2` は透明な背景に対応していない。白い背景（#FFFFFF）で作って、あとで切り抜く。

## 決まりごと
1. **ロゴ・徽章・文字が正確でないといけないもの**は、先にコードで（SVG など）正確に描き、それを `edit` に渡して質感だけを足す。
   文字は「元の画像のとおり、足さない・消さない・つづりを変えない」と指示し、できた画像の文字を必ず目で確かめる。
2. **人の名前・番号など、1人ずつ変わる文字**は画像AIに書かせない。背景と素材だけを作り、文字はあとから重ねる（PHP の GD やブラウザの canvas）。
3. 指示文には「no text, no logos」（素材のとき）、構図（中央・正面・余白の位置）、光、材質を具体的に書く。
4. できた画像は Pillow で切り抜き・縮小し、WebP（品質 80〜88）にしてから `assets/` に入れる。1枚 150KB 以内を目安にする。
5. 作ったものは並べた一覧画像にして、ユーザーに見せてから組み込む。

## 例: 創設メンバーの記念メダル（1.24）
- 元: `assets/badges/founder-<design>.svg` を 1024px の PNG にする
- `edit` で「本物の金属の記念メダルの写真に。文字と配置はそのまま」→ 白い背景を円で切り抜き → `founder-<design>-medal.webp`（640px）
- 背景: `gen --size 1536x1024`「no text, no objects」の布や紙 → 1200×630 に切って `founder-<design>-card-bg.webp`
- 通し番号・名前・参加日は `assets/js/snscs-founder.js` が canvas で重ねる
