<?php

namespace ClockworkCompanion\ContactForm\Strategies;

use ClockworkCompanion\ContactForm\StrategyResult;

/**
 * Submits a Gravity Forms form via GFAPI::submit_form() — Gravity's
 * official programmatic submission API.
 *
 * Gravity stores entries by default. In lab mode we hook
 * gform_entry_pre_save_lead and return false to skip storage.
 *
 * GFAPI::submit_form() returns:
 *   - 'is_valid' (bool) — passed validation
 *   - 'validation_messages' (array) — if not valid
 *   - 'page_number' (int) — for multi-page forms (we only handle single-page)
 *   - 'confirmation_message' (string|array) — success body
 *   - 'confirmation_type' (string) — 'message' | 'redirect'
 */
class GravityFormsStrategy extends AbstractStrategy
{
    private $entrySuppressionFilter;

    public function suppressStorage(string $mode): void
    {
        if ($mode !== 'lab') {
            return;
        }
        $this->entrySuppressionFilter = static fn () => false;
        add_filter('gform_entry_pre_save_lead', $this->entrySuppressionFilter, 10, 1);
    }

    public function releaseStorage(): void
    {
        if ($this->entrySuppressionFilter) {
            remove_filter('gform_entry_pre_save_lead', $this->entrySuppressionFilter, 10);
            $this->entrySuppressionFilter = null;
        }
    }

    public function submit(string $formId, string $marker, string $mode): StrategyResult
    {
        if (! class_exists(\GFAPI::class)) {
            return StrategyResult::rejected('Gravity Forms not loaded (GFAPI missing).');
        }

        $form = \GFAPI::get_form((int) $formId);
        if (! $form) {
            return StrategyResult::rejected("Gravity form id {$formId} not found.");
        }

        $payload = $this->payload($marker);
        $fieldValues = $this->mapFieldValues($form, $payload);

        try {
            $result = \GFAPI::submit_form((int) $formId, $fieldValues);
        } catch (\Throwable $e) {
            return StrategyResult::rejected('Gravity submit threw: '.$e->getMessage());
        }

        if (is_wp_error($result)) {
            return StrategyResult::rejected('Gravity returned WP_Error: '.$result->get_error_message());
        }

        if (! is_array($result)) {
            return StrategyResult::rejected('Gravity submit returned non-array.');
        }

        if (empty($result['is_valid'])) {
            $messages = $result['validation_messages'] ?? [];
            $msgList = is_array($messages) ? implode('; ', array_map('strval', $messages)) : (string) $messages;
            return StrategyResult::rejected("Gravity validation rejected: {$msgList}", 'validation_failed');
        }

        return StrategyResult::accepted('submitted');
    }

    /**
     * Walk Gravity's form definition and populate input_<id> values by
     * field type. Gravity field IDs can be int or 'int.subindex' (e.g.
     * '1.3' for a name field's last-name component).
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function mapFieldValues(array $form, array $payload): array
    {
        $values = [];
        foreach (($form['fields'] ?? []) as $field) {
            $type = (string) ($field['type'] ?? '');
            $id = (string) ($field['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $key = 'input_'.$id;

            switch ($type) {
                case 'email':
                    $values[$key] = $payload['email'];
                    break;
                case 'name':
                    // Name field is composite: input_<id>.3=first, input_<id>.6=last
                    $values['input_'.$id.'_3'] = 'Clockwork';
                    $values['input_'.$id.'_6'] = 'Test';
                    break;
                case 'textarea':
                    $values[$key] = $payload['message'];
                    break;
                case 'text':
                    $values[$key] = str_contains(strtolower((string) ($field['label'] ?? '')), 'subject')
                        ? $payload['subject']
                        : $payload['name'];
                    break;
                case 'consent':
                case 'checkbox':
                    $values[$key.'_1'] = '1';
                    break;
                default:
                    $values[$key] = $payload['name'];
            }
        }

        return $values;
    }
}
