# Privacy model

[日本語](privacy-model.md)

## Purpose

This application creates only the minimum **aggregates** needed for site improvements from normal web-server access logs. It adds no new browser tracking.

## Stored

- Day or month
- Page views, regular requests, known bots, HTTP result, and safe 404 category
- Identifier for a page listed in the published-route manifest
- Explicitly approved external-referrer domain, Direct, or internal category
- Browser, operating system, and PC/phone/other category
- Import filename, SHA-256, import time, and row counts

## Never stored

- IP address
- Raw User-Agent
- Complete referrer URL
- Query string
- Individual request, visitor ID, cookie identifier, or fingerprint
- Copied raw log, visitor count, or session count
- Unknown 404 URL

Daily aggregates are deleted after 90 days by default; monthly aggregates after 24 months. If you change retention, update the public privacy notice at the same time.

`referrer_domains` is an allowlist of external domains that may be stored. An unapproved external referrer stores no hostname and becomes `external:other`. This prevents an arbitrary site from persisting identifiers embedded in subdomains.

## Boundary

This distribution has no public browser-beacon or arbitrary-payload API. If future download or outbound-link metrics are required, aggregate only facts already observable in existing server logs or server-side processing.
