# Supported log formats

[日本語](supported-log-formats.md)

The default parser reads Apache/Nginx Combined lines such as:

```text
192.0.2.10 - - [28/Aug/2026:06:30:00 +0900] "GET /example/ HTTP/1.1" 200 1234 "-" "Mozilla/5.0 ..."
```

An optional vhost prefix such as `example.test:443 ` is supported. Full request URLs are normalized to their path.

Import stops when:

- gzip cannot be read;
- the unparsable-line ratio exceeds `max_parse_error_ratio`; or
- a previously imported filename has a different SHA-256.

Check log format, encoding, and rotation settings before retrying. Do not paste production logs into an issue; reproduce with a synthetic log that contains no personal data.
