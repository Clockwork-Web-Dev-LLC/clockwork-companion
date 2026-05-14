<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\Admin\FormsAjaxHandlers;
use ClockworkCompanion\Admin\Layout;
use ClockworkCompanion\ContactForm\DetectedFormsCache;
use ClockworkCompanion\ContactForm\SubscriptionsService;

/**
 * Tools → Clockwork → Forms admin page.
 *
 * Self-service surface for the local WP admin user. Three logical sections:
 *
 *   1. **Detected forms** — every form the active form plugin reports, with
 *      a "Monitor" toggle. Toggling on calls SubscriptionsService::subscribe()
 *      via AJAX; the agency's nightly sync command picks it up from
 *      /form-subscriptions and creates a contact_form_tests row.
 *
 *   2. **Subscribed forms** — the user's monitored forms with last-run
 *      result, mail outcome, error, and a "Test now" button that exercises
 *      the local Tester directly (no roundtrip to the agency).
 *
 *   3. **Recent results** — when Clockwork's scheduler runs a test on this
 *      site (or the user clicks Test now), the outcome lands in
 *      wp_options[self::OPTION] via self::recordResult(). The Subscribed
 *      section reads the latest result per form_id from there.
 *
 * Capability gate: same as the rest of the Clockwork menu (manage_options).
 * Everything client-driven uses admin-ajax with a nonce; the agency side
 * is the HMAC-protected REST endpoints.
 */
class FormsPage
{
    public const SLUG = 'clockwork-forms';

    public const OPTION = 'clockwork_companion_form_test_results';

    public static function render(): void
    {
        Layout::render('forms', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        Layout::pageHeader(
            'Forms',
            'Monitor your contact forms. Pick which forms to test below — Clockwork Web Dev will run them on a schedule and ping us if one breaks.'
        );

        // Lazy-refresh the detected-forms cache on first visit and after the
        // 24h TTL. The "Re-detect" button forces it for cases where someone
        // just installed a new form plugin and doesn't want to wait.
        $detected = DetectedFormsCache::readFresh();
        $subs = SubscriptionsService::all();
        $results = self::loadStore();

        self::renderCadenceBanner($detected, $results);
        self::renderDetectedSection($detected, $subs);
        self::renderSubscribedSection($subs, $results);
        self::renderInlineStyles();
        self::renderInlineScript();
    }

    /**
     * @return array{forms: array<string, array<string, mixed>>, updated_at: ?string}
     */
    public static function loadStore(): array
    {
        $raw = get_option(self::OPTION, null);
        if (! is_array($raw)) {
            return ['forms' => [], 'updated_at' => null];
        }
        $forms = isset($raw['forms']) && is_array($raw['forms']) ? $raw['forms'] : [];
        $updatedAt = isset($raw['updated_at']) ? (string) $raw['updated_at'] : null;
        return ['forms' => $forms, 'updated_at' => $updatedAt];
    }

    /**
     * Persist a single test result. Called by TestContactFormRoute (when
     * Clockwork runs a scheduled test) AND by the local Test-now AJAX
     * handler (when the local admin runs one).
     *
     * @param  array<string, mixed>  $result  Tester::run() output.
     */
    public static function recordResult(string $plugin, string $formId, array $result): void
    {
        if ($formId === '') {
            return;
        }
        $store = self::loadStore();
        $forms = $store['forms'];

        $ok = ! empty($result['ok']);
        $mailOutcome = isset($result['mail_outcome']) ? (string) $result['mail_outcome'] : null;
        $error = isset($result['error']) ? (string) $result['error'] : null;

        $forms[$formId] = [
            'form_id' => $formId,
            'plugin' => $plugin,
            'last_result' => $ok ? 'pass' : 'fail',
            'last_run_at' => gmdate('c'),
            'last_error' => $ok ? null : $error,
            'last_mail_outcome' => $mailOutcome,
        ];

        update_option(self::OPTION, [
            'forms' => $forms,
            'updated_at' => gmdate('c'),
        ], false);
    }

    /**
     * @param  array{plugin: ?string, forms: list<array{id:string,title:string,page_url:?string}>, fetched_at: ?string}  $detected
     * @param  array{forms: array<string, array<string, mixed>>, updated_at: ?string}  $results
     */
    private static function renderCadenceBanner(array $detected, array $results): void
    {
        $detectedAt = self::formatTimestamp($detected['fetched_at']);
        $plugin = $detected['plugin'];
        ?>
        <div class="clockwork-notice clockwork-notice--muted" style="margin-bottom: 16px;">
            <strong>Form plugin detected:</strong>
            <?php echo $plugin ? esc_html($plugin) : '<em>none active</em>'; ?>
            · <strong>Last scan:</strong>
            <?php echo $detectedAt ? esc_html($detectedAt) : 'never'; ?>.
            <button type="button" class="button button-small" id="clockwork-redetect">Re-detect now</button>
        </div>
        <?php
    }

    /**
     * @param  array{plugin: ?string, forms: list<array{id:string,title:string,page_url:?string}>, fetched_at: ?string}  $detected
     * @param  array<string, array<string, mixed>>  $subs
     */
    private static function renderDetectedSection(array $detected, array $subs): void
    {
        $forms = $detected['forms'];
        $plugin = (string) ($detected['plugin'] ?? '');
        $atCap = SubscriptionsService::atCap();
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__head">
                <h2>Forms detected on this site</h2>
                <span class="clockwork-card__meta"><?php echo count($forms); ?> form<?php echo count($forms) === 1 ? '' : 's'; ?></span>
            </div>
            <div class="clockwork-card__body">
                <?php if ($forms === []) : ?>
                    <p class="clockwork-empty">No form plugins active, or no forms configured. Install Contact Form 7, WPForms, or Gravity Forms and create a form, then click <em>Re-detect now</em>.</p>
                <?php else : ?>
                    <p class="clockwork-help">Toggle a form on to have Clockwork Web Dev test it on a schedule (weekly). Up to <?php echo SubscriptionsService::MAX; ?> forms can be monitored at once.</p>
                    <table class="widefat striped clockwork-forms-table">
                        <thead>
                            <tr>
                                <th>Form</th>
                                <th>Plugin ID</th>
                                <th style="width: 130px;">Monitor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forms as $f) :
                                $isSubscribed = isset($subs[$f['id']]);
                                $disabled = ! $isSubscribed && $atCap;
                            ?>
                                <tr data-form-id="<?php echo esc_attr($f['id']); ?>"
                                    data-plugin="<?php echo esc_attr($plugin); ?>">
                                    <td>
                                        <strong><?php echo esc_html($f['title'] ?: '(untitled)'); ?></strong>
                                        <?php if (! empty($f['page_url'])) : ?>
                                            <div style="font-size: 11px; color: #6b7280;">
                                                <a href="<?php echo esc_url($f['page_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($f['page_url']); ?></a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><code>#<?php echo esc_html($f['id']); ?></code></td>
                                    <td>
                                        <label class="clockwork-switch <?php echo $disabled ? 'is-disabled' : ''; ?>">
                                            <input type="checkbox"
                                                   class="clockwork-subscribe-toggle"
                                                   <?php checked($isSubscribed); ?>
                                                   <?php disabled($disabled); ?> />
                                            <span class="clockwork-switch__track"></span>
                                        </label>
                                        <span class="clockwork-row-status" aria-live="polite"></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($atCap) : ?>
                        <p class="clockwork-help" style="margin-top: 12px;">
                            <strong>You're at the maximum of <?php echo SubscriptionsService::MAX; ?> monitored forms.</strong>
                            Unsubscribe one below to free up a slot, or contact Clockwork Web Dev to raise the limit.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<string, array<string, mixed>>  $subs
     * @param  array{forms: array<string, array<string, mixed>>, updated_at: ?string}  $results
     */
    private static function renderSubscribedSection(array $subs, array $results): void
    {
        if ($subs === []) {
            return;
        }
        $resultMap = $results['forms'];
        ?>
        <div class="clockwork-card" style="margin-top: 18px;">
            <div class="clockwork-card__head">
                <h2>Forms you're monitoring</h2>
                <span class="clockwork-card__meta"><?php echo count($subs); ?> / <?php echo SubscriptionsService::MAX; ?></span>
            </div>
            <div class="clockwork-card__body">
                <div class="clockwork-forms-grid">
                    <?php foreach ($subs as $formId => $sub) :
                        $result = $resultMap[$formId] ?? null;
                        $state = $result ? (string) ($result['last_result'] ?? '') : '';
                        $statusClass = $state === 'fail' ? 'is-fail' : ($state === 'pass' ? 'is-pass' : 'is-unknown');
                        $statusLabel = $state === 'fail' ? 'Failing' : ($state === 'pass' ? 'Passing' : 'Not tested yet');
                        $ranAt = $result ? self::formatTimestamp($result['last_run_at'] ?? null) : null;
                    ?>
                        <div class="clockwork-card clockwork-form-card <?php echo esc_attr($statusClass); ?>"
                             data-form-id="<?php echo esc_attr($formId); ?>"
                             data-plugin="<?php echo esc_attr((string) ($sub['plugin'] ?? '')); ?>">
                            <div class="clockwork-card__body">
                                <div class="clockwork-form-card__head">
                                    <span class="clockwork-form-card__pill"><?php echo esc_html($statusLabel); ?></span>
                                    <div>
                                        <div class="clockwork-form-card__title">Form #<?php echo esc_html((string) $formId); ?></div>
                                        <div class="clockwork-form-card__sub">
                                            Frequency: <?php echo esc_html((string) ($sub['frequency'] ?? 'weekly')); ?>
                                            · Plugin: <?php echo esc_html((string) ($sub['plugin'] ?? '—')); ?>
                                        </div>
                                    </div>
                                </div>
                                <dl class="clockwork-form-card__kv">
                                    <dt>Last run</dt>
                                    <dd><?php echo $ranAt ? esc_html($ranAt) : '—'; ?></dd>
                                    <dt>Mail outcome</dt>
                                    <dd><?php echo esc_html($result['last_mail_outcome'] ?? '—'); ?></dd>
                                    <?php if ($state === 'fail' && ! empty($result['last_error'])) : ?>
                                        <dt>Error</dt>
                                        <dd class="clockwork-form-card__err"><?php echo esc_html((string) $result['last_error']); ?></dd>
                                    <?php endif; ?>
                                </dl>
                                <div class="clockwork-form-card__actions">
                                    <button type="button" class="button clockwork-test-now-btn">Test now</button>
                                    <button type="button" class="button-link clockwork-unsubscribe-btn">Stop monitoring</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private static function formatTimestamp(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        $ts = strtotime($iso);
        if ($ts === false) {
            return null;
        }
        return wp_date('M j, Y g:i a', $ts);
    }

    private static function renderInlineStyles(): void
    {
        ?>
        <style>
            .clockwork-card__head {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 16px;
                border-bottom: 1px solid #e5e7eb;
            }
            .clockwork-card__head h2 { margin: 0; font-size: 14px; font-weight: 600; }
            .clockwork-card__meta { font-size: 12px; color: #6b7280; }
            .clockwork-card__body { padding: 16px; }
            .clockwork-help { font-size: 13px; color: #4b5563; margin: 0 0 12px; }
            .clockwork-empty { font-size: 13px; color: #6b7280; margin: 0; }
            .clockwork-forms-table { border-collapse: collapse; width: 100%; }
            .clockwork-forms-table th, .clockwork-forms-table td { padding: 10px 12px; vertical-align: middle; }
            .clockwork-row-status { display: inline-block; margin-left: 8px; font-size: 11px; color: #6b7280; }
            .clockwork-row-status.is-saving { color: #4b5563; }
            .clockwork-row-status.is-ok { color: #15803d; }
            .clockwork-row-status.is-err { color: #b91c1c; }

            .clockwork-switch {
                position: relative;
                display: inline-block;
                width: 36px;
                height: 20px;
                vertical-align: middle;
            }
            .clockwork-switch input { opacity: 0; width: 0; height: 0; }
            .clockwork-switch__track {
                position: absolute; inset: 0;
                background: #d1d5db;
                border-radius: 9999px;
                transition: background 120ms ease;
                cursor: pointer;
            }
            .clockwork-switch__track::before {
                content: '';
                position: absolute;
                top: 2px; left: 2px;
                width: 16px; height: 16px;
                background: #fff;
                border-radius: 50%;
                box-shadow: 0 1px 2px rgba(0,0,0,0.2);
                transition: transform 120ms ease;
            }
            .clockwork-switch input:checked + .clockwork-switch__track { background: #16a34a; }
            .clockwork-switch input:checked + .clockwork-switch__track::before { transform: translateX(16px); }
            .clockwork-switch.is-disabled .clockwork-switch__track { opacity: 0.5; cursor: not-allowed; }

            .clockwork-forms-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 16px;
            }
            .clockwork-form-card.is-pass { border-left: 4px solid #16a34a; }
            .clockwork-form-card.is-fail { border-left: 4px solid #dc2626; }
            .clockwork-form-card.is-unknown { border-left: 4px solid #9ca3af; }
            .clockwork-form-card__head {
                display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px;
            }
            .clockwork-form-card__pill {
                display: inline-block; font-size: 11px; font-weight: 600;
                padding: 2px 8px; border-radius: 9999px;
                background: #f3f4f6; color: #374151;
                text-transform: uppercase; letter-spacing: 0.04em;
                flex-shrink: 0;
            }
            .clockwork-form-card.is-pass .clockwork-form-card__pill { background: #dcfce7; color: #15803d; }
            .clockwork-form-card.is-fail .clockwork-form-card__pill { background: #fee2e2; color: #b91c1c; }
            .clockwork-form-card__title { font-size: 14px; font-weight: 600; color: #111827; }
            .clockwork-form-card__sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
            .clockwork-form-card__kv {
                display: grid; grid-template-columns: max-content 1fr;
                gap: 4px 12px; margin: 0; font-size: 12px;
            }
            .clockwork-form-card__kv dt { color: #6b7280; font-weight: 500; }
            .clockwork-form-card__kv dd { margin: 0; color: #111827; }
            .clockwork-form-card__err {
                color: #b91c1c; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-word;
            }
            .clockwork-form-card__actions {
                margin-top: 12px;
                display: flex; gap: 12px; align-items: center;
            }
        </style>
        <?php
    }

    private static function renderInlineScript(): void
    {
        $nonce = wp_create_nonce(FormsAjaxHandlers::NONCE_ACTION);
        $ajaxUrl = admin_url('admin-ajax.php');
        ?>
        <script>
        (function () {
            const ajax = <?php echo wp_json_encode($ajaxUrl); ?>;
            const nonce = <?php echo wp_json_encode($nonce); ?>;
            const nonceName = <?php echo wp_json_encode(FormsAjaxHandlers::NONCE_NAME); ?>;

            function postForm(action, params) {
                const body = new URLSearchParams();
                body.set('action', action);
                body.set(nonceName, nonce);
                for (const [k, v] of Object.entries(params || {})) {
                    body.set(k, v);
                }
                return fetch(ajax, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body,
                }).then(r => r.json().then(json => ({status: r.status, json})));
            }

            function setRowStatus(row, message, cls) {
                const el = row.querySelector('.clockwork-row-status');
                if (! el) return;
                el.textContent = message || '';
                el.className = 'clockwork-row-status ' + (cls ? 'is-' + cls : '');
            }

            // Subscribe / unsubscribe toggles in the Detected section.
            document.querySelectorAll('.clockwork-subscribe-toggle').forEach(input => {
                input.addEventListener('change', async (ev) => {
                    const row = ev.target.closest('tr');
                    const formId = row.getAttribute('data-form-id');
                    const plugin = row.getAttribute('data-plugin');
                    const wantOn = ev.target.checked;
                    setRowStatus(row, wantOn ? 'subscribing…' : 'unsubscribing…', 'saving');
                    const action = wantOn ? 'clockwork_companion_subscribe_form' : 'clockwork_companion_unsubscribe_form';
                    const {status, json} = await postForm(action, {form_id: formId, plugin});
                    if (status === 200 && json && json.success) {
                        setRowStatus(row, wantOn ? 'Monitoring — Clockwork Web Dev will pick this up tonight.' : 'Stopped monitoring.', 'ok');
                        // Reload the page so the Subscribed section + cap state refresh
                        // cleanly. Keeps the DOM dead-simple — no per-row reconciliation.
                        setTimeout(() => window.location.reload(), 800);
                    } else {
                        ev.target.checked = ! wantOn;
                        setRowStatus(row, (json && json.data && json.data.message) || 'Failed.', 'err');
                    }
                });
            });

            // Test now button in the Subscribed section.
            document.querySelectorAll('.clockwork-test-now-btn').forEach(btn => {
                btn.addEventListener('click', async (ev) => {
                    const card = ev.target.closest('.clockwork-form-card');
                    const formId = card.getAttribute('data-form-id');
                    const plugin = card.getAttribute('data-plugin');
                    btn.disabled = true;
                    const original = btn.textContent;
                    btn.textContent = 'Testing…';
                    const {status, json} = await postForm('clockwork_companion_test_form_now', {form_id: formId, plugin});
                    btn.disabled = false;
                    btn.textContent = original;
                    if (status === 200 && json && json.success && json.data && json.data.ok) {
                        alert('Form #' + formId + ' passed. Reloading.');
                        window.location.reload();
                    } else {
                        const err = (json && json.data && json.data.error) || (json && json.data && json.data.message) || 'Test failed.';
                        alert('Form #' + formId + ' failed: ' + err);
                        window.location.reload();
                    }
                });
            });

            // Stop monitoring (unsubscribe from inside the Subscribed card).
            document.querySelectorAll('.clockwork-unsubscribe-btn').forEach(btn => {
                btn.addEventListener('click', async (ev) => {
                    const card = ev.target.closest('.clockwork-form-card');
                    const formId = card.getAttribute('data-form-id');
                    if (! confirm('Stop monitoring form #' + formId + '?')) return;
                    btn.disabled = true;
                    const {status, json} = await postForm('clockwork_companion_unsubscribe_form', {form_id: formId});
                    if (status === 200 && json && json.success) {
                        window.location.reload();
                    } else {
                        btn.disabled = false;
                        alert('Could not unsubscribe.');
                    }
                });
            });

            // Re-detect button in the cadence banner.
            const reBtn = document.getElementById('clockwork-redetect');
            if (reBtn) {
                reBtn.addEventListener('click', async () => {
                    reBtn.disabled = true;
                    const original = reBtn.textContent;
                    reBtn.textContent = 'Scanning…';
                    const {status, json} = await postForm('clockwork_companion_redetect_forms', {});
                    if (status === 200 && json && json.success) {
                        window.location.reload();
                    } else {
                        reBtn.disabled = false;
                        reBtn.textContent = original;
                        alert('Re-detect failed.');
                    }
                });
            }
        })();
        </script>
        <?php
    }
}
