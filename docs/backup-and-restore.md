# バックアップと復元

[English](backup-and-restore.en.md)

SQLite DBのバックアップ正本は、サーバーではなくローカルのGit管理外領域に保管します。`bin/Protect-AnalyticsBackup.ps1` はWindows DPAPIで現在のユーザーに暗号化し、復号確認とSHA-256確認を行ってから、既定で12世代を残します。

```powershell
./bin/Protect-AnalyticsBackup.ps1 -DatabasePath C:\private-analytics\data\analytics.sqlite -DestinationDirectory C:\private-backups\analytics
```

復元先を正確に確認してから実行します。既存ファイルを置き換える場合だけ `-Force` を使用してください。

```powershell
./bin/Restore-AnalyticsBackup.ps1 -EncryptedBackupPath C:\private-backups\analytics\privacy-log-analytics-YYYYMMDD-HHMMSS.sqlite.dpapi -DestinationPath C:\recovery\analytics.sqlite
```

復元後は、復元先のSHA-256がmanifestと一致すること、`preflight.php` と合成ログでの取込試験が通ることを確認してから切り替えます。
