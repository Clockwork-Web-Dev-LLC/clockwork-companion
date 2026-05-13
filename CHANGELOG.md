# Clockwork Companion — Changelog

Versions track `CLOCKWORK_COMPANION_VERSION` in `clockwork-companion.php`. Earlier releases (1.0.0 → 1.16.8) predate this file; treat the git log as authoritative for those.

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
