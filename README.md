# OD MCP Bridge

OD MCP Bridge の WordPress プラグイン開発用リポジトリです。

## 必要な環境

- Docker
- Node.js / npm
- PHP 7.4 以上
- Composer 2

## 開発環境

```bash
composer install
npm install
npm run env:start
```

WordPress は `http://localhost:8888`、管理画面は
`http://localhost:8888/wp-admin` で開けます。wp-env の初期認証情報は
ユーザー名 `admin`、パスワード `password` です。

```bash
npm run env:status
npm run env:logs
npm run env:stop
composer lint
```

## リリース

`od-mcp-bridge.php` の `Version`、`package.json` の `version`、`readme.txt` の
`Stable tag` を同じ値へ更新してコミットした後、`1.2.3` のような `0.0.0`
形式（`v` 接頭辞なし）のタグを push します。

```bash
git tag 0.0.1
git push origin 0.0.1
```

タグとプラグインヘッダーのバージョンが一致すると、GitHub Actions が
本番用 Composer 依存を含む ZIP を作成し、同名の GitHub Release に添付します。
ローカルでは次のコマンドで同じ ZIP を生成できます。

```bash
npm run package -- 0.0.0
```
