<?php

namespace ClockworkCompanion\Gatekeeper;

use ClockworkCompanion\WhiteLabel\WhiteLabel;

class LockoutPage
{
    /**
     * Renders the branded lockout HTML page and terminates the request.
     *
     * @param array{ip: string, unlock_at: string, retry_after: int} $lockout
     */
    public static function renderAndExit(array $lockout): void
    {
        status_header(429);
        header('Retry-After: ' . (int) $lockout['retry_after']);
        header('Content-Type: text/html; charset=UTF-8');

        $settings = Settings::get();
        $appName = WhiteLabel::getPluginName();
        $ip = $lockout['ip'];
        $remainingSecs = (int) $lockout['retry_after'];

        if ($remainingSecs >= 3600) {
            $hours = max(1, round($remainingSecs / 3600));
            $durationStr = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
        } else {
            $mins = max(1, round($remainingSecs / 60));
            $durationStr = $mins . ' ' . ($mins === 1 ? 'minute' : 'minutes');
        }

        $headline = str_replace(
            ['{duration}', '{ip}'],
            [$durationStr, $ip],
            (string) ($settings['headline'] ?: 'Too many failed login attempts')
        );

        $body = str_replace(
            ['{duration}', '{ip}'],
            [$durationStr, $ip],
            (string) ($settings['body'] ?: 'Please wait {duration} before trying again.')
        );

        $showIp = (bool) ($settings['show_ip'] ?? true);
        $showUnlock = (bool) ($settings['show_unlock_link'] ?? true);
        $unlockUrl = (string) ($settings['unlock_url'] ?? '');
        $hasValidUnlockUrl = $showUnlock && ! empty($unlockUrl) && str_starts_with($unlockUrl, 'https://');

        $supportLabel = (string) ($settings['support_label'] ?? '');
        $supportEmail = (string) ($settings['support_email'] ?? '');
        $supportUrl = (string) ($settings['support_url'] ?? '');

        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html($headline); ?> &middot; <?php echo esc_html($appName); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f3f4f6;
            color: #1f2937;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
        }
        .card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
            max-width: 28rem;
            width: 100%;
            padding: 2rem;
            text-align: center;
        }
        .icon-circle {
            width: 3.5rem;
            height: 3.5rem;
            background-color: #fee2e2;
            color: #dc2626;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
        }
        .icon-circle svg {
            width: 1.75rem;
            height: 1.75rem;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.75rem;
            line-height: 1.35;
        }
        p.body-text {
            font-size: 0.9375rem;
            color: #4b5563;
            line-height: 1.5;
            margin-bottom: 1.5rem;
        }
        .ip-box {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.875rem;
            color: #374151;
            margin-bottom: 1.5rem;
        }
        .copy-btn {
            background: #ffffff;
            border: 1px solid #d1d5db;
            color: #4b5563;
            border-radius: 0.375rem;
            padding: 0.25rem 0.625rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .copy-btn:hover {
            background: #f3f4f6;
            color: #111827;
            border-color: #9ca3af;
        }
        .actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1.25rem;
            border-top: 1px solid #f3f4f6;
        }
        .btn-primary {
            display: inline-block;
            background-color: #2563eb;
            color: #ffffff;
            text-decoration: none;
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            transition: background-color 0.15s ease;
        }
        .btn-primary:hover {
            background-color: #1d4ed8;
        }
        .support-info {
            font-size: 0.8125rem;
            color: #6b7280;
            margin-top: 0.5rem;
            line-height: 1.4;
        }
        .support-info a {
            color: #2563eb;
            text-decoration: none;
        }
        .support-info a:hover {
            text-decoration: underline;
        }
        .footer-brand {
            margin-top: 1.5rem;
            font-size: 0.6875rem;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-circle">
            <svg viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>

        <h1><?php echo esc_html($headline); ?></h1>
        <p class="body-text"><?php echo esc_html($body); ?></p>

        <?php if ($showIp): ?>
            <div class="ip-box">
                <span id="ip-val"><?php echo esc_html($ip); ?></span>
                <button type="button" class="copy-btn" id="copy-btn" onclick="copyIp()">Copy IP</button>
            </div>
        <?php endif; ?>

        <?php if ($hasValidUnlockUrl || ! empty($supportEmail) || ! empty($supportUrl)): ?>
            <div class="actions">
                <?php if ($hasValidUnlockUrl): ?>
                    <a href="<?php echo esc_url($unlockUrl); ?>" class="btn-primary" target="_blank" rel="noopener noreferrer">
                        Self-Service Unlock
                    </a>
                <?php endif; ?>

                <?php if (! empty($supportEmail) || ! empty($supportUrl)): ?>
                    <div class="support-info">
                        <?php if (! empty($supportLabel)): ?>
                            <strong><?php echo esc_html($supportLabel); ?>:</strong>
                        <?php endif; ?>
                        <?php if (! empty($supportEmail)): ?>
                            <a href="<?php echo esc_url('mailto:' . $supportEmail . '?subject=Access+Restoration+Request&body=My+IP+is:+' . rawurlencode($ip)); ?>">
                                <?php echo esc_html($supportEmail); ?>
                            </a>
                        <?php endif; ?>
                        <?php if (! empty($supportEmail) && ! empty($supportUrl)): ?> &middot; <?php endif; ?>
                        <?php if (! empty($supportUrl)): ?>
                            <a href="<?php echo esc_url($supportUrl); ?>" target="_blank" rel="noopener noreferrer">Support Portal</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="footer-brand">
            Protected by <?php echo esc_html($appName); ?>
        </div>
    </div>

    <script>
        function copyIp() {
            var ip = document.getElementById('ip-val').innerText;
            var btn = document.getElementById('copy-btn');
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(ip).then(onCopied);
            } else {
                var ta = document.createElement('textarea');
                ta.value = ip;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); onCopied(); } catch(e){}
                document.body.removeChild(ta);
            }
            function onCopied() {
                btn.innerText = 'Copied!';
                setTimeout(function() { btn.innerText = 'Copy IP'; }, 2000);
            }
        }
    </script>
</body>
</html>
        <?php
        exit;
    }
}
