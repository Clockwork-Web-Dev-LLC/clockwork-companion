# Clockwork Companion

Clockwork Companion is the WordPress plugin that lets **Clockwork Control** (the fleet monitoring and maintenance app) manage a site. It exposes HMAC-signed REST endpoints under `/wp-json/clockwork/v1/` for updates, backups and restores, security scans, login protection (Gatekeeper and 2FA), contact-form testing, SSO and telemetry, and adds a top-level **Clockwork** menu to wp-admin with client-facing reports (Activity, Uptime, Security, Login Security, Performance, Traffic, Forms, Backups, Notifications, Update coverage) and agency-only tools (Connection, Gatekeeper, Unlock).

- **Requirements** (plugin header): WordPress 6.4+, PHP 8.0+.
- **Licence:** MIT.
- **Where it lives:** a regular plugin at `wp-content/plugins/clockwork-companion/clockwork-companion.php`. Older installs ran it as a mu-plugin (`wp-content/mu-plugins/clockwork-companion.php`); a copy running from `wp-content/plugins/` removes that legacy loader, and the entry file returns early if a second copy has already loaded in the same request.
- **Kept active:** the Deactivate / Delete links are hidden on the Plugins screen and the plugin is re-added to `active_plugins` if it is removed, except under WP-CLI or with `CLOCKWORK_COMPANION_ALLOW_DEACTIVATE`.

The plugin is **deployed by Clockwork Control**, not installed by hand: `php artisan clockwork:install-companion` (SSH hosts), `clockwork:install-companion-pressable` (Pressable) and `clockwork:companion-fleet-deploy` (canary-gated fleet roll-out). After that, sites update themselves from GitHub Releases (see [Releases](#releases)).

## Layout

- `clockwork-companion.php` — loader: defines the version, `CLOCKWORK_COMPANION_DIR`, `CLOCKWORK_COMPANION_FILE` and `CLOCKWORK_COMPANION_NAMESPACE` (`clockwork/v1`), registers the PSR-4 autoloader (`ClockworkCompanion\` → `src/`) and boots `Plugin`.
- `src/Auth` — `HmacVerifier` (signature check, replay store, rate limit), `Secret` (secret storage and two-phase rotation), `RemoteActionAudit`.
- `src/Rest` — one class per route (see [REST routes](#rest-routes)).
- `src/Gatekeeper` — native login-attempt limiter (`Gate`, `Store`, `Settings`, `ClientIp`, `LockoutPage`, `Schema`).
- `src/TwoFactor` — TOTP 2FA login challenge, enrolment nudge, Wordfence Login Security migration.
- `src/Backup` — database dumper / importer, archiver, restore staging and apply.
- `src/Maintenance` — `MaintenanceGuard` (503 page during restores and when switched on remotely).
- `src/SecurityScans`, `src/ContactForm`, `src/Updates`, `src/Sso`, `src/Admin`, `src/WhiteLabel`, `src/Compat` — the remaining features.

## How Control talks to the plugin

Every route in the `clockwork/v1` namespace uses `HmacVerifier::verify` as its permission callback. There are no unsigned routes. Responses in the namespace carry `Cache-Control: no-store` (and `DONOTCACHEPAGE` is defined) so page caches and CDNs never store them.

### Signature scheme

Clients sign requests with two headers:

```
X-Clockwork-Timestamp: <unix seconds>
X-Clockwork-Signature: <hex hmac-sha256 of payload>
```

Where `payload` is:

```
METHOD\n/wp-json/<route>\n<timestamp>\n<body>
```

Body is the raw request body (the empty string for GET). The replay window is 300 seconds; requests outside it are rejected even with valid signatures.

Requests with query parameters (other than `rest_route`) must also send:

```
X-Clockwork-Signature-V2: <hex hmac-sha256 of payload_v2>
```

Where `payload_v2` is the same payload with the path replaced by `/wp-json/<route>?<canonical query>`. The canonical query is the params minus `rest_route`, sorted by key and encoded as `http_build_query($params, '', '&', PHP_QUERY_RFC3986)`. Without query parameters only `X-Clockwork-Signature` is checked, so clients can send both headers to every Companion version.

- **Replay protection:** mutating requests (POST/PUT/PATCH/DELETE) consume their signature with a single `INSERT IGNORE` into `wp_options`, so concurrent copies of one request cannot both pass. The marker lives until `max(now, timestamp) + 300 s`. A signed request whose raw body is empty but which carries form or file fields is refused (`unsigned_body`).
- **Rate limit:** failed verifications are limited to 30 per 60 s per client address; a request whose signature verifies is never limited. Once an address is over the limit its failures get a bare 429 with nothing written. The client address is resolved with Gatekeeper's trusted-proxy rules (see [Trusted proxies](#trusted-proxies)).
- **Result codes:** `missing_headers`, `stale_timestamp`, `invalid_signature`, `replayed_request`, `unsigned_body` (401), `rate_limited` (429), `no_secret` (500).

### The shared secret and rotation

The per-site secret is stored in the `clockwork_companion_secret` option, or pinned in `wp-config.php` with `CLOCKWORK_COMPANION_SECRET` (an empty constant counts as not pinned).

Rotation is two-phase. `POST /secret/rotate`, signed with the current secret, stores a new secret as *pending* and returns it; the current secret stays valid. The first request Control signs with the pending secret promotes it. If the response is lost, the site stays reachable with the old secret and the next rotation replaces the unconfirmed one. The route returns a secret only once it is stored (`500 rotate_failed` otherwise). A site with the secret pinned by the constant refuses with `409` (`secret_pinned`); rotate it by editing `wp-config.php` and updating the secret stored in Control.

From Control:

```bash
php artisan clockwork:rotate-companion-secret --site=<id|domain>
php artisan clockwork:rotate-companion-secret --all
php artisan clockwork:rotate-companion-secret --all --dry-run   # list what would be rotated
```

### Backup URL rules (create + restore)

`POST /backup/create` (`upload_url`) and `POST /backup/restore/stage` (`download_url`) accept **public HTTPS only**. Private/reserved IPs are refused, redirects are not followed, TLS hostname is verified, and upload headers cannot contain CR/LF. HMAC already gates the route; this stops a stolen secret from turning Companion into an SSRF / metadata client. Archive entries with `../` or absolute paths, and symlink or hardlink entries, are rejected on extraction and skipped again on file apply.

Backup and restore work files go to a per-site directory beside the WordPress root when that is outside the document root and writable, else to `CLOCKWORK_BACKUP_DIR` / the `clockwork_backup_staging_dir` filter, else to uploads behind deny rules. Their names are random.

## REST routes

All under `/wp-json/clockwork/v1/`, all HMAC-signed.

| Method | Route | Purpose |
|---|---|---|
| GET | `/health` | Plugin version, capability list, WordPress/PHP versions, multisite info. Control reads capabilities from here. |
| GET | `/detect` | Detect installed contact-form plugins and their forms. |
| POST | `/test-contact-form` | Submit a synthetic entry to a form (lab mode leaves no entry, mail or integration side effects). |
| GET | `/form-subscriptions` | Forms the site admin has subscribed to recurring testing. |
| GET | `/snapshot` | One-call bundle: plugins, admins, cron, comments summary, database telemetry, client notifications. |
| GET | `/environment` | Server, PHP, WordPress and database environment. |
| GET | `/plugins` | Installed-plugin inventory with available updates. |
| POST | `/plugins/update` | Update one plugin with `Plugin_Upgrader` (maintenance mode and load check for active plugins). |
| POST | `/plugins/toggle` | Activate or deactivate a plugin. |
| POST | `/plugins/delete` | Delete a plugin. |
| POST | `/plugins/install` | Install a plugin from WordPress.org. |
| GET | `/themes` | Installed-theme inventory with available updates. |
| POST | `/themes/update` | Update one theme with `Theme_Upgrader`. |
| POST | `/wp-core/update` | Update WordPress core (any X.Y change needs `confirm_major`). |
| POST | `/translations/update` | Install pending translation updates. |
| POST | `/post-update-verify` | Check after an update that active plugins/theme still match the pre-update state. |
| POST | `/update-exceptions` | Receive the list of auto-paused plugin/theme updates. |
| GET | `/admins` | Administrator accounts, for the fleet admin audit. |
| GET | `/two-factor` | Per-user 2FA enrolment status and Wordfence Login Security migration state. |
| POST | `/two-factor/migrate` | Migrate a user's Wordfence Login Security secret to Companion 2FA. |
| GET | `/lockouts` | Active Gatekeeper and Limit Login Attempts Reloaded lockouts (public IPs only, max 500). |
| DELETE | `/lockouts` | Clear all lockouts, or one address with `?ip=`. |
| GET | `/wordfence-blocks` | Active Wordfence IP blocks (public IPs only, max 500). |
| POST | `/gatekeeper-settings` | Receive the Gatekeeper lockout policy. |
| POST | `/sso/magic-link` | Mint a single-use login link for a named administrator. |
| POST | `/secret/rotate` | Two-phase secret rotation (see above). |
| GET, POST | `/branding` | Read or update white-label branding (POST merges the keys sent). |
| GET, POST | `/client-notifications` | Read or update Care Plan report recipients and notification settings. |
| GET, POST | `/maintenance-mode` | Read or switch the plugin's maintenance (503) mode. |
| POST | `/backup/create` | Build a backup archive and upload it to a presigned URL. |
| POST | `/backup/restore/stage` | Download and stage an archive for restore. |
| GET | `/backup/restore/status` | Restore progress. |
| POST | `/backup/restore/apply` | Apply a staged restore (database and/or files) under maintenance mode. |
| POST | `/backups-report` | Receive backup configuration and history for the Backups page. |
| POST | `/malware-scan` | Run the in-WordPress malware probe. |
| POST | `/security-summary-report` | Receive host-side security signals for the Security page. |
| POST | `/traffic-report` | Receive the 30-day access-log rollup for the Traffic page. |
| GET | `/resource-report` | Hourly resource-usage buckets since a cursor. |
| POST | `/resource-sampler-config` | Switch the per-request resource sampler on or off. |
| GET | `/wp-cron` | WP-Cron health: schedules and overdue events. |
| GET | `/comments-summary` | Comment counts and settings summary. |
| GET | `/comments` | List comments with status/search filters. |
| POST | `/comments/moderate` | Batch approve / hold / spam / trash / delete. |
| POST | `/comments/cleanup` | Bulk-remove spam / trashed comments older than N days. |
| GET | `/database/summary` | Database bloat summary (revisions, transients, drafts). |
| POST | `/database/optimize` | Chunked clean-up of that bloat. |
| GET | `/debug-log` | Tail of `debug.log`, with credentials redacted. |
| DELETE | `/debug-log` | Truncate `debug.log`. |
| POST | `/cache/flush` | Best-effort object/page cache flush. |
| POST | `/code-snippet` | Run a PHP snippet sent by Control (logged on the site by hash; disable with `CLOCKWORK_COMPANION_DISABLE_CODE_SNIPPETS`). |
| POST | `/action-log/append` | Mirror a row of Control's action log into the site's Activity page. |

Routes are registered by `src/Rest/*Route.php`; that directory is the source of truth.

## Gatekeeper

Gatekeeper is Companion's own login-attempt limiter (the replacement for Limit Login Attempts Reloaded). Control pushes the policy with `POST /gatekeeper-settings`; it is off until a policy enables it. It counts failed logins per address (IPv6 per /64), reserves attempts atomically before the password check, locks an address out after the threshold, extends repeat lockouts, and serves a lockout page. On multisite the table and policy are network-wide. Agency users can see the status, lockouts and policy, and unlock addresses, under **Clockwork → Gatekeeper**.

Enabling Gatekeeper does not deactivate Limit Login Attempts Reloaded; Control's rollout retires LLAR after it has confirmed Gatekeeper is enforcing. `CLOCKWORK_GATEKEEPER_DISABLE` switches enforcement off on a server.

### Trusted proxies

`CF-Connecting-IP` and `X-Forwarded-For` are honoured only when the TCP peer is a trusted proxy: one listed in Gatekeeper's Trusted Proxies setting, in `CLOCKWORK_COMPANION_TRUSTED_PROXIES`, or added by the `clockwork_companion_trusted_proxies` filter. Entries are IPs, CIDRs, or the keywords `cloudflare` (Cloudflare's published ranges; replace them with the `clockwork_companion_cloudflare_ranges` filter) and `private` (loopback, RFC 1918, IPv6 unique-local). With no list configured, `CLOCKWORK_COMPANION_TRUST_PROXY` means `cloudflare` + `private`. With neither, the headers are ignored. If the web server already rewrites `REMOTE_ADDR` (nginx `real_ip`, Apache `mod_remoteip`), no proxy needs to be trusted. The same rules decide the address used by the signed-request rate limiter and the audit logs.

## Two-factor authentication

The **Login Security** page lets users enrol a TOTP authenticator and backup codes. Enrolment is opt-in unless an administrator requires it for a user (with a grace period, `CLOCKWORK_2FA_GRACE_DAYS`) or `CLOCKWORK_2FA_REQUIRE_ALL` is set. For enrolled users:

- the login challenge allows 10 bad codes per 15 minutes per account (`clockwork_companion_2fa_max_failures` filter), then locks the second step for 15 minutes; bad codes fire `wp_login_failed`;
- a plain password is refused over XML-RPC and REST (application passwords still work);
- disabling 2FA, regenerating backup codes or replacing the secret needs a current code.

`CLOCKWORK_2FA_DISABLE` is the rescue switch if 2FA ever locks administrators out: `wp config set CLOCKWORK_2FA_DISABLE true --raw`.

## Configuration constants

All optional; define them in `wp-config.php`.

| Constant | Meaning |
|---|---|
| `CLOCKWORK_COMPANION_SECRET` | Pins the HMAC secret instead of the `clockwork_companion_secret` option. Rotation is then manual (the rotate route answers 409). |
| `CLOCKWORK_COMPANION_TRUSTED_PROXIES` | Comma-separated IPs, CIDRs, `cloudflare`, `private`: peers whose forwarding headers are trusted. |
| `CLOCKWORK_COMPANION_TRUST_PROXY` | Legacy switch: with no trusted-proxy list, trust Cloudflare's ranges and private peers. Control's `clockwork:ensure-companion-trust-proxy` writes it on care-plan sites. |
| `CLOCKWORK_GATEKEEPER_DISABLE` | Turns Gatekeeper enforcement off on this server, whatever the stored policy says. |
| `CLOCKWORK_2FA_DISABLE` | Rescue switch: skips the 2FA login challenge and the enrolment nudge. |
| `CLOCKWORK_2FA_REQUIRE_ALL` | Require 2FA for every administrator instead of per user (also the `clockwork_companion_2fa_require_all` filter). |
| `CLOCKWORK_2FA_GRACE_DAYS` | Grace period, in days, before required 2FA locks wp-admin (default 30; filter `clockwork_companion_2fa_grace_days`). |
| `CLOCKWORK_BACKUP_DIR` | Directory for backup and restore work files (preferably outside the web root). |
| `CLOCKWORK_COMPANION_DISABLE_CODE_SNIPPETS` | Refuse `POST /code-snippet` and stop advertising the capability. |
| `CLOCKWORK_COMPANION_DISABLE_SELF_UPDATE` | Turn off updates from GitHub Releases (also the `clockwork_companion_self_update_enabled` filter). |
| `CLOCKWORK_COMPANION_ALLOW_DEACTIVATE` | Allow the plugin to be deactivated / deleted from wp-admin. |
| `CLOCKWORK_COMPANION_USE_WPCLI` | Use the `wp` binary for core checksum verification instead of the pure-PHP path. |
| `CLOCKWORK_AGENCY_EMAIL_DOMAINS` | Email domains whose users count as agency staff (who see the Clockwork menu and agency-only tools). Overrides the list pushed with branding; the `clockwork_companion_agency_email_domains` filter overrides both. Empty everywhere means no gating. |
| `CLOCKWORK_UNLOCK_HUB` | Mark this install as the agency Unlock Hub, bypassing domain detection. |
| `CLOCKWORK_UNLOCK_HUB_DOMAIN` | Override the Unlock Hub domain pushed with branding. |
| `CLOCKWORK_UNLOCK_KEY` | Key material for encrypting the Unlock Hub's stored site secrets (defaults to one derived from the `wp-config.php` salts). |
| `CLOCKWORK_SITE_DOMAIN` | Domain written into the backup manifest when `home_url()` is not available. |
| `CLOCKWORK_COMPANION_DIR`, `CLOCKWORK_COMPANION_FILE` | Override the plugin directory / main file path (normally set by the loader). |

Other filters: `clockwork_backup_staging_dir` (backup work directory).

## Unlock Hub

Companion includes an agency-only emergency unlock tool (**Clockwork → Unlock**) that clears login lockouts on any managed site without SSH access or credentials on the target site. On the hub site, an agency operator picks a site; the hub sends a signed `DELETE /wp-json/clockwork/v1/lockouts` to that site's Companion with the stored secret, and the site clears its Gatekeeper and LLAR lockouts.

- The hub is the site whose `home_url()` matches the configured Agency Primary Hub Domain (pushed with white-label branding, e.g. `example.com`), or any site with `CLOCKWORK_UNLOCK_HUB`.
- The operator must have `manage_options` and an agency email address (a configured agency domain or the support address).
- On every other site the Unlock menu entry and tab are not shown, and the page and its AJAX action return 403.

## Releases

1. Bump the version in both the plugin header (`Version:`) and `CLOCKWORK_COMPANION_VERSION` in `clockwork-companion.php`, and move the `[Unreleased]` entries in `CHANGELOG.md` under the new version.
2. Push a tag `v<version>`. `.github/workflows/release.yml` checks that the tag, header and constant match, runs the PHPUnit suite, builds the zip with `bin/build-release-zip.sh` (plugin file, `src/`, `assets/`, `README.md`, `CHANGELOG.md`, `LICENSE`, plus a `.zip.sha256`), and publishes both files as a GitHub Release.
3. Sites pick the release up through WordPress's updater (`Update URI` → GitHub Releases). The self-updater refuses a package whose SHA-256 does not match the published `.zip.sha256`.

To build locally: `bin/build-release-zip.sh` (writes `dist/clockwork-companion.zip` and its `.sha256`; set `OUT_DIR` to change the location).

## Local development

```bash
composer install
composer test
```

To run it in a local WordPress, symlink the repository into `wp-content/plugins/clockwork-companion` and activate it. There is no bundled request-signing helper; sign test requests as described in [Signature scheme](#signature-scheme).
