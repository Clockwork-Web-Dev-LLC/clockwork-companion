# Clockwork Companion — Changelog

Versions track `CLOCKWORK_COMPANION_VERSION` in `clockwork-companion.php`. Earlier releases (1.0.0 → 1.16.8) predate this file; treat the git log as authoritative for those.

## [Unreleased]

### Changed

- Reconciled PHP requirement to `php: >=8.1` in `composer.json` and added `Requires PHP: 8.1` to the plugin header in `clockwork-companion.php` (aligning package metadata with the PHP 8.1 compatibility fixes in 1.36.0).
- Updated test bootstrap to dynamically read `CLOCKWORK_COMPANION_VERSION` from `clockwork-companion.php`.

## 1.36.0 — 2026-09-11

### Added

- **`POST /backup/create`** (capability `backup-create`). Takes a full site backup — pure-PHP database dump via `$wpdb` (500-row chunks, streamed to `.sql.gz`, no `mysqldump`/`exec`) plus a zip of `wp-content/` and `wp-config.php` (caches, logs, other backup plugins' folders, and `.git` excluded) — and streams it directly to a presigned S3 PUT URL (`x-amz-storage-class: GLACIER_IR`) with zero local RAM buffering. Temp files cleaned in `finally`. Powers Clockwork's per-site off-site backups for unhosted/custom sites.
- **Connection screen** (`Tools → Clockwork → Connection`, capability `connection-key`): live connection status, copyable base64 Connection Key, and manual domain+secret fallback — the ManageWP-style pairing flow for sites Clockwork doesn't host.
- **Dual loader**: the plugin now resolves its own directory correctly whether installed as a regular plugin (`wp-content/plugins/`) or an mu-plugin (`wp-content/mu-plugins/`).

### Fixed

- **PHP 8.1 compatibility**: `HmacVerifier::verify()` used a PHP 8.2-only `true|\WP_Error` return type, fataling every signed REST call on 8.1 hosts. Now `bool|\WP_Error`.
- **`plugins.php` white screen**: `WhiteLabel` hooked `show_advanced_plugins` as though it received a plugin list; WordPress passes a boolean. Hook removed.
- **Missing header logo on regular-plugin installs**: assets were always resolved via `WPMU_PLUGIN_URL` (defined even when unused), 404ing on non-mu installs. New `WhiteLabel::bundledAssetUrl()` resolves against the actual install location.
- **Backup zip on hosts without php-zip**: `BackupArchiver` falls back to a one-shot PclZip create (per-file `add()` was O(n²) and 504'd behind ~60s gateways).
- **Backups page copy**: the off-site archive card no longer promises the host-copy pipeline's "copied twice a week" wording on sites where this plugin itself takes the backup directly (`source=clockwork-companion`); history table renders combined Date · Type · Size rows for full archives.

## 1.35.0 — 2026-09-10

### Added

- **`POST /cache/flush`** (capability `cache-flush`). Flushes the WordPress object cache, Spinup page cache helpers when present, and WP Engine varnish/memcached helpers when present. Clockwork calls this once per site when an update batch for that site finishes.

## 1.34.0 — 2026-09-06

### Added

- **Agency email domains are now a setting instead of a hard-coded list.** Menu visibility used to be gated on two compiled-in email domains, so any other operator installing Companion got an invisible admin menu with no way to fix it short of editing the source. The domains now come from a new **Agency Email Domains** field under Branding, the `CLOCKWORK_AGENCY_EMAIL_DOMAINS` constant (for deploying one value across a fleet from `wp-config.php`), or the `clockwork_companion_agency_email_domains` filter — in that order of precedence. Entries may be written with or without the leading `@`, as full addresses, or separated by commas, spaces, or newlines. **Leaving the field empty means no gating at all**, so a fresh install shows the menu to every administrator; that is the new default. Gating is deliberately independent of the white-label on/off switch, since it is an access-control decision rather than a cosmetic one, and the configured support address always counts as an agency domain so an operator can't lock themselves out.

### Changed

- **Client-facing copy and support links follow the active branding.** The Performance, Security, Traffic, Backups, Activity, Uptime, Forms, and Notifications pages previously hard-coded one agency's name and `https://…/support/` and `/contact/` URLs, so a white-labelled install still showed — and linked clients to — a third party. Every one of those now resolves through `WhiteLabel::getAuthorName()` and the new `WhiteLabel::supportLink()` helper, which renders a link when a support destination is configured and plain text when it isn't.
- **Contact-form health checks no longer submit from a hard-coded address.** The synthetic submission now uses the configured support address, falling back to a no-reply on the site's own domain, so a white-labelled site's test mail never reaches someone else's inbox.
- **`CLOCKWORK_SUPPORT_SITE_URL` now defaults to empty** rather than to one specific agency's site. Set it in `wp-config.php` to enable the in-plugin support form; left unset, the form reports that support isn't configured instead of posting to a stranger's endpoint.

### Internal

- Added a PHPUnit harness (`phpunit.xml`, `require-dev`, `autoload-dev`). The `tests/` directory already existed but nothing could run it, so it had gone unverified. The WordPress function stubs in `tests/bootstrap.php` gained `apply_filters`, `is_email`, `home_url`, and `esc_js`.

## 1.33.0 — 2026-09-05

### Added

- **White Label & Agency Branding Customizer with real-time live preview.** Agencies can now completely rebrand the Clockwork Companion plugin from a dedicated `Branding` admin tab (`admin.php?page=clockwork-branding`). Allows customizing the plugin name, author name, author URL, plugin URL, description, sidebar menu label, brand subtitle, custom logo image, and admin color palette. Includes 6 curated 1-click theme presets (Clockwork Classic, Emerald Forest, Royal Indigo, Ocean Midnight, Crimson Amber, Monochrome), synchronized hex/color pickers, WordPress Media Library integration (`wp.media`) for logo uploads, and real-time live preview of the header, tabs, and simulated `plugins.php` table row. White-label metadata filters apply to both standard plugins (`all_plugins`) and must-use plugins (`show_advanced_plugins`).
- **Annual Support & Contribution Callout.** An open, non-blocking annual reminder encouraging agencies to support continued open-source development via PayPal. Clicking "I Donated! ❤️" records a verified contribution timestamp and snoozes the reminder for 365 days (1 full year); a dismiss option snoozes for 90 days. All white-labeling features remain 100% free, unrestricted, and open.

## 1.32.1 — 2026-09-04

### Added

- **Backups page now shows the 90-day off-host Glacier archive, for Pressable care-plan sites.** A new standalone project archives these sites' backups to S3 Glacier Instant Retrieval twice a week, independent of the on-host backups shown above it. Renders as an informational notice ("Also archived off-host for 90 days") plus, when a currently-valid link exists, real "Download latest filesystem/database backup" buttons — direct links straight to S3, no proxying through this plugin or the agency's monitoring app. Absent entirely for any site not enrolled (no false promise). New `offsite_archive` field on the existing `/backups-report` payload, loosely validated like everything else on that endpoint.

## 1.32.0 — 2026-09-04

### Added

- **Two-factor enrollment nudge with a 30-day grace period, manually adjustable per person.** Agency-visible admins (`TwoFactor\EnrollmentNudge` — same visibility gate as the Clockwork menu itself: agency-domain email + `manage_options`) who haven't set up 2FA now see a dismissible admin notice with a day countdown, starting a per-user grace period (stored as an absolute deadline, not a fixed countdown) the first time they're observed without 2FA. Default length is 30 days, filterable (`clockwork_companion_2fa_grace_days`) or overridable via the `CLOCKWORK_2FA_GRACE_DAYS` wp-config constant, mirroring the existing `CLOCKWORK_2FA_DISABLE` rescue hatch. Once the grace period elapses, the notice becomes non-dismissible and wp-admin is redirect-locked to the Login Security page until enrollment completes. The Login Security page's Team Status table gains a "Grace period" column for every eligible teammate — any agency admin can set a specific person's remaining days directly (extend someone who's out, or cut it short) or reset to the default; a per-target nonce keeps one person's form from being replayed against another's account, and the endpoint independently re-checks the *acting* user's own agency + manage_options standing (`EnrollmentNudge::handleSetGrace()` — deliberately separate from `TwoFactorActions`, which only ever acts on the current user). Deliberately scoped to client-invisible accounts only — client admins get 2FA available but not pushed, since the feature isn't even in their sidebar. Does not touch the wp-login.php flow (that stays LoginInterceptor's job for already-enrolled users) — enrollment needs the interactive QR/confirm flow, which only exists inside an authenticated wp-admin session.

## 1.31.11 — 2026-08-29

### Changed

- **Removed the Defensive Mode status line from the Security page.** It only ever showed status (on/off), with no way for anyone — client or ops — to actually act on it; that read as more confusing than useful. The underlying data is still pushed by `clockwork:pressable-security-summary-report` (harmless, kept for a future ops-side trigger), just not rendered. The vulnerability-alerts card on the same page is unaffected.

## 1.31.10 — 2026-08-29

### Fixed

- **Backup history depth and sizes were wrong — Pressable exposes much more than we were pulling.** The endpoint used until now (`/sites/{id}/backups`) only returns a short recent-pairing window; two separate, dedicated endpoints (`/backups/fs`, `/backups/db`) return the real depth (confirmed live on an established site: daily filesystem backups tapering to weekly, going back months) with a real size embedded in each entry. Filesystem and database backups run on independent cadences (daily vs. hourly) — the History section is now two separate tables (Filesystem backups, Database backups) with real per-entry sizes, instead of one merged table forcing them into the same row and showing permanently-empty size columns. SpinupWP sites are unaffected — unchanged single-table rendering.

## 1.31.9 — 2026-08-29

### Fixed

- **Backup history's Database/Files columns always showed "—" on Pressable sites, reading as a failure.** Pressable's `/backups` endpoint has no byte-size data at all, but each row does tell us whether that specific backup point captured a database snapshot and/or a files snapshot (via presence of the two backup IDs) — real signal we were discarding. `history_scope: 'available'` rows now show an "Included" pill per component instead of a permanently-empty size column that looked identical to "this part of the backup failed." SpinupWP sites are unaffected — they still show real byte sizes.

## 1.31.8 — 2026-08-29

### Fixed

- **Backups page misrepresented Pressable's real backup state.** Two issues, both on sites with `history_scope: 'available'` (Pressable): "Next scheduled run" showed "Not scheduled" — flatly contradicting a history table right below it full of real, hourly backups, because Pressable's API has no schedule-config endpoint to report a future run time from. Replaced with "Last backup," computed from the newest history row (a fact we actually have, not a guess). Separately, every history row's Type column showed "Scheduled" — reads as future-tense ("this will happen") next to something that already happened; relabeled to "Automatic" at the source. Also softened the page's top description away from "on a schedule" framing for the same reason. SpinupWP sites are unaffected — this only branches on `history_scope`.

## 1.31.7 — 2026-08-29

### Fixed

- **Traffic page's period-summary view (Pressable) still said "sourced from your server's access logs."** Leftover copy from the daily-chart path — wrong for Pressable, which has no access logs involved at all. Page header, the bottom caption, and the "Visits = unique IPs/day" methodology line (meaningless for host-reported page views) now all branch correctly for the period-summary case. Also added "Last month" as a 5th stat box — Pressable's stats API already returns it, it just wasn't being surfaced.

## 1.31.6 — 2026-08-29

### Added

- **Security page shows Pressable-only vulnerability alerts + Defensive Mode status.** Two signals Pressable's own API exposes with no SpinupWP equivalent: known plugin/theme CVEs (Pressable's own vulnerability feed) and Defensive Mode's current on/off state (an aggressive edge-cache mitigation for traffic spikes/bot attacks). Status-only for Defensive Mode by design — this is an ops-triggered mitigation, not a client-facing toggle; clients see whether it's active, not a switch to flip it themselves. New `/security-summary-report` endpoint, renders nothing on SpinupWP sites (the option is simply never populated there). Also fixed a real pre-existing gap found while building this: `.clockwork-notice--ok`/`--warn` were used on the Backups page but never defined in CSS — added both, matching the existing pill color tokens.

## 1.31.5 — 2026-08-29

### Fixed

- **Backups page no longer overpromises retention for Pressable sites.** Pressable's `/backups` endpoint has no pagination and returns whatever window it returns — observed ~36-38 hours across both pilot sites, nowhere near the 30/90-day figures the care-plan messaging assumed (that framing only ever applied to SpinupWP, where we control the retention window ourselves via Spaces). `BackupsReportRoute` now accepts a `history_scope` field; when it's `"available"` (Pressable), the History card shows "All available history" and a neutral note instead of a day-count promise or a care-plan upsell that Pressable's own API can't back up.

## 1.31.4 — 2026-08-29

### Added

- **Traffic page now handles period-totals-only hosts (Pressable).** SpinupWP sites source Traffic from an SSH-tailed nginx access-log rollup, which Pressable sites structurally cannot provide (no SSH access at all). Pressable's own stats API exposes period totals instead (today/yesterday/this-month/last-12-months), not a daily breakdown. `TrafficReportRoute` now accepts an optional `period_summary` alongside (in place of) `daily`, and `TrafficPage` renders a simplified stat-grid view for it instead of stretching sparse data into a chart that needs day-by-day granularity it doesn't have. SpinupWP sites are unaffected — this only activates when `daily` is empty and `period_summary` is present.

## 1.31.3 — 2026-08-28


## 1.31.2 — 2026-08-28

### Fix

- **`Runner::run()` reported a plugin update as successful even when re-activation failed, letting broken updates through as "complete."** Investigated after reports of random plugin disables across the fleet (WFLS/Contact Form 7 left deactivated on one site, The Events Calendar left with missing files on another). Root cause: `Plugin_Upgrader::upgrade()` succeeding does not mean the plugin is usable afterward — Runner re-activates it separately, and if that `activate_plugin()` call fails (corrupted/incomplete file swap, a fatal on activation, a genuinely missing plugin file), the failure was only appended to `messages`; `ok` stayed `true`. Since `AbstractRunUpdate` in the monitoring app trusts `ok` to decide `STATUS_COMPLETE` vs `STATUS_FAILED`, these updates sailed through nightly runs as successful — no Mattermost alert, no retry, no visibility — while `post-update-verify`'s `verifyPlugins()` explicitly skips slugs no longer in `get_plugins()`, so a fully-missing plugin file could never self-heal either. `Runner::run()` now returns `ok: false` (with a descriptive `error`) whenever a plugin that was active before the upgrade fails to re-activate after it, while still reporting `upgrade_completed: true` so the orchestrator's post-update-verify pass still runs and can repair collateral damage to *other* plugins. Companion-side half of a two-part fix; see the clockwork-monitoring-app changelog for the orchestrator-side defense-in-depth for sites still running older Companion versions.

## 1.31.1 — 2026-08-14

### Fixed

- Notifications page card header now uses the correct `.clockwork-card__head` class, restoring the expected `14px 20px` padding around the "Slack" heading.

## 1.31.0 — 2026-08-14

### Added

- **Client Slack notifications.** A new Notifications page (Clockwork admin → Notifications) lets Clockwork configure a Slack incoming webhook URL per site. When saved, the monitoring app reads the URL from the `/snapshot` endpoint and sends plain-English alerts directly to the client's Slack workspace when a contact form fails or the site goes down. Messages are intentionally generic and direct the client to contact Clockwork for a fix. Clients who have not configured a webhook receive no change in behavior.

## 1.30.4 — 2026-08-13

### Security

- **Support form now requires `manage_options`.** `SupportForm::handleSubmit()` verified a nonce but performed no capability check, and the nonce was localized onto every admin page for every logged-in user. A low-privilege user (e.g. subscriber) could lift the nonce and drive the handler, which proxies to the agency's Gravity Forms endpoint using the shared GF API credentials — an authenticated abuse/spam vector. The handler now rejects non-admins with 403, and the asset/nonce enqueue, dashboard widget, and footer modal are all gated on `current_user_can('manage_options')` so nothing is emitted to lower-privilege users.
- **Unlock hub secrets encrypted at rest.** `UnlockPage` stored every managed site's Companion HMAC secret in plaintext in `wp_options['cw_unlock_sites']` — a single DB dump of the ops hub would leak the whole fleet's secrets. Secrets are now sealed with `sodium_crypto_secretbox` under a key derived from wp-config material (`CLOCKWORK_UNLOCK_KEY` if defined, else the site's `AUTH_KEY`/`SECURE_AUTH_KEY` salts), which lives outside the database. Existing plaintext rows are read transparently and upgraded to ciphertext on the next save. Note: a change to the key material (salt rotation) makes existing ciphertext unreadable — re-import the affected sites (secrets are held authoritatively in Clockwork).
- **Unlock hub rejects non-public target hosts.** Domains are validated at store time (add + import) and again before the signed `DELETE /lockouts` request fires, blocking the proxy from being pointed at `localhost`, link-local, or private-range hosts (bare IPs, `*.local`, `*.internal`, single-label names).
- **2FA enrollment code is now burned.** `UserSettings::confirmEnrollment()` recorded no replay step, so the TOTP code used to confirm enrollment stayed valid for its full window and could be replayed as the first login factor. It now records the winning step (RFC 6238 §5.2). The WFLS migration path (`activateWithSecret()`) also clears stale replay state to avoid locking out a re-enrolling user.

## 1.30.3 — 2026-08-12

`PluginsRoute`: only set `update_available=true` when the transient's `new_version` is strictly greater than the installed version. Stale transients could keep a slug in `response[]` after it was already updated, producing false "1.x → 1.x" entries in the updates queue that would run as no-ops.

## 1.30.2 — 2026-08-12

Merge of 1.29.x 2FA branch (1.29.1–1.29.6) with 1.30.x Elementor branch (1.30.0–1.30.1). No new logic beyond resolving the divergence; both lines of development are fully included.

## 1.29.6 — 2026-07-31

### Added

- **`POST /wp-json/clockwork/v1/two-factor/migrate`** — HMAC-authenticated endpoint that triggers a WFLS → Companion migration for a given `user_id` without requiring the user to click through the admin UI. Intended for monitoring-app-driven fleet migrations.

## 1.29.5 — 2026-07-22

### Change

- **Migrate button shows a loading spinner.** Clicking "Migrate my two-factor setup" now immediately disables the button, shows a spinning circle, and changes the label to "Migrating…" so the page doesn't appear frozen while the migration POST processes.

## 1.29.4 — 2026-07-22

### Security

- **TOTP replay prevention.** `Totp::verify()` now returns `int|false` — the winning step counter on success, false on failure. `LoginInterceptor` records the last accepted step in `_clockwork_2fa_last_step` user meta and passes it as `$minStep` on every subsequent verify call. Any code at or below the already-used step is rejected, closing the 90-second reuse window (RFC 6238 §5.2).
- **Lock out after 5th bad code (not 6th).** After the 5th wrong TOTP or backup code the transient is immediately deleted and the user is bounced back to the login page with the "verification window expired" notice, instead of rendering the challenge form one more time with "0 attempts left." The existing `> MAX_ATTEMPTS` guard (which would have caught request 6) is now defense-in-depth for concurrent races only.

### Change

- **Attempt counter non-atomicity documented.** Added a comment explaining that the get-transient → increment → set-transient sequence is not atomic under Redis/Memcached, and why it's acceptable here (races allow at most ~2× the attempt cap, which is well within brute-force infeasibility for a 6-digit TOTP).

## 1.29.3 — 2026-07-22

### Change

- **Rename "Login Security" tab to "2FA"** to keep the nav compact.

## 1.29.2 — 2026-07-22

### Fix

- **Login Security page cards unstyled.** All four cards were using `cwk-card` (an undefined class), leaving content with no padding, shadow, or radius — most visibly the backup-codes alert card had no spacing between the red left border and the text, and no background tint. Switched to `clockwork-card` + `clockwork-card__body` throughout. Backup codes card gets a `#fef2f2` red tint; WFLS removal card gets a `#f0fdf4` green tint.

## 1.29.1 — 2026-07-22

### Fix

- **Login broken for users migrated from WFLS to Companion 2FA.** Migration was leaving the user's row in `wfls_2fa_secrets` for rollback purposes, but WFLS is still active on the site. WFLS's `authenticate` hook (priority 25) would see that row, try to issue its own 2FA challenge, and produce "An error was encountered while trying to authenticate" — Companion's gate at `PHP_INT_MAX` never ran. Fix: `migrate()` now deletes the user's WFLS row immediately after the secret is safely written to Companion user meta. The underlying binary key is identical in both systems, so the user's authenticator-app entry keeps producing valid codes.

## 1.30.1 — 2026-07-23

### Fix

- **`ElementorCacheGuard` could hang PHP-FPM (504 Gateway Timeout) editing a brand-new Elementor document.** Reproduced live on AEX: opening a never-saved JetEngine listing item (posts 1928/1918) in the Elementor editor times out. Elementor's `get_elements_data()` bootstraps an empty document via `convert_to_elementor()` → `save([])`, which fires `elementor/document/after_save` — the hook our 1.30.0 fix listens on. Rebuilding CSS for that empty document calls `get_elements_data()` again, sees the document still empty (the bootstrap save never persists real `_elementor_data`), and re-enters `convert_to_elementor()` → `save([])` → `after_save` → us again: infinite recursion, confirmed via a captured backtrace. Fixed by skipping the hook entirely when `_elementor_data` is empty (nothing to rebuild CSS for anyway), plus a re-entrancy lock as a second line of defense. Verified against the exact hang, plus a regression check that the original fix still rebuilds CSS and re-purges correctly for real saves.

## 1.30.0 — 2026-07-22

### Added

- **Elementor cache-race fix (`ElementorCacheGuard`).** Elementor deletes a post's compiled CSS mid-save but only regenerates it lazily on the next page load; hosts purge their page cache earlier in that same save request (SpinupWP on `transition_post_status`, WP Engine on `save_post`), so whichever request lands in the gap can get an unstyled page baked into the cache — a years-old upstream Elementor defect (GitHub #27735), not host-specific. Hooking `elementor/document/after_save`, Companion now forces the CSS rebuild synchronously in the save request, then re-purges the page cache (SpinupWP or WP Engine Varnish, whichever is present) only once the CSS is confirmed back on disk. No-op on non-Elementor sites; the callback is additionally guarded with class/method checks and a try/catch against future Elementor internals changes. Replaces the per-site `elementor-cache-race-fix.php` mu-plugin piloted on AEX and xqstaging.
## 1.29.0 — 2026-07-22

### Added

- **"Wordfence Login Security can now be removed" card.** Appears on the Login Security page the moment no existing user's 2FA depends on WFLS anymore (all roles counted, not just admins/editors — deactivating WFLS kills the gate for everyone on it). One click deactivates and deletes the plugin, running its uninstall cleanup; if file deletion fails the plugin is still left safely deactivated. `removeWfls()` refuses while anyone remains unmigrated. While accounts remain, the Team Status card shows how many are left instead. `/two-factor` (and the snapshot block) gain `wfls_unmigrated_total` and `wfls_ready_to_remove` so the monitoring app can show "migrated — remove WFLS" fleet-wide.

## 1.28.0 — 2026-07-22

### Added

- **Login Security admin page (Clockwork → Login Security).** Self-service 2FA enrollment: scan a QR code (rendered locally by a bundled MIT qrcode.js — the secret never leaves the page), confirm with a live code, get one-time backup codes. Enrolled users can regenerate codes or disable. A Team Status card lists every admin/editor and whether they're on Clockwork 2FA, still on Wordfence Login Security, or unprotected. When WFLS is detected, the enrollment card becomes a migration banner — one click ports the existing authenticator-app secret; urgent red styling when WFLS's data is present but the plugin is inactive (those users' gate is already off). Backup codes hand off from the action handler to the page via a 60-second single-read transient, never a URL.
- **`/two-factor` REST endpoint + `two_factor` snapshot block + `two-factor` capability.** Per-admin/editor enrollment state (`clockwork` / `wfls` / `none`), site-level WFLS presence/activity, and the gate-disabled hatch flag — embedded in `/snapshot` so Clockwork's nightly fleet refresh picks it up with zero extra round trips. Feeds the monitoring app's "Two-factor at risk" issue section.

## 1.27.0 — 2026-07-22

### Added

- **Two-factor authentication (TOTP), replacing Wordfence Login Security.** Wordfence is discontinuing the standalone WFLS plugin; Companion now carries the same core capability: RFC 6238 authenticator-app codes (Google Authenticator / Authy / 1Password), hashed single-use backup codes, and a login gate that withholds the auth cookie until a valid code is entered (never a set-then-destroy window). Enrollment is two-phase — the secret only activates after the user proves their app produces a valid code — so self-lockout via an unscanned QR is impossible. Challenge attempts cap at 5 per pending login and failures land in the auth-failures audit table. Fleet rescue hatch: `wp config set CLOCKWORK_2FA_DISABLE true --raw` turns the gate off instantly over SSH. XML-RPC/REST requests bypass the challenge (application passwords keep working; xmlrpc stays blocked at the edge as before).
- **Wordfence Login Security migrator.** Detects WFLS installs (including deactivated-but-data-present ones) and copies a user's TOTP secret into Companion 2FA — the same authenticator-app entry keeps working, no re-scan needed. Schema verified against a live WFLS 1.1.16 install: the secret is a raw 20-byte binary tinyblob, and enrollment-state is row-existence in `wfls_2fa_secrets`. WFLS rows are left untouched for rollback; fresh hashed backup codes are issued instead of porting WFLS's unhashed recovery blob. No admin UI yet — this release is the engine + gate + migrator, deployed to a canary for soak testing; enrollment/migration UI follows.

## 1.26.10 — 2026-07-21

### Fix

- **Activity page "Uptime" tile showed a plain circle instead of an icon.** The tile grid's `typeConfig()` only mapped the `uptime_check` action type, but `UptimePage::ACTION_TYPE` actually logs rows as `uptime_transition`, so those rows fell through to the generic circle icon and an ungainly "Uptime Transition" label. Added the `uptime_transition` mapping (label "Uptime checks", same `pulse` icon) and swapped the `pulse` icon itself for a cleaner heartbeat/waveform glyph.

## 1.26.9 — 2026-07-21

### Changed

- **Admin chrome design pass, per design-team review.** The page background dropped its pale-purple tint in favor of white, and the 4px lime-green rule under the header is gone. The header logo shrunk from 36px to 24px tall. The Activity page's "You're on a care plan" banner switched from a green success card to a light-purple one with a purple icon and dark-grey title text. The month-filter buttons on the Activity page now use the actual brand purple (`--cwk-primary` / `--cwk-primary-dark`) instead of a mismatched indigo, and their hover state is a legible light-purple fill instead of a low-contrast blue tint. The Activity page's stat-count pills and "Run automatically" note also moved from green to purple, matching the icon tiles already on that card.

## 1.26.8 — 2026-07-15

### Changed

- **Traffic page now distinguishes visits from requests.** The hero stat trio (Today / 7d / 30d) and the chart below measure different things — visitors vs total HTTP requests — but nothing said so, and on bot-heavy sites the chart's request bars dwarf the visitor numbers, reading as a contradiction (e.g. one client site: ~2,400 visits/30d vs ~500k requests). The hero card now carries a "Visitors" heading and a one-line definition (de-duplicated by IP, bots and static files excluded), the "Today" box labels its number as visits, and the chart body opens with a note that it counts every request — page views, assets, API calls, and crawlers — so it measures server workload, not people.

## 1.26.7 — 2026-07-15

### Fix

- **Traffic chart date labels past x=1000 no longer jump to the far left.** The SVG `<text>` x-coordinate for date labels was formatted with `number_format($colCenter, 2)`, whose default thousands separator turned `x="1014.80"` into `x="1,014.80"` — which SVG parses as `x="1"`, slamming the label to the left edge. On every 30-day chart the last two date labels (centers ~1015 and ~1165) were affected, so e.g. `07-11` and the final day rendered stacked at the far left instead of on the right. Both the date-label x and the y-tick y now format with an explicit empty thousands separator. (Bar rects were already fine — they used `printf('%.2f')`.) Surfaced on one high-traffic client site, but it affected all sites' charts.

## 1.26.6 — 2026-07-15

### Fix

- **Traffic chart y-axis labels no longer clip on high-traffic sites.** Labels were drawn 6px inside a 32px left gutter, so six-figure ticks ("100.0k", "300.0k" — one high-traffic client site serves ~200k/week) ran off the left edge of the SVG viewBox and rendered as "00.0k". The gutter is now 56px (right gutter narrowed to 16px to compensate, keeping the plot width about the same), and `shortNumber()` drops the trailing ".0" on round values so ticks read "300k" / "1.5M" instead of "300.0k" / "1.5M" — narrower and cleaner. The dashboard-widget 30-day total uses the same helper, so it benefits too.

## 1.26.5 — 2026-07-15

### Fix

- **`php_in_uploads` accepts comment-only guard stubs.** Following the die()/exit() fix in 1.26.4, a `<?php // Nothing to show here` stub (dropped by the debug-log-manager plugin, seen on a client site) still flagged. `isHarmlessIndexStub()` now also accepts an opening tag followed solely by a single line (`//`, `#`) or block (`/* */`) comment — inert by definition, still under the 60-byte cap, and any statement after the comment still flags.

## 1.26.4 — 2026-07-15

### Fix

- **Malware scanner no longer self-flags stray copies of the Companion.** The signature-scan exclusion matched only `/wp-content/mu-plugins/clockwork-companion/`, so a botched historical install left at `wp-content/plugins/clockwork-companion/` (one client site — flat files with literal backslashes in the names, from a pre-fix tarball) produced 8 shell/eval "findings" that were the scanner reading its own signature list. The exclusion now matches any `/clockwork-companion/` path segment; the `php_in_uploads` check intentionally keeps no such exclusion, so PHP hidden in a same-named uploads directory still flags.
- **`php_in_uploads` accepts bare die()/exit() guard stubs.** `isHarmlessIndexStub()` only recognized empty files and "Silence is golden" comments; a 15-byte `<?php die(); ?>` guard (one client site's `uploads/index.php`) was flagged as "never legitimate". A whole-file-anchored regex now accepts an opening tag followed only by die/exit (optional short string message), still under the 60-byte cap — trailing code of any kind keeps flagging.

## 1.26.3 — 2026-07-14

### Fix

- **Failed GTmetrix scans no longer render on the Performance page.** 1.26.2's engine filter matched failed rows too, so a site whose scan errored (blocked test agent, Lighthouse timeout) showed a client-visible "Failed" hero card — worse than the empty state. `latestPerformanceScanFromEngine()` now requires `ok = 1` and the history filter drops failed rows. Scan failures are Clockwork's ops signal (visible in the monitoring app), not a client-facing result.

## 1.26.2 — 2026-07-14

### Changed

- **Performance page shows GTmetrix scans only.** PSI fallback rows (and pre-cutover PSI-era rows) score under throttled mobile emulation — 30-60 on sites that grade 90+ on GTmetrix desktop — and made healthy sites look broken next to GTmetrix rows. The hero card, dashboard-widget summary, and scan history now filter to `engine=gtmetrix` (new `Repository::latestPerformanceScanFromEngine()`, matching on the details JSON, plus a PHP-side history filter). Sites with no GTmetrix row yet show the "first scan pending" empty state until their weekly rotation night. Raw PSI rows remain in the local table and the monitoring app's database — nothing is deleted. The mixed-engine history footnote (added in 1.26.0) is gone; it's unreachable now.
- **Dropped the page-header intro paragraph** ("Weekly Lighthouse scan via GTmetrix — …"). The care-plan banner right below carries the same message.

## 1.26.1 — 2026-07-14

### Changed

- **Performance page copy says "weekly" instead of "daily at 03:30 UTC".** The monitoring app moved to a weekly-per-site rotation on 2026-07-14 (nightly run at 04:45 UTC scans 1/7th of the fleet) so every scan lands on GTmetrix instead of exhausting the 10-credit daily API budget and falling back to PSI. Header, care-plan banner, upsell banner, and empty-state copy updated to match.

## 1.26.0 — 2026-07-14

### Changed

- **Performance page shows a single "Latest Scan" card instead of the Mobile/Desktop pair.** Only one scan runs per night since the 2026-06-27 GTmetrix cutover, so the Desktop card had been frozen at its last pre-cutover result (weeks stale) while the Mobile card updated daily — reading as two current-but-wildly-different scores. The single card is keyed off the latest `performance_scan` row regardless of target, and its subtitle now names the engine and test conditions (`GTmetrix (desktop Chrome, pinned location)` vs `Google PageSpeed (simulated mobile phone)`), because the monitoring app silently falls back to PSI when GTmetrix fails and the two engines score very differently. The dashboard-widget `summary()` follows the same latest-row lookup instead of latest-mobile.
- **Scan History "Strategy" column replaced with "Engine".** The stored strategy label no longer describes the test (GTmetrix runs desktop Chrome regardless of the strategy the scheduler passes); the engine is what actually determines the score's meaning. Rows without an `engine` key (pre-cutover) render as PageSpeed with their historical strategy. When the visible history mixes engines, a footnote warns that scores are only comparable within one engine.

## 1.25.2 — 2026-07-10

### Fix

- **Backups page now shows the correct care-plan retention banner.** `BackupsReportRoute` stored only a whitelisted subset of the pushed report (`source`, `fetched_at`, `config`, `history`) and dropped `care_plan_enabled` + `retention_days` — so `BackupsPage` rendered the off-plan "30 days, talk to us about a care plan" upsell on every site, including care-plan sites whose history was already filtered to 90 days. The route now stores both fields (plus `schedules`, which Clockwork already pushes, for forward-compat). Requires a fresh `/backups-report` push after upgrading — the banner stays wrong until the next daily 06:30 UTC push or a manual `clockwork:push-companion-backups --site=X`.

## 1.25.1 — 2026-06-30

### Fix

- **Performance page copy updated to credit GTmetrix instead of Google PageSpeed Insights.** The data pipeline became engine-agnostic when the monitoring app cut over to GTmetrix as the primary performance-scan engine on 2026-06-27, but two intro strings on `PerformancePage` still named PSI. Clients reading the page now see accurate attribution + a brief explanation of why pinned-location testing produces less day-over-day noise than the previous PSI-driven scans. The intro also dropped the "mobile + desktop are both shown" line since the scheduler now runs a single nightly scan (GTmetrix doesn't expose mobile device emulation on the current paid tier; if it does later, the line can come back). No data-shape changes — `PerformancePage` was already reading engine-neutral fields out of `wp_clockwork_action_log.details` (performance_score, lcp_ms, fcp_ms, tbt_ms, cls_x1000, page_weight_bytes, si_ms, strategy), so existing rows render unchanged.

## 1.25.0 — 2026-06-30

### Added

- **Dashboard widget stats grid.** The wp-admin "Clockwork Web Dev" dashboard widget now shows a 6-tile grid — uptime, security, backups, performance, traffic, and tasks done this month — instead of just the support button, so a glance at wp-admin shows the site is actively monitored. Each tile links to its full Tools → Clockwork page. Added `summary()` to `UptimePage`, `SecurityPage`, `BackupsPage`, `PerformancePage`, `TrafficPage`, and `ActivityPage` so the widget reuses each page's own status/scoring logic rather than duplicating it — the numbers can never disagree with the full pages.

### Fix

- **Activity page care-plan banner icon** actually renders a party popper now. The previous SVG (added in 1.24.0) used the wrong path data and looked like a paper airplane/rocket.
- **Performance page spacing.** The "opportunities to improve this score" note sat flush against the vitals grid above it; added top margin.

## 1.24.1 — 2026-06-30

### Fix

- **Companion REST endpoints no longer 401 on sites running perfmatters with "Disable REST API" enabled.** The `perfmatters` plugin (a popular performance optimizer) lets site owners disable anonymous REST API access via a `rest_authentication_errors` filter at priority 20. It ships with a hard-coded allowlist (contact-form-7, wordfence, elementor, ws-form, etc.) and exposes the `perfmatters_rest_api_exceptions` filter for additional plugins to register themselves — but Companion wasn't on the list, so Clockwork's HMAC-signed calls were being rejected at WordPress's REST auth layer *before* Companion's per-route permission_callback ran. Symptom: SSO mint fails with "rest_authentication_error", snapshot refreshes silently fail (companion_last_seen_at drifts), every other endpoint 401s. Caught on a client site 2026-06-30 — site had been quietly broken since perfmatters' REST-disable was toggled on. New `ClockworkCompanion\Compat\PerfmattersCompat` class registers via `Plugin::boot()` and unconditionally adds `clockwork` to the `perfmatters_rest_api_exceptions` allowlist. No-op on sites without perfmatters installed.

## 1.24.0 — 2026-06-24

### Added

- **Support form modal.** A "Get Support" button in the admin header opens a modal that proxies submissions to the Clockwork GF form via the Gravity Forms REST API v2 (Basic Auth with Consumer Key + Secret). The form auto-fills the user's name, email, and site URL. Includes billing-acknowledgement checkbox and optional email-updates opt-in.

### Fix

- **Activity page care-plan banner icon** updated to a styled SVG party-popper matching the Clockwork brand icon set.
- **Support form intro text** no longer persists in the modal after a successful send; it is hidden on success and restored when the modal reopens.

## 1.23.0 — 2026-06-22

### Added

- **Bulk import on the Unlock Manager admin page.** Paste the Markdown table from the fleet secrets snapshot (the `| Site | Server | Companion secret |` format) and the page parses domains + 64-char hex secrets in a single submit. Existing entries with a changed secret are updated in place; invalid rows are counted and skipped without aborting the import. Reports added / updated / skipped counts on success. Replaces the per-site one-at-a-time entry flow when bootstrapping a new operator's hub install.

### Fix

- **LLAR unlock now works across every subsite on multisite.** `unlockIp` and `unlockAll` previously only touched the main blog's `limit_login_*` options, leaving locked-out IPs stuck on subsites. The endpoints now iterate every blog via `switch_to_blog()` so per-site option storage is cleared everywhere. Single-site installs are unaffected — they iterate exactly one blog.
- **LLAR "Network/Site Wide" mode lockouts are also cleared.** When LLAR is configured to store lockouts via `update_site_option()` into `wp_sitemeta` (the network-mode storage path), the previous per-blog sweep missed them entirely and the IP stayed locked out network-wide. Post-loop sweep now clears `limit_login_lockouts` / `limit_login_retries` / `limit_login_retries_valid` from `wp_sitemeta` too.
- **Cleared-count on multisite no longer under-reports.** `get_site_option()` could return a stale empty value from the WP object cache after the `switch_to_blog()` loop, making the cleared-count display "0" even when a network-level lockout was successfully removed. Query `$wpdb->sitemeta` directly before the delete so the count reflects what's actually in the DB.

## 1.22.2 — 2026-06-19

### Fix

- **Multisite network-active plugins no longer silently deactivate during plugin upgrades.** The 1.21.3 post-update-verify covered single-site `wp_options.active_plugins`, but on multisite a network-activated plugin (Beaver Builder on a multisite-built design, WPMU DEV plugins, MainWP, etc.) lives in `wp_sitemeta.active_sitewide_plugins` — invisible to `get_option('active_plugins')`. When WordPress's filesystem-swap step during a plugin upgrade temporarily deactivated the network-active plugin, the verifier didn't know it was supposed to be active and never re-activated it. Caught after a client site lost Beaver Builder during an unrelated `bb-theme-builder` upgrade (same shape as another client site a month prior). Three changes:
  - `PluginsRoute` now reads `get_site_option('active_sitewide_plugins')` on multisite and merges those slugs into the `active=true` set in the snapshot. Each plugin row gets a new `network_active` boolean field so downstream code can distinguish per-site vs network activation. Single-site installs always get `network_active=false`.
  - `PostUpdateVerifyRoute` accepts a new `network_active_plugins` array in the request body and runs a parallel verify pass against `active_sitewide_plugins`, re-activating any missing slugs via `activate_plugin($slug, '', true)` (the `$network_wide=true` branch, which writes back to `active_sitewide_plugins`). Emits `network_plugin_reactivated` / `network_plugin_reactivate_failed` repair types so action logs distinguish the two scopes.
  - On the Clockwork side, `AbstractRunUpdate::captureStateBefore` splits the snapshot's active plugins into per-site and network buckets, and `ClockworkCompanionClient::verifyAndRepair` sends both lists. Backward-compatible: pre-1.22.2 Companions ignore the new key; pre-fix snapshots (no `network_active` field) populate an empty list, preserving the pre-fix behavior on those sites until the snapshot refreshes.

## 1.22.1 — 2026-06-16

### Copy + UI polish

- **Admin pages got a consistency pass across Activity, Forms, Performance, Security, Traffic, and Uptime.** Anton's `feature/normalize-action-labels` branch normalized link labels, button copy, empty-state phrasing, and inter-section spacing so the six client-visible pages read like one product instead of six independently-evolved screens. `FormsPage` picks up a richer page subtitle that pulls the value-prop ("we submit it like a real visitor would, verify the email actually leaves your server, alert immediately when a form silently fails") into the header rather than burying it in the off-plan banner — works on care-plan AND off-plan since the framing is "what this feature does," not "what you'd unlock." `SecurityPage` saw the deepest revision (47 insertions / 13 deletions): tightened the malware-scan + Sucuri SiteCheck + core-checksums card copy, fixed link affordances, evened out vertical rhythm against the other admin pages. `PerformancePage` and `TrafficPage` got mirror treatments at smaller scale. `ActivityPage` actually went down 5 net lines — refactor cleanup, not a behavior change.
- **No REST routes touched, no capabilities advertised, no schema migrations.** Purely client-facing presentation.

## 1.22.0 — 2026-06-15

### Features

- **`DELETE /wp-json/clockwork/v1/lockouts` endpoint to clear LLAR lockouts remotely.** `LockoutsRoute::handleDelete()` supports two modes: bare `DELETE /lockouts` clears every active lockout across both LLAR storage locations (the dedicated table AND the `wp_options` row that older LLAR versions use), and `DELETE /lockouts?ip=<addr>` clears a single IP along with its retry counters and log markers. HMAC-signed like every other Companion endpoint, so the agency can spring a customer's IP without needing wp-admin access. New `lockouts-unlock` capability advertised in `/health` so Clockwork can gate the call on Companion ≥ 1.22.0.
- **Agency-side Unlock hub page (opt-in via `CLOCKWORK_UNLOCK_HUB` constant).** When `define('CLOCKWORK_UNLOCK_HUB', true);` is set in `wp-config.php`, Companion adds a `Clockwork → Unlock` submenu page where the agency operator can clear LLAR lockouts on any other Companion-installed site without leaving the dashboard. The page reads its target-site roster from `wp_options` (pre-configured per-site secrets) and fires HMAC-signed `DELETE /lockouts` calls server-side via `wp_remote_request()`. Page, AJAX handler, and menu entry are **only registered when the constant is defined** — client sites with the plugin installed see no UI difference and no new attack surface.

### Internal

- Merged accumulated 1.21.1 → 1.21.6 changes onto the feature branch before integration (ThemesRoute, ThemeUpdateRoute, CoreUpdateRoute, premium-plugin loopback, Forms upsell, backups page tidy-up, custom REST UA).

## 1.21.6 — 2026-06-15

### Fix

- **Backups admin page no longer claims history "isn't shown" on the hosting tier.** Clockwork already pushes 30 days of backup history to off-plan sites (and 90 to care-plan sites) — but the BackupsPage empty-state copy was lying to off-plan clients, telling them "Detailed backup history isn't shown on the hosting-only tier" even when no data had been indexed yet. That message also fired when the actual cause was just "the nightly indexer hasn't run yet," making it look like a feature gate when it was a timing artifact. Empty state is now plan-agnostic: every site sees "Backup history hasn't been indexed yet, your backups are still running" with a small italic addendum for off-plan customers noting that care plan extends retention to 90 days with monthly retention reports. The "Last 30 days" / "Last 90 days" retention pill in the card header is now the canonical place the plan difference is communicated. Also removed the interruptive upsell banner that previously rendered above the table on off-plan sites with data — it duplicated what the retention pill already says and broke the "just show what's available" promise.

## 1.21.5 — 2026-06-15

### Features

- **Forms admin page now upsells the care plan instead of silently no-op'ing.** Off-plan customers used to see the Monitor toggles as fully functional, but the agency-side scheduler never actually tested their submissions — confusing in-product, and a missed sales opportunity. `FormsPage` now reads the care-plan flag (same `Repository::latestCarePlanFlag()` used by Performance / Security / Backups), renders an amber upsell banner at the top explaining what scheduled form testing buys ("we submit it like a real visitor would, verify the email actually leaves your server, ping our team the moment a form starts silently failing"), and keeps the **Detected forms section fully visible** with the toggles styled normally — locked with a small inline "Care plan" pill and a `not-allowed` cursor. The list is the selling point: clients see exactly what they'd get tested. Existing subscriptions (from a previous care-plan period) stay rendered as the "Forms you were monitoring" section with a "Care plan paused" pill on each row; Stop-monitoring still works (cleanup), Test-now is gated. On-plan customers see the existing UX with a confident green confirmation banner above. Defense-in-depth: `FormsAjaxHandlers::subscribe` and `::testNow` refuse with `403 + error: care_plan_required` when off-plan, so a clever admin can't bypass the disabled UI. `unsubscribe` and `redetect` deliberately stay open — let them clean up old subs and continue to demo the detector.

## 1.21.4 — 2026-06-15

### Fix

- **Premium-plugin updates now surface in `/plugins`, `/themes`, and `/snapshot`.** Plugins that ship update info via Crocoblock's Jet Dashboard (Jet Search, Jet Smart Filters, etc.) and similar admin-only update mechanisms gate their initialization on `is_admin()` — true for `/wp-admin/` and `admin-ajax.php` requests, false for plain `/wp-json/` REST. Without admin context, their `pre_set_site_transient_update_plugins` hook never registers and their updates are invisible to Clockwork (and to `wp-cli` for the same reason), even though wp-admin shows them. New `Updates\TransientRefresher` makes a `wp_remote_post()` loopback call to the site's own `admin-ajax.php` (action `clockwork_refresh_updates`, HMAC-signed with the Companion secret over `<action>|<timestamp>`). The admin-ajax request runs through the real admin lifecycle, so premium plugins initialize and their filters fire. The receiving `Admin\UpdatesRefreshAjaxHandler` calls `wp_update_plugins(['source' => 'clockwork-companion-loopback'])` — the non-empty `$extra_stats` array is **required**: it bypasses WP's built-in 60s/12h rate-limit short-circuit that otherwise causes the call to return without firing the filter chain when `last_checked` is recent. Rate-limited at the Companion side to once per 30 min via the transient's own `last_checked` so back-to-back snapshot calls don't loop. Soft-fails on loopback errors. Handler registers on both `wp_ajax_*` and `wp_ajax_nopriv_*` since the loopback has no user session; the HMAC + 60s replay window is the gate.

## 1.21.3 — 2026-06-01

### Features

- **Post-update state verification and repair.** New `POST /wp-json/clockwork/v1/post-update-verify` endpoint. After every successful plugin, theme, or core update Clockwork calls this endpoint with the list of plugins that were active and the theme that was active before the update ran. Companion compares against current WordPress state: any plugin that was active but is no longer gets re-activated via `activate_plugin()`; if the active theme changed it is restored via `switch_theme()`. The response includes a `repairs` array (`[{type, slug, detail}]`) that Clockwork persists to the `plugin_update_jobs` row and surfaces in the action log summary. Best-effort from Clockwork's side — a verify failure never marks the update as failed.
- **New capability advertised: `post-update-verify`.** Clockwork gates the verify call on this capability, so sites with older Companion versions skip it silently.

## 1.21.2 — 2026-06-01

### Fix

- **Multisite theme protection during theme upgrades.** WordPress's `validate_current_theme()` can fire during the filesystem replacement window of a theme upgrade and silently switch a sub-site's active theme to a fallback. `ThemeUpdateRoute` now takes a pre-upgrade snapshot of every sub-site that uses the slug being upgraded (as stylesheet or template), and after the upgrade walks each affected sub-site, flushes the theme cache, and restores the theme if WordPress changed it. The number of sub-sites repaired is returned in the response and included in Clockwork's action log entry.

## 1.20.3 — 2026-05-14

### Fix

- **Core checksum scan tolerates intentionally-stripped disclosure files.** `license.txt`, `readme.html`, and `wp-config-sample.php` are commonly removed for hardening (they leak the WP version). Their absence isn't tampering. Both the wp-cli output parser and the pure-PHP fallback now filter these out of the "missing" tally, so a hardened install reports clean. Also matches the newer wp-cli phrasing `"File doesn't exist:"` alongside the older `"File is missing:"` — without both, a stripped-readme.html site fell through to a generic STATUS_FAILED.

## 1.20.2 — 2026-05-14

### Copy

- **User-facing admin pages now name the brand explicitly ("Clockwork Web Dev") instead of generic "your hosting provider" / "your agency" / "the agency".** Every visible string across Activity, Uptime, Security, Performance, Traffic, Backups, and Forms got the same treatment so the wp-admin chrome promotes the agency consistently. Architectural docblocks and inline comments stay generic — they describe the Companion/Clockwork architecture, not the brand identity.

## 1.20.1 — 2026-05-14

### Fix

- **Traffic chart now fills its container width.** The SVG had `max-width: 720px` which left a large empty band on the right at typical wp-admin column widths. Bumped the intrinsic viewBox width to 1200 (~6:1 aspect) and dropped the max-width cap; the SVG already had `width: 100%; height: auto` so it stretches cleanly to fit. Bars get proportionally wider at larger sizes without distortion.

## 1.20.0 — 2026-05-14

### Features

- **Uniform pagination on Activity and Security admin pages.** Long-running care-plan sites accumulate hundreds of action_log entries and scan history rows; loading them all in one page render was getting heavy and the rendered table was hard to scan. New shared helper `Layout::renderPagination()` emits a consistent prev/next strip with row-range label ("26 – 50 of 312") and clamped page-of-pages controls. Both pages now load 25 rows per page via new repository methods `findInWindowPaged()` / `findByActionTypePaged()` and matching `countInWindow()` / `countByActionType()` for total counts. Activity's per-type breakdown still reads unpaginated so the "X actions performed this month" hero number reflects the full window, not just the visible page.

### Fix

- **Scan history pill respects the `warning` status downgrade.** A scan with only `wp_config_recently_modified` findings is stored as status=`warning` on the agency side, but the Companion's history table was painting it red ("Issues found") because the pill logic short-circuited on `modified_files_count > 0` before checking status. Now the warning branch wins first → yellow "Review" pill, matching the agency dashboard's per-site Security tab.

## 1.19.5 — 2026-05-14

### Fix

- **Lab-mode form tests now bypass CAPTCHA / anti-bot filters.** Forms protected by Cloudflare Turnstile, reCAPTCHA, hCaptcha, or similar were silently failing validation with no field message because the anti-bot plugin globally flips `is_valid=false` via `gform_validation` when no token is present — but an automated test can't produce a real token. In lab mode the strategy now sweeps `gform_validation`, `gform_pre_validation`, and `gform_entry_is_spam` for callbacks whose name contains `turnstile`/`captcha`/`recaptcha`/`hcaptcha`, detaches them around the submit, and reattaches them in a `finally`. Live mode runs untouched — an operator running a live test against a CAPTCHA-protected form still sees the rejection and can decide whether to disable CAPTCHA or stop monitoring that form.

## 1.19.4 — 2026-05-14

### Fix

- **Read per-field validation messages from BOTH places Gravity Forms stores them.** Some GF versions/hooks only populate `$result['form']['fields'][i]['validation_message']` (when `failed_validation` is true) and leave `$result['validation_messages']` empty. The strategy now walks both arrays and dedupes by field id, so a "no field messages" hint only fires when neither path has anything.

## 1.19.3 — 2026-05-14

### Fix

- **Better diagnostic when Gravity Forms validation rejects without per-field messages.** Previously a whole-form rejection (conditional-logic miss, anti-spam, page-routing mismatch) surfaced as the bare string `Gravity validation rejected:` with no detail. Now the strategy falls back to a hint string including the result keys, page numbers, and the names of the inputs we submitted, so the operator can see what GF was looking at when it bailed.

## 1.19.2 — 2026-05-14

### Fix

- **Hotfix for 1.19.1's TypeError on choice-based Gravity Forms fields.** `firstRealChoice()` typed `$field` as `array`, but Gravity Forms enumerates fields as `GF_Field_*` ArrayAccess objects — the strict hint refused them and threw `TypeError: Argument #1 ($field) must be of type array, GF_Field_Checkbox given`, producing the WP "critical error" / HTTP 500 that the agency dashboard surfaced as a stuck "Testing…" button. Loosened the hint to `mixed`; ArrayAccess `$field['choices']` already worked for both shapes.

## 1.19.1 — 2026-05-14

### Fix

- **Gravity Forms tests now handle every field type, not just text/email/textarea/checkbox.** Forms with required `select`, `radio`, `multiselect`, `date`, `time`, `phone`, `website`, `number`, or `address` fields previously failed validation with messages like *"Invalid selection. Please select from the available choices."* — the strategy was falling through to the `default` branch and submitting the literal name string. New behaviour: choice fields (`select`/`radio`/`multiselect`/`checkbox`) read the form's `choices` array and submit the first non-placeholder real value; format-validated fields (`date`/`time`/`phone`/`website`/`number`) submit a format-compliant placeholder; composite `address` fills the documented composite keys; non-input field types (`hidden`/`fileupload`/`section`/`html`/`page`/`captcha`) are skipped instead of getting a junk value.

## 1.19.0 — 2026-05-13

### Features

- **Client self-service Forms tab.** Local wp-admin users can now subscribe forms to recurring testing directly inside Companion — no agency-side dashboard access needed. The Forms tab now shows two sections: "Forms detected on this site" (list of every form the active plugin reports, with a Monitor toggle per row) and "Forms you're monitoring" (subscribed forms with Test-now button + last-run status). Hard cap of 3 monitored forms, enforced both client-side (toggle disables) and server-side (`SubscriptionsService::subscribe()`). All AJAX paths gated on `manage_options` + nonce; the agency's HMAC paths are unchanged.
- **New REST endpoint `/wp-json/clockwork/v1/form-subscriptions`** (HMAC-protected). Returns the local admin's subscriptions list; Clockwork's nightly `clockwork:sync-companion-form-subscriptions` pulls and reconciles into the agency-side `contact_form_tests` table with provenance `client`.
- **`DetectedFormsCache`** caches `PluginRegistry` enumeration in a wp_option with a 24h TTL, so the Forms tab renders instantly. "Re-detect now" button forces a refresh.
- **New capability advertised: `form-subscriptions`.** Lets Clockwork detect which sites support the self-service flow.

### Notes

- Existing agency-driven tests are unaffected — `contact_form_tests` rows with `created_by=agency` are managed entirely from the dashboard. Client subscriptions live alongside as `created_by=client`; the sync command only touches the latter.
- Test-now from the wp-admin UI runs the local `Tester` directly (no HTTP roundtrip to the agency) and persists the result via `FormsPage::recordResult()` — same code path that the agency-initiated tests use.

## 1.18.0 — 2026-05-13

### Features

- **Forms admin page.** New tab between Traffic and Backups. Renders one card per contact form Clockwork has tested against this site (status pill, last run, mail outcome, error if failing). Failing forms float to the top. Empty state explains the gap when no tests have run yet. Backed by `wp_options['clockwork_companion_form_test_results']`, written as a side effect of the existing `/test-contact-form` endpoint — no new push channel.

### Fix

- **Traffic chart now always renders the full 30-day window.** The previous auto-crop produced 6 absurdly wide bars on newly-onboarded sites with limited rollup history, which read as "30 days lumped together." New behaviour: walk today-29 → today, look each date up in the pushed rows, render empty slots for dates without data. Bar width is `plot_width / 30 * 0.7` — narrow and consistent regardless of how many days of history exist. The "tracking started YYYY-MM-DD" subhead is preserved for new sites where data starts inside the window.

## 1.17.2 — 2026-05-11

### Fix

- **Remote sampler toggle now actually persists when the option row is absent.** `Sampler::setEnabled(false)` on a fresh-from-1.17.0 install was a no-op: WP's `update_option` short-circuits when the new value equals the old, and `get_option` on a missing row returns the caller-supplied default (`false` in our case) — making the toggle indistinguishable from "no change." Switched the flag's storage from bool to the string sentinels `'enabled'`/`'disabled'`, which have no such collision with the missing-row default. Only 1.17.1 (canary-only) was affected; no fleet sites ran the buggy version.

## 1.17.1 — 2026-05-11

### Features

- **Remote sampler toggle.** New HMAC-gated `POST /wp-json/clockwork/v1/resource-sampler-config` with body `{ "enabled": true|false }` flips `wp_options['clockwork_companion_resource_sampler_enabled']`. When disabled, `Sampler::register()` short-circuits before adding the `shutdown` hook — zero per-request overhead. Takes effect on the next request.
- **New capability advertised: `resource-sampler-toggle`.** Lets Clockwork detect which sites support the remote toggle (vs 1.17.0 sites that have the sampler but no toggle endpoint).

### Notes

- Default state is enabled — 1.17.0 → 1.17.1 in-place upgrades keep sampling without any explicit toggle.
- The toggle is observed at sampler registration time (per request), so flipping it takes effect on the first request AFTER the POST completes.

## 1.17.0 — 2026-05-11

### Features

- **Per-request resource sampler.** New `Resource\Sampler` hooks `shutdown` and writes the request's `getrusage()` user+system CPU microseconds, wall time, and `memory_get_peak_usage(true)` into a new `wp_clockwork_resource_hourly` bucket. Single UPSERT per request — one row per hour per site. 90-day retention with lazy prune.
- **New REST route `GET /wp-json/clockwork/v1/resource-report?since=<iso8601>&limit=<int>`** returns hourly buckets ≥ the cursor. HMAC-gated like every other route. Clockwork's ingest loop pulls this every 15 min to feed the per-site CPU leaderboard on `/capacity`.
- **New capability advertised: `resource-sampler`.** Existing snapshot/health refresh will pick it up on next pull; Clockwork's pull loop gates on this capability so older sites are skipped cleanly.

### Coverage notes

- PHP CPU only — MySQL, nginx, Redis, etc. are not captured. Sites that are DB-bound rank lower than they should on the leaderboard; cross-reference per-server metrics for the fuller picture.
- CLI invocations are skipped unless `DOING_CRON` is true. One-off ops scripts don't pollute the rollup; WP-Cron runs (including DISABLE_WP_CRON + system-cron setups) are kept.

## 1.16.9 — 2026-05-10

### Security

- **`HmacVerifier::clientIp()` now validates the source IP** with `filter_var(..., FILTER_VALIDATE_IP)` before returning it. A garbage value (from any upstream that ever populates `REMOTE_ADDR` from an untrusted forwarded header) now becomes the literal `'unknown'` instead of being written verbatim into the auth-failure audit table.
- **New opt-in constant `CLOCKWORK_COMPANION_TRUST_PROXY`** lets `clientIp()` read the real client IP from `CF-Connecting-IP` (preferred) or `X-Forwarded-For` (leftmost entry) when defined in `wp-config.php`. Required on CDN/proxy-fronted sites — without it, the per-IP rate limiter degrades to one shared bucket for the entire internet, and the audit table records edge IPs instead of attacker IPs. Opt-in by design: trusting forwarded headers when no real proxy is in front lets a direct attacker spoof their bucket key. See README "Configuration constants".

### Notes for operators

- Both fixes are backwards compatible. Sites without the new constant behave exactly as 1.16.8 did.
- For Clockwork-managed care-plan sites: `clockwork:ensure-companion-trust-proxy` idempotently injects the constant into `wp-config.php` and runs nightly.
