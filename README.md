# Privacy-first Server Log Analytics

[English](README.en.md)

Cookie、訪問者ID、フィンガープリント、ブラウザBeaconを使わず、Webサーバーが既に出力した日次アクセスログだけを集計する、PHP 8.1+ / SQLite の管理画面です。

保存するのは、日付単位のPV・404・Bot・HTTP状態、公開済みページ、許可済み参照元ドメイン分類、端末／OS／ブラウザ分類などの**集計値だけ**です。IP、UA原文、完全なReferrer、Query String、個別リクエスト、生ログの複製、訪問者数、セッション数は保存しません。

## 主な機能

- Apache/Nginx Combined形式（任意のvhost接頭辞も可）のgzip日次ログを冪等に取込
- 公開ルート一覧にあるページだけをPVとして保存。未知URLは404の安全な種類だけを集計
- 静的アセットをPVから除外し、既知Botを別集計
- 日次明細90日、月次集計24か月（設定で変更可）
- CSRF、セキュアセッション、ログイン試行制限、CSP、PDO prepared statement
- 日本語／英語の管理画面。設定の `locale` で選択

## すぐに始める

1. [設置手順](docs/installation.md) に従い、DocumentRoot外へprivate領域を作成します。
2. `config/config.example.php` をprivate領域の `config.php` としてコピーし、パス・対象プレフィックス・管理者パスワードハッシュを設定します。
3. 公開ページ一覧をprivate領域の `routes.json` として用意します。例は [`examples/routes.example.json`](examples/routes.example.json) です。
4. 日次ログの生成後に [`bin/daily-import.php`](bin/daily-import.php) をCronで実行します。

リリース前の確認:

```powershell
npm test
```

## 対応範囲と制約

ログ形式、導入、バックアップ、プライバシー境界は [`docs/`](docs/) にまとめています。Cloudfree形式を使う場合は、一般手順とは分けた[Cloudfree向け例](docs/cloudfree.md)を参照してください。

このソフトウェアはアクセスログ由来の集計だけを扱います。ブラウザから任意データを受け取るイベントAPIは提供しません。

## 更新履歴

- **1.0.1（2026-08-31）**: 日次Cron入口が解析本体を二重に読み込んで停止する問題を修正し、実際のCron入口を起動する回帰テストを追加しました。

## ライセンス

ソースコードと文書は [Apache License 2.0](LICENSE) です。特定の製品名、ロゴ、キャラクター、第三者のアイコンや実運用データはこの配布物に含まれません。

セキュリティ上の連絡方法は [SECURITY.md](SECURITY.md) を参照してください。
