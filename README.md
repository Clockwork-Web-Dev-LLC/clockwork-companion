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

## Local development

```bash
ln -s "$PWD" /path/to/wp-content/mu-plugins/clockwork-companion
ln -s "$PWD/clockwork-companion.php" /path/to/wp-content/mu-plugins/clockwork-companion.php
```

The two symlinks let WordPress load the plugin while you edit source in this repo. Then exercise endpoints with a small signing helper (see `bin/sign.php` once added).
