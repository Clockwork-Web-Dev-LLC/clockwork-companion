<?php

namespace ClockworkCompanion\ContactForm;

use ClockworkCompanion\ContactForm\Strategies\AbstractStrategy;
use ClockworkCompanion\ContactForm\Strategies\ContactForm7Strategy;
use ClockworkCompanion\ContactForm\Strategies\GravityFormsStrategy;
use ClockworkCompanion\ContactForm\Strategies\WpFormsStrategy;

/**
 * Orchestrates a single test-contact-form execution:
 *
 *   1. Resolve plugin slug → strategy
 *   2. Activate MailCapture (lab mode short-circuits wp_mail; live observes)
 *   3. Activate per-strategy storage suppression in lab mode
 *   4. Strategy submits the synthetic payload through the plugin's PHP API
 *   5. Read MailCapture's events, combine with strategy outcome
 *   6. Tear down filters
 *
 * Returned shape (the REST response body):
 *   {
 *     "ok": true|false,
 *     "accepted": bool,
 *     "mail_invoked": bool,
 *     "mail_outcome": "sent"|"failed"|"suppressed"|null,
 *     "plugin_status": "...",
 *     "error": "..."|null,
 *     "events": [...]    // MailCapture event log
 *   }
 */
class Tester
{
    public function run(string $plugin, string $formId, string $marker, string $mode): array
    {
        $strategy = $this->resolveStrategy($plugin);
        if ($strategy === null) {
            return [
                'ok' => false,
                'accepted' => false,
                'mail_invoked' => false,
                'mail_outcome' => null,
                'plugin_status' => null,
                'error' => "Unsupported form plugin: {$plugin}",
                'events' => [],
            ];
        }

        $capture = new MailCapture($mode, $marker);
        $capture->attach();
        $strategy->suppressStorage($mode);

        try {
            $result = $strategy->submit($formId, $marker, $mode);
        } finally {
            $strategy->releaseStorage();
            $capture->detach();
        }

        $error = $result->error;
        if ($error === null && $result->accepted && ! $capture->invoked()) {
            $error = 'Form accepted submission but never called wp_mail.';
        }

        return [
            'ok' => $result->accepted && $capture->invoked(),
            'accepted' => $result->accepted,
            'mail_invoked' => $capture->invoked(),
            'mail_outcome' => $capture->outcome(),
            'plugin_status' => $result->pluginStatus,
            'error' => $error ?? $capture->firstError(),
            'events' => $capture->events(),
        ];
    }

    private function resolveStrategy(string $plugin): ?AbstractStrategy
    {
        return match ($plugin) {
            PluginRegistry::PLUGIN_CF7 => new ContactForm7Strategy(),
            PluginRegistry::PLUGIN_WPFORMS => new WpFormsStrategy(),
            PluginRegistry::PLUGIN_GRAVITY => new GravityFormsStrategy(),
            default => null,
        };
    }
}
