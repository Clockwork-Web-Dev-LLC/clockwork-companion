# Clockwork Companion — Changelog

Versions track `CLOCKWORK_COMPANION_VERSION` in `clockwork-companion.php`. Earlier releases (1.0.0 → 1.16.8) predate this file; treat the git log as authoritative for those.

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
