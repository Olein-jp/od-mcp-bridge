# OD MCP Bridge 利用マニュアル

このマニュアルは、OD MCP Bridge を WordPress サイトへ導入し、MCP クライアントから
公開コンテンツやサイト保守情報を安全に読み取る手順をまとめたものです。

OD MCP Bridge は、WordPress に登録した機能を AI クライアントから呼び出せるようにする
プラグインです。現時点では読み取り専用で、投稿の作成、編集、削除は行いません。

## 実サイトで試すための最短手順

最初に全体の流れだけを確認したい場合は、次の順番で進めてください。

1. 配布用 ZIP を WordPress へアップロードして有効化する
2. 「設定」→「OD MCP Bridge」で MCP server が有効になっていることを確認する
3. MCP 接続専用の購読者ユーザーを作成する
4. 専用ユーザーでアプリケーションパスワードを発行する
5. MCP クライアントへ endpoint (接続先 URL)、ユーザー名、アプリケーションパスワードを設定する
6. `mcp-adapter-discover-abilities` で初期状態の6つの Ability を確認する
7. `mcp-adapter-execute-ability` から必要な Ability を実行する

Application Password は、WordPress の管理画面や通常のログインに使うパスワードとは
別に発行する、外部アプリケーション専用の認証情報です。この値をプラグインの設定や
リポジトリへ保存する必要はありません。

## 動作要件

### WordPress サイト側

- WordPress 6.9 以上
- PHP 7.4 以上
- HTTPS でアクセスできる本番サイト
- プラグインをインストールできる管理者権限
- WordPress REST API (外部のアプリケーションが WordPress の情報を扱うための窓口) への
  アクセスを許可している環境

### MCP クライアント側

- Node.js 22.19 以上
- npm および `npx` を実行できる環境
- stdio 形式の MCP server を登録できる MCP クライアント、または MCP Inspector

MCP は Model Context Protocol の略で、AI クライアントと外部サービスとの間で機能や
データをやり取りするための共通仕様です。OD MCP Bridge では、WordPress MCP Adapter と
HTTP プロキシを介して WordPress サイトへ接続します。

## できることと、できないこと

Ability は、WordPress 側で実行できる個別の機能を表します。公開コンテンツ系6件と、
サイト保守系8件を利用できます。保守系は初期状態で無効です。

## Ability 一覧と必要権限

| Ability | 内容 | 必要な capability | 初期状態 |
| --- | --- | --- | --- |
| `get-site-info` | サイト基本情報 | `read` | 有効 |
| `get-posts` / `get-post` | 公開済み投稿の一覧・本文 | `read` | 有効 |
| `get-pages` / `get-page` | 公開済み固定ページの一覧・本文 | `read` | 有効 |
| `get-terms` | カテゴリーまたはタグ | `read` | 有効 |
| `get-update-status` | キャッシュ済み更新状況 | `od_mcp_bridge_view_*_updates` | 無効 |
| `get-plugins` | プラグイン状態 | `od_mcp_bridge_view_plugins` | 無効 |
| `get-themes` | テーマ状態 | `od_mcp_bridge_view_themes` | 無効 |
| `get-site-health` | 限定的なサイトヘルス結果 | `od_mcp_bridge_view_site_health` | 無効 |
| `get-content-summary` | 投稿・固定ページの件数と30日間の活動 | `od_mcp_bridge_view_content_summary` | 無効 |
| `get-stale-content` | 長期間更新されていない公開コンテンツ | `read` | 無効 |
| `get-cron-status` | WP-Cron の実行予定 | `od_mcp_bridge_view_cron` | 無効 |
| `get-maintenance-snapshot` | 保守情報の集約結果 | `od_mcp_bridge_view_maintenance` | 無効 |

保守系の独自 capability は、プラグインが作成する「MCP Maintenance Reader」ロールと
管理者ロールへ付与されます。この専用ロールにはプラグイン有効化、テーマ変更、設定変更などの
WordPress管理権限を付与しないため、管理者をMCP接続へ使わずに保守情報を参照できます。

## Codex から使うときのプロンプト例

OD MCP Bridge を MCP server として Codex に接続すると、通常は Ability 名や JSON を
直接指定せず、調べたいことを自然な日本語で依頼できます。Codex は利用可能な Ability を確認し、
必要なものを実行して結果を読みやすく要約します。

以下の返答例にあるサイト名、件数、バージョン、日時は説明用のサンプルです。Codex の回答形式は
依頼内容によって変わります。正規化された元データが必要な場合は、プロンプトの末尾に
「取得結果を省略せず JSON で示してください」と加えてください。

保守系 Ability は初期状態で無効です。利用前に「設定」→「OD MCP Bridge」で対象を有効にし、
必要な capability を持つ専用ユーザーで接続してください。無効な Ability は Codex から発見できず、
権限が不足している場合は実行を拒否します。

### 1. サイトの基本情報を確認する：`get-site-info`

サイト名、URL、言語、タイムゾーン、WordPress バージョンを確認するときに使います。

> 接続している WordPress サイトの基本情報を確認してください。

Codex は「サイト名は Example Site、URL は `https://example.com/`、言語は `ja`、
タイムゾーンは `Asia/Tokyo`、WordPress は 7.0」のように返します。

### 2. 公開済み投稿を探す：`get-posts`

公開済み投稿を検索し、タイトル、抜粋、公開・更新日時、URLを一覧で取得します。

> 公開済み投稿から「WordPress」を含む記事を更新日の新しい順に5件探してください。

Codex は条件に一致した投稿を一覧にし、各投稿の ID、タイトル、抜粋、更新日時、URLと、
総件数・総ページ数を返します。下書き、予約投稿、非公開投稿は含みません。

### 3. 投稿本文を読む：`get-post`

`get-posts` で確認した投稿 ID を使い、公開済み投稿の本文を取得します。

> 投稿 ID 123 の本文を取得し、見出し構成と要点をまとめてください。

Codex はタイトル、本文、抜粋、日時、URLを取得したうえで、依頼に合わせて要点を整理します。
本文には WordPress のブロックコメントや HTML が含まれる場合があります。

### 4. 公開済み固定ページを探す：`get-pages`

会社案内やお問い合わせなど、公開済み固定ページを検索・一覧取得します。

> 公開中の固定ページから「お問い合わせ」に関係するページを探してください。

Codex は一致した固定ページの ID、タイトル、抜粋、日時、URLとページング情報を返します。
下書きや非公開の固定ページは含みません。

### 5. 固定ページ本文を読む：`get-page`

指定した公開済み固定ページの本文、親子関係、メニュー順を確認します。

> 固定ページ ID 456 の内容を読み、訪問者向けの案内事項を箇条書きにしてください。

Codex はタイトル、本文、抜粋、日時、URLに加えて、`parent_id` と `menu_order` を取得し、
依頼された形式で内容を説明します。

### 6. カテゴリーやタグを確認する：`get-terms`

カテゴリーまたはタグの名前、slug、説明、公開投稿数を一覧取得します。

> サイトで使われているカテゴリーを投稿数の多い順に20件見せてください。

Codex はカテゴリー ID、名前、slug、説明、公開投稿数を返します。「タグを」と依頼した場合は
`post_tag` を使います。カスタムタクソノミーや term meta は取得しません。

### 7. 更新待ちを確認する：`get-update-status`

WordPress 本体、プラグイン、テーマ、翻訳のキャッシュ済み更新情報を確認します。

> WordPress サイトに保留中の更新があるか確認し、種類ごとに整理してください。

Codex は権限のある区分について、現在版、更新候補版、件数、最終確認日時を返します。
この確認のために外部更新チェックを強制しないため、WordPress の更新キャッシュが古い場合は
その旨も考慮する必要があります。

### 8. プラグイン構成を確認する：`get-plugins`

インストール済みプラグインのバージョン、有効状態、自動更新、更新有無を確認します。

> インストール済みプラグインを、有効・無効と更新の有無が分かる表にしてください。

Codex は slug、表示名、バージョン、有効状態、ネットワーク有効状態、自動更新、更新有無を
表などに整理します。プラグインファイルの絶対パスや内容は返しません。

### 9. テーマ構成を確認する：`get-themes`

インストール済みテーマと、現在の有効テーマ、親テーマ、自動更新、更新有無を確認します。

> 現在の有効テーマとインストール済みテーマの状態を確認してください。

Codex は stylesheet、表示名、バージョン、有効状態、親テーマ、自動更新、更新有無を返します。
テーマファイルの内容やサーバー上の絶対パスは取得しません。

### 10. Site Health の要点を確認する：`get-site-health`

安全な範囲に限定した同期テストから、良好・推奨・重大の状態を確認します。

> サイトヘルスを確認し、重大な問題と改善を推奨されている項目を優先して説明してください。

Codex は全体状態、状態別件数、各テストの名前・状態・説明を返し、重要度順に要約できます。
外部 HTTP、loopback、非同期テスト、修正操作、内部パスは対象外です。

### 11. コンテンツ運用状況を確認する：`get-content-summary`

投稿と固定ページの公開数・下書き数、直近30日間の公開数・更新数を確認します。

> この30日間のコンテンツ運用状況を、投稿と固定ページに分けて教えてください。

Codex は UTC 基準の集計期間と、投稿・固定ページ別の件数を返します。本文、タイトル、作成者など
個別コンテンツの情報は含みません。

### 12. 長期間更新されていないコンテンツを探す：`get-stale-content`

指定日数より長く更新されていない公開済み投稿または固定ページを探します。

> 2年以上更新されていない公開済み固定ページを、古い順に50件まで調べてください。

Codex は基準日時とともに、対象の ID、種類、タイトル、最終更新日時、URLを返します。
内容が本当に古いかどうかは自動判定しないため、結果を更新候補の洗い出しとして利用します。

### 13. WP-Cron の状態を確認する：`get-cron-status`

予約イベントの総数、期限超過、重複候補、次回実行予定を確認します。

> WP-Cron の遅延と重複候補を確認し、注意が必要なイベントを教えてください。

Codex は hook、次回実行日時、schedule、interval、期限超過状態を返します。同じ時刻・hook・
schedule の組み合わせを重複候補として数えますが、秘密情報を含み得る Cron 引数は返しません。

### 14. 保守状況をまとめて確認する：`get-maintenance-snapshot`

複数の保守系 Ability を一度に実行し、サイト全体の現状を確認する入口です。

> WordPress サイトの保守スナップショットを取得し、対応が必要そうな項目を整理してください。

Codex はサイト基本情報、更新、Site Health、プラグイン、テーマ、コンテンツ、Cron を
セクションごとに整理します。個別 Ability が無効、権限不足、実行失敗の場合、そのセクションを
`unavailable` と理由コードで示し、取得できた他のセクションはそのまま返します。

すべて読み取り専用です。次の情報や操作は対象に含まれません。

- 下書き、予約投稿、非公開投稿の取得
- ユーザー情報や認証情報の取得
- 投稿の作成、更新、削除
- WordPress の設定変更
- サーバー内のファイルパスや WP-Cron の引数の取得
- サイトヘルス確認を目的とした外部 HTTP 通信

## プラグインをインストールする

### 1. 配布用 ZIP を準備する

GitHub Releases に配布用 ZIP が公開されている場合は、リリースに添付された
`od-mcp-bridge-0.0.0.zip` 形式のファイルを利用してください。

[GitHub Releases](https://github.com/Olein-jp/od-mcp-bridge/releases)

GitHub の「Code」→「Download ZIP」で取得できるソースコード ZIP には、実行に必要な
Composer 依存パッケージが含まれません。実サイトへのインストールには、
必ずリリースへ添付された配布用 ZIP、または開発者が `npm run package` で生成した ZIP を
利用してください。

### 2. WordPress へアップロードする

1. WordPress の管理画面を開く
2. 「プラグイン」→「新規プラグインを追加」を開く
3. 「プラグインのアップロード」を押す
4. 配布用 ZIP を選択して「今すぐインストール」を押す
5. インストール完了後に「プラグインを有効化」を押す

### 3. 動作状態を確認する

管理画面の「設定」→「OD MCP Bridge」を開きます。

「MCP server」の「Status」が「Enabled」と表示されていれば、WordPress MCP Adapter は
正しく読み込まれています。「Endpoint」に表示される URL は、MCP クライアント設定で
使用するため、手元へ控えてください。

通常は次の形式になります。

```text
https://example.com/wp-json/mcp/mcp-adapter-default-server
```

本番サイトでは、必ず `https://` から始まる URL を利用してください。

### 4. 接続診断を確認する

同じ画面の「Connection diagnostics」では、次の項目をWordPress内部だけで確認できます。

- WordPress MCP Adapterの読み込み状態
- WordPressとPHPの最低バージョン
- HTTPSとApplication Passwordの利用可否
- パーマリンク設定
- MCP Maintenance Readerロールの最小権限
- 有効なAbility数と、Abilityごとの必要capability

「Ready」以外の項目がある場合はDetailsの説明を確認してください。この診断は外部のMCPクライアントへ
接続せず、ユーザー名やApplication Passwordも入力・保存しません。実際の認証を含む疎通確認は、
後述のMCP Inspectorの手順で行います。

## 公開する Ability を設定する

「設定」→「OD MCP Bridge」では、MCP クライアントへ公開する Ability を個別に切り替えられます。
初期状態では公開コンテンツ系6件だけが有効です。保守系は公開範囲と必要権限を確認してから
個別に有効化してください。

1. 公開したい Ability にチェックを入れる
2. 公開しない Ability のチェックを外す
3. 画面下部の「変更を保存」を押す

無効にした Ability は登録されなくなり、`mcp-adapter-discover-abilities` の結果にも
表示されません。利用目的に必要なものだけを有効にしておくと、公開範囲を把握しやすくなります。

## MCP 接続専用ユーザーを作成する

管理者アカウントを MCP 接続へ使わず、接続専用ユーザーを用意します。

1. 管理者で「ユーザー」→「ユーザーを追加」を開く
2. MCP 接続専用のユーザー名とメールアドレスを入力する
3. 公開コンテンツだけなら「購読者」、保守系も使うなら「MCP Maintenance Reader」を選択する
4. ユーザーを追加する

購読者は、初期状態の6件と `get-stale-content` を利用できます。「MCP Maintenance Reader」は
公開コンテンツ系に加えて、管理画面で有効にした保守系 Ability を利用できます。管理者を接続に
使う場合は、すべての管理情報へ到達できる認証情報になるため、保管と失効を特に厳格に行ってください。

専用ユーザーは通常の閲覧者と区別しやすい名前にしてください。ただし、このユーザー名を
README、Issue、サポートへの問い合わせなど、第三者が閲覧できる場所へ記載しないでください。

## アプリケーションパスワードを発行する

1. 作成した専用ユーザーで WordPress へログインする
2. 「ユーザー」→「プロフィール」を開く
3. 「アプリケーションパスワード (Application Passwords)」まで移動する
4. `OD MCP Bridge` など、用途を判断できる名前を入力する
5. 「新しいアプリケーションパスワードを追加」を押す
6. 画面へ一度だけ表示されるパスワードを安全な場所へコピーする

通常のログインパスワードを MCP クライアントへ設定しないでください。また、発行した
アプリケーションパスワードは、ソースファイル、WordPress option、Issue、チャット、ログへ
貼り付けないでください。

アプリケーションパスワードの項目が表示されない場合は、最初にサイトが HTTPS で
提供されているか確認してください。

## MCP クライアントを設定する

`@automattic/mcp-wordpress-remote` は、MCP クライアントの stdio (標準入出力) 接続を
WordPress の HTTP endpoint へ中継するプロキシです。

以下は一般的な MCP クライアント用の設定例です。すべてサンプル値なので、実際のサイトに
合わせてクライアント側で置き換えてください。

```json
{
  "mcpServers": {
    "od-mcp-bridge": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "mcp-reader",
        "WP_API_PASSWORD": "<APPLICATION_PASSWORD>",
        "OAUTH_ENABLED": "false"
      }
    }
  }
}
```

各設定値の役割は次のとおりです。

| 環境変数 | 内容 |
| --- | --- |
| `WP_API_URL` | 「設定」→「OD MCP Bridge」に表示された endpoint |
| `WP_API_USERNAME` | MCP 接続専用ユーザーのユーザー名 |
| `WP_API_PASSWORD` | 専用ユーザーで発行したアプリケーションパスワード |
| `OAUTH_ENABLED` | Application Password を使うため `false` |

実際の認証情報は MCP クライアントのローカル設定、環境変数、または OS のシークレットストアで
管理してください。クライアント設定ファイルを Git で管理している場合は、認証情報を直接
書き込まないように注意が必要です。

設定を変更したあとは、MCP クライアントを再起動してください。

## MCP Inspector で接続を確認する

MCP Inspector は、MCP server が公開している tool の確認や実行に使える開発者向けツールです。
実際の MCP クライアントへ登録する前の切り分けにも利用できます。

### 1. 接続情報を環境変数へ設定する

次の値を実サイトの内容へ置き換えます。認証情報がシェル履歴へ残らない方法で設定してください。

```bash
export WP_API_URL='https://example.com/wp-json/mcp/mcp-adapter-default-server'
export WP_API_USERNAME='mcp-reader'
export WP_API_PASSWORD='<APPLICATION_PASSWORD>'
export OAUTH_ENABLED='false'
```

### 2. 確認用の関数を用意する

```bash
mcp_inspector() {
  npx -y @modelcontextprotocol/inspector@latest --cli \
    npx @automattic/mcp-wordpress-remote@latest \
    -e "WP_API_URL=$WP_API_URL" \
    -e "WP_API_USERNAME=$WP_API_USERNAME" \
    -e "WP_API_PASSWORD=$WP_API_PASSWORD" \
    -e "OAUTH_ENABLED=$OAUTH_ENABLED" \
    "$@"
}
```

### 3. MCP tool を確認する

```bash
mcp_inspector --method tools/list
```

次の3つが表示されれば、WordPress MCP Adapter との接続はできています。

- `mcp-adapter-discover-abilities`
- `mcp-adapter-get-ability-info`
- `mcp-adapter-execute-ability`

ここで表示される MCP tool と、OD MCP Bridge が公開する Ability は別のものです。
`mcp-adapter-discover-abilities` で Ability を探し、`mcp-adapter-execute-ability` を通して
実行します。

### 4. Ability を検出する

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-discover-abilities \
  --tool-args-json '{}'
```

初期設定では、結果に次の6件が含まれます。

- `od-mcp-bridge/get-site-info`
- `od-mcp-bridge/get-posts`
- `od-mcp-bridge/get-post`
- `od-mcp-bridge/get-pages`
- `od-mcp-bridge/get-page`
- `od-mcp-bridge/get-terms`

### 5. Ability を実行する

サイト情報を取得します。

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-site-info","parameters":{}}'
```

公開済み投稿を5件取得します。

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-posts","parameters":{"per_page":5}}'
```

公開済み投稿 ID `123` の本文を取得します。

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-post","parameters":{"post_id":123}}'
```

カテゴリーを20件取得します。

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-terms","parameters":{"taxonomy":"category","per_page":20}}'
```

保守スナップショットを使う場合は、管理画面でスナップショットと必要な保守系 Ability を
有効にしてから、「MCP Maintenance Reader」ロールの専用ユーザーで実行します。

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-maintenance-snapshot","parameters":{}}'
```

確認が終わったら、少なくともパスワードの環境変数を削除してください。

```bash
unset WP_API_PASSWORD
```

## Ability の入力と出力

### `od-mcp-bridge/get-site-info`

入力パラメータはありません。

次の情報を返します。

- `name`: サイト名
- `description`: キャッチフレーズ
- `url`: サイト URL
- `language`: サイトの言語
- `timezone`: WordPress のタイムゾーン
- `wordpress_version`: WordPress バージョン

### `od-mcp-bridge/get-posts`

すべての入力パラメータを省略できます。

| パラメータ | 型 | 初期値 | 内容 |
| --- | --- | --- | --- |
| `per_page` | 整数 | `10` | 1ページあたりの件数。1〜100 |
| `page` | 整数 | `1` | 取得するページ番号。1以上 |
| `search` | 文字列 | なし | 投稿を絞り込む検索語 |
| `orderby` | 文字列 | `date` | 並び替え項目。`date`、`modified`、`title` |
| `order` | 文字列 | `desc` | 並び順。`asc` または `desc` |

各投稿には `id`、`title`、`excerpt`、`date`、`modified`、`link` が含まれます。
あわせて、現在のページ、1ページあたりの件数、総件数、総ページ数が `pagination` として
返されます。

たとえば、タイトル順の昇順で2ページ目を取得する場合は次のように指定します。

```json
{
  "ability_name": "od-mcp-bridge/get-posts",
  "parameters": {
    "per_page": 20,
    "page": 2,
    "orderby": "title",
    "order": "asc"
  }
}
```

### `od-mcp-bridge/get-post`

`post_id` に、取得したい公開済み投稿の ID を指定します。

```json
{
  "ability_name": "od-mcp-bridge/get-post",
  "parameters": {
    "post_id": 123
  }
}
```

投稿一覧の情報に加えて、`content` に投稿本文が返されます。本文は WordPress に保存された
ブロックマークアップを含む未加工の投稿内容です。MCP クライアント側で表示したり別の処理へ
渡したりする場合は、HTML やブロックコメントを含む可能性を考慮してください。

存在しない投稿 ID、公開済みではない投稿、投稿以外のコンテンツを指定した場合はエラーになります。

### `od-mcp-bridge/get-pages` と `get-page`

`get-pages` の入力、ページング、返却項目は `get-posts` と同じです。公開済み固定ページだけを
返します。`get-page` では `page_id` を指定し、`content` に加えて親ページ ID の `parent_id` と
メニュー順の `menu_order` を返します。

```json
{
  "ability_name": "od-mcp-bridge/get-page",
  "parameters": {
    "page_id": 456
  }
}
```

### `od-mcp-bridge/get-terms`

カテゴリーとタグを、空の項目も含めて一覧取得します。

| パラメータ | 型 | 初期値 | 内容 |
| --- | --- | --- | --- |
| `taxonomy` | 文字列 | `category` | `category` または `post_tag` |
| `per_page` | 整数 | `10` | 1〜100件 |
| `page` | 整数 | `1` | 取得するページ番号 |
| `search` | 文字列 | なし | 名前を絞り込む検索語 |
| `orderby` | 文字列 | `name` | `name`、`slug`、`count` |
| `order` | 文字列 | `asc` | `asc` または `desc` |

各項目には `id`、`name`、`slug`、HTMLを除いた `description`、`count`、`taxonomy` が
含まれます。

### `od-mcp-bridge/get-update-status`

WordPress が保持している更新キャッシュだけを読み取り、確認のための外部通信は行いません。
コア、プラグイン、テーマのうち、接続ユーザーが更新権限を持つセクションだけを返します。
`generated_at`、利用可能なバージョンや対象名、翻訳更新の情報が含まれます。更新キャッシュが
古い場合、結果も古い可能性があります。

### `od-mcp-bridge/get-plugins` と `get-themes`

入力はありません。プラグインでは slug、名前、バージョン、有効状態、ネットワーク有効状態、
自動更新、更新有無を返します。テーマでは stylesheet、名前、バージョン、有効状態、親テーマ、
自動更新、更新有無を返します。サーバー上の絶対パスは返しません。

### `od-mcp-bridge/get-site-health`

入力はありません。PHP、データベース、予約イベント、デバッグ設定、ファイルアップロード、
autoload option など、同期実行できる限定的なテストだけを行います。全体の `status`、状態別件数、
各テストの名前・ラベル・状態・HTMLを除いた説明を返します。外部 HTTP、loopback、非同期テスト、
修正操作へのリンク、内部パスは対象外です。

### `od-mcp-bridge/get-content-summary`

入力はありません。投稿と固定ページについて、公開数、下書き数、直近30日間の公開数・更新数を
UTC 基準で返します。個別コンテンツの本文は含みません。

### `od-mcp-bridge/get-stale-content`

長期間更新されていない公開済み投稿または固定ページを、更新日時の古い順で返します。

| パラメータ | 型 | 初期値 | 内容 |
| --- | --- | --- | --- |
| `older_than_days` | 整数 | `730` | 最終更新からの経過日数。1〜3650 |
| `post_type` | 文字列 | `post` | `post` または `page` |
| `per_page` | 整数 | `20` | 最大取得件数。1〜100 |

結果には UTC の基準日時 `cutoff` と、各項目の ID、種別、タイトル、更新日時、公開 URL が
含まれます。

### `od-mcp-bridge/get-cron-status`

`per_page`（初期値20、1〜100）で返す予定イベント数を制限できます。全イベント数、期限超過数、
同じ時刻・hook・schedule を持つ重複候補数と、hook、次回実行時刻、schedule、interval、
期限超過状態を返します。イベント引数は機密情報を含む可能性があるため返しません。

### `od-mcp-bridge/get-maintenance-snapshot`

サイト情報、更新、サイトヘルス、プラグイン、テーマ、コンテンツ、Cron を一度に確認します。
各セクションは、対象 Ability が有効で権限を満たせば `available` とデータを返し、無効・権限不足・
実行エラーの場合は `unavailable` と理由を返します。あるセクションの失敗で全体が失敗しないため、
定期的な保守確認の入口として利用できます。必要なセクションの Ability も個別に有効化してください。

## 接続を終了・停止する

MCP 接続を恒久的に止める場合は、次のいずれかを行います。

- MCP クライアントから OD MCP Bridge の server 設定を削除する
- 専用ユーザーのプロフィールで、使用中のアプリケーションパスワードを失効する
- 「設定」→「OD MCP Bridge」で不要な Ability を無効にする
- すべて停止する場合は、OD MCP Bridge プラグインを無効化する

アプリケーションパスワードが外部へ漏れた可能性がある場合は、MCP クライアントの設定変更より
先に WordPress 側で失効してください。必要であれば、新しいアプリケーションパスワードを
改めて発行します。

## プラグインを更新する

OD MCP Bridge には GitHub ベースのアップデーターが組み込まれています。GitHub Releases に
新しい配布バージョンが公開され、利用中の WordPress から GitHub へ接続できる場合は、通常の
プラグイン更新と同じ画面から更新できる構成です。

更新前には、次の内容を確認してください。

- リリースノートに互換性や設定変更の案内がないか
- WordPress と PHP の必要バージョンを満たしているか
- 可能であればステージング環境で接続確認できるか
- 利用中の Ability が更新後も有効になっているか

## トラブルシューティング

### 「Status」が「Unavailable」になる

WordPress MCP Adapter の読み込みに失敗しています。GitHub のソースコード ZIP ではなく、
Composer 依存パッケージを含む配布用 ZIP をインストールしたか確認してください。

### endpoint が 404 になる

- 「設定」→「OD MCP Bridge」に表示された URL をそのまま使用しているか確認する
- WordPress REST API が利用できるか確認する
- パーマリンクや Web server の rewrite (URL を内部処理へ振り分ける仕組み) 設定を確認する
- セキュリティープラグインや WAF (不正な Web 通信を遮断する仕組み) が
  `/wp-json/mcp/` を遮断していないか確認する

実サイトのパーマリンク設定を変更すると既存 URL へ影響する場合があります。原因がはっきりしない
状態で変更せず、まずサイト管理者やホスティング事業者へ確認してください。

### HTTP 401 になる

認証情報を付けていない場合、HTTP 401 は「認証されていない」ことを表す正常な拒否応答です。
認証済み接続でも 401 になる場合は、
次の内容を確認します。

- `WP_API_USERNAME` が専用ユーザーのユーザー名になっているか
- `WP_API_PASSWORD` に通常のログインパスワードではなく、アプリケーションパスワードを設定したか
- アプリケーションパスワードを失効していないか
- `OAUTH_ENABLED` が `false` になっているか
- CDN (Web コンテンツの配信を中継する仕組み)、WAF、Web server が `Authorization`
  header を WordPress まで転送しているか

### 必要な Ability が表示されない

- 「設定」→「OD MCP Bridge」で対象の Ability が有効になっているか確認する
- 設定変更後に MCP クライアントを再起動する
- `mcp-adapter-discover-abilities` を改めて実行する
- 接続ユーザーが対象 Ability の capability を持っているか、上の一覧で確認する

### `get-post` でエラーになる

`post_id` が整数になっているか、対象が公開済みの投稿か確認してください。固定ページ、下書き、
非公開投稿は取得できません。先に `get-posts` を実行すると、取得可能な投稿 ID を確認できます。

### Node.js のバージョンエラーになる

MCP Inspector と `@automattic/mcp-wordpress-remote` の実行環境で、Node.js 22.19 以上を
使用してください。WordPress が動いている Web server 側ではなく、MCP クライアントを動かす
パソコン側の Node.js バージョンです。

## セキュリティー確認リスト

- 本番 endpoint は HTTPS を使用している
- MCP 接続には、利用する Ability に必要な権限だけを持つ専用ユーザーを使用している
- 通常のログインパスワードを MCP クライアントへ渡していない
- アプリケーションパスワードをリポジトリや Issue へ保存していない
- 必要な Ability だけを有効にしている
- 使わなくなったアプリケーションパスワードを失効している
- 認証情報が漏れた可能性があれば、最初に WordPress 側で失効している

現時点の機能は読み取り専用ですが、公開済み投稿の本文やサイト情報を取得できる点は変わりません。
接続先と認証情報を適切に管理したうえで利用してください。

## 参考リンク

- [OD MCP Bridge](https://github.com/Olein-jp/od-mcp-bridge)
- [OD MCP Bridge Releases](https://github.com/Olein-jp/od-mcp-bridge/releases)
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)
- [MCP WordPress Remote](https://github.com/Automattic/mcp-wordpress-remote)
- [WordPress REST API Handbook: Authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/#basic-authentication-with-application-passwords)
- [MCP Inspector](https://github.com/modelcontextprotocol/inspector)
