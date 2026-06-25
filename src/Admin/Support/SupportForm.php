<?php

namespace ClockworkCompanion\Admin\Support;

/**
 * Support ticket form — proxies submissions to the Clockwork GravityForms
 * form (form ID 3, site defined by CLOCKWORK_SUPPORT_SITE_URL constant).
 *
 * Exposes three integration points:
 *  1. Admin-footer modal: output on every wp-admin page so any
 *     .cwk-support-trigger element can open it, regardless of page.
 *  2. Dashboard widget: compact "Open Support Form" button on the
 *     wp-admin home screen, visible to all users.
 *  3. AJAX handler (wp_ajax_clockwork_support_submit): server-side proxy
 *     that relays sanitised input to the GF REST endpoint so credentials
 *     never appear in the browser.
 *
 * GF field map (form 3, exported 2026-06-23):
 *   input_1_3  = Name / First
 *   input_1_6  = Name / Last
 *   input_3    = Email
 *   input_2    = Website Link (URL)
 *   input_8    = Short Description (subject line)
 *   input_4    = Detailed Description
 *   input_7    = "Have you worked with Clockwork before?" (radio) — hidden, pre-set
 *   input_6_1  = Billing approval checkbox
 *   input_9_1  = Newsletter subscribe checkbox
 *
 * Auth: uses GF REST API v2. Define these constants with the keys from
 * clockworkwp.com → Forms → Settings → REST API → Authentication (API version 2):
 *   CLOCKWORK_SUPPORT_GF_KEY    — Consumer Key  (ck_…)
 *   CLOCKWORK_SUPPORT_GF_SECRET — Consumer Secret (cs_…)
 */
class SupportForm
{
    private const GF_FORM_ID = 3;

    private const BILLING_NOTICE = 'I understand that my request may be subject to a minimum charge of one billable hour at an hourly rate of $200';

    private const SUBSCRIBE_TEXT = "Yes! I'd like to receive occasional email updates from Clockwork.";

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_footer', [self::class, 'renderModal']);
        add_action('wp_dashboard_setup', [self::class, 'registerWidget']);
        add_action('wp_ajax_clockwork_support_submit', [self::class, 'handleSubmit']);
    }

    public function enqueueAssets(): void
    {
        wp_enqueue_script(
            'clockwork-support-form',
            plugins_url('assets/support-form.js', CLOCKWORK_COMPANION_DIR . '/clockwork-companion.php'),
            [],
            CLOCKWORK_COMPANION_VERSION,
            true
        );

        $user = wp_get_current_user();
        wp_localize_script('clockwork-support-form', 'cwkSupport', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('cwk_support_submit'),
            'siteUrl' => get_site_url(),
            'user'    => [
                'firstName' => (string) ($user->first_name ?? ''),
                'lastName'  => (string) ($user->last_name ?? ''),
                'email'     => (string) ($user->user_email ?? ''),
            ],
        ]);
    }

    public static function registerWidget(): void
    {
        wp_add_dashboard_widget(
            'clockwork_support_widget',
            'Clockwork Support',
            [self::class, 'renderWidget']
        );
    }

    public static function renderWidget(): void
    {
        ?>
        <p style="margin: 0 0 12px; color: #374151; font-size: 13px; line-height: 1.5;">
            Need help with your website? Send us a message and we'll get back to you shortly.
        </p>
        <button type="button" class="button button-primary cwk-support-trigger">
            Open Support Form
        </button>
        <?php
    }

    public static function renderModal(): void
    {
        ?>
        <div id="cwk-support-modal" role="dialog" aria-modal="true" aria-labelledby="cwk-modal-title" style="display:none;">
            <div class="cwk-modal-overlay"></div>
            <div class="cwk-modal-box">
                <div class="cwk-modal-head">
                    <span id="cwk-modal-title" class="cwk-modal-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
                        Clockwork Support
                    </span>
                    <button type="button" class="cwk-modal-close" aria-label="Close support form">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="cwk-modal-body">
                    <p class="cwk-modal-intro">Send us a message and we'll get back to you shortly.</p>
                    <form id="cwk-support-form" novalidate>
                        <input type="hidden" name="action" value="clockwork_support_submit">
                        <input type="hidden" name="nonce" id="cwk-nonce-field">

                        <div class="cwk-form-row cwk-form-row--half">
                            <div class="cwk-form-group">
                                <label for="cwk-first-name">First name <span class="cwk-required" aria-hidden="true">*</span></label>
                                <input type="text" id="cwk-first-name" name="first_name" required autocomplete="given-name">
                            </div>
                            <div class="cwk-form-group">
                                <label for="cwk-last-name">Last name <span class="cwk-required" aria-hidden="true">*</span></label>
                                <input type="text" id="cwk-last-name" name="last_name" required autocomplete="family-name">
                            </div>
                        </div>

                        <div class="cwk-form-row cwk-form-row--half">
                            <div class="cwk-form-group">
                                <label for="cwk-email">Email <span class="cwk-required" aria-hidden="true">*</span></label>
                                <input type="email" id="cwk-email" name="email" required autocomplete="email">
                            </div>
                            <div class="cwk-form-group">
                                <label for="cwk-site-url">Website URL <span class="cwk-required" aria-hidden="true">*</span></label>
                                <input type="url" id="cwk-site-url" name="website_url" required>
                            </div>
                        </div>

                        <div class="cwk-form-group">
                            <label for="cwk-subject">Subject <span class="cwk-required" aria-hidden="true">*</span></label>
                            <input type="text" id="cwk-subject" name="short_desc" required placeholder="Brief description of your request">
                        </div>

                        <div class="cwk-form-group">
                            <label for="cwk-message">Message <span class="cwk-required" aria-hidden="true">*</span></label>
                            <textarea id="cwk-message" name="long_desc" required rows="5" placeholder="Please describe your issue in detail…"></textarea>
                        </div>

                        <div class="cwk-form-group cwk-form-checkbox">
                            <label class="cwk-checkbox-label">
                                <input type="checkbox" name="approved" value="1" required>
                                <span><?php echo esc_html(self::BILLING_NOTICE); ?> <span class="cwk-required" aria-hidden="true">*</span></span>
                            </label>
                        </div>

                        <div class="cwk-form-group cwk-form-checkbox">
                            <label class="cwk-checkbox-label">
                                <input type="checkbox" name="subscribe" value="1">
                                <span>Yes! I'd like to receive occasional email updates from Clockwork.</span>
                            </label>
                        </div>

                        <div class="cwk-form-actions">
                            <button type="submit" class="cwk-submit-btn">
                                <span class="cwk-submit-label">Send My Message</span>
                                <span class="cwk-submit-spinner" aria-hidden="true" style="display:none;">Sending…</span>
                            </button>
                        </div>

                        <div id="cwk-form-error" class="cwk-form-message cwk-form-message--error" role="alert" style="display:none;"></div>
                    </form>

                    <div id="cwk-form-success" class="cwk-form-success" style="display:none;">
                        <div class="cwk-form-success__icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
                        </div>
                        <strong>Message sent!</strong>
                        <p>We'll get back to you shortly.</p>
                    </div>
                </div>
            </div>
        </div>
        <style>
        #cwk-support-modal { position:fixed; inset:0; z-index:99999; }
        .cwk-modal-overlay { position:absolute; inset:0; background:rgba(0,0,0,.55); cursor:pointer; }
        .cwk-modal-box {
            position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
            width:min(560px,calc(100vw - 48px)); max-height:calc(100vh - 80px);
            background:#fff; border-radius:8px; overflow:hidden;
            display:flex; flex-direction:column;
            box-shadow:0 20px 60px rgba(0,0,0,.3);
        }
        .cwk-modal-head {
            display:flex; align-items:center; justify-content:space-between;
            padding:14px 20px; background:#1e1b4b; color:#fff; flex-shrink:0;
        }
        .cwk-modal-title { display:flex; align-items:center; gap:8px; font-size:14px; font-weight:600; }
        .cwk-modal-close {
            background:none; border:none; cursor:pointer; color:#a5b4fc;
            padding:4px; display:flex; align-items:center; border-radius:4px;
        }
        .cwk-modal-close:hover { color:#fff; background:rgba(255,255,255,.1); }
        .cwk-modal-body { padding:22px; overflow-y:auto; flex:1; }
        .cwk-modal-intro { margin:0 0 18px; color:#6b7280; font-size:13px; }
        .cwk-form-row { display:flex; gap:12px; }
        .cwk-form-row--half > * { flex:1; min-width:0; }
        .cwk-form-group { margin-bottom:12px; }
        .cwk-form-group > label {
            display:block; font-size:11px; font-weight:700; color:#374151;
            margin-bottom:4px; text-transform:uppercase; letter-spacing:.04em;
        }
        .cwk-required { color:#ef4444; margin-left:1px; }
        .cwk-form-group input[type="text"],
        .cwk-form-group input[type="email"],
        .cwk-form-group input[type="url"],
        .cwk-form-group textarea {
            width:100%; box-sizing:border-box; padding:7px 10px;
            border:1px solid #d1d5db; border-radius:5px; font-size:13px;
            line-height:1.5; color:#111827; background:#fff; font-family:inherit;
            transition:border-color .15s, box-shadow .15s;
        }
        .cwk-form-group input:focus,
        .cwk-form-group textarea:focus {
            outline:none; border-color:#4f46e5;
            box-shadow:0 0 0 3px rgba(79,70,229,.12);
        }
        .cwk-form-group textarea { resize:vertical; }
        .cwk-form-checkbox .cwk-checkbox-label {
            display:flex; gap:8px; align-items:flex-start; cursor:pointer;
            font-size:12px; color:#374151; font-weight:normal;
            text-transform:none; letter-spacing:normal;
        }
        .cwk-form-checkbox input[type="checkbox"] {
            flex-shrink:0; margin-top:2px; accent-color:#4f46e5; width:14px; height:14px;
            appearance:checkbox; -webkit-appearance:checkbox;
        }
        .cwk-form-actions { margin-top:18px; }
        .cwk-submit-btn {
            display:flex; align-items:center; justify-content:center; gap:6px;
            width:100%; background:#4f46e5; color:#fff; border:none; border-radius:6px;
            padding:10px 24px; font-size:13px; font-weight:600; cursor:pointer;
            transition:background .15s;
        }
        .cwk-submit-btn:hover:not(:disabled) { background:#4338ca; }
        .cwk-submit-btn:disabled { opacity:.65; cursor:not-allowed; }
        .cwk-form-message { margin-top:12px; padding:10px 14px; border-radius:5px; font-size:13px; }
        .cwk-form-message--error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .cwk-form-message--error a { color:inherit; text-decoration:underline; }
        .cwk-form-success { text-align:center; padding:40px 20px; }
        .cwk-form-success__icon {
            display:inline-flex; align-items:center; justify-content:center;
            width:56px; height:56px; background:#f0fdf4; border-radius:50%;
            color:#16a34a; margin:0 auto 12px;
        }
        .cwk-form-success strong { display:block; font-size:16px; margin-bottom:6px; color:#111827; }
        .cwk-form-success p { color:#6b7280; margin:0; font-size:13px; }
        /* Trigger helpers */
        .cwk-link-btn {
            background:none; border:none; padding:0; color:inherit;
            text-decoration:underline; cursor:pointer;
            font-size:inherit; font-family:inherit; line-height:inherit;
        }
        .cwk-header-support-btn {
            background:transparent; border:1px solid rgba(255,255,255,.3);
            color:#fff; border-radius:5px; padding:4px 12px;
            font-size:12px; cursor:pointer; line-height:1.5;
            transition:background .15s, border-color .15s;
        }
        .cwk-header-support-btn:hover {
            background:rgba(255,255,255,.1); border-color:rgba(255,255,255,.5);
        }
        </style>
        <?php
    }

    public static function handleSubmit(): void
    {
        check_ajax_referer('cwk_support_submit', 'nonce');

        $firstName = sanitize_text_field((string) ($_POST['first_name'] ?? ''));
        $lastName  = sanitize_text_field((string) ($_POST['last_name'] ?? ''));
        $email     = sanitize_email((string) ($_POST['email'] ?? ''));
        $siteUrl   = esc_url_raw((string) ($_POST['website_url'] ?? get_site_url()));
        $shortDesc = sanitize_text_field((string) ($_POST['short_desc'] ?? ''));
        $longDesc  = sanitize_textarea_field((string) ($_POST['long_desc'] ?? ''));
        $approved  = ! empty($_POST['approved']);
        $subscribe = ! empty($_POST['subscribe']);

        if (! $firstName || ! $lastName || ! $email || ! $shortDesc || ! $longDesc) {
            wp_send_json_error(['message' => 'Please fill in all required fields.'], 422);
        }
        if (! is_email($email)) {
            wp_send_json_error(['message' => 'Please enter a valid email address.'], 422);
        }
        if (! $approved) {
            wp_send_json_error(['message' => 'Please accept the billing terms to continue.'], 422);
        }

        $body = [
            'input_1_3'    => $firstName,
            'input_1_6'    => $lastName,
            'input_3'      => $email,
            'input_2'      => $siteUrl,
            'input_8'      => $shortDesc,
            'input_4'      => $longDesc,
            'input_7'      => 'I am an existing client',
            'input_6_1'    => self::BILLING_NOTICE,
            'source_page'  => 1,
            'target_page'  => 0,
            'field_values' => '',
        ];
        if ($subscribe) {
            $body['input_9_1'] = self::SUBSCRIBE_TEXT;
        }

        $baseUrl      = defined('CLOCKWORK_SUPPORT_SITE_URL') ? (string) CLOCKWORK_SUPPORT_SITE_URL : 'https://www.clockworkwp.com';
        $endpoint     = rtrim($baseUrl, '/') . '/wp-json/gf/v2/forms/' . self::GF_FORM_ID . '/submissions';
        $supportUrl   = esc_url(rtrim($baseUrl, '/') . '/support');
        $fallbackLink = ' <a href="' . $supportUrl . '" target="_blank" rel="noopener">Open our support page →</a>';

        $headers = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if (defined('CLOCKWORK_SUPPORT_GF_KEY') && defined('CLOCKWORK_SUPPORT_GF_SECRET')) {
            $headers['Authorization'] = 'Basic ' . base64_encode(
                CLOCKWORK_SUPPORT_GF_KEY . ':' . CLOCKWORK_SUPPORT_GF_SECRET
            );
        }

        $response = wp_remote_post($endpoint, [
            'body'    => wp_json_encode($body),
            'headers' => $headers,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Could not reach the support system. Please try again or' . $fallbackLink], 503);
        }

        $code         = wp_remote_retrieve_response_code($response);
        $responseBody = json_decode(wp_remote_retrieve_body($response), true);

        if ((int) $code === 200 && ! empty($responseBody['is_valid'])) {
            wp_send_json_success(['message' => "Thanks — we'll get back to you shortly."]);
        }

        if (isset($responseBody['is_valid']) && $responseBody['is_valid'] === false) {
            wp_send_json_error(['message' => 'The support form returned a validation error. Please try again.'], 422);
        }

        if ((int) $code === 401 || (int) $code === 403) {
            wp_send_json_error(['message' => 'The support system requires authentication. Please contact Clockwork to configure API credentials.'], 500);
        }

        wp_send_json_error(['message' => 'Something went wrong (HTTP ' . (int) $code . '). Please try again or' . $fallbackLink], 500);
    }
}
