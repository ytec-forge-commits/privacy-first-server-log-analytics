# Privacy-first Server Log Analytics

[日本語](README.md)

A PHP 8.1+ / SQLite administration dashboard that aggregates only daily web-server access logs already produced by the server. It does not use cookies, visitor IDs, fingerprinting, or browser beacons.

It stores **aggregates only**: daily page views, 404s, bot traffic, HTTP status classes, published pages, approved-referrer-domain categories, and device/OS/browser categories. It does not store IP addresses, raw user agents, complete referrers, query strings, individual requests, copied raw logs, visitor counts, or sessions.

## Features

- Idempotent import of gzipped daily Apache/Nginx Combined logs, including an optional vhost prefix
- Counts page views only for paths in a published-route manifest; unknown URLs become safe 404 categories
- Excludes static assets from page views and reports known bots separately
- Keeps daily aggregates for 90 days and monthly aggregates for 24 months by default
- CSRF protection, secure sessions, sign-in rate limiting, CSP, and PDO prepared statements
- Japanese and English dashboard selected with the `locale` configuration setting

## Quick start

1. Create a private directory outside DocumentRoot following the [installation guide](docs/installation.en.md).
2. Copy `config/config.example.php` to `config.php` in that private directory, then set paths, site prefix, and an administrator password hash.
3. Create `routes.json` in the private directory with published pages. See [`examples/routes.example.json`](examples/routes.example.json).
4. Run [`bin/daily-import.php`](bin/daily-import.php) from Cron after the daily log becomes available.

Verify a release with:

```powershell
npm test
```

## Scope and limitations

The [`docs/`](docs/) directory covers log formats, installation, backups, and privacy boundaries. For Cloudfree-style hosting, see the separate [Cloudfree example](docs/cloudfree.en.md).

This application aggregates server logs only. It intentionally provides no browser event API that accepts arbitrary payloads.

## Changelog

- **1.0.1 (2026-08-31):** Fixed a duplicate class-loading failure in the daily Cron entry point and added a regression test that executes the real Cron entry point.

## License

Source code and documentation are under the [Apache License 2.0](LICENSE). This distribution contains no product names, logos, characters, third-party icons, or real operational data.

For security reporting, see [SECURITY.md](SECURITY.md).
