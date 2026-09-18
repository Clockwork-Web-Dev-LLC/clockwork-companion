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

Mutating requests (POST/PUT/PATCH/DELETE) also consume the exact signature for that window so a captured body cannot be replayed. Failed verifications are rate-limited per IP (30 / 60s).

## Backup URL rules (create + restore)

`POST /backup/create` (`upload_url`) and `POST /backup/restore/stage` (`download_url`) accept **public HTTPS only**. Private/reserved IPs are refused, redirects are not followed, TLS hostname is verified, and upload headers cannot contain CR/LF. HMAC already gates the route; this stops a stolen secret from turning Companion into an SSRF / metadata client. Zip entries with `../` or absolute paths abort extract and are skipped again on file apply.

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

### `CLOCKWORK_UNLOCK_HUB`

```php
define('CLOCKWORK_UNLOCK_HUB', true);
```

Explicitly marks the WordPress install as an agency Unlock Hub, bypassing domain detection. Recommended as a manual override for local development or custom multi-network setups.

### `CLOCKWORK_UNLOCK_HUB_DOMAIN`

```php
define('CLOCKWORK_UNLOCK_HUB_DOMAIN', 'clockworkwd.com');
```

Overrides the primary agency hub domain configured via White Labeling.

## LLAR Unlock Hub & Remote Lockout Clearing

Companion includes an agency-only emergency unlock tool (`Clockwork → Unlock` / `UnlockPage`) designed to clear **Limit Login Attempts Reloaded (LLAR)** lockouts across your entire managed fleet without needing SSH access or WP credentials on the target site.

### How It Works

1. An authorized agency operator enters or selects a target site on the Unlock Hub page.
2. The Hub fires an authenticated, HMAC-SHA256 signed `DELETE /wp-json/clockwork/v1/lockouts` request server-side to the client's Companion plugin using that site's stored Companion secret.
3. The client site verifies the HMAC signature and flushes LLAR lockouts immediately.

### Dynamic Hub Detection & Client Isolation

To prevent client sites from ever exposing the emergency unlock console:
- **Primary Domain Match:** Companion checks if the current WordPress site's `home_url()` matches the configured **Agency Primary Hub Domain** (default: `clockworkwd.com`, pushed centrally via White Labeling in Clockwork Control).
- **Agency Staff Authentication:** Even on the hub domain, the user must have `manage_options` AND their email address must match the agency domain (e.g. `*@clockworkwd.com` or configured `agency_email_domains` / `support_email`).
- **Client Site Isolation:** On all client sites, the Unlock submenu and navigation tab are completely suppressed, and direct requests to `renderBody()` or `ajaxUnlock()` return HTTP 403 Forbidden.
- **Public Admin Menu:** The main Clockwork Companion menu is public to all administrators with `manage_options` to inspect telemetry, connection health, and capabilities, while the Unlock Hub tool remains strictly restricted to agency staff on the primary domain.

## Local development

```bash
ln -s "$PWD" /path/to/wp-content/mu-plugins/clockwork-companion
ln -s "$PWD/clockwork-companion.php" /path/to/wp-content/mu-plugins/clockwork-companion.php
```

The two symlinks let WordPress load the plugin while you edit source in this repo. Then exercise endpoints with a small signing helper (see `bin/sign.php` once added).
