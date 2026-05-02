<?php

namespace ClockworkCompanion\ContactForm\Strategies;

use ClockworkCompanion\ContactForm\StrategyResult;

/**
 * Submits a CF7 form by instantiating WPCF7_ContactForm and invoking
 * its submit() method against synthetic $_POST data.
 *
 * CF7 statuses (from WPCF7_Submission::get_status()):
 *   init | validation_failed | acceptance_missing | spam | aborted
 *   | mail_sent | mail_failed
 *
 * "aborted" can happen if any pre-flight filter halts the submission
 * (anti-spam plugins commonly do this) — we treat it as rejection.
 *
 * CF7 does NOT store entries in core; if site has Flamingo or similar,
 * those plugins listen on wpcf7_submit and copy the data themselves.
 * We don't try to suppress that — out of scope for v1.
 */
class ContactForm7Strategy extends AbstractStrategy
{
    public function submit(string $formId, string $marker, string $mode): StrategyResult
    {
        if (! class_exists(\WPCF7_ContactForm::class)) {
            return StrategyResult::rejected('CF7 not loaded (WPCF7_ContactForm missing).');
        }

        $form = \WPCF7_ContactForm::get_instance((int) $formId);
        if (! $form) {
            return StrategyResult::rejected("CF7 form id {$formId} not found.");
        }

        $payload = $this->payload($marker);

        // CF7 reads from $_POST. Inject our synthetic values; back up + restore
        // to avoid contaminating the rest of the request lifecycle.
        $originalPost = $_POST;
        $_POST = $this->mapToCf7Fields($form, $payload);

        // Bypass nonce check for our internal-only submission. CF7's submit()
        // calls wpcf7_verify_nonce() internally; we register a filter to
        // override the result for this request.
        $bypassNonce = static fn () => true;
        add_filter('wpcf7_verify_nonce', $bypassNonce, 10, 1);

        try {
            $submission = $form->submit();
            $status = $this->statusFromSubmission($submission);
        } catch (\Throwable $e) {
            return StrategyResult::rejected('CF7 submit threw: '.$e->getMessage());
        } finally {
            remove_filter('wpcf7_verify_nonce', $bypassNonce, 10);
            $_POST = $originalPost;
        }

        // mail_sent is the success path; mail_failed means CF7 tried to send
        // and the underlying mailer rejected. Both count as "form processed
        // and reached the wp_mail step" — what MailCapture observes. The
        // remaining statuses mean CF7 rejected before invoking wp_mail.
        return match ($status) {
            'mail_sent', 'mail_failed' => StrategyResult::accepted($status),
            default => StrategyResult::rejected("CF7 rejected submission (status={$status}).", $status),
        };
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, string>
     */
    private function mapToCf7Fields(\WPCF7_ContactForm $form, array $payload): array
    {
        $tags = $form->scan_form_tags();
        $post = [
            '_wpcf7' => $form->id(),
            '_wpcf7_version' => defined('WPCF7_VERSION') ? WPCF7_VERSION : '5.0',
            '_wpcf7_locale' => get_locale(),
            '_wpcf7_unit_tag' => 'wpcf7-f'.$form->id().'-clockworktest',
            '_wpcf7_container_post' => '0',
            '_wpcf7_posted_data_hash' => '',
            '_wpnonce' => 'clockwork-bypass',
        ];

        // CF7's standard tags use names like 'your-name', 'your-email', 'your-subject',
        // 'your-message'. Custom forms can rename them — we set the standard ones AND
        // walk the form's tags filling in anything that looks recognisable.
        $post['your-name'] = $payload['name'];
        $post['your-email'] = $payload['email'];
        $post['your-subject'] = $payload['subject'];
        $post['your-message'] = $payload['message'];

        foreach ($tags as $tag) {
            /** @var \WPCF7_FormTag $tag */
            $name = $tag->name ?? '';
            if ($name === '' || isset($post[$name])) {
                continue;
            }
            $post[$name] = $this->valueForTag($tag, $payload);
        }

        return $post;
    }

    private function valueForTag(\WPCF7_FormTag $tag, array $payload): string
    {
        $name = strtolower($tag->name ?? '');
        $type = strtolower($tag->basetype ?? '');

        if (str_contains($name, 'mail') || $type === 'email') {
            return $payload['email'];
        }
        if (str_contains($name, 'name')) {
            return $payload['name'];
        }
        if (str_contains($name, 'subject')) {
            return $payload['subject'];
        }
        if (str_contains($name, 'message') || $type === 'textarea') {
            return $payload['message'];
        }
        if ($type === 'checkbox' || $type === 'acceptance') {
            return '1';
        }
        return $payload['name'];
    }

    private function statusFromSubmission($submission): string
    {
        // submit() may return the WPCF7_Submission instance OR an array,
        // depending on CF7 version. The singleton is the safest read.
        if (class_exists(\WPCF7_Submission::class)) {
            $instance = \WPCF7_Submission::get_instance();
            if ($instance) {
                return (string) $instance->get_status();
            }
        }

        if (is_array($submission) && isset($submission['status'])) {
            return (string) $submission['status'];
        }

        return 'unknown';
    }
}
