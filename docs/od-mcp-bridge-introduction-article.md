# WordPress と AI クライアントを安全につなぐ「OD MCP Bridge」を作りました

生成 AI を使って文章を考えたり、情報を整理したりする機会は、ずいぶん増えてきました。一方で、WordPress の情報を AI クライアントから直接確認したいと思うと、どの情報を渡すのか、どこまで操作を許可するのかという問題が出てきます。

そこで今回、WordPress と MCP クライアントをつなぐための WordPress プラグイン「OD MCP Bridge」を作りました。

MCP は Model Context Protocol の略で、AI アプリケーションと外部のサービスやデータを共通の方法で接続するための仕様です。OD MCP Bridge を使うと、Codex、Claude、Visual Studio Code、Cursor、Gemini CLI などの MCP に対応したクライアントから、WordPress に登録された機能を呼び出せるようになります。

ただし、このプラグインが目指しているのは、AI に WordPress の管理権限を丸ごと渡すことではありません。必要な情報と操作だけを、WordPress の権限に沿って公開することを大切にしています。

この記事では、OD MCP Bridge でできること、導入の流れ、利用前に確認しておきたい注意点を紹介します。

## OD MCP Bridge とは

OD MCP Bridge は、WordPress 6.9 から利用できる [Abilities API](https://developer.wordpress.org/apis/abilities-api/) と、WordPress 公式の [MCP Adapter](https://github.com/WordPress/mcp-adapter) を橋渡しするプラグインです。

Abilities API は、WordPress やプラグインが持つ機能を「Ability」という単位で登録する仕組みです。Ability には、受け取る値、返す値、実行に必要な権限などを定義できます。そのため、AI クライアント側は、利用できる機能と使い方を機械的に確認してから実行できます。

MCP Adapter は、その Ability を MCP クライアントから呼び出せるように変換します。OD MCP Bridge は、その上で実際のサイト運用に使いやすい Ability と、公開範囲を管理するための設定画面を追加しています。

執筆時点の主な動作条件は次のとおりです。

| 項目 | 内容 |
| --- | --- |
| プラグイン | OD MCP Bridge 0.4.0 |
| 必要な WordPress | 6.9以上 |
| 動作確認済みの WordPress | 7.0まで |
| 必要な PHP | 7.4以上 |
| ライセンス | GPL-2.0-or-later |
| 配布場所 | [GitHub Releases](https://github.com/Olein-jp/od-mcp-bridge/releases) |

WordPress 6.9以上が必要なのは、Abilities API が WordPress 6.9 で導入されたためです。古い WordPress へそのまま追加できるプラグインではない点には注意してください。

## OD MCP Bridge でできること

OD MCP Bridge 0.4.0 には、合計16件の Ability が用意されています。すべてが最初から有効になるわけではなく、扱う情報や操作の性質によって初期状態が分けられています。

### 公開済みコンテンツを確認する

インストール直後は、公開情報を扱う次の6件が有効です。

- サイト名や URL などの基本情報を取得する
- 公開済み投稿の一覧を取得する
- 指定した公開済み投稿の本文を取得する
- 公開済み固定ページの一覧を取得する
- 指定した公開済み固定ページの本文を取得する
- カテゴリーまたはタグの一覧を取得する

たとえば、AI クライアントから既存記事の一覧を確認し、内容の重複や更新候補を整理するといった使い方ができます。

取得対象は公開済みの投稿と固定ページに限定されています。下書き、非公開コンテンツ、ユーザー情報、認証情報などを返さない設計です。

### 保守に必要な情報を確認する

保守情報を扱う9件の Ability は、初期状態では無効です。WordPress 管理画面の「設定」→「OD MCP Bridge」から、必要な機能だけを有効にします。

確認できる情報は次のとおりです。

- WordPress、プラグイン、テーマの更新状況
- インストールされているプラグインの概要
- インストールされているテーマの概要
- 外部通信を伴わない範囲のサイトヘルス結果
- 公開コンテンツの件数や更新状況
- 長期間更新されていないコンテンツ
- WP-Cron の実行予定と遅延状況
- 複数の保守情報をまとめたスナップショット
- HTTPS、デバッグ表示、ファイル編集などのセキュリティ設定の要約

WP-Cron は、予約投稿や定期処理などを予定した時刻に動かすための WordPress の仕組みです。OD MCP Bridge は、登録された処理へ渡される引数を除外して状況を返します。設定値や内部情報を必要以上に AI クライアントへ渡さないためです。

これらの情報は、日々の保守作業で状況を把握する入口として使えます。ただし、セキュリティ診断やバックアップ、実際の更新作業そのものを置き換える機能ではありません。AI の回答だけで判断を完結させず、WordPress 管理画面やサーバー側でも確認する前提で使うのが良いでしょう。

### 通常投稿の下書きを作成する

書き込みを行う Ability として、通常投稿の下書きを作成する `create-post-draft` も用意しています。この機能も初期状態では無効です。

有効にすると、AI クライアントから記事タイトルと本文を送り、WordPress に下書きを作成できます。ただし、プラグイン側で次の制限を設けています。

- 作成できるのは通常投稿の下書きだけ
- AI クライアントから投稿タイプ、公開状態、投稿者を指定できない
- 既存投稿の更新、公開、削除はできない
- 実行する WordPress ユーザーに `edit_posts` 権限が必要
- 同じリクエストが通信上の理由で再送されても、下書きを重複作成しない

最後の仕組みは「冪等性 (べきとうせい)」と呼ばれます。同じ識別子を持つ処理を複数回実行しても、結果が重複しないようにする考え方です。MCP クライアントから WordPress への通信が途中で切れ、同じ処理が再送された場合の二重投稿を避けるために使っています。

AI に記事のたたき台を作ってもらい、WordPress の下書きまで送るところは効率化できます。一方、内容の確認と公開は人が WordPress 管理画面から行います。この境界は、現時点では残しておいた方が運用しやすいと僕は考えています。

## 権限を小さく分けて接続する

個人的には、AI 連携では「何ができるか」と同じくらい、「何ができないか」を把握できることが大切だと考えています。

OD MCP Bridge は、WordPress のユーザー権限を使って各 Ability の実行可否を判断します。接続には普段の管理者アカウントを使わず、用途ごとに専用ユーザーを作成するのが基本です。

| 利用目的 | 適した専用ユーザー |
| --- | --- |
| 公開コンテンツだけを読む | 購読者 |
| 保守情報を読む | MCP Maintenance Reader |
| 投稿の下書きを作る | `edit_posts` を持つ投稿者など |

「MCP Maintenance Reader」は、このプラグインが追加する読み取り専用の権限グループです。保守情報の参照に必要な独自権限を持ちますが、プラグインの有効化、テーマの変更、WordPress の設定変更などはできません。また、投稿の下書きを作成する `edit_posts` も付与されません。

1人の接続ユーザーへすべての権限を集めるよりも、公開コンテンツ確認用、保守確認用、下書き作成用に分けた方が、認証情報が漏れた場合の影響範囲を小さくできます。少し手間は増えますが、実サイトと AI を接続するなら省略したくない部分です。

## 接続には Application Password を利用する

基本的な接続では、WordPress の Application Password を利用します。

Application Password は、外部のアプリケーションから WordPress の API へ接続するために発行する専用の認証情報です。普段 WordPress の管理画面へログインするときのパスワードとは別に作成でき、不要になったものだけを個別に失効できます。

[WordPress の公式ドキュメント](https://developer.wordpress.org/advanced-administration/security/application-passwords/)でも、外部ツールへ通常のログインパスワードを渡さずに API 認証する方法として案内されています。通信中の認証情報を保護するため、本番環境では必ず HTTPS を使ってください。

OD MCP Bridge は、Application Password をプラグイン設定へ保存しません。認証情報は、MCP クライアント側の環境変数や OS のシークレットストアで管理します。リポジトリ、Issue、ログなど、第三者が見られる場所へ記載しないよう注意が必要です。

外部の認可サーバーを用意できる環境では、OAuth 2.1 のリソースサーバーモードも利用できます。このモードでは、外部の認可サーバーが発行したアクセストークンを検証し、トークンに含まれる Scope と WordPress のユーザー権限の両方を確認します。

Scope は、アクセストークンに許可する操作範囲です。OD MCP Bridge では、コンテンツの読み取り、コンテンツの書き込み、保守情報の読み取りを分けています。ただし、OD MCP Bridge 自体がログイン画面やアクセストークンを発行するわけではありません。OAuth を使う場合は、別途、認可サーバーの構築と設定が必要です。

## インストールと初期設定の流れ

詳しい手順は [OD MCP Bridge 利用マニュアル](https://github.com/Olein-jp/od-mcp-bridge/blob/main/docs/user-manual.md) にまとめています。ここでは、全体の流れだけを紹介します。

### 1. 配布 ZIP をインストールする

[GitHub Releases](https://github.com/Olein-jp/od-mcp-bridge/releases) から、バージョン番号の付いた `od-mcp-bridge-0.4.0.zip` をダウンロードします。

GitHub の「Source code (zip)」ではなく、Release に添付されたプラグイン用 ZIP を利用してください。プラグインの実行に必要な Composer の依存パッケージが、配布用 ZIP には含まれています。

WordPress 管理画面の「プラグイン」→「プラグインを追加」→「プラグインのアップロード」から ZIP を選び、インストールして有効化します。

### 2. 接続状態を確認する

「設定」→「OD MCP Bridge」を開くと、MCP の接続先 URL と現在公開されている Ability を確認できます。

同じ画面にある接続診断では、HTTPS、Application Password、MCP Adapter、専用ロール、Ability ごとの権限などを、外部通信なしで確認できます。「Ready」以外の項目があれば、表示された内容を確認して設定を見直します。

### 3. 接続専用ユーザーを作成する

利用したい Ability に合わせて、購読者、MCP Maintenance Reader、投稿者などの専用ユーザーを作成します。管理者アカウントをそのまま MCP 接続へ使うのは避けましょう。

### 4. Application Password を発行する

接続専用ユーザーの「プロフィール」にある「Application Passwords」で、用途が分かる名前を付けて発行します。

表示された Application Password をコピーできるのは発行時の1回だけです。MCP クライアント側へ設定した後は、安全な場所で管理してください。

### 5. MCP クライアントへ登録する

MCP クライアントからインターネット上の WordPress へ接続する場合は、`@automattic/mcp-wordpress-remote` を使って、クライアントの標準入出力と WordPress の HTTP エンドポイントを中継します。

設定方法や設定ファイルの場所は、Codex、Claude、Visual Studio Code、Cursor などのクライアントによって異なります。利用中のバージョンに対応した各クライアントの公式ドキュメントと、OD MCP Bridge の利用マニュアルを確認してください。

## どのような場面に向いているか

OD MCP Bridge は、次のような使い方に向いています。

- 公開済みの記事や固定ページを AI と一緒に棚卸ししたい
- 長期間更新していないコンテンツの候補を整理したい
- WordPress の更新状況や WP-Cron の状態を、保守確認の入口として使いたい
- AI で作った原稿を、公開せずに WordPress の下書きへ送りたい
- AI に渡す WordPress の権限と機能を、小さな単位で管理したい

反対に、次のような用途には、そのままでは向いていません。

- WordPress 6.8以前のサイトで利用する
- AI から投稿を自動公開、更新、削除する
- メディアファイルやカスタム投稿タイプを操作する
- 認可サーバーなしで OAuth 2.1 の認可フローを完結させる
- WordPress の保守、バックアップ、セキュリティ対策をすべて代替する

また、MCP の設定には、JSON の設定ファイル、環境変数、Node.js などの知識が必要になる場合があります。現時点では、WordPress の管理画面だけで接続が完了するプラグインではありません。まずはローカル環境や検証用サイトで接続を試し、取得される情報と実行できる操作を確認してから本番環境へ導入するのが安心です。

## 利用前に確認しておきたいこと

最後に、導入前に確認しておきたい点をまとめます。

### AI クライアント側のデータの扱いを確認する

OD MCP Bridge が取得した情報は、接続先の MCP クライアントへ渡されます。公開済みコンテンツだけであっても、利用する AI サービスのデータ取り扱い、保存期間、組織ポリシーを確認してください。

保守情報を有効にする場合は、サイト構成に関する情報も含まれます。必要な Ability だけを有効にし、用途が終わったら無効にする運用をおすすめします。

### 認証情報を使い回さない

Application Password は、クライアントや用途ごとに分けて発行します。接続を使わなくなったときや、漏えいした可能性があるときは、WordPress のプロフィール画面から該当する Application Password を失効させます。

### AI の結果をそのまま公開しない

投稿下書き作成は、原稿を WordPress へ移す手間を減らせます。しかし、事実確認、著作権、個人情報、表現の妥当性などは、公開前に人が確認する必要があります。

OD MCP Bridge が作成する投稿を下書きに限定しているのも、確認の工程を残すためです。便利さと安全性のバランスは運用によって変わりますが、公開までを一気に自動化しない方が良い場面は多いと考えています。

### 各自の判断と責任で利用する

OD MCP Bridge は、接続する WordPress の環境、ほかのプラグインとの組み合わせ、MCP クライアントの仕様や設定などによって、動作や結果が変わる可能性があります。利用前にはバックアップを用意し、まずは検証環境で十分に確認してください。

本プラグインは、内容と注意点を確認したうえで、各自の判断と責任のもとで利用をお願いします。本プラグインの利用、または利用できなかったことによって生じたいかなる損失や損害についても、当方は責任を負いません。あらかじめご了承ください。

## まとめ

OD MCP Bridge は、WordPress の公開コンテンツや保守情報を MCP クライアントから確認し、必要に応じて通常投稿の下書きを作成するためのプラグインです。

このプラグインで大切にしているのは、AI に大きな権限をまとめて渡すのではなく、専用ユーザー、WordPress の権限、Ability ごとの有効化を組み合わせ、利用できる範囲を小さく保つことです。

MCP と WordPress の連携は、まだ変化の大きい分野です。まずは検証環境で、どの情報が取得され、どの操作ができるのかを確認しながら試してみてください。WordPress と AI をつなぐ方法を検討している方の参考になれば嬉しいです。

## 参考リンク

- [OD MCP Bridge](https://github.com/Olein-jp/od-mcp-bridge)
- [OD MCP Bridge Releases](https://github.com/Olein-jp/od-mcp-bridge/releases)
- [OD MCP Bridge 利用マニュアル](https://github.com/Olein-jp/od-mcp-bridge/blob/main/docs/user-manual.md)
- [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/)
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)
- [WordPress Application Passwords](https://developer.wordpress.org/advanced-administration/security/application-passwords/)
- [Model Context Protocol](https://modelcontextprotocol.io/docs/getting-started/intro)
