<?php

namespace ClockworkCompanion\ContactForm\Strategies;

use ClockworkCompanion\ContactForm\StrategyResult;

/**
 * Submits a WPForms form by populating $_POST and invoking
 * wpforms()->process->process($_POST['wpforms']) — the same entry array the
 * front-end listen() / ajax_submit() handlers pass in.
 *
 * In lab mode the synthetic lead must leave no trace: the form's
 * "disable entries" setting is forced on, every callback on the entry-save
 * and process-complete hooks (where Pro stores entries and providers such
 * as mailing lists and webhooks act) is detached, and the legacy
 * wpforms_disable_entries filter is still answered.
 *
 * Confirmations are forced to the "message" type: a redirect or page
 * confirmation makes process() call wp_redirect() and exit, which would
 * end the REST request that runs the test.
 *
 * Field-name mapping is driven by each form's saved settings. Field
 * IDs are integers; we map by best-guess on field type
 * (email/text/textarea/name/checkbox/radio/select) since custom forms
 * rename labels arbitrarily.
 */
class WpFormsStrategy extends AbstractStrategy
{
    private $entrySuppressionFilter;

    private bool $lab = false;

    protected function integrationHooks(string $formId): array
    {
        return [
            'wpforms_process_entry_save',
            'wpforms_process_entry_saved',
            'wpforms_process_complete',
            'wpforms_process_complete_'.(int) $formId,
        ];
    }

    public function suppressStorage(string $mode, string $formId = ''): void
    {
        parent::suppressStorage($mode, $formId);
        if ($mode !== 'lab') {
            return;
        }
        $this->lab = true;
        $this->entrySuppressionFilter = static fn () => true;
        add_filter('wpforms_disable_entries', $this->entrySuppressionFilter);
    }

    public function releaseStorage(): void
    {
        if ($this->entrySuppressionFilter) {
            remove_filter('wpforms_disable_entries', $this->entrySuppressionFilter);
            $this->entrySuppressionFilter = null;
        }
        $this->lab = false;
        parent::releaseStorage();
    }

    /**
     * Form settings used for a test submission: non-AJAX, anti-spam off (the
     * test cannot solve a challenge), message confirmations only, and — in
     * lab mode — entry storage disabled.
     *
     * @param  mixed  $formData
     * @return mixed
     */
    public static function testFormData($formData, bool $lab)
    {
        if (! is_array($formData)) {
            return $formData;
        }
        if (! isset($formData['settings']) || ! is_array($formData['settings'])) {
            $formData['settings'] = [];
        }
        $settings = &$formData['settings'];
        $settings['ajax_submit'] = '0';
        $settings['antispam_v3'] = '0';
        $settings['antispam'] = '0';
        $settings['honeypot'] = '0';
        $settings['recaptcha'] = '0';
        $settings['anti_spam'] = [];

        // A redirect/page confirmation would wp_redirect() and exit.
        $settings['confirmation_type'] = 'message';
        if (isset($settings['confirmations']) && is_array($settings['confirmations'])) {
            foreach ($settings['confirmations'] as $key => $confirmation) {
                if (is_array($confirmation)) {
                    $settings['confirmations'][$key]['type'] = 'message';
                }
            }
        }

        if ($lab) {
            $settings['disable_entries'] = '1';
        }

        return $formData;
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

        $lab = $this->lab;
        $beforeFormDataFilter = static fn ($decodedFormData) => self::testFormData($decodedFormData, $lab);
        $bypassTrue = static fn () => true;

        if (function_exists('add_filter')) {
            add_filter('wpforms_process_before_form_data', $beforeFormDataFilter, 9999, 1);
            add_filter('wpforms_process_bypass_spam_check', $bypassTrue, 9999, 1);
            add_filter('wpforms_process_bypass_captcha', $bypassTrue, 9999, 1);
        }

        // WPForms' process->process() expects the `$_POST['wpforms']` entry array
        // (containing `id` and `fields`), not the outer `$_POST` superglobal.
        try {
            wpforms()->process->process($_POST['wpforms']);
        } catch (\Throwable $e) {
            $_POST = $originalPost;

            return StrategyResult::rejected('WPForms process threw: '.$e->getMessage());
        } finally {
            if (function_exists('remove_filter')) {
                remove_filter('wpforms_process_before_form_data', $beforeFormDataFilter, 9999);
                remove_filter('wpforms_process_bypass_spam_check', $bypassTrue, 9999);
                remove_filter('wpforms_process_bypass_captcha', $bypassTrue, 9999);
            }
        }

        $errors = wpforms()->process->errors[$formId] ?? wpforms()->process->errors[(int) $formId] ?? wpforms()->process->errors[0] ?? [];
        $_POST = $originalPost;

        if (! empty($errors)) {
            $flat = [];
            array_walk_recursive($errors, static function ($val) use (&$flat): void {
                if (is_scalar($val) && (string) $val !== '') {
                    $flat[] = (string) $val;
                }
            });
            $errorList = $flat !== [] ? implode('; ', $flat) : json_encode($errors);

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
            $label = strtolower((string) ($field['label'] ?? ''));
            $firstChoice = isset($field['choices']) && is_array($field['choices']) && $field['choices'] !== []
                ? reset($field['choices'])
                : null;
            $firstChoiceValue = is_array($firstChoice)
                ? ((! empty($field['show_values']) && isset($firstChoice['value']) && (string) $firstChoice['value'] !== '')
                    ? (string) $firstChoice['value']
                    : (string) ($firstChoice['label'] ?? '1'))
                : '1';

            $value = match ($type) {
                'email' => ! empty($field['confirmation'])
                    ? ['primary' => $payload['email'], 'secondary' => $payload['email']]
                    : $payload['email'],
                'name' => ((string) ($field['format'] ?? 'simple')) === 'simple'
                    ? $payload['name']
                    : ['first' => 'Clockwork', 'middle' => '', 'last' => 'Health Check'],
                'phone' => '(555) 555-0199',
                'number', 'number-slider' => '1',
                'url' => 'https://example.com',
                'text' => str_contains($label, 'subject')
                    ? $payload['subject']
                    : (str_contains($label, 'phone') ? '(555) 555-0199' : $payload['name']),
                'textarea' => $payload['message'],
                'gdpr-checkbox', 'checkbox' => [$firstChoiceValue],
                'radio', 'select' => $firstChoiceValue,
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
