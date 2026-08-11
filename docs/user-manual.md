# OD MCP Bridge 利用マニュアル

このマニュアルは、OD MCP Bridge バージョン0.1.0 を WordPress サイトへ導入し、
MCP クライアントから公開済み投稿を読み取れる状態にするまでの手順をまとめたものです。

OD MCP Bridge は、WordPress に登録した機能を AI クライアントから呼び出せるようにする
プラグインです。現時点では読み取り専用で、投稿の作成、編集、削除は行いません。

## 実サイトで試すための最短手順

最初に全体の流れだけを確認したい場合は、次の順番で進めてください。

1. 配布用 ZIP を WordPress へアップロードして有効化する
2. 「設定」→「OD MCP Bridge」で MCP server が有効になっていることを確認する
3. MCP 接続専用の購読者ユーザーを作成する
4. 専用ユーザーでアプリケーションパスワードを発行する
5. MCP クライアントへ endpoint (接続先 URL)、ユーザー名、アプリケーションパスワードを設定する
6. `mcp-adapter-discover-abilities` で3つの Ability を確認する
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

バージョン0.1.0 では、次の3つの Ability を利用できます。Ability は、WordPress 側で
実行できる個別の機能を表します。

| Ability | 内容 |
| --- | --- |
| `od-mcp-bridge/get-site-info` | サイト名、URL、言語、タイムゾーン、WordPress バージョンなどを取得する |
| `od-mcp-bridge/get-posts` | 公開済み投稿を検索し、ページ単位で一覧取得する |
| `od-mcp-bridge/get-post` | 指定した公開済み投稿の本文を取得する |

すべて読み取り専用です。次の情報や操作は対象に含まれません。

- 下書き、予約投稿、非公開投稿の取得
- 固定ページの取得
- ユーザー情報や認証情報の取得
- 投稿の作成、更新、削除
- WordPress の設定変更

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

## 公開する Ability を設定する

「設定」→「OD MCP Bridge」では、MCP クライアントへ公開する Ability を個別に切り替えられます。
初期状態では3つとも有効です。

1. 公開したい Ability にチェックを入れる
2. 公開しない Ability のチェックを外す
3. 画面下部の「変更を保存」を押す

無効にした Ability は登録されなくなり、`mcp-adapter-discover-abilities` の結果にも
表示されません。利用目的に必要なものだけを有効にしておくと、公開範囲を把握しやすくなります。

## MCP 接続専用ユーザーを作成する

管理者アカウントを MCP 接続へ使わず、接続専用ユーザーを用意します。

1. 管理者で「ユーザー」→「ユーザーを追加」を開く
2. MCP 接続専用のユーザー名とメールアドレスを入力する
3. 「権限グループ」で「購読者」を選択する
4. ユーザーを追加する

購読者は、WordPress 内部の権限を表す `read` capability を持ちます。OD MCP Bridge の
3つの Ability は `read` のみを要求するため、管理者権限を付ける必要はありません。

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
| `OAUTH_ENABLED` | バージョン0.1.0 では Application Password を使うため `false` |

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

初期設定では、結果に次の3件が含まれます。

- `od-mcp-bridge/get-site-info`
- `od-mcp-bridge/get-posts`
- `od-mcp-bridge/get-post`

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

### Ability が3件表示されない

- 「設定」→「OD MCP Bridge」で対象の Ability が有効になっているか確認する
- 設定変更後に MCP クライアントを再起動する
- `mcp-adapter-discover-abilities` を改めて実行する
- 接続ユーザーが `read` capability を持っているか確認する

### `get-post` でエラーになる

`post_id` が整数になっているか、対象が公開済みの投稿か確認してください。固定ページ、下書き、
非公開投稿は取得できません。先に `get-posts` を実行すると、取得可能な投稿 ID を確認できます。

### Node.js のバージョンエラーになる

MCP Inspector と `@automattic/mcp-wordpress-remote` の実行環境で、Node.js 22.19 以上を
使用してください。WordPress が動いている Web server 側ではなく、MCP クライアントを動かす
パソコン側の Node.js バージョンです。

## セキュリティー確認リスト

- 本番 endpoint は HTTPS を使用している
- MCP 接続には管理者ではなく専用の購読者を使用している
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
