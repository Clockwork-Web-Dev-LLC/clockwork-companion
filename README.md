# Clockwork Companion

WordPress mu-plugin that exposes signed REST endpoints under `/wp-json/clockwork/v1/` so the Clockwork monitoring app can drive fleet-wide WordPress maintenance tasks: contact-form testing today, plugin/theme/core update orchestration, security scans, and white-label client reporting in future releases.

This plugin is **deployed by Clockwork**, not installed by hand. See `clockwork:install-companion` in the Clockwork app.

## Architecture

- **Loader**: `clockwork-companion.php` (mu-plugin entry; defines version, registers PSR-4 autoloader, boots `Plugin`)
- **Auth**: HMAC-SHA256 signature with 5-minute replay window. Per-site secret in `wp_options.clockwork_companion_secret`. See `src/Auth/HmacVerifier.php`.
- **Routes**: `src/Rest/*` — `/health`, `/detect`, `/test-contact-form` (more added per release)
- **Form-test strategies**: `src/ContactForm/Strategies/*` — one per supported form plugin (CF7, WPForms, Gravity)

## Signature scheme

Clients sign requests with two headers:

```
X-Clockwork-Timestamp: <unix seconds>
X-Clockwork-Signature: <hex hmac-sha256 of payload>
```

Where `payload` is:

```
METHOD\n/wp-json/<route>\n<timestamp>\n<body>
```

Body is the empty string for GET. The replay window is 300 seconds; requests outside it are rejected even with valid signatures.

## Capabilities advertised by `/health`

```json
{
  "ok": true,
  "version": "1.0.0",
  "capabilities": ["contact-form-test"],
  "wp_version": "6.x",
  "php_version": "8.x"
}
```

Clockwork stores the latest snapshot per site so it always knows which features are available without re-probing.

## Configuration constants

Both constants are defined in `wp-config.php`. Both are optional — Companion runs with safe defaults if neither is set.

### `CLOCKWORK_COMPANION_SECRET`

```php
define('CLOCKWORK_COMPANION_SECRET', 'hex-encoded-32-bytes');
```

When defined, takes precedence over the `wp_options.clockwork_companion_secret` row. Recommended for security-sensitive sites — keeps the HMAC secret out of database backups and out of the SQL-injection blast radius of any other plugin. Rotation is manual: edit `wp-config.php` and re-run `php artisan clockwork:rotate-companion-secret <site>` so Clockwork picks up the new value.

### `CLOCKWORK_COMPANION_TRUST_PROXY` (1.16.9+)

```php
define('CLOCKWORK_COMPANION_TRUST_PROXY', true);
```

Tells `HmacVerifier::clientIp()` to read the real client IP from `CF-Connecting-IP` (preferred) or `X-Forwarded-For` (leftmost entry) instead of `REMOTE_ADDR`. Required on Cloudflare-fronted (or any reverse-proxied) sites — without it, every request appears to come from the proxy edge IP, which:

1. Neutralises the per-IP rate limiter (one noisy probe locks out the entire planet, since every request shares one bucket).
2. Pollutes the auth-failure audit table with edge IPs instead of attacker IPs.

**Opt-in by design.** Trusting forwarded headers without an actual proxy in front lets a direct attacker spoof their bucket key. Only define this constant when a trusted reverse proxy (Cloudflare, your load balancer, etc.) is verifiably in front of the WordPress origin.

For Clockwork-managed care-plan sites, the `clockwork:ensure-companion-trust-proxy` command idempotently injects this constant fleet-wide and runs nightly to catch newly-eligible sites.

## Local development

```bash
ln -s "$PWD" /path/to/wp-content/mu-plugins/clockwork-companion
ln -s "$PWD/clockwork-companion.php" /path/to/wp-content/mu-plugins/clockwork-companion.php
```

The two symlinks let WordPress load the plugin while you edit source in this repo. Then exercise endpoints with a small signing helper (see `bin/sign.php` once added).
