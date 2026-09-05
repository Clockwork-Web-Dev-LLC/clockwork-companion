<?php

namespace ClockworkCompanion\Admin\Pages;

use ClockworkCompanion\Admin\Layout;
use ClockworkCompanion\WhiteLabel\WhiteLabel;

/**
 * White Label & Agency Branding Customizer Page.
 *
 * Allows agencies and companies to customize plugin metadata, logo, colors,
 * navigation labels, and support buttons with an interactive live preview.
 */
class WhiteLabelPage
{
    public const SLUG = 'clockwork-branding';

    public static function render(): void
    {
        Layout::render('branding', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        $settings = WhiteLabel::getSettings();
        $donationState = WhiteLabel::getDonationState();
        $isPromptVisible = WhiteLabel::isDonationPromptVisible();
        $nonce = wp_create_nonce('clockwork_whitelabel_save');
        $donatedNonce = wp_create_nonce('clockwork_whitelabel_donated');
        $dismissNonce = wp_create_nonce('clockwork_whitelabel_dismiss');

        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
        $justDonated = isset($_GET['donated']) && $_GET['donated'] === '1';
        $justDismissed = isset($_GET['dismissed']) && $_GET['dismissed'] === '1';
        ?>
        <?php Layout::pageHeader('White Label & Agency Branding', 'Customize the plugin name, author credits, dashboard colors, and logo to match your agency.'); ?>

        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible" style="margin:16px 0;padding:12px 16px;border-left:4px solid #10b981;border-radius:6px;background:#f0fdf4;">
                <p style="margin:0;font-weight:600;color:#065f46;">✓ Branding settings saved successfully! Changes are now active across wp-admin.</p>
            </div>
        <?php endif; ?>

        <?php if ($justDonated) : ?>
            <div class="notice notice-success is-dismissible" style="margin:16px 0;padding:12px 16px;border-left:4px solid #10b981;border-radius:6px;background:#f0fdf4;">
                <p style="margin:0;font-weight:600;color:#065f46;">❤️ Thank you so much for supporting Clockwork! The donation reminder is snoozed for 1 full year.</p>
            </div>
        <?php elseif ($justDismissed) : ?>
            <div class="notice notice-info is-dismissible" style="margin:16px 0;padding:12px 16px;border-left:4px solid #6b7280;border-radius:6px;background:#f9fafb;">
                <p style="margin:0;color:#374151;">Donation reminder snoozed for 90 days.</p>
            </div>
        <?php endif; ?>

        <?php if ($isPromptVisible) : ?>
            <!-- Annual Support & Donation Callout Banner -->
            <div id="cwk-donation-banner" class="clockwork-card" style="margin-bottom:24px;border:1px solid #fde68a;background:linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(245,158,11,0.08);">
                <div style="padding:18px 24px;display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;">
                    <div style="display:flex;gap:14px;align-items:flex-start;max-width:760px;">
                        <div style="width:42px;height:42px;border-radius:10px;background:#fbbf24;color:#78350f;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;box-shadow:0 2px 4px rgba(0,0,0,0.06);">
                            ☕
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:15px;color:#78350f;margin-bottom:3px;display:flex;align-items:center;gap:8px;">
                                <span>Support Clockwork's Open Fleet Development</span>
                                <span style="font-size:10px;text-transform:uppercase;padding:2px 7px;background:#fef3c7;border:1px solid #f59e0b;color:#92400e;border-radius:9999px;font-weight:700;">Free & Open Feature</span>
                            </div>
                            <p style="margin:0;font-size:13px;line-height:1.55;color:#92400e;">
                                White-labeling is <strong>100% free and fully unlocked</strong> for your agency — nothing is held behind a paywall. If Clockwork saves you billable time and maintenance headaches, please consider buying us a coffee or donating to support ongoing updates!
                            </p>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;align-self:center;">
                        <a href="https://www.paypal.com/donate?business=abc@123_example.com" target="_blank" rel="noopener noreferrer" class="button button-primary" style="background:#d97706;border-color:#b45309;font-weight:600;padding:4px 14px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                            Donate via PayPal &rarr;
                        </a>
                        <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="clockwork_whitelabel_donated">
                            <input type="hidden" name="nonce" value="<?php echo esc_attr($donatedNonce); ?>">
                            <button type="submit" class="button" style="font-weight:600;background:#ffffff;border-color:#d97706;color:#92400e;border-radius:6px;" title="Snoozes this reminder for a full year">
                                I Donated! ❤️
                            </button>
                        </form>
                        <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="clockwork_whitelabel_dismiss_donation">
                            <input type="hidden" name="nonce" value="<?php echo esc_attr($dismissNonce); ?>">
                            <button type="submit" class="button button-link" style="color:#b45309;text-decoration:underline;font-size:12px;padding:4px 8px;">
                                Remind me later
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php elseif ($donationState['has_donated']) : ?>
            <div style="margin-bottom:20px;padding:10px 16px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;font-size:12px;color:#065f46;display:flex;align-items:center;justify-content:space-between;">
                <span>❤️ <strong>Supporting Agency:</strong> Thank you for donating! Your annual check-in is snoozed for <strong><?php echo (int) $donationState['days_remaining']; ?> more days</strong>.</span>
                <a href="https://www.paypal.com/donate?business=abc@123_example.com" target="_blank" rel="noopener" style="color:#047857;text-decoration:underline;font-weight:600;">Donate again</a>
            </div>
        <?php endif; ?>

        <!-- Split Screen Layout: Customizer Form + Real-Time Live Preview -->
        <div class="cwk-whitelabel-layout" style="display:grid;grid-template-columns:1fr 420px;gap:28px;align-items:start;">

            <!-- Left Column: Form Fields -->
            <form id="cwk-whitelabel-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="clockwork_whitelabel_save">
                <input type="hidden" name="clockwork_whitelabel_nonce" value="<?php echo esc_attr($nonce); ?>">

                <!-- Card 1: Master Toggle -->
                <div class="clockwork-card" style="margin-bottom:24px;border-radius:10px;border:1px solid #e5e7eb;box-shadow:0 1px 3px rgba(0,0,0,0.04);background:#ffffff;">
                    <div class="clockwork-card__head" style="padding:16px 20px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;">
                        <div>
                            <h2 style="font-size:16px;font-weight:700;margin:0;color:#111827;">White Label Mode</h2>
                            <p style="margin:2px 0 0;font-size:12px;color:#6b7280;">Toggle white-label overrides on or off without losing your saved settings.</p>
                        </div>
                        <label class="cwk-switch" style="position:relative;display:inline-block;width:44px;height:24px;">
                            <input type="checkbox" id="field_enabled" name="whitelabel[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?> style="opacity:0;width:0;height:0;">
                            <span class="cwk-slider" style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#ccc;transition:.3s;border-radius:24px;"></span>
                        </label>
                    </div>
                </div>

                <!-- Card 2: Plugin Metadata (plugins.php) -->
                <div class="clockwork-card" style="margin-bottom:24px;border-radius:10px;border:1px solid #e5e7eb;box-shadow:0 1px 3px rgba(0,0,0,0.04);background:#ffffff;">
                    <div class="clockwork-card__head" style="padding:16px 20px;border-bottom:1px solid #f3f4f6;">
                        <h2 style="font-size:16px;font-weight:700;margin:0;color:#111827;">WordPress Plugins Screen Rebranding</h2>
                        <p style="margin:2px 0 0;font-size:12px;color:#6b7280;">Replaces the metadata displayed on <code>Plugins &rarr; Installed Plugins</code> and Must-Use plugins list.</p>
                    </div>
                    <div class="clockwork-card__body" style="padding:20px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                            <div>
                                <label for="field_plugin_name" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Plugin Name</label>
                                <input type="text" id="field_plugin_name" name="whitelabel[plugin_name]" value="<?php echo esc_attr($settings['plugin_name']); ?>" class="regular-text" style="width:100%;box-sizing:border-box;border-radius:6px;height:36px;">
                                <span style="font-size:11px;color:#6b7280;">e.g. Acme Site Companion, Client Care Tools</span>
                            </div>
                            <div>
                                <label for="field_author_name" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Author Name</label>
                                <input type="text" id="field_author_name" name="whitelabel[author_name]" value="<?php echo esc_attr($settings['author_name']); ?>" class="regular-text" style="width:100%;box-sizing:border-box;border-radius:6px;height:36px;">
                                <span style="font-size:11px;color:#6b7280;">e.g. Your Agency Name, LLC</span>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                            <div>
                                <label for="field_author_url" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Author Website URL</label>
                                <input type="url" id="field_author_url" name="whitelabel[author_url]" value="<?php echo esc_attr($settings['author_url']); ?>" class="regular-text" style="width:100%;box-sizing:border-box;border-radius:6px;height:36px;">
                                <span style="font-size:11px;color:#6b7280;">https://youragency.com</span>
                            </div>
                            <div>
                                <label for="field_plugin_url" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Plugin URL</label>
                                <input type="url" id="field_plugin_url" name="whitelabel[plugin_url]" value="<?php echo esc_attr($settings['plugin_url']); ?>" class="regular-text" style="width:100%;box-sizing:border-box;border-radius:6px;height:36px;">
                                <span style="font-size:11px;color:#6b7280;">Link for plugin row details</span>
                            </div>
                        </div>

                        <div>
                            <label for="field_plugin_description" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Plugin Description</label>
                            <textarea id="field_plugin_description" name="whitelabel[plugin_description]" rows="3" style="width:100%;box-sizing:border-box;border-radius:6px;padding:8px;font-size:13px;"><?php echo esc_textarea($settings['plugin_description']); ?></textarea>
                            <span style="font-size:11px;color:#6b7280;">Custom description displayed to site administrators.</span>
                        </div>
                    </div>
                </div>

                <!-- Card 3: Color Palette & Themes -->
                <div class="clockwork-card" style="margin-bottom:24px;border-radius:10px;border:1px solid #e5e7eb;box-shadow:0 1px 3px rgba(0,0,0,0.04);background:#ffffff;">
                    <div class="clockwork-card__head" style="padding:16px 20px;border-bottom:1px solid #f3f4f6;">
                        <h2 style="font-size:16px;font-weight:700;margin:0;color:#111827;">Brand Colors & Themes</h2>
                        <p style="margin:2px 0 0;font-size:12px;color:#6b7280;">Choose a curated 1-click theme or dial in custom hex colors.</p>
                    </div>
                    <div class="clockwork-card__body" style="padding:20px;">
                        <!-- 1-Click Presets -->
                        <div style="margin-bottom:20px;">
                            <span style="display:block;font-size:12px;font-weight:600;color:#4b5563;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px;">1-Click Theme Presets</span>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:10px;">
                                <button type="button" class="cwk-preset-btn" data-primary="#6953C4" data-dark="#2D2062" data-soft="#D1C9F4" data-accent="#7EFF83">
                                    <span class="cwk-preset-swatch" style="background:#6953C4;"><span style="background:#7EFF83;"></span></span>
                                    <span>Clockwork</span>
                                </button>
                                <button type="button" class="cwk-preset-btn" data-primary="#059669" data-dark="#064E3B" data-soft="#A7F3D0" data-accent="#10B981">
                                    <span class="cwk-preset-swatch" style="background:#059669;"><span style="background:#10B981;"></span></span>
                                    <span>Emerald Forest</span>
                                </button>
                                <button type="button" class="cwk-preset-btn" data-primary="#4F46E5" data-dark="#1E1B4B" data-soft="#C7D2FE" data-accent="#06B6D4">
                                    <span class="cwk-preset-swatch" style="background:#4F46E5;"><span style="background:#06B6D4;"></span></span>
                                    <span>Royal Indigo</span>
                                </button>
                                <button type="button" class="cwk-preset-btn" data-primary="#2563EB" data-dark="#0F172A" data-soft="#BAE6FD" data-accent="#38BDF8">
                                    <span class="cwk-preset-swatch" style="background:#2563EB;"><span style="background:#38BDF8;"></span></span>
                                    <span>Ocean Midnight</span>
                                </button>
                                <button type="button" class="cwk-preset-btn" data-primary="#DC2626" data-dark="#450A0A" data-soft="#FECACA" data-accent="#F59E0B">
                                    <span class="cwk-preset-swatch" style="background:#DC2626;"><span style="background:#F59E0B;"></span></span>
                                    <span>Crimson Amber</span>
                                </button>
                                <button type="button" class="cwk-preset-btn" data-primary="#374151" data-dark="#111827" data-soft="#E5E7EB" data-accent="#6B7280">
                                    <span class="cwk-preset-swatch" style="background:#374151;"><span style="background:#6B7280;"></span></span>
                                    <span>Monochrome</span>
                                </button>
                            </div>
                        </div>

                        <!-- Color Pickers Grid -->
                        <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:16px;">
                            <div>
                                <label for="field_primary_color" style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Primary Brand Color</label>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <input type="color" id="picker_primary_color" value="<?php echo esc_attr($settings['primary_color']); ?>" style="width:38px;height:36px;padding:1px;border-radius:6px;cursor:pointer;border:1px solid #d1d5db;">
                                    <input type="text" id="field_primary_color" name="whitelabel[primary_color]" value="<?php echo esc_attr($settings['primary_color']); ?>" class="code" style="width:100%;height:36px;border-radius:6px;">
                                </div>
                                <span style="font-size:11px;color:#6b7280;">Tabs active state, links, badges</span>
                            </div>

                            <div>
                                <label for="field_primary_dark_color" style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Header Band Color</label>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <input type="color" id="picker_primary_dark_color" value="<?php echo esc_attr($settings['primary_dark_color']); ?>" style="width:38px;height:36px;padding:1px;border-radius:6px;cursor:pointer;border:1px solid #d1d5db;">
                                    <input type="text" id="field_primary_dark_color" name="whitelabel[primary_dark_color]" value="<?php echo esc_attr($settings['primary_dark_color']); ?>" class="code" style="width:100%;height:36px;border-radius:6px;">
                                </div>
                                <span style="font-size:11px;color:#6b7280;">Top header background</span>
                            </div>

                            <div>
                                <label for="field_primary_soft_color" style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Soft Highlight Color</label>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <input type="color" id="picker_primary_soft_color" value="<?php echo esc_attr($settings['primary_soft_color']); ?>" style="width:38px;height:36px;padding:1px;border-radius:6px;cursor:pointer;border:1px solid #d1d5db;">
                                    <input type="text" id="field_primary_soft_color" name="whitelabel[primary_soft_color]" value="<?php echo esc_attr($settings['primary_soft_color']); ?>" class="code" style="width:100%;height:36px;border-radius:6px;">
                                </div>
                                <span style="font-size:11px;color:#6b7280;">Card highlights and borders</span>
                            </div>

                            <div>
                                <label for="field_accent_color" style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Accent / Button Color</label>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <input type="color" id="picker_accent_color" value="<?php echo esc_attr($settings['accent_color']); ?>" style="width:38px;height:36px;padding:1px;border-radius:6px;cursor:pointer;border:1px solid #d1d5db;">
                                    <input type="text" id="field_accent_color" name="whitelabel[accent_color]" value="<?php echo esc_attr($settings['accent_color']); ?>" class="code" style="width:100%;height:36px;border-radius:6px;">
                                </div>
                                <span style="font-size:11px;color:#6b7280;">Support button & highlights</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 4: Logos & Admin Navigation -->
                <div class="clockwork-card" style="margin-bottom:24px;border-radius:10px;border:1px solid #e5e7eb;box-shadow:0 1px 3px rgba(0,0,0,0.04);background:#ffffff;">
                    <div class="clockwork-card__head" style="padding:16px 20px;border-bottom:1px solid #f3f4f6;">
                        <h2 style="font-size:16px;font-weight:700;margin:0;color:#111827;">Logos & Admin Header</h2>
                        <p style="margin:2px 0 0;font-size:12px;color:#6b7280;">Upload your agency logo and configure the admin header branding.</p>
                    </div>
                    <div class="clockwork-card__body" style="padding:20px;">
                        <!-- Logo Upload -->
                        <div style="margin-bottom:18px;">
                            <label for="field_logo_url" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Header Logo Image URL</label>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <input type="url" id="field_logo_url" name="whitelabel[logo_url]" value="<?php echo esc_attr($settings['logo_url']); ?>" placeholder="https://.../logo.png" class="regular-text" style="flex:1;height:36px;border-radius:6px;">
                                <button type="button" id="cwk-upload-logo-btn" class="button" style="height:36px;font-weight:600;">
                                    Upload / Select Logo
                                </button>
                                <button type="button" id="cwk-clear-logo-btn" class="button" style="height:36px;">
                                    Clear
                                </button>
                            </div>
                            <span style="font-size:11px;color:#6b7280;">Recommended format: Transparent PNG or SVG (~160px &times; 36px). Defaults to Clockwork logo if blank.</span>
                        </div>

                        <!-- Sidebar Menu Title & Brand Subtitle -->
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;">
                            <div>
                                <label for="field_menu_title" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Admin Sidebar Menu Label</label>
                                <input type="text" id="field_menu_title" name="whitelabel[menu_title]" value="<?php echo esc_attr($settings['menu_title']); ?>" class="regular-text" style="width:100%;height:36px;border-radius:6px;">
                                <span style="font-size:11px;color:#6b7280;">Title in the left WordPress sidebar (Default: Clockwork)</span>
                            </div>
                            <div>
                                <label for="field_brand_text" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Header Sub-Title</label>
                                <input type="text" id="field_brand_text" name="whitelabel[brand_text]" value="<?php echo esc_attr($settings['brand_text']); ?>" class="regular-text" style="width:100%;height:36px;border-radius:6px;">
                                <span style="font-size:11px;color:#6b7280;">Text next to logo in header (Default: Companion)</span>
                            </div>
                        </div>

                        <!-- Support CTA & Version Tag -->
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;">
                            <div>
                                <label for="field_support_button_label" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Support Button Label</label>
                                <input type="text" id="field_support_button_label" name="whitelabel[support_button_label]" value="<?php echo esc_attr($settings['support_button_label']); ?>" class="regular-text" style="width:100%;height:36px;border-radius:6px;">
                            </div>
                            <div>
                                <label for="field_support_url" style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px;">Custom Support URL (Optional)</label>
                                <input type="url" id="field_support_url" name="whitelabel[support_url]" value="<?php echo esc_attr($settings['support_url']); ?>" placeholder="https://agency.com/help or mailto:support@..." class="regular-text" style="width:100%;height:36px;border-radius:6px;">
                                <span style="font-size:11px;color:#6b7280;">Leave blank to use built-in support modal</span>
                            </div>
                        </div>

                        <div style="padding-top:8px;">
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;cursor:pointer;">
                                <input type="checkbox" id="field_hide_version" name="whitelabel[hide_version]" value="1" <?php checked(!empty($settings['hide_version'])); ?>>
                                <span>Hide version indicator (<code>v<?php echo esc_html(CLOCKWORK_COMPANION_VERSION); ?></code>) from the admin header</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div style="display:flex;align-items:center;gap:16px;margin-top:20px;">
                    <button type="submit" class="button button-primary" style="height:40px;padding:0 24px;font-size:14px;font-weight:700;border-radius:8px;background:var(--cwk-primary);border-color:var(--cwk-primary);">
                        Save Branding Changes
                    </button>
                    <span style="font-size:12px;color:#6b7280;">Changes take effect immediately across all wp-admin screens.</span>
                </div>
            </form>

            <!-- Right Column: Interactive Real-Time Live Preview -->
            <div class="cwk-preview-column" style="position:sticky;top:40px;">
                <div class="clockwork-card" style="border-radius:12px;border:1px solid #e5e7eb;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.08);background:#ffffff;">
                    <div style="padding:12px 16px;background:#f9fafb;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#6b7280;display:flex;align-items:center;gap:6px;">
                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#10b981;"></span>
                            Real-Time Live Preview
                        </span>
                        <span id="preview-status-indicator" style="font-size:11px;color:#10b981;font-weight:600;">Active</span>
                    </div>

                    <!-- 1. Live Header Preview -->
                    <div id="preview-header" style="background:<?php echo esc_attr($settings['primary_dark_color']); ?>;padding:16px;color:#ffffff;display:flex;align-items:center;justify-content:space-between;transition:background 0.2s ease;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <img id="preview-logo-img" src="<?php echo esc_url(WhiteLabel::getLogoUrl()); ?>" alt="Logo" style="max-height:28px;max-width:130px;object-fit:contain;">
                            <span id="preview-brand-text" style="font-size:12px;color:#D1C9F4;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;">
                                <?php echo esc_html($settings['brand_text'] ?: 'Companion'); ?>
                            </span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <button type="button" id="preview-support-btn" style="background:<?php echo esc_attr($settings['accent_color']); ?>;color:#18181b;font-weight:700;font-size:11px;padding:4px 10px;border-radius:6px;border:none;cursor:pointer;">
                                <?php echo esc_html($settings['support_button_label'] ?: 'Get Support'); ?>
                            </button>
                            <span id="preview-version-tag" style="font-size:10px;color:rgba(255,255,255,0.7);font-family:monospace;<?php echo !empty($settings['hide_version']) ? 'display:none;' : ''; ?>">
                                v<?php echo esc_html(CLOCKWORK_COMPANION_VERSION); ?>
                            </span>
                        </div>
                    </div>

                    <!-- 2. Live Tabs Preview -->
                    <div id="preview-tabs" style="background:#ffffff;border-bottom:1px solid #e5e7eb;display:flex;padding:0 12px;gap:6px;">
                        <span id="preview-tab-active" style="padding:10px 12px;font-size:12px;font-weight:700;color:<?php echo esc_attr($settings['primary_color']); ?>;border-bottom:2px solid <?php echo esc_attr($settings['primary_color']); ?>;cursor:default;">
                            Activity
                        </span>
                        <span style="padding:10px 12px;font-size:12px;color:#6b7280;cursor:default;">Uptime</span>
                        <span style="padding:10px 12px;font-size:12px;color:#6b7280;cursor:default;">Security</span>
                    </div>

                    <!-- 3. Mini Dashboard Card Preview -->
                    <div style="padding:16px;background:#f9fafb;">
                        <div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;padding:14px;box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                <span style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;">Fleet Health</span>
                                <span style="display:inline-flex;align-items:center;gap:4px;font-size:10px;padding:2px 8px;border-radius:9999px;background:#dcfce7;color:#15803d;font-weight:600;">
                                    <span style="width:6px;height:6px;border-radius:50%;background:#16a34a;"></span> Active
                                </span>
                            </div>
                            <div style="font-size:12px;color:#6b7280;line-height:1.4;">
                                Companion is synchronized and communicating with your fleet hub.
                            </div>
                        </div>
                    </div>

                    <!-- 4. Simulated plugins.php Table Row -->
                    <div style="padding:16px;border-top:1px solid #e5e7eb;background:#ffffff;">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:8px;letter-spacing:0.5px;">
                            Simulated Plugins List Row (plugins.php)
                        </div>
                        <div style="border-left:4px solid #3b82f6;background:#f0f9ff;padding:12px;border-radius:0 6px 6px 0;font-size:12px;">
                            <div style="font-weight:700;color:#1e3a8a;margin-bottom:2px;">
                                <span id="preview-plugin-title"><?php echo esc_html($settings['plugin_name'] ?: 'Clockwork Companion'); ?></span>
                            </div>
                            <div style="color:#4b5563;font-size:11px;margin-bottom:4px;" id="preview-plugin-desc">
                                <?php echo esc_html($settings['plugin_description'] ?: 'Companion plugin...'); ?>
                            </div>
                            <div style="font-size:11px;color:#6b7280;">
                                Version <?php echo esc_html(CLOCKWORK_COMPANION_VERSION); ?> | By <a href="#" id="preview-plugin-author" style="color:#2563eb;text-decoration:none;font-weight:600;"><?php echo esc_html($settings['author_name'] ?: 'Clockwork Web Dev, LLC'); ?></a>
                            </div>
                        </div>
                    </div>

                    <!-- 5. Simulated Admin Sidebar Item -->
                    <div style="padding:16px;border-top:1px solid #e5e7eb;background:#1d2327;">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#8c8f94;margin-bottom:8px;letter-spacing:0.5px;">
                            Simulated WP Admin Sidebar
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:#2271b1;color:#ffffff;border-radius:4px;font-size:13px;font-weight:600;">
                            <span style="font-size:14px;">⚙️</span>
                            <span id="preview-sidebar-label"><?php echo esc_html($settings['menu_title'] ?: 'Clockwork'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style>
        /* Custom Switch Styling */
        .cwk-switch input:checked + .cwk-slider {
            background-color: #10b981 !important;
        }
        .cwk-switch input:focus + .cwk-slider {
            box-shadow: 0 0 1px #10b981;
        }
        .cwk-switch input:checked + .cwk-slider:before {
            transform: translateX(20px);
        }
        .cwk-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        /* Presets buttons */
        .cwk-preset-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .cwk-preset-btn:hover {
            border-color: #9ca3af;
            background: #f9fafb;
            transform: translateY(-1px);
        }
        .cwk-preset-swatch {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 1px 2px rgba(0,0,0,0.15);
        }
        .cwk-preset-swatch span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: block;
        }
        @media (max-width: 900px) {
            .cwk-whitelabel-layout {
                grid-template-columns: 1fr !important;
            }
            .cwk-preview-column {
                position: static !important;
            }
        }
        </style>

        <script>
        (function() {
            // Elements
            const form = document.getElementById('cwk-whitelabel-form');
            const fieldEnabled = document.getElementById('field_enabled');
            const fieldPluginName = document.getElementById('field_plugin_name');
            const fieldAuthorName = document.getElementById('field_author_name');
            const fieldAuthorUrl = document.getElementById('field_author_url');
            const fieldPluginDesc = document.getElementById('field_plugin_description');
            const fieldMenuTitle = document.getElementById('field_menu_title');
            const fieldBrandText = document.getElementById('field_brand_text');
            const fieldLogoUrl = document.getElementById('field_logo_url');
            const fieldHideVersion = document.getElementById('field_hide_version');
            const fieldSupportLabel = document.getElementById('field_support_button_label');

            const pickerPrimary = document.getElementById('picker_primary_color');
            const fieldPrimary = document.getElementById('field_primary_color');
            const pickerDark = document.getElementById('picker_primary_dark_color');
            const fieldDark = document.getElementById('field_primary_dark_color');
            const pickerSoft = document.getElementById('picker_primary_soft_color');
            const fieldSoft = document.getElementById('field_primary_soft_color');
            const pickerAccent = document.getElementById('picker_accent_color');
            const fieldAccent = document.getElementById('field_accent_color');

            // Preview targets
            const previewHeader = document.getElementById('preview-header');
            const previewLogoImg = document.getElementById('preview-logo-img');
            const previewBrandText = document.getElementById('preview-brand-text');
            const previewSupportBtn = document.getElementById('preview-support-btn');
            const previewVersionTag = document.getElementById('preview-version-tag');
            const previewTabActive = document.getElementById('preview-tab-active');
            const previewPluginTitle = document.getElementById('preview-plugin-title');
            const previewPluginDesc = document.getElementById('preview-plugin-desc');
            const previewPluginAuthor = document.getElementById('preview-plugin-author');
            const previewSidebarLabel = document.getElementById('preview-sidebar-label');
            const defaultLogo = '<?php echo esc_url(plugins_url('assets/clockwork-logo.png', CLOCKWORK_COMPANION_DIR . '/clockwork-companion.php')); ?>';

            // Sync Color Pickers with Text Inputs
            function bindColorPair(picker, textInput, onChange) {
                picker.addEventListener('input', function() {
                    textInput.value = picker.value;
                    onChange(picker.value);
                });
                textInput.addEventListener('input', function() {
                    if (/^#[0-9A-Fa-f]{6}$/.test(textInput.value)) {
                        picker.value = textInput.value;
                    }
                    onChange(textInput.value);
                });
            }

            bindColorPair(pickerPrimary, fieldPrimary, function(val) {
                previewTabActive.style.color = val;
                previewTabActive.style.borderBottomColor = val;
            });

            bindColorPair(pickerDark, fieldDark, function(val) {
                previewHeader.style.background = val;
            });

            bindColorPair(pickerAccent, fieldAccent, function(val) {
                previewSupportBtn.style.background = val;
            });

            bindColorPair(pickerSoft, fieldSoft, function(val) {
                // soft accents
            });

            // Live Text Updates
            function updatePreview() {
                // Metadata
                const name = fieldPluginName.value.trim() || 'Clockwork Companion';
                const author = fieldAuthorName.value.trim() || 'Clockwork Web Dev, LLC';
                const desc = fieldPluginDesc.value.trim() || 'Companion plugin...';
                const brand = fieldBrandText.value.trim() || 'Companion';
                const menu = fieldMenuTitle.value.trim() || 'Clockwork';
                const support = fieldSupportLabel.value.trim() || 'Get Support';
                const logo = fieldLogoUrl.value.trim() || defaultLogo;

                previewPluginTitle.textContent = name;
                previewPluginAuthor.textContent = author;
                previewPluginDesc.textContent = desc;
                previewBrandText.textContent = brand;
                previewSidebarLabel.textContent = menu;
                previewSupportBtn.textContent = support;
                previewLogoImg.src = logo;

                previewVersionTag.style.display = fieldHideVersion.checked ? 'none' : 'inline';
            }

            [fieldPluginName, fieldAuthorName, fieldPluginDesc, fieldBrandText, fieldMenuTitle, fieldSupportLabel, fieldLogoUrl].forEach(input => {
                input.addEventListener('input', updatePreview);
            });
            fieldHideVersion.addEventListener('change', updatePreview);

            // Preset themes click handler
            document.querySelectorAll('.cwk-preset-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const p = this.dataset.primary;
                    const d = this.dataset.dark;
                    const s = this.dataset.soft;
                    const a = this.dataset.accent;

                    pickerPrimary.value = p;
                    fieldPrimary.value = p;
                    previewTabActive.style.color = p;
                    previewTabActive.style.borderBottomColor = p;

                    pickerDark.value = d;
                    fieldDark.value = d;
                    previewHeader.style.background = d;

                    pickerSoft.value = s;
                    fieldSoft.value = s;

                    pickerAccent.value = a;
                    fieldAccent.value = a;
                    previewSupportBtn.style.background = a;
                });
            });

            // WordPress Media Uploader integration
            const uploadBtn = document.getElementById('cwk-upload-logo-btn');
            const clearBtn = document.getElementById('cwk-clear-logo-btn');

            if (uploadBtn && typeof wp !== 'undefined' && wp.media) {
                let mediaFrame;
                uploadBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (mediaFrame) {
                        mediaFrame.open();
                        return;
                    }
                    mediaFrame = wp.media({
                        title: 'Select or Upload White Label Logo',
                        button: { text: 'Use this Logo' },
                        multiple: false
                    });
                    mediaFrame.on('select', function() {
                        const attachment = mediaFrame.state().get('selection').first().toJSON();
                        fieldLogoUrl.value = attachment.url;
                        updatePreview();
                    });
                    mediaFrame.open();
                });
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    fieldLogoUrl.value = '';
                    updatePreview();
                });
            }
        })();
        </script>
        <?php
    }
}
