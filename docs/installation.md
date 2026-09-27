# 設置手順

[English](installation.en.md)

## 構成

公開するのは `public/admin/` の `index.php` と `style.css` だけです。`app.php`、`src/`、`bin/`、`config.php`、`routes.json`、SQLite DB、セッション、ロック、ログ、バックアップは必ずDocumentRoot外へ置きます。

例（実際のアカウント名・ドメイン名に読み替えてください）:

```text
/home/ACCOUNT/public_html/example/admin/       公開管理画面
/home/ACCOUNT/private-analytics/               private領域
/home/ACCOUNT/private-analytics/config.php     実設定（Git管理外）
/home/ACCOUNT/private-analytics/routes.json    公開ルート一覧（Git管理外）
/home/ACCOUNT/private-analytics/data/          SQLite（Git管理外）
```

## 手順

1. private領域を作り、リポジトリの `app.php`、`src/`、`bin/` を配置します。
2. `config/config.example.php` をprivate領域へ `config.php` としてコピーします。`db_path` 等はprivate領域内、`site_prefix` は集計対象のURL接頭辞、`internal_hosts` は自サイトのホスト名だけにします。外部Referrerは `referrer_domains` の明示的な許可リストだけを保存し、その他はまとめて扱います。
3. `php bin/hash-password.php` でパスワードハッシュを作り、`config.php` の例示値を置換します。ハッシュをGit・Issue・公開画面へ載せません。
4. 公開ページだけを記した `routes.json` をprivate領域へ置きます。未知URLはPVにせず、404の安全な分類だけを集計します。
5. `public/admin/` だけを対象URLの公開ディレクトリへ置きます。private領域が公開rootの隣ではない場合は、Webサーバーの環境変数 `PRIVACY_LOG_ANALYTICS_PRIVATE_ROOT` にprivate領域の絶対パスを設定します。
6. `php /home/ACCOUNT/private-analytics/bin/preflight.php` を実行し、PDO SQLite・privateパス・ログ読取・書込を確認します。失敗した場合は公開運用を開始しません。
7. 日次ログが生成された後にCronを登録します。例は [`../examples/crontab.example`](../examples/crontab.example) です。

## パーミッション例

ホスティング環境の所有者・グループ設定を優先します。まずは次を安全な開始値にし、PHP実行ユーザーが読書きできることを確認してください。

```sh
chmod 755 /home/ACCOUNT/public_html/example /home/ACCOUNT/public_html/example/admin
chmod 644 /home/ACCOUNT/public_html/example/admin/index.php /home/ACCOUNT/public_html/example/admin/style.css
chmod 700 /home/ACCOUNT/private-analytics
chmod 700 /home/ACCOUNT/private-analytics/data /home/ACCOUNT/private-analytics/sessions /home/ACCOUNT/private-analytics/locks /home/ACCOUNT/private-analytics/state
chmod 600 /home/ACCOUNT/private-analytics/config.php /home/ACCOUNT/private-analytics/routes.json
chmod 600 /home/ACCOUNT/private-analytics/data/analytics.sqlite
```

既存DBの所有者を変える必要がある場合は、ホスティング事業者の実行ユーザーを確認してから行ってください。`777` は使いません。

## 管理画面

`https://example.test/example/admin/` をHTTPSで開きます。初回のログイン後、設定の `locale` を `ja` または `en` に変更できます。管理者認証CookieはSecure / HttpOnly / SameSite=Strictで、訪問者の解析には使いません。
