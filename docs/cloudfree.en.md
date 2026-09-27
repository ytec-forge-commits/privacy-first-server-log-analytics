# Cloudfree-style hosting example

[日本語](cloudfree.md)

This chapter is an example for Cloudfree-style shared hosting that can save gzipped daily access logs in user storage and run Cron. Features and labels may change, so check the official documentation available at deployment time.

## Preflight

Before changing anything, read and verify:

- whether access-log storage in user space is enabled or can be enabled;
- daily gzip-log location, filename, generation time, and retention;
- Cron availability;
- PDO SQLite availability in PHP CLI;
- PHP and Cron read/write access to the private directory outside DocumentRoot; and
- that private DB, configuration, and logs are unreachable over HTTP.

If any check fails, do not publish the database or administrator page. Consider a separate design, such as JSON aggregation.

## Example

```text
/home/ACCOUNT/log/                         daily gzip logs managed by host
/home/ACCOUNT/public_html/example/admin/   public administrator page
/home/ACCOUNT/private-analytics/           private directory
```

Set `access_log_directory` in `config.php` to `/home/ACCOUNT/log`, and set `access_log_prefix` to the actual filename prefix. `bin/preflight.php` checks the configured directory read-only.

Schedule Cron after the previous day's log is complete. For example, if generation completes after 06:30, schedule 06:40.

```cron
40 6 * * * /usr/bin/php /home/ACCOUNT/private-analytics/bin/daily-import.php >> /home/ACCOUNT/private-analytics/state/cron.log 2>&1
```

Begin with a host-provided safe validation log or synthetic log with personal data removed. Separately verify Cron import, the administrator page, HTTP inaccessibility of private files, and no change to the public site.
