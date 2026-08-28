# Clockwork Companion — Changelog

Versions track `CLOCKWORK_COMPANION_VERSION` in `clockwork-companion.php`. Earlier releases (1.0.0 → 1.16.8) predate this file; treat the git log as authoritative for those.

## 1.30.2 — 2026-08-28

### Fix

- **`Runner::run()` reported a plugin update as successful even when re-activation failed, letting broken updates through as "complete."** Investigated after reports of random plugin disables across the fleet (WFLS/Contact Form 7 left deactivated on one site, The Events Calendar left with missing files on another). Root cause: `Plugin_Upgrader::upgrade()` succeeding does not mean the plugin is usable afterward — Runner re-activates it separately, and if that `activate_plugin()` call fails (corrupted/incomplete file swap, a fatal on activation, a genuinely missing plugin file), the failure was only appended to `messages`; `ok` stayed `true`. Since `AbstractRunUpdate` in the monitoring app trusts `ok` to decide `STATUS_COMPLETE` vs `STATUS_FAILED`, these updates sailed through nightly runs as successful — no Mattermost alert, no retry, no visibility — while `post-update-verify`'s `verifyPlugins()` explicitly skips slugs no longer in `get_plugins()`, so a fully-missing plugin file could never self-heal either. `Runner::run()` now returns `ok: false` (with a descriptive `error`) whenever a plugin that was active before the upgrade fails to re-activate after it, while still reporting `upgrade_completed: true` so the orchestrator's post-update-verify pass still runs and can repair collateral damage to *other* plugins. Companion-side half of a two-part fix; see the clockwork-monitoring-app changelog for the orchestrator-side defense-in-depth for sites still running older Companion versions.

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
