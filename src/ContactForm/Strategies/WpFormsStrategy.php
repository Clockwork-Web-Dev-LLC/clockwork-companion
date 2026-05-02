<?php

namespace ClockworkCompanion\ContactForm\Strategies;

use ClockworkCompanion\ContactForm\StrategyResult;

/**
 * Submits a WPForms form by populating $_POST and invoking
 * wpforms()->process->process() — the same path the front-end ajax
 * handler uses.
 *
 * WPForms stores entries by default (paid version stores via DB; lite
 * doesn't). In lab mode we hook wpforms_disable_entries to suppress
 * the write.
 *
 * Field-name mapping is driven by each form's saved settings. Field
 * IDs are integers; we map by best-guess on field type
 * (email/text/textarea) since custom forms rename labels arbitrarily.
 */
class WpFormsStrategy extends AbstractStrategy
{
    private $entrySuppressionFilter;

    public function suppressStorage(string $mode): void
    {
        if ($mode !== 'lab') {
            return;
        }
        $this->entrySuppressionFilter = static fn () => true;
        add_filter('wpforms_disable_entries', $this->entrySuppressionFilter);
    }

    public function releaseStorage(): void
    {
        if ($this->entrySuppressionFilter) {
            remove_filter('wpforms_disable_entries', $this->entrySuppressionFilter);
            $this->entrySuppressionFilter = null;
        }
    }

    public function submit(string $formId, string $marker, string $mode): StrategyResult
    {
        if (! function_exists('wpforms')) {
            return StrategyResult::rejected('WPForms not loaded (wpforms() function missing).');
        }

        $form = wpforms()->form->get((int) $formId);
        if (! $form) {
            return StrategyResult::rejected("WPForms form id {$formId} not found.");
        }

        $formData = wpforms_decode($form->post_content ?? '');
        if (! is_array($formData) || empty($formData['fields'])) {
            return StrategyResult::rejected("WPForms form id {$formId} has no fields decoded.");
        }

        $payload = $this->payload($marker);

        $originalPost = $_POST;
        $_POST = $this->buildPostData((int) $formId, $formData['fields'], $payload);

        // WPForms' process->process() reads $_POST and walks fields. Errors
        // accumulate on wpforms()->process->errors — empty array = clean.
        try {
            wpforms()->process->process($_POST);
        } catch (\Throwable $e) {
            $_POST = $originalPost;
            return StrategyResult::rejected('WPForms process threw: '.$e->getMessage());
        }

        $errors = wpforms()->process->errors[$formId] ?? [];
        $_POST = $originalPost;

        if (! empty($errors)) {
            $errorList = is_array($errors) ? implode('; ', array_map('strval', $errors)) : (string) $errors;
            return StrategyResult::rejected("WPForms validation rejected: {$errorList}");
        }

        return StrategyResult::accepted('processed');
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function buildPostData(int $formId, array $fields, array $payload): array
    {
        $wpformsField = [];
        foreach ($fields as $fieldId => $field) {
            $type = (string) ($field['type'] ?? '');
            $value = match ($type) {
                'email' => $payload['email'],
                'name' => $payload['name'],
                'text' => str_contains(strtolower((string) ($field['label'] ?? '')), 'subject')
                    ? $payload['subject']
                    : $payload['name'],
                'textarea' => $payload['message'],
                'gdpr-checkbox', 'checkbox' => ['1'],
                default => $payload['name'],
            };
            $wpformsField[$fieldId] = $value;
        }

        return [
            'wpforms' => [
                'id' => $formId,
                'fields' => $wpformsField,
                'submit' => 'wpforms-submit',
                'token' => '',
                'post_id' => 0,
            ],
        ];
    }
}
