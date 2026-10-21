# SNS発信者検索サービス

SNSアカウントを登録しておき、「どんな発信者がいるか」を探せる検索サービスです。
WordPress のプラグイン **SNS Creator Search** として作っています。

> 全体の設計と今後の計画は [docs/design.md](docs/design.md) を見てください。

## できること（現在のバージョン 0.2.0）

- **発信者本人による登録・変更**: 発信者が会員登録し、マイページから掲載内容を申請。新規登録も変更も **運営の審査を通ったものだけ** が公開される
- **審査画面**（管理画面「発信者 → 審査」）: 申請の一覧、現在の内容と申請内容の比較（変わった項目は黄色）、承認／差し戻し（理由つき）
- **お知らせメール**: 申請が届いたら運営へ、承認・差し戻しの結果は発信者へ
- **掲載停止**: 発信者はマイページからいつでも掲載をやめられる（審査なし）
- **運営による直接登録**（管理画面）: 名前・紹介文・写真・ジャンル・地域と、複数のSNSアカウント（SNSの種類 / ハンドル / URL / フォロワー数）
- **対応SNS**: X / YouTube / Instagram / Threads / TikTok / Substack
- **検索画面**: SNSごとの **インデックス（見出しタブ）** で切り替え。各タブに該当人数を表示。キーワード、ジャンル、地域、フォロワー数の範囲で絞り込み。フォロワー順・新着順・名前順に並べ替え
- **発信者ごとの紹介ページ**: SNSアカウントの一覧を自動で表示
- **CSV一括登録**: たくさんの発信者をまとめて登録
- **検索API**: 将来アプリなどから使える `/wp-json/snscs/v1/creators`
- 同じSNSアカウントを2人の発信者に重複して登録できないようにする仕組み

## 使い方

### 1. インストール
1. `wp-content/plugins/sns-creator-search` フォルダを、WordPress の `wp-content/plugins/` に置く
2. 管理画面の「プラグイン」で **SNS Creator Search** を有効化する
3. 管理画面の「設定 → パーマリンク」で「投稿名」などを選んで保存する（発信者ページのURLを有効にするため）

### 2. 検索ページを作る
固定ページを新規作成し、本文に次の1行を書いて公開します。

```
[sns_creator_search]
```

1ページに表示する人数を変えたいときは `[sns_creator_search per_page="30"]` のようにします。

### 3. 発信者マイページを作る
固定ページ（例: タイトル「発信者マイページ」）を作り、本文に次の1行を書いて公開します。

```
[sns_creator_portal]
```

- ログインしていない人には「ログイン」と「新規登録」が表示されます。
- 発信者がログインすると、掲載状況・審査状況と、登録内容の入力フォームが表示されます。
- 発信者は WordPress の管理画面には入れません（このページだけで完結します）。

申請が届くと、管理画面の「発信者 → 審査」に件数が表示され、サイト管理者のメールアドレスにお知らせが届きます。

### 4. 運営が発信者を登録する（任意）
- **1人ずつ**: 管理画面の「発信者 → 発信者を追加」。画面下の「SNSアカウント」欄に入力します。
- **まとめて**: CSVを用意し、サーバーで次のコマンドを実行します（[WP-CLI](https://wp-cli.org/) が必要）。

  ```
  wp snscs import creators.csv
  ```

  CSVの形式は [samples/creators-sample.csv](samples/creators-sample.csv) を見てください。
  1行が1アカウントで、同じ名前の行は同じ発信者としてまとめられます。ジャンルと地域は `|` で区切ると複数指定でき、無いものは自動で作られます。
  同じCSVをもう一度取り込んでも、発信者が二重に登録されることはありません。

### 5. 動作確認用のダミーデータ
```
wp snscs seed --count=10000   # ダミー発信者を1万人作る
wp snscs delete-dummy         # ダミー発信者を全部消す
```

## 検索API

```
GET /wp-json/snscs/v1/creators?q=料理&platform=instagram&min_followers=10000&sort=followers&page=1&per_page=20
```

| パラメーター | 意味 |
|---|---|
| `q` | キーワード（空白区切りで、すべての語を含むものを探す） |
| `platform` | `x` `youtube` `instagram` `threads` `tiktok` `substack` |
| `genre` / `region` | ジャンル・地域のスラッグ（親を指定すると子も含む） |
| `min_followers` / `max_followers` | フォロワー数の範囲 |
| `sort` | `followers`（多い順） / `newest`（新着順） / `name`（名前順） |
| `page` / `per_page` | ページ番号 / 1ページの件数（最大100） |

全体の件数は、応答のヘッダー `X-WP-Total` で分かります。

## フォルダ構成

```
wp-content/plugins/sns-creator-search/
├── sns-creator-search.php          プラグインの入口
├── includes/
│   ├── class-snscs-post-types.php  「発信者」「ジャンル」「地域」の登録
│   ├── class-snscs-accounts.php    SNSアカウント専用テーブルの読み書き
│   ├── class-snscs-search.php      検索の窓口（入力チェック・結果の整形）
│   ├── class-snscs-search-backend.php  検索エンジン部分の差し替え口
│   ├── class-snscs-mysql-backend.php   MySQLでの検索（現在の検索エンジン）
│   ├── class-snscs-admin.php       管理画面の入力欄
│   ├── class-snscs-frontend.php    検索画面・発信者ページの表示
│   ├── class-snscs-rest.php        検索API
│   ├── class-snscs-cli.php         CSV取り込み・ダミーデータ
│   ├── class-snscs-portal.php      発信者マイページ（会員登録・申請・掲載停止）
│   ├── class-snscs-submissions.php 申請の保存と、承認時の反映
│   ├── class-snscs-review-admin.php 運営の審査画面
│   ├── class-snscs-notify.php      お知らせメール
│   └── functions.php               対応SNSの一覧など
└── assets/css/snscs.css            見た目
```

## 動作環境
- WordPress 6.0 以上
- PHP 7.4 以上
- MySQL 5.7 以上 / MariaDB 10.3 以上
