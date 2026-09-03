# OD MCP Bridge 利用マニュアル

このマニュアルは、OD MCP Bridge を WordPress サイトへ導入し、MCP クライアントから
公開コンテンツやサイト保守情報を安全に読み取り、必要に応じて投稿・固定ページの下書きや
テンプレートパーツを作成する手順をまとめたものです。

OD MCP Bridge は、WordPress に登録した機能を AI クライアントから呼び出せるようにする
プラグインです。読み取り機能に加え、初期状態で無効な3つの write 系機能を提供します。
投稿や固定ページの公開、既存コンテンツの更新、削除は行いません。

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

## Codex 以外の AI サービスでも利用できます

このプラグインは Codex 専用ではありません。Codex は利用できる MCP クライアントの一つです。
OD MCP Bridge が WordPress 側で公開した Ability は、MCP を介して Claude、Visual Studio Code、
Cursor、Gemini CLI など、対応する別の AI アプリケーションからも利用できます。

このマニュアルでは、`@automattic/mcp-wordpress-remote` をローカルの stdio 形式 MCP サーバーとして
起動し、WordPress の HTTPS endpoint へ中継する方法を共通の基準にしています。そのため、別の
クライアントへ移る場合も、基本的には次の4点をそのクライアントの MCP 設定形式へ移します。

- 実行コマンド：`npx`
- 引数：`-y` と `@automattic/mcp-wordpress-remote@latest`
- WordPress 接続情報：`WP_API_URL`、`WP_API_USERNAME`、`WP_API_PASSWORD`
- Application Password 接続時の設定：`OAUTH_ENABLED=false`

クライアントによって、設定ファイル名、JSON や TOML の構造、ユーザー単位・プロジェクト単位の
保存場所、MCP ツールを実行する前の確認方法が異なります。このマニュアルの設定例をそのまま
貼り付けるのではなく、次の一次情報を確認して構成を移してください。

| 提供元・クライアント | 主な登録方法 | 公式ドキュメント |
| --- | --- | --- |
| OpenAI Codex／ChatGPTデスクトップアプリ | 設定画面、`codex mcp`、または `config.toml` | [OpenAI公式：Model Context Protocol](https://developers.openai.com/codex/mcp/) |
| Anthropic Claude Code | `claude mcp` または MCP 設定 JSON | [Anthropic公式：Connect Claude Code to tools via MCP](https://code.claude.com/docs/en/mcp) |
| Anthropic Claude Desktop | デスクトップアプリの Extensions／MCP 設定 | [Anthropic公式：Getting Started with Local MCP Servers on Claude Desktop](https://support.claude.com/en/articles/10949351-getting-started-with-local-mcp-servers-on-claude-desktop) |
| Microsoft Visual Studio Code | コマンドパレットまたは `mcp.json` | [Microsoft公式：Add and manage MCP servers in VS Code](https://code.visualstudio.com/docs/agent-customization/mcp-servers) |
| Cursor | MCP 設定画面または設定ファイル | [Cursor公式：Model Context Protocol](https://docs.cursor.com/context/model-context-protocol) |
| Google Gemini CLI | `settings.json` の `mcpServers` | [Google公式：MCP servers with Gemini CLI](https://geminicli.com/docs/tools/mcp-server/) |

MCP の仕様と用語は、[Model Context Protocol 公式ドキュメント](https://modelcontextprotocol.io/docs/getting-started/intro)
を参照してください。各クライアントの MCP 対応状況は、アプリのバージョン、契約プラン、組織の
管理ポリシーによって変わる場合があります。

どの AI クライアントを使っても、WordPress 側の安全制御は共通です。有効な Ability、接続ユーザーの
capability、OAuth 利用時の scope を満たさなければ実行できません。一方、Ability を自動選択する精度、
回答の文章、write 系ツールを呼ぶ前に確認を求めるかどうかはクライアントごとに異なります。
`create-post-draft` を利用する場合は、クライアント側の確認機能だけに依存せず、作成後に WordPress の
編集画面で必ず内容を確認してください。

## 表示言語

OD MCP Bridge の管理画面は WordPress のサイト言語に従います。「設定」→「一般」の「サイトの言語」が
日本語の場合は、同梱された日本語翻訳が自動で使用されます。英語環境では原文の英語を表示します。
ほかの言語も gettext 形式の翻訳ファイルを追加することで対応できます。

Ability の識別名、JSON のキー、capability、OAuth scope は外部連携で使用する固定識別子であるため、
表示言語が変わっても翻訳されません。

## できることと、できないこと

Ability は、WordPress 側で実行できる個別の機能を表します。公開コンテンツ系6件と、
サイト保守系9件と、安全な書き込み系3件を利用できます。保守系と書き込み系は初期状態で無効です。

## Ability 一覧と必要権限

| Ability | 内容 | 必要な capability | OAuth scope | 初期状態 |
| --- | --- | --- | --- | --- |
| `get-site-info` | サイト基本情報 | `read` | `od-mcp:content:read` | 有効 |
| `get-posts` / `get-post` | 公開済み投稿の一覧・本文 | `read` | `od-mcp:content:read` | 有効 |
| `get-pages` / `get-page` | 公開済み固定ページの一覧・本文 | `read` | `od-mcp:content:read` | 有効 |
| `get-terms` | カテゴリーまたはタグ | `read` | `od-mcp:content:read` | 有効 |
| `create-post-draft` | 投稿下書きを新規作成 | `edit_posts`（カテゴリー指定時は `assign_terms` も必要） | `od-mcp:content:write` | 無効 |
| `create-page-draft` | 固定ページ下書きを新規作成 | `edit_pages` | Application Passwordのみ | 無効 |
| `create-template-part` | 現在のブロックテーマへテンプレートパーツを新規作成 | `od_mcp_bridge_create_template_parts` | Application Passwordのみ | 無効 |
| `get-update-status` | キャッシュ済み更新状況 | `od_mcp_bridge_view_*_updates` | `od-mcp:maintenance:read` | 無効 |
| `get-plugins` | プラグイン状態 | `od_mcp_bridge_view_plugins` | `od-mcp:maintenance:read` | 無効 |
| `get-themes` | テーマ状態 | `od_mcp_bridge_view_themes` | `od-mcp:maintenance:read` | 無効 |
| `get-site-health` | 限定的なサイトヘルス結果 | `od_mcp_bridge_view_site_health` | `od-mcp:maintenance:read` | 無効 |
| `get-content-summary` | 投稿・固定ページの件数と30日間の活動 | `od_mcp_bridge_view_content_summary` | `od-mcp:maintenance:read` | 無効 |
| `get-stale-content` | 長期間更新されていない公開コンテンツ | `read` | `od-mcp:maintenance:read` | 無効 |
| `get-cron-status` | WP-Cron の実行予定 | `od_mcp_bridge_view_cron` | `od-mcp:maintenance:read` | 無効 |
| `get-maintenance-snapshot` | 保守情報の集約結果 | `od_mcp_bridge_view_maintenance` | `od-mcp:maintenance:read` | 無効 |
| `get-security-posture` | セキュリティ設定の要約 | `od_mcp_bridge_view_security` | `od-mcp:maintenance:read` | 無効 |

OAuth接続では、表のScopeに加えてMCP接続とAbility探索用の `od-mcp:discover` が必要です。
ScopeだけではWordPressの権限を付与できず、対応するWordPress capabilityも満たす必要があります。

保守系の独自 capability は、プラグインが作成する「MCP Maintenance Reader」ロールと
管理者ロールへ付与されます。この専用ロールにはプラグイン有効化、テーマ変更、設定変更などの
WordPress管理権限を付与しないため、管理者をMCP接続へ使わずに保守情報を参照できます。
`MCP Maintenance Reader` には `edit_posts` を付与しないため、下書き作成には投稿者などの
投稿作成権限を持つ別の専用ユーザーを使用してください。

テンプレートパーツ作成用の `od_mcp_bridge_create_template_parts` は管理者ロールへ自動付与されますが、
MCP接続に管理者を使う必要はありません。専用ユーザーへこの capability だけを個別に付与してください。
たとえばWP-CLIでは、実際の専用ユーザー名へ置き換えて次のように設定できます。

```bash
wp user add-cap <専用ユーザー名> od_mcp_bridge_create_template_parts
```

## Codex などの AI クライアントから使うときのプロンプト例

OD MCP Bridge を MCP server として Codex などの AI クライアントに接続すると、通常は Ability 名や
JSON を直接指定せず、調べたいことを自然な日本語で依頼できます。対応クライアントは利用可能な
Ability を確認し、必要なものを実行して結果を読みやすく要約します。

以下の返答例にあるサイト名、件数、バージョン、日時は説明用のサンプルです。AI クライアントの回答形式は
依頼内容によって変わります。正規化された元データが必要な場合は、プロンプトの末尾に
「取得結果を省略せず JSON で示してください」と加えてください。

保守系 Ability は初期状態で無効です。利用前に「設定」→「OD MCP Bridge」で対象を有効にし、
必要な capability を持つ専用ユーザーで接続してください。無効な Ability は AI クライアントから発見できず、
権限が不足している場合は実行を拒否します。
書き込み系Abilityも初期状態では無効です。意図しない書き込みを避けるため、必要なサイトだけで
`create-post-draft`、`create-page-draft`、`create-template-part` のうち必要なものを有効にしてください。

### 1. サイトの基本情報を確認する：`get-site-info`

サイト名、URL、言語、タイムゾーン、WordPress バージョンを確認するときに使います。

> 接続している WordPress サイトの基本情報を確認してください。

AIクライアントは「サイト名は Example Site、URL は `https://example.com/`、言語は `ja`、
タイムゾーンは `Asia/Tokyo`、WordPress は 7.0」のように返します。

### 2. 公開済み投稿を探す：`get-posts`

公開済み投稿を検索し、タイトル、抜粋、公開・更新日時、URLを一覧で取得します。

> 公開済み投稿から「WordPress」を含む記事を更新日の新しい順に5件探してください。

AIクライアントは条件に一致した投稿を一覧にし、各投稿の ID、タイトル、抜粋、更新日時、URLと、
総件数・総ページ数を返します。下書き、予約投稿、非公開投稿は含みません。

### 3. 投稿本文を読む：`get-post`

`get-posts` で確認した投稿 ID を使い、公開済み投稿の本文を取得します。

> 投稿 ID 123 の本文を取得し、見出し構成と要点をまとめてください。

AIクライアントはタイトル、本文、抜粋、日時、URLを取得したうえで、依頼に合わせて要点を整理します。
本文には WordPress のブロックコメントや HTML が含まれる場合があります。

### 4. 公開済み固定ページを探す：`get-pages`

会社案内やお問い合わせなど、公開済み固定ページを検索・一覧取得します。

> 公開中の固定ページから「お問い合わせ」に関係するページを探してください。

AIクライアントは一致した固定ページの ID、タイトル、抜粋、日時、URLとページング情報を返します。
下書きや非公開の固定ページは含みません。

### 5. 固定ページ本文を読む：`get-page`

指定した公開済み固定ページの本文、親子関係、メニュー順を確認します。

> 固定ページ ID 456 の内容を読み、訪問者向けの案内事項を箇条書きにしてください。

AIクライアントはタイトル、本文、抜粋、日時、URLに加えて、`parent_id` と `menu_order` を取得し、
依頼された形式で内容を説明します。

### 6. カテゴリーやタグを確認する：`get-terms`

カテゴリーまたはタグの名前、slug、説明、公開投稿数を一覧取得します。

> サイトで使われているカテゴリーを投稿数の多い順に20件見せてください。

AIクライアントはカテゴリー ID、名前、slug、説明、公開投稿数を返します。「タグを」と依頼した場合は
`post_tag` を使います。カスタムタクソノミーや term meta は取得しません。

### 7. 更新待ちを確認する：`get-update-status`

WordPress 本体、プラグイン、テーマ、翻訳のキャッシュ済み更新情報を確認します。

> WordPress サイトに保留中の更新があるか確認し、種類ごとに整理してください。

AIクライアントは権限のある区分について、現在版、更新候補版、件数、最終確認日時を返します。
この確認のために外部更新チェックを強制しないため、WordPress の更新キャッシュが古い場合は
その旨も考慮する必要があります。

### 8. プラグイン構成を確認する：`get-plugins`

インストール済みプラグインのバージョン、有効状態、自動更新、更新有無を確認します。

> インストール済みプラグインを、有効・無効と更新の有無が分かる表にしてください。

AIクライアントは slug、表示名、バージョン、有効状態、ネットワーク有効状態、自動更新、更新有無を
表などに整理します。プラグインファイルの絶対パスや内容は返しません。

### 9. テーマ構成を確認する：`get-themes`

インストール済みテーマと、現在の有効テーマ、親テーマ、自動更新、更新有無を確認します。

> 現在の有効テーマとインストール済みテーマの状態を確認してください。

AIクライアントは stylesheet、表示名、バージョン、有効状態、親テーマ、自動更新、更新有無を返します。
テーマファイルの内容やサーバー上の絶対パスは取得しません。

### 10. Site Health の要点を確認する：`get-site-health`

安全な範囲に限定した同期テストから、良好・推奨・重大の状態を確認します。

> サイトヘルスを確認し、重大な問題と改善を推奨されている項目を優先して説明してください。

AIクライアントは全体状態、状態別件数、各テストの名前・状態・説明を返し、重要度順に要約できます。
外部 HTTP、loopback、非同期テスト、修正操作、内部パスは対象外です。

### 11. コンテンツ運用状況を確認する：`get-content-summary`

投稿と固定ページの公開数・下書き数、直近30日間の公開数・更新数を確認します。

> この30日間のコンテンツ運用状況を、投稿と固定ページに分けて教えてください。

AIクライアントは UTC 基準の集計期間と、投稿・固定ページ別の件数を返します。本文、タイトル、作成者など
個別コンテンツの情報は含みません。

### 12. 長期間更新されていないコンテンツを探す：`get-stale-content`

指定日数より長く更新されていない公開済み投稿または固定ページを探します。

> 2年以上更新されていない公開済み固定ページを、古い順に50件まで調べてください。

AIクライアントは基準日時とともに、対象の ID、種類、タイトル、最終更新日時、URLを返します。
内容が本当に古いかどうかは自動判定しないため、結果を更新候補の洗い出しとして利用します。

### 13. WP-Cron の状態を確認する：`get-cron-status`

予約イベントの総数、期限超過、重複候補、次回実行予定を確認します。

> WP-Cron の遅延と重複候補を確認し、注意が必要なイベントを教えてください。

AIクライアントは hook、次回実行日時、schedule、interval、期限超過状態を返します。同じ時刻・hook・
schedule の組み合わせを重複候補として数えますが、秘密情報を含み得る Cron 引数は返しません。

### 14. 保守状況をまとめて確認する：`get-maintenance-snapshot`

複数の保守系 Ability を一度に実行し、サイト全体の現状を確認する入口です。

> WordPress サイトの保守スナップショットを取得し、対応が必要そうな項目を整理してください。

AIクライアントはサイト基本情報、更新、Site Health、プラグイン、テーマ、コンテンツ、Cron を
セクションごとに整理します。個別 Ability が無効、権限不足、実行失敗の場合、そのセクションを
`unavailable` と理由コードで示し、取得できた他のセクションはそのまま返します。

### 15. セキュリティ設定を確認する：`get-security-posture`

HTTPS、WordPress更新、debug、ファイル編集、自動更新、管理者人数、Application Passwordの
設定状態を、外部通信なしで確認します。

> WordPressサイトのセキュリティ設定を確認し、`attention`、`recommended`、`unknown` の順に対応候補を説明してください。確認できないことは推測しないでください。

AIクライアントは「Core更新がキャッシュ上で利用可能」「本番環境でdebug表示が有効」「ファイルエディターが
利用可能」などの観測結果を重要度順に整理します。結果は設定状態の要約であり、脆弱性診断や
「安全である」という保証ではありません。

Application Passwordは利用可能かどうかだけを確認します。件数、名称、UUID、ハッシュ、
利用日時、IPアドレスは取得しません。管理者についても現在サイトの人数だけを返し、ユーザー名、
メールアドレス、ユーザーIDは返しません。

### 16. 投稿の下書きを作成する：`create-post-draft`

新しい投稿を、接続ユーザーを作成者とする下書きとして保存します。

> 「夏季休業のお知らせ」というタイトルで、8月13日から16日まで休業する案内文を投稿の下書きとして作成してください。公開はしないでください。

AIクライアントは本文を整え、重複防止用の UUID を生成して下書き作成を実行します。成功時は投稿 ID、
`draft` という状態、管理画面の編集 URL、新規作成か再送結果かを返します。同じ UUID と同じ内容を
再送しても投稿は増えません。タイトルや本文を変えて同じ UUID を再利用すると競合エラーになります。

この Ability では投稿タイプ、公開状態、作成者、公開日時、slugを指定できません。WordPress側が
常に通常投稿、下書き、現在の接続ユーザーへ固定します。作成後は編集 URL を開いて内容を確認し、
公開操作はWordPress管理画面から人が行ってください。

### 17. 固定ページの下書きを作成する：`create-page-draft`

新しい固定ページを、接続ユーザーを作成者とする下書きとして保存します。

> 「会社概要」というタイトルで、会社情報を掲載する固定ページの下書きを作成してください。公開はしないでください。

親固定ページと表示順も任意で指定できます。作成後は返された編集URLをWordPress管理画面で開き、
本文、階層、表示順を確認してから人が公開してください。

### 18. テンプレートパーツを作成する：`create-template-part`

現在のブロックテーマへ、新しいデータベース保存型テンプレートパーツを作成します。

> 「キャンペーンヘッダー」という名前で、slugが `campaign-header`、areaが `header` のテンプレートパーツを作成してください。

既存のテンプレートパーツは上書きせず、作成したパーツを既存テンプレートへ自動配置することもありません。
サイトエディターで内容を確認してから、使用するテンプレートへ配置してください。

このほかのAbilityはすべて読み取り専用です。次の情報や操作は対象に含まれません。

- 下書き、予約投稿、非公開投稿の取得
- ユーザー情報や認証情報の取得
- 対応する3種類以外の作成、既存コンテンツの更新、投稿・固定ページの公開、削除
- WordPress の設定変更
- サーバー内のファイルパスや WP-Cron の引数の取得
- サイトヘルス確認を目的とした外部 HTTP 通信

## プラグインをインストールする

### 1. 配布用 ZIP を準備する

GitHub Releases に配布用 ZIP が公開されている場合は、リリースに添付された
`od-mcp-bridge-0.0.0.zip` 形式のファイルを利用してください。

[GitHub Releases](https://github.com/Olein-jp/od-mcp-bridge/releases)

このマニュアルの内容に対応するバージョンは `0.4.1` です。現在の配布ファイルは
[od-mcp-bridge-0.4.1.zip](https://github.com/Olein-jp/od-mcp-bridge/releases/download/0.4.1/od-mcp-bridge-0.4.1.zip)
から取得できます。

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
- HTTPS、Application Password、OAuth設定の状態
- パーマリンク設定
- MCP Maintenance Readerロールの最小権限
- 有効なAbility数と、Abilityごとの必要capability・OAuth scope

「Ready」以外の項目がある場合はDetailsの説明を確認してください。この診断は外部のMCPクライアントへ
接続せず、ユーザー名やApplication Passwordも入力・保存しません。実際の認証を含む疎通確認は、
後述のMCP Inspectorの手順で行います。

## 公開する Ability を設定する

「設定」→「OD MCP Bridge」では、MCP クライアントへ公開する Ability を個別に切り替えられます。
初期状態では公開コンテンツ系6件だけが有効です。保守系は公開範囲と必要権限を確認してから
個別に有効化してください。write系の `create-post-draft`、`create-page-draft`、
`create-template-part` も初期状態では無効です。実際に作成操作が必要なサイトでのみ有効にし、
読み取り専用の接続では無効のままにしてください。

1. 公開したい Ability にチェックを入れる
2. 公開しない Ability のチェックを外す
3. 画面下部の「変更を保存」を押す

無効にした Ability は登録されなくなり、`mcp-adapter-discover-abilities` の結果にも
表示されません。利用目的に必要なものだけを有効にしておくと、公開範囲を把握しやすくなります。

## MCP 接続専用ユーザーを作成する

管理者アカウントを MCP 接続へ使わず、接続専用ユーザーを用意します。

1. 管理者で「ユーザー」→「ユーザーを追加」を開く
2. MCP 接続専用のユーザー名とメールアドレスを入力する
3. 公開コンテンツだけなら「購読者」、保守系も使うなら「MCP Maintenance Reader」、投稿下書きなら「投稿者」、固定ページ下書きなら「編集者」など必要な権限を持つロールを選択する
4. ユーザーを追加する

購読者は、初期状態の6件と `get-stale-content` を利用できます。「MCP Maintenance Reader」は
公開コンテンツ系に加えて、管理画面で有効にした保守系 Ability を利用できます。管理者を接続に
使う場合は、すべての管理情報へ到達できる認証情報になるため、保管と失効を特に厳格に行ってください。

「MCP Maintenance Reader」には `edit_posts` がないため、`create-post-draft` は実行できません。
読み取りと書き込みの用途を分離したい場合は、保守確認用と下書き作成用でユーザーとApplication
Passwordを分けてください。下書き作成だけを目的に管理者アカウントを使う必要はありません。

テンプレートパーツ作成では、ブロックテーマが有効であることに加え、接続ユーザーへ
`od_mcp_bridge_create_template_parts` capabilityを個別に付与します。この独自権限だけではテーマ変更、
プラグイン管理、WordPress設定変更はできません。

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

## OAuth 2.1で接続する

OAuth接続は、外部の認可サーバーが発行したアクセストークンをOD MCP Bridgeで検証する方式です。
OD MCP Bridgeは認可画面、クライアント登録、認可コード、リフレッシュトークンを発行しません。
これらは接続先の認可サーバーとMCPクライアントが担当します。

### 対応するアクセストークン

組み込みの検証機能は、次の条件を満たすJWTアクセストークンに対応します。

- 署名アルゴリズムが `RS256`
- `iss` が管理画面に設定したIssuerと完全に一致する
- `aud` が設定したOAuth resource URIを含む
- `sub` がWordPressユーザーに設定したOAuth subjectと完全に一致する
- 有効な `exp` を持ち、`nbf` と `iat` がある場合も有効期間内である
- `scope` が空白区切りで必要なScopeを含む
- 公開鍵をHTTPSのJWKS URIから取得できる

アクセストークン、認可コード、リフレッシュトークン、クライアントシークレットは
WordPressへ保存しません。JWKSから取得した公開鍵情報だけを短時間キャッシュします。

### WordPress側を設定する

1. 認可サーバーでAuthorization CodeとPKCE S256を有効にし、MCPクライアントを登録する
2. 「設定」→「OD MCP Bridge」のAuthenticationでIssuer、JWKS URI、OAuth resource URIを入力する
3. Authentication modeを最初は「Application Password and OAuth」、移行確認後は「OAuth only」にする
4. MCP専用ユーザーの編集画面を開き、「OD MCP Bridge OAuth」のOAuth subjectへトークンの `sub` を入力する
5. 公開コンテンツだけなら購読者、保守情報も使うならMCP Maintenance Reader、下書き作成なら投稿者など `edit_posts` を持つ専用ユーザーを割り当てる
6. 認可サーバーで `od-mcp:discover` と、用途に応じた `od-mcp:content:read`、`od-mcp:maintenance:read`、`od-mcp:content:write` をクライアントへ許可する

OAuth resource URIを空欄にすると、表示中のMCP endpointが使用されます。認可サーバーが
アクセストークンへ設定する `aud` と、一文字単位で同じ値にしてください。

OAuth接続でAbilityを探索すると、アクセストークンが持つScopeに対応するAbilityだけが返ります。
たとえば `od-mcp:discover od-mcp:content:read` のトークンには公開コンテンツ系だけが表示され、
保守系Abilityの探索結果と詳細は表示されません。Application Password接続の探索結果は従来どおりです。
投稿下書き作成を探索・実行するには `od-mcp:content:write` が必要で、読み取りScopeだけでは許可されません。
固定ページ下書きとテンプレートパーツの作成はApplication Password接続専用で、OAuthでは実行できません。

「Application Password and OAuth」は移行確認用です。このモードでは有効なApplication Passwordも
代替の認証経路になるため、OAuth移行後は「OAuth only」を推奨します。

### MetadataとChallengeを確認する

Protected Resource Metadataは次のURLで公開されます。

```text
https://example.com/wp-json/od-mcp-bridge/v1/oauth-protected-resource
```

認証なしでMCP endpointへアクセスすると `401` になり、`WWW-Authenticate` ヘッダーの
`resource_metadata` からこのURLを案内します。Scopeが不足する場合は `403` と、追加で必要な
Scopeを含むChallengeを返します。

```bash
curl --include \
  --request POST \
  --header 'Content-Type: application/json' \
  --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"oauth-check","version":"1.0.0"}}}' \
  'https://example.com/wp-json/mcp/mcp-adapter-default-server'
```

本番環境ではWebサーバーやリバースプロキシが `Authorization` と `WWW-Authenticate` を削除しない
ことも確認してください。実トークンをコマンド履歴、アクセスログ、Issue、チャットへ貼り付けないでください。

### 実サイトでOAuth E2Eを確認する

認可サーバーでAuthorization Code + PKCE S256を完了し、`od-mcp:discover` と
`od-mcp:content:read` を持つアクセストークンを取得してから実行します。対話的なシェル入力など、
トークンをシェル履歴へ残さない方法で環境変数を設定してください。

```bash
export OD_MCP_URL='https://example.com/wp-json/mcp/mcp-adapter-default-server'
export OD_MCP_METADATA_URL='https://example.com/wp-json/od-mcp-bridge/v1/oauth-protected-resource'
read -r -s OD_MCP_ACCESS_TOKEN
export OD_MCP_ACCESS_TOKEN
composer test:oauth:live
unset OD_MCP_ACCESS_TOKEN
```

このテストは、Metadataの取得、未認証時の401 Bearer Challenge、Bearerトークンを使ったMCP
initialize、session IDの受領、`od-mcp-bridge/get-site-info` の実行を確認します。成功すると
`OAuth MCP smoke test passed.` と表示します。失敗時のレスポンス本文やトークンは表示しません。

ブラウザクライアントが `Origin` ヘッダーを送る場合、WordPressのhome/site Origin以外は
既定で403になります。別Originを利用する統合では、テーマや連携用プラグインから次のように
完全なOriginを追加します。ワイルドカードやリクエスト値の無条件追加は行わないでください。

```php
add_filter(
	'od_mcp_bridge_allowed_origins',
	static function ( $origins ) {
		$origins[] = 'https://mcp-client.example.com';
		return $origins;
	}
);
```

### 外部プロバイダーへの対応

Opaque TokenやRS256以外のトークンを使用する場合は、WordPressの
`od_mcp_bridge_oauth_validate_token` フィルターでプロバイダー固有の検証を実装できます。
検証結果には `iss`、`sub`、`od_mcp_scopes` を含め、Issuer、Audience、期限、失効状態を
コールバック側で必ず検証してください。生トークンをログやDBへ保存してはいけません。

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

投稿下書きを作成する場合は、管理画面で `create-post-draft` を有効にし、`edit_posts` を持つ
専用ユーザーで実行します。`request_id` は実行ごとに新しい UUID を生成し、通信再送時だけ同じ値を使います。

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/create-post-draft","parameters":{"request_id":"550e8400-e29b-41d4-a716-446655440000","title":"夏季休業のお知らせ","content":"<!-- wp:paragraph --><p>8月13日から16日まで休業します。</p><!-- /wp:paragraph -->"}}'
```

固定ページ下書きを作成する場合は `create-page-draft` を有効にし、`edit_pages` を持つ専用ユーザーの
Application Passwordで実行します。

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/create-page-draft","parameters":{"request_id":"650e8400-e29b-41d4-a716-446655440000","title":"会社概要","content":"<!-- wp:paragraph --><p>会社概要を入力してください。</p><!-- /wp:paragraph -->"}}'
```

テンプレートパーツを作成する場合は `create-template-part` を有効にし、専用capabilityを持つユーザーの
Application Passwordで実行します。

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/create-template-part","parameters":{"request_id":"750e8400-e29b-41d4-a716-446655440000","title":"キャンペーンヘッダー","slug":"campaign-header","content":"<!-- wp:group --><div class=\"wp-block-group\"></div><!-- /wp:group -->","area":"header"}}'
```

保守スナップショットを使う場合は、管理画面でスナップショットと必要な保守系 Ability を
有効にしてから、「MCP Maintenance Reader」ロールの専用ユーザーで実行します。

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-maintenance-snapshot","parameters":{}}'
```

セキュリティ設定要約を使う場合は、管理画面で `get-security-posture` を有効にしてから、
同じ専用ユーザーで実行します。

```bash
mcp_inspector \
  --method tools/call \
  --tool-name mcp-adapter-execute-ability \
  --tool-args-json '{"ability_name":"od-mcp-bridge/get-security-posture","parameters":{}}'
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

### `od-mcp-bridge/create-post-draft`

初期状態では無効です。「設定」→「OD MCP Bridge」で有効にし、`edit_posts` を持つ専用ユーザーで
接続してください。入力は次の項目だけを受け付けます。

| パラメータ | 型 | 必須 | 内容 |
| --- | --- | --- | --- |
| `request_id` | UUID文字列 | 必須 | 接続ユーザー単位の重複防止キー |
| `title` | 文字列 | 必須 | サニタイズ後に空ではないタイトル。最大200文字 |
| `content` | 文字列 | 必須 | 投稿本文。空文字可、最大200,000文字 |
| `excerpt` | 文字列 | 任意 | 抜粋。最大5,000文字 |
| `categories` | 正の整数の配列 | 任意 | 存在するカテゴリーID。重複不可、最大20件 |

返却値は `id`、固定値 `draft` の `status`、`edit_url`、新規作成時に `true` となる `created` です。
同じユーザーが同じ `request_id` と同じ正規化済み内容を再送すると、既存下書きと
`created: false` を返します。異なる内容での再利用、同じ依頼の同時実行、作成済み投稿が既に
公開・削除されている場合は、新しい投稿を作らず競合エラーを返します。

`post_type`、`post_status`、`post_author`、日時、slugなどは入力できません。投稿は必ず通常投稿の
下書きとなり、作成者は現在の接続ユーザーへ固定されます。カテゴリー指定時はカテゴリーへの
割り当て権限も確認します。作成元と冪等性確認用の値は保護された投稿メタへ保存しますが、
認証情報、OAuth claim、ユーザー名、メールアドレスは保存しません。

新しく下書きを作成した場合の返却例です。

```json
{
  "id": 123,
  "status": "draft",
  "edit_url": "https://example.com/wp-admin/post.php?post=123&action=edit",
  "created": true
}
```

同じ `request_id` と同じ内容を再送した場合は、同じ `id` と `created: false` が返ります。
`edit_url` は認証済みのWordPress管理画面で開き、本文、カテゴリー、表示内容を確認してください。

### `od-mcp-bridge/create-page-draft`

初期状態では無効です。「設定」→「OD MCP Bridge」で有効にし、`edit_pages` を持つ専用ユーザーの
Application Passwordで接続してください。OAuth接続からは実行できません。

| パラメータ | 型 | 必須 | 内容 |
| --- | --- | --- | --- |
| `request_id` | UUID文字列 | 必須 | 接続ユーザー単位の重複防止キー |
| `title` | 文字列 | 必須 | サニタイズ後に空ではないタイトル。最大200文字 |
| `content` | 文字列 | 必須 | 固定ページ本文。空文字可、最大200,000文字 |
| `excerpt` | 文字列 | 任意 | 抜粋。最大5,000文字 |
| `parent_id` | 0以上の整数 | 任意 | 親にする既存固定ページID。初期値0 |
| `menu_order` | 0〜100,000の整数 | 任意 | 表示順。初期値0 |

固定ページは必ず `draft`、現在の接続ユーザーを作成者として保存します。公開状態、作成者、日時、
slugは指定できません。返却値と再送時の動作は `create-post-draft` と同じです。

### `od-mcp-bridge/create-template-part`

初期状態では無効で、Application Password接続専用です。現在のテーマがブロックテーマであり、
接続ユーザーが `od_mcp_bridge_create_template_parts` capabilityを持つ場合だけ実行できます。

| パラメータ | 型 | 必須 | 内容 |
| --- | --- | --- | --- |
| `request_id` | UUID文字列 | 必須 | 接続ユーザー単位の重複防止キー |
| `title` | 文字列 | 必須 | テンプレートパーツ名。最大200文字 |
| `slug` | 文字列 | 必須 | 小文字英数字とハイフンだけの一意なslug。最大200文字 |
| `content` | 文字列 | 必須 | ブロックマークアップ。空文字可、最大200,000文字 |
| `area` | 文字列 | 必須 | WordPressで許可された `header`、`footer`、`uncategorized` などの領域 |

テンプレートパーツは現在のテーマに属するデータベース保存型として作成されます。現在のテーマに
同じslugのファイル由来パーツがある場合や、いずれかのテーマ用に同じslugのデータベース由来パーツが
ある場合は、WordPressによる意図しないslug変更を避けるため、上書きせず競合エラーを返します。
作成しただけでは既存テンプレートへ自動挿入されません。サイトエディターで内容を確認し、必要な
テンプレートへ配置してください。

返却値は数値の `id`、`theme//slug` 形式の `template_id`、`publish` の `status`、`slug`、`theme`、
`area`、`created` です。WordPress内部ではテンプレートパーツを利用可能にするため `publish` として
保存されますが、既存テンプレートへ自動配置しないため、作成だけでサイト表示は変更されません。

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

### `od-mcp-bridge/get-security-posture`

入力はありません。`generated_at`、全体状態、WordPressのenvironment typeと、次の項目を返します。

- HTTPSの設定状態。HTTPSサーバー対応可否を調べる外部リクエストは実行しません
- キャッシュ済みWordPress Core更新状態
- debug、debug表示、debugログの有効・無効。ログの場所や内容は返しません
- 管理画面のファイルエディターとファイル変更の許可状態
- Coreマイナー自動更新、プラグイン・テーマ自動更新設定、過去のCore自動更新失敗
- 現在サイトの管理者ロール割り当て人数
- Application Passwordがサイト全体と現在ユーザーで利用可能か

各項目と全体状態は `good`、`recommended`、`attention`、`unknown` のいずれかです。
`attention`は脆弱性の確定ではなく、早めの確認を推奨する状態です。`unknown`は情報不足を表し、
安全・危険のどちらにも推測しません。

ユーザー名、メールアドレス、認証情報、nonce、secret key、DB情報、`wp-config.php`の内容、
絶対パス、プラグイン・テーマの詳細、Application Passwordの件数・個別情報は返しません。

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

### `create-post-draft` が表示されない・実行できない

- 「設定」→「OD MCP Bridge」で `create-post-draft` を有効にしたか確認する
- 接続ユーザーが `edit_posts` を持つ「投稿者」などのロールか確認する
- カテゴリー指定時は、指定したカテゴリーIDが存在し、カテゴリー割り当て権限があるか確認する
- OAuth接続では `od-mcp:discover` と `od-mcp:content:write` の両方があるか確認する
- 読み取り用の `od-mcp:content:read` だけでは下書き作成できない点を確認する

同じ `request_id` で内容を変更すると競合エラーになります。内容を変えて新しい下書きを作る場合は、
新しい UUID を使用してください。同一リクエストが処理中の場合も重複を避けるため競合エラーになります。
少し待ってから、内容と `request_id` を変えずに再送してください。以前作成した投稿を公開・削除した後も、
同じ `request_id` から別の投稿は作成されません。

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
- write系Abilityは必要なサイトだけで有効にし、作成後の内容を人が確認している
- テンプレートパーツ作成用ユーザーには専用capabilityだけを付与している
- OAuthで投稿下書きを作るクライアントだけに `od-mcp:content:write` を許可している
- 使わなくなったアプリケーションパスワードを失効している
- 認証情報が漏れた可能性があれば、最初に WordPress 側で失効している

公開コンテンツ・保守系 Ability は読み取り専用ですが、3つの作成AbilityはWordPressへ保存するwrite系です。
投稿・固定ページの公開や既存コンテンツの変更は行わないものの、WordPressへデータを書き込む点を理解し、
接続先、認証情報、有効なAbility、作成されたコンテンツを適切に管理してください。

## 参考リンク

- [OD MCP Bridge](https://github.com/Olein-jp/od-mcp-bridge)
- [OD MCP Bridge Releases](https://github.com/Olein-jp/od-mcp-bridge/releases)
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)
- [MCP WordPress Remote](https://github.com/Automattic/mcp-wordpress-remote)
- [WordPress REST API Handbook: Authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/#basic-authentication-with-application-passwords)
- [MCP Inspector](https://github.com/modelcontextprotocol/inspector)
