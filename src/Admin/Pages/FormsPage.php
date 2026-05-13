<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\Admin\Layout;

/**
 * Tools → Clockwork → Forms admin page.
 *
 * Surfaces the per-form contact-form-test results that Clockwork's
 * scheduled runs (or "Test now" button) leave behind. Each time the
 * `/test-contact-form` REST endpoint runs, TestContactFormRoute writes
 * the outcome into wp_options[self::OPTION] keyed by form_id; this page
 * renders that map.
 *
 * **Data source.** Local WP option only — written by Clockwork's
 * inbound test calls. No outbound network from this page.
 *
 * **Empty-state honesty.** If the agency hasn't configured any form-
 * tests yet (or the first scheduled run hasn't fired), we surface that
 * explicitly rather than implying anything is broken.
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
            'Your agency tests this site\'s contact forms on a schedule — fills the form, sends it, and confirms the message arrives. Results below.'
        );

        $store = self::loadStore();
        $forms = self::sortedForms($store);

        if ($forms === []) {
            self::renderEmptyState();
            return;
        }

        self::renderCadenceBanner($store);
        self::renderFormCards($forms);
        self::renderInlineStyles();
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
     * Persist a single test result. Called by TestContactFormRoute after
     * each /test-contact-form invocation.
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
     * @param  array{forms: array<string, array<string, mixed>>, updated_at: ?string}  $store
     * @return list<array<string, mixed>>
     */
    private static function sortedForms(array $store): array
    {
        $forms = array_values($store['forms']);
        // Failing first, then most-recently-tested.
        usort($forms, function ($a, $b) {
            $aFail = ($a['last_result'] ?? '') === 'fail' ? 0 : 1;
            $bFail = ($b['last_result'] ?? '') === 'fail' ? 0 : 1;
            if ($aFail !== $bFail) {
                return $aFail <=> $bFail;
            }
            $aTs = strtotime((string) ($a['last_run_at'] ?? '')) ?: 0;
            $bTs = strtotime((string) ($b['last_run_at'] ?? '')) ?: 0;
            return $bTs <=> $aTs;
        });
        return $forms;
    }

    private static function renderEmptyState(): void
    {
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__body">
                <div class="clockwork-notice">
                    <strong>No form tests yet.</strong>
                    Your agency hasn't run a contact-form test on this site yet, or the first scheduled run hasn't fired. Tests are configured per-form from the agency's dashboard; results land here automatically once they start running.
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array{forms: array<string, array<string, mixed>>, updated_at: ?string}  $store
     */
    private static function renderCadenceBanner(array $store): void
    {
        $when = self::formatTimestamp($store['updated_at']);
        ?>
        <div class="clockwork-notice clockwork-notice--muted" style="margin-bottom: 16px;">
            <strong>Last updated:</strong>
            <?php echo $when ? esc_html($when) : 'unknown'; ?>.
            Each form on this page is tested on its own schedule (weekly by default) — failure pings your agency on Mattermost.
        </div>
        <?php
    }

    /**
     * @param  list<array<string, mixed>>  $forms
     */
    private static function renderFormCards(array $forms): void
    {
        ?>
        <div class="clockwork-forms-grid">
            <?php foreach ($forms as $f) :
                $result = (string) ($f['last_result'] ?? 'pass');
                $isFail = $result === 'fail';
                $isPass = $result === 'pass';
                $statusLabel = $isFail ? 'Failing' : ($isPass ? 'Passing' : 'Unknown');
                $statusClass = $isFail ? 'is-fail' : ($isPass ? 'is-pass' : 'is-unknown');
                $ranAt = self::formatTimestamp($f['last_run_at'] ?? null);
            ?>
                <div class="clockwork-card clockwork-form-card <?php echo esc_attr($statusClass); ?>">
                    <div class="clockwork-card__body">
                        <div class="clockwork-form-card__head">
                            <span class="clockwork-form-card__pill"><?php echo esc_html($statusLabel); ?></span>
                            <div>
                                <div class="clockwork-form-card__title">Form #<?php echo esc_html((string) ($f['form_id'] ?? '')); ?></div>
                                <div class="clockwork-form-card__sub">
                                    Plugin: <?php echo esc_html((string) ($f['plugin'] ?? '—')); ?>
                                </div>
                            </div>
                        </div>
                        <dl class="clockwork-form-card__kv">
                            <dt>Last run</dt>
                            <dd><?php echo $ranAt ? esc_html($ranAt) : '—'; ?></dd>
                            <dt>Mail outcome</dt>
                            <dd><?php echo esc_html((string) ($f['last_mail_outcome'] ?? '—')); ?></dd>
                            <?php if ($isFail && ! empty($f['last_error'])) : ?>
                                <dt>Error</dt>
                                <dd class="clockwork-form-card__err"><?php echo esc_html((string) $f['last_error']); ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>
            <?php endforeach; ?>
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
            .clockwork-forms-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 16px;
            }
            .clockwork-form-card.is-pass { border-left: 4px solid #16a34a; }
            .clockwork-form-card.is-fail { border-left: 4px solid #dc2626; }
            .clockwork-form-card.is-unknown { border-left: 4px solid #9ca3af; }
            .clockwork-form-card__head {
                display: flex;
                align-items: flex-start;
                gap: 10px;
                margin-bottom: 10px;
            }
            .clockwork-form-card__pill {
                display: inline-block;
                font-size: 11px;
                font-weight: 600;
                padding: 2px 8px;
                border-radius: 9999px;
                background: #f3f4f6;
                color: #374151;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                flex-shrink: 0;
            }
            .clockwork-form-card.is-pass .clockwork-form-card__pill { background: #dcfce7; color: #15803d; }
            .clockwork-form-card.is-fail .clockwork-form-card__pill { background: #fee2e2; color: #b91c1c; }
            .clockwork-form-card__title {
                font-size: 14px;
                font-weight: 600;
                color: #111827;
            }
            .clockwork-form-card__sub {
                font-size: 12px;
                color: #6b7280;
                margin-top: 2px;
            }
            .clockwork-form-card__kv {
                display: grid;
                grid-template-columns: max-content 1fr;
                gap: 4px 12px;
                margin: 0;
                font-size: 12px;
            }
            .clockwork-form-card__kv dt {
                color: #6b7280;
                font-weight: 500;
            }
            .clockwork-form-card__kv dd {
                margin: 0;
                color: #111827;
            }
            .clockwork-form-card__err {
                color: #b91c1c;
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                word-break: break-word;
            }
        </style>
        <?php
    }
}
