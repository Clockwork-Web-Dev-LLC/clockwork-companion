<?php

namespace ClockworkCompanion\ContactForm;

/**
 * Hooks into wp_mail to record every send attempt during a single
 * test-contact-form REST request. Two modes:
 *
 *   lab:  pre_wp_mail returns non-null → short-circuits delivery,
 *         we record (to, subject, headers, message) without sending.
 *
 *   live: lets wp_mail run normally; we observe via wp_mail_succeeded
 *         and wp_mail_failed hooks.
 *
 * Singleton-ish via a static instance because WP filters are global.
 * Reset between requests by re-instantiating in the Tester.
 */
class MailCapture
{
    private string $mode;
    private string $marker;

    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    private static ?self $current = null;

    public function __construct(string $mode, string $marker)
    {
        $this->mode = $mode;
        $this->marker = $marker;
        self::$current = $this;
    }

    public function attach(): void
    {
        // Lab mode: short-circuit at the entry point. Returning non-null from
        // pre_wp_mail tells WP "we handled it, don't try to send."
        if ($this->mode === 'lab') {
            add_filter('pre_wp_mail', [$this, 'onPreWpMail'], 10, 2);
            return;
        }

        // Live mode: observe outcomes without interfering.
        add_action('wp_mail_succeeded', [$this, 'onWpMailSucceeded'], 10, 1);
        add_action('wp_mail_failed', [$this, 'onWpMailFailed'], 10, 1);
    }

    public function detach(): void
    {
        remove_filter('pre_wp_mail', [$this, 'onPreWpMail'], 10);
        remove_action('wp_mail_succeeded', [$this, 'onWpMailSucceeded'], 10);
        remove_action('wp_mail_failed', [$this, 'onWpMailFailed'], 10);
        self::$current = null;
    }

    public function onPreWpMail($return, $atts)
    {
        $this->record($atts, 'suppressed', null);
        return true; // tells wp_mail to consider the send "successful" without doing anything
    }

    public function onWpMailSucceeded($info): void
    {
        $this->record($info, 'sent', null);
    }

    public function onWpMailFailed($error): void
    {
        $errMsg = $error instanceof \WP_Error ? $error->get_error_message() : (string) $error;
        $data = $error instanceof \WP_Error ? (array) $error->get_error_data() : [];
        $this->record($data, 'failed', $errMsg);
    }

    private function record(array $atts, string $outcome, ?string $error): void
    {
        $subject = (string) ($atts['subject'] ?? '');
        $message = (string) ($atts['message'] ?? '');
        $matchesMarker = ($this->marker !== '')
            && (str_contains($subject, $this->marker) || str_contains($message, $this->marker));

        $this->events[] = [
            'outcome' => $outcome,
            'to' => $atts['to'] ?? null,
            'subject' => $subject,
            'matches_marker' => $matchesMarker,
            'error' => $error,
        ];
    }

    public function invoked(): bool
    {
        return $this->events !== [];
    }

    /**
     * Did wp_mail get called with our test marker (i.e., it was OUR test
     * triggering the send, not some unrelated email that happened to fly
     * during the request)?
     */
    public function matchedMarker(): bool
    {
        foreach ($this->events as $event) {
            if (! empty($event['matches_marker'])) {
                return true;
            }
        }
        return false;
    }

    public function outcome(): ?string
    {
        // Prefer the marker-matched event if any exist; otherwise fall back
        // to the first recorded event.
        foreach ($this->events as $event) {
            if (! empty($event['matches_marker'])) {
                return (string) $event['outcome'];
            }
        }
        return $this->events[0]['outcome'] ?? null;
    }

    public function firstError(): ?string
    {
        foreach ($this->events as $event) {
            if (! empty($event['error'])) {
                return (string) $event['error'];
            }
        }
        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function events(): array
    {
        return $this->events;
    }
}
