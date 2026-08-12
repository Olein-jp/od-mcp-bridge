# OD MCP Bridge

OD MCP Bridge は、WordPress Abilities API で登録した機能を、公式の
[WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) 経由で
MCP クライアントへ安全に公開するためのプラグインです。

実サイトへのインストールから MCP クライアントでの確認、各 Ability の入力項目、
トラブル対応までの詳しい手順は、[利用マニュアル](docs/user-manual.md)を参照してください。

## 提供する Ability

すべて読み取り専用です。初期状態では、公開情報を扱う次の6件が有効です。

- `od-mcp-bridge/get-site-info`: サイト基本情報
- `od-mcp-bridge/get-posts` / `get-post`: 公開済み投稿の一覧・本文
- `od-mcp-bridge/get-pages` / `get-page`: 公開済み固定ページの一覧・本文
- `od-mcp-bridge/get-terms`: カテゴリーまたはタグの一覧

保守情報を扱う次の9件は初期状態で無効です。管理画面で必要なものだけを有効にし、
各 Ability が要求する権限を持つ専用ユーザーで利用してください。

- `get-update-status`: キャッシュ済み更新状況
- `get-plugins` / `get-themes`: サニタイズ済みプラグイン・テーマ情報
- `get-site-health`: 外部通信を伴わない限定的なサイトヘルス結果
- `get-content-summary` / `get-stale-content`: コンテンツ活動・長期未更新コンテンツ
- `get-cron-status`: 引数を除外した WP-Cron 状況
- `get-maintenance-snapshot`: 上記の保守情報を権限境界付きで集約
- `get-security-posture`: 外部通信や認証情報の取得を行わないセキュリティ設定要約

下書きや非公開コンテンツ、ユーザー情報、認証情報、ファイルパス、Cron 引数は返しません。
詳細な権限と入出力は[利用マニュアル](docs/user-manual.md#ability-一覧と必要権限)を参照してください。

## 必要な環境

- Docker
- Node.js 22.19 以上 / npm（HTTP MCP 検証時）
- WordPress 6.9 以上
- PHP 7.4 以上
- Composer 2

## 開発環境

```bash
composer install
npm install
npm run env:start
npm run env:cli -- rewrite structure '/%postname%/' --hard
```

WordPress は `http://localhost:8888`、管理画面は
`http://localhost:8888/wp-admin` で開けます。wp-env の初期認証情報は
ユーザー名 `admin`、パスワード `password` です。

```bash
npm run env:status
npm run env:logs
npm run env:stop
```

### 自動テスト

Docker と wp-env を起動した状態で、WordPress の専用テスト DB を使う統合テストを実行します。
テストデータは各テスト内で作成されるため、開発環境の投稿やユーザーには依存しません。

```bash
npm run env:start
composer test:integration
composer lint
```

`composer test:integration` は wp-env の `tests-cli` コンテナで PHPUnit を実行します。
テストでは WordPress 6.9以上、Ability の登録とスキーマ、権限、公開コンテンツの絞り込み、
保守情報のサニタイズ、設定による無効化、保守スナップショットの失敗分離、
セキュリティ設定要約の権限・情報漏えい・外部通信禁止を確認します。

WordPress 管理画面の「設定 → OD MCP Bridge」では、MCP エンドポイントの確認と、
公開する Ability の有効・無効を設定できます。接続診断では、HTTPS、Application Password、
MCP Adapter、専用ロール、Abilityごとの必要capabilityを外部通信なしで確認できます。
初期状態では公開情報を扱う6件だけが有効です。

## MCP 接続

HTTP エンドポイントは次のURLです。

```text
https://example.com/wp-json/mcp/mcp-adapter-default-server
```

本番環境では必ず HTTPS の endpoint を使用してください。

### 専用ユーザーと Application Password

1. 管理者で「ユーザー → ユーザーを追加」を開き、MCP 接続専用ユーザーを作成します。
2. 公開コンテンツ系だけを使う場合は「購読者（Subscriber）」、保守系も使う場合は
   プラグインが作成する「MCP Maintenance Reader」ロールを選びます。この専用ロールには
   プラグイン有効化、テーマ変更、設定変更などのWordPress管理権限は含まれません。
3. 専用ユーザーでログインし、「ユーザー → プロフィール」の「Application Passwords」で
   `OD MCP Bridge` などの識別しやすい名前を入力して発行します。
4. 表示された Application Password は一度だけコピーし、MCP クライアント側の
   環境変数またはシークレットストアで管理します。WordPress の通常のログインパスワードは
   使用しません。
5. 接続確認を終えた場合やクライアントを廃止した場合は、同じプロフィール画面の
   Application Passwords 一覧から該当するものを失効させます。

Application Password、通常のログインパスワード、実際のユーザー名を、このプラグインの
設定、WordPress option、リポジトリ、Issue、ログへ保存しないでください。Application
Password の詳細は [WordPress REST API Handbook](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/#basic-authentication-with-application-passwords)
も参照してください。

### MCP クライアント設定

`@automattic/mcp-wordpress-remote` を stdio MCP server として起動し、WordPress の
HTTP endpoint へ中継します。次はサンプル値だけを含む設定例です。実際の値はローカルの
MCP クライアント設定または、そのクライアントを起動する環境から渡してください。

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

### MCP Inspector での確認

シェルの環境変数へ実サイトの値を設定します。値を含むコマンドをシェル履歴へ残さない
方法で設定してください。

```bash
export WP_API_URL='https://example.com/wp-json/mcp/mcp-adapter-default-server'
export WP_API_USERNAME='mcp-reader'
export WP_API_PASSWORD='<APPLICATION_PASSWORD>'
export OAUTH_ENABLED='false'
```

以下の関数は、環境変数を MCP Inspector が起動するプロキシへ渡します。

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

まず、default server の3つの MCP tool を確認します。

```bash
mcp_inspector --method tools/list
```

- `mcp-adapter-discover-abilities`
- `mcp-adapter-get-ability-info`
- `mcp-adapter-execute-ability`

次に、公開中の Ability を検出します。初期設定では、サイト情報、投稿、固定ページ、
カテゴリー・タグを扱う6件が含まれることを確認します。

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-discover-abilities \
  --tool-args-json '{}'
```

検出された Ability はすべて `mcp-adapter-execute-ability` から実行できます。
`get-post` の `post_id` は実サイトに存在する公開済み投稿 ID へ置き換えてください。

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-site-info","parameters":{}}'

mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-posts","parameters":{"per_page":5}}'

mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-post","parameters":{"post_id":1}}'

mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-pages","parameters":{"per_page":5}}'

mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-page","parameters":{"page_id":2}}'

mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-terms","parameters":{"taxonomy":"category","per_page":20}}'
```

認証拒否も確認します。次のリクエストは Application Password を送らないため、HTTP
`401` になる必要があります。

```bash
curl --include \
  --request POST \
  --header 'Accept: application/json, text/event-stream' \
  --header 'Content-Type: application/json' \
  --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"od-mcp-bridge-check","version":"1.0.0"}}}' \
  "$WP_API_URL"
```

不正な認証情報でも確認する場合は、実際の Application Password をコマンドへ含めず、
`--user "$WP_API_USERNAME:definitely-not-valid"` を追加して同じリクエストを送ります。
確認後は `unset WP_API_PASSWORD` を実行してください。

ローカルの wp-env では、WP-CLI の STDIO transport でも確認できます。

```bash
npm run env:cli -- mcp-adapter list
npm run env:cli -- mcp-adapter serve \
  --server=mcp-adapter-default-server \
  --user=admin
```

default server では、各 Ability は個別のMCP toolとして直接列挙されません。
`mcp-adapter-discover-abilities` で検出し、`mcp-adapter-execute-ability` から実行します。

## リリース

`od-mcp-bridge.php` の `Version`、`package.json` の `version`、`readme.txt` の
`Stable tag` を同じ値へ更新してコミットした後、`1.2.3` のような `0.0.0`
形式（`v` 接頭辞なし）のタグを push します。

```bash
git tag 0.1.1
git push origin 0.1.1
```

タグとプラグインヘッダーのバージョンが一致すると、GitHub Actions が
本番用 Composer 依存を含む ZIP を作成し、同名の GitHub Release に添付します。
ローカルでは次のコマンドで同じ ZIP を生成できます。

```bash
npm run package -- 0.2.0
```
