# 対応ログ形式

[English](supported-log-formats.en.md)

標準では、次のようなApache/NginxのCombined形式を読みます。

```text
192.0.2.10 - - [28/Aug/2026:06:30:00 +0900] "GET /example/ HTTP/1.1" 200 1234 "-" "Mozilla/5.0 ..."
```

先頭に `example.test:443 ` のようなvhost接頭辞があっても読めます。リクエストURLが完全URLで書かれている形式も、パスだけへ正規化します。

以下の場合は取込を停止します。

- gzipを展開できない
- 解析不能な行の割合が `max_parse_error_ratio` を超える
- 同じファイル名で以前と異なるSHA-256が検出される

その場合はログ形式・文字コード・ローテーション設定を確認し、実運用ログをIssueへ貼らずに、個人情報を除いた合成ログで再現してください。
