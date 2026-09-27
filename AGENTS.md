# Privacy-first Server Log Analytics rules

- Keep application UI and user-facing documentation available in Japanese and English.
- This repository must not contain production logs, databases, routes, credentials, password hashes, host names, or brand assets.
- Keep `config.php`, route manifests, SQLite data, locks, sessions, and backups outside the web document root.
- Do not add browser beacons, visitor identifiers, tracking cookies, or arbitrary event-collection endpoints.
- Run `node bin/run-php-tests.mjs` and `node bin/verify-public-package.mjs` before a release.
