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
        // Lab mode stops delivery through the pre_wp_mail filter, which only
        // core's wp_mail() applies. A plugin that replaces wp_mail() (some
        // SMTP plugins do) would send the test lead for real, so refuse.
        if ($mode === 'lab' && ($replacedBy = self::wpMailReplacedBy()) !== null) {
            return [
                'ok' => false,
                'accepted' => false,
                'mail_invoked' => false,
                'mail_outcome' => null,
                'plugin_status' => null,
                'error' => "Lab mode is unavailable: wp_mail() is replaced by {$replacedBy}, which ignores the filter lab mode uses to stop delivery. Nothing was submitted.",
                'events' => [],
            ];
        }

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

        // Everything that installs hooks runs inside the try, so a failure in
        // any step (including an exception from the form plugin) still
        // removes them; a capture left attached would swallow every later
        // email in this request.
        try {
            $capture->attach();
            $strategy->suppressStorage($mode, $formId);
            $result = $strategy->submit($formId, $marker, $mode);
        } catch (\Throwable $e) {
            $result = StrategyResult::rejected('Form test failed: '.$e->getMessage());
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

    /**
     * Where wp_mail() comes from when it is not core's: a path relative to
     * the WordPress root, or null for core's own (or when it is not loaded).
     */
    public static function wpMailReplacedBy(): ?string
    {
        if (! function_exists('wp_mail')) {
            return null;
        }

        $file = (new \ReflectionFunction('wp_mail'))->getFileName();
        $file = is_string($file) ? str_replace('\\', '/', $file) : '';
        if ($file !== '' && str_ends_with($file, '/wp-includes/pluggable.php')) {
            return null;
        }

        $root = defined('ABSPATH') ? str_replace('\\', '/', (string) ABSPATH) : '';

        return $root !== '' && str_starts_with($file, $root) ? substr($file, strlen($root)) : ($file !== '' ? basename($file) : 'an unknown source');
    }

    protected function resolveStrategy(string $plugin): ?AbstractStrategy
    {
        return match ($plugin) {
            PluginRegistry::PLUGIN_CF7 => new ContactForm7Strategy(),
            PluginRegistry::PLUGIN_WPFORMS => new WpFormsStrategy(),
            PluginRegistry::PLUGIN_GRAVITY => new GravityFormsStrategy(),
            default => null,
        };
    }
}
