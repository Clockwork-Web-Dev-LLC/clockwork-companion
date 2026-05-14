<?php

namespace ClockworkCompanion\ContactForm;

/**
 * Client-self-service form-test subscriptions store.
 *
 * Each row represents a form on the local WordPress install that the site
 * admin has opted into recurring testing for. Clockwork's nightly sync
 * (`clockwork:sync-companion-form-subscriptions`) pulls this list via the
 * `/wp-json/clockwork/v1/form-subscriptions` REST endpoint and reconciles
 * it into the agency-side `contact_form_tests` table with provenance
 * `client`.
 *
 * Storage is a single wp_option keyed by form_id so subscribe / unsubscribe
 * are O(1) and the list is bounded by self::MAX. Admin UI enforces the cap;
 * the storage layer enforces it too as a backstop against direct DB pokes.
 */
class SubscriptionsService
{
    public const OPTION = 'clockwork_companion_form_test_subscriptions';

    /** Hard cap on subscribed forms per site. Matches Clockwork. */
    public const MAX = 3;

    public const FREQUENCY_WEEKLY = 'weekly';

    /**
     * Returns the full subscriptions map keyed by form_id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $raw = get_option(self::OPTION, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Subscribe a form to recurring testing. Returns true on success, false
     * if the cap is reached (and the form isn't already subscribed).
     */
    public static function subscribe(string $formId, string $plugin, string $frequency = self::FREQUENCY_WEEKLY): bool
    {
        $formId = trim($formId);
        if ($formId === '') {
            return false;
        }
        // Self-service UI only ever offers weekly. Daily is an agency-only
        // setting (toggled by an operator on the dashboard via ?admin=1).
        // We accept daily here for completeness but the UI doesn't expose it.
        if (! in_array($frequency, [self::FREQUENCY_WEEKLY, 'daily'], true)) {
            $frequency = self::FREQUENCY_WEEKLY;
        }

        $subs = self::all();
        if (! isset($subs[$formId]) && count($subs) >= self::MAX) {
            return false;
        }
        $subs[$formId] = [
            'form_id' => $formId,
            'plugin' => $plugin,
            'frequency' => $frequency,
            'enabled' => true,
            'subscribed_at' => $subs[$formId]['subscribed_at'] ?? gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        update_option(self::OPTION, $subs, false);
        return true;
    }

    public static function unsubscribe(string $formId): bool
    {
        $formId = trim($formId);
        $subs = self::all();
        if (! isset($subs[$formId])) {
            return false;
        }
        unset($subs[$formId]);
        update_option(self::OPTION, $subs, false);
        return true;
    }

    public static function isSubscribed(string $formId): bool
    {
        return array_key_exists(trim($formId), self::all());
    }

    public static function count(): int
    {
        return count(self::all());
    }

    public static function atCap(): bool
    {
        return self::count() >= self::MAX;
    }
}
