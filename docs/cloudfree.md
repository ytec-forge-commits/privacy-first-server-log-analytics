# Cloudfree形式のホスティング例

[English](cloudfree.en.md)

この章は、日次gzipアクセスログをユーザー領域へ保存でき、Cronが使えるCloudfree形式の共有ホスティング向けの例です。機能や画面名は変更される可能性があるため、導入時点の公式マニュアルを確認してください。

## 事前確認

設定変更の前に、次を読み取りで確認します。

- ユーザー領域へのアクセスログ保存が有効か、または有効化できるか
- gzip日次ログの保存先、ファイル名、生成時刻、保持期間
- Cronが使えるか
- PHP CLIでPDO SQLiteが使えるか
- DocumentRoot外のprivate領域へPHPとCronが読書きできるか
- private DB・設定・ログがHTTPで到達不能か

この確認に失敗した場合は、DBや管理画面を公開せず、JSON集計など別設計を検討してください。

## 例

```text
/home/ACCOUNT/log/                         日次gzipログ（提供者管理）
/home/ACCOUNT/public_html/example/admin/   公開管理画面
/home/ACCOUNT/private-analytics/           private領域
```

`config.php` の `access_log_directory` を `/home/ACCOUNT/log`、`access_log_prefix` を実際のファイル接頭辞に合わせます。`bin/preflight.php` は設定されたディレクトリを読み取りで確認します。

前日のログが完成する時刻より後にCronを動かします。たとえば06:30以降に生成される場合、06:40に設定します。

```cron
40 6 * * * /usr/bin/php /home/ACCOUNT/private-analytics/bin/daily-import.php >> /home/ACCOUNT/private-analytics/state/cron.log 2>&1
```

最初は提供者が用意した安全な検証ログまたは個人情報を除いた合成ログで確認し、Cron取込、管理画面、privateファイルのHTTP到達不能、公開サイトへの影響なしを個別に検証してください。
