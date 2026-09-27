# Installation

[日本語](installation.md)

## Layout

Only `index.php` and `style.css` in `public/admin/` are public. Keep `app.php`, `src/`, `bin/`, `config.php`, `routes.json`, the SQLite database, sessions, locks, logs, and backups outside DocumentRoot.

Example (replace account and domain placeholders):

```text
/home/ACCOUNT/public_html/example/admin/       public administrator page
/home/ACCOUNT/private-analytics/               private directory
/home/ACCOUNT/private-analytics/config.php     live configuration (not Git)
/home/ACCOUNT/private-analytics/routes.json    published routes (not Git)
/home/ACCOUNT/private-analytics/data/          SQLite (not Git)
```

## Steps

1. Create the private directory and deploy `app.php`, `src/`, and `bin/` there.
2. Copy `config/config.example.php` to `config.php` in the private directory. Keep `db_path` and related paths private; set `site_prefix` to the URL prefix to aggregate and list only your own hosts in `internal_hosts`. Only explicit domains in `referrer_domains` are stored; all other external referrers are grouped.
3. Run `php bin/hash-password.php`, then replace the example hash in `config.php`. Never commit or publish the hash.
4. Put a manifest of published pages in private `routes.json`. Unknown URLs never become page views; only a safe 404 category is aggregated.
5. Deploy only `public/admin/` to the public URL. If the private directory is not a sibling of DocumentRoot, set the web-server environment variable `PRIVACY_LOG_ANALYTICS_PRIVATE_ROOT` to its absolute path.
6. Run `php /home/ACCOUNT/private-analytics/bin/preflight.php` to verify PDO SQLite, private paths, log reading, and writing. Do not start public operation if it fails.
7. Register a Cron job after daily logs are generated. See [`../examples/crontab.example`](../examples/crontab.example).

## Permission example

Use the hosting provider's ownership and group requirements first. These are secure starting values; verify that the PHP execution user can read and write what it needs.

```sh
chmod 755 /home/ACCOUNT/public_html/example /home/ACCOUNT/public_html/example/admin
chmod 644 /home/ACCOUNT/public_html/example/admin/index.php /home/ACCOUNT/public_html/example/admin/style.css
chmod 700 /home/ACCOUNT/private-analytics
chmod 700 /home/ACCOUNT/private-analytics/data /home/ACCOUNT/private-analytics/sessions /home/ACCOUNT/private-analytics/locks /home/ACCOUNT/private-analytics/state
chmod 600 /home/ACCOUNT/private-analytics/config.php /home/ACCOUNT/private-analytics/routes.json
chmod 600 /home/ACCOUNT/private-analytics/data/analytics.sqlite
```

Do not change existing database ownership until the hosting provider's PHP user is known. Never use `777`.

## Administrator page

Open `https://example.test/example/admin/` over HTTPS. After the first sign-in, select `ja` or `en` in the configuration `locale`. The administrator cookie is Secure / HttpOnly / SameSite=Strict and is never used for visitor analytics.
