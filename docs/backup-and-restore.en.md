# Backup and restore

[日本語](backup-and-restore.md)

Keep the authoritative SQLite backup locally outside Git, never permanently on the server. `bin/Protect-AnalyticsBackup.ps1` encrypts it with Windows DPAPI for the current user, verifies decryption and SHA-256, and keeps 12 generations by default.

```powershell
./bin/Protect-AnalyticsBackup.ps1 -DatabasePath C:\private-analytics\data\analytics.sqlite -DestinationDirectory C:\private-backups\analytics
```

Confirm the exact recovery target before restoring. Use `-Force` only when replacing an existing file.

```powershell
./bin/Restore-AnalyticsBackup.ps1 -EncryptedBackupPath C:\private-backups\analytics\privacy-log-analytics-YYYYMMDD-HHMMSS.sqlite.dpapi -DestinationPath C:\recovery\analytics.sqlite
```

Before switching over, verify that the restored hash matches its manifest and that `preflight.php` and a synthetic-log import succeed.
