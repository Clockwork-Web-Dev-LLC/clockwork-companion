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
            // GF stores per-field validation messages in TWO places:
            //   1. $result['validation_messages']  (top-level, field_id => message)
            //   2. $result['form']['fields'][i]['validation_message']  (per-field, set
            //      when $field['failed_validation'] is true)
            // We walk both because some GF versions/hooks only populate one.
            $messages = [];
            foreach (($result['validation_messages'] ?? []) as $k => $msg) {
                $messages[] = "#{$k}: {$msg}";
            }
            foreach (($result['form']['fields'] ?? []) as $field) {
                if (empty($field['failed_validation'])) {
                    continue;
                }
                $fid = $field['id'] ?? '?';
                $msg = $field['validation_message'] ?? '(no message)';
                // Dedupe — skip if we already captured this field via the
                // top-level array.
                $already = false;
                foreach ($messages as $existing) {
                    if (str_starts_with($existing, "#{$fid}:")) {
                        $already = true;
                        break;
                    }
                }
                if (! $already) {
                    $messages[] = "#{$fid} ({$field['type']}): {$msg}";
                }
            }
            $msgList = implode('; ', $messages);

            if ($msgList === '') {
                $hints = [
                    'page_number=' . ($result['page_number'] ?? '?'),
                    'source_page=' . ($result['source_page_number'] ?? '?'),
                    'keys=[' . implode(',', array_keys($result)) . ']',
                ];
                $submitted = array_keys($fieldValues);
                sort($submitted);
                $hints[] = 'submitted_keys=[' . implode(',', $submitted) . ']';
                $msgList = '(no field messages) ' . implode(' · ', $hints);
            }

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
                    $values[$key.'_1'] = '1';
                    break;
                case 'checkbox':
                    // Checkbox is multi-select with composite inputs: input_<id>_1,
                    // input_<id>_2, etc. Picking the first real choice (value matches
                    // one of the predefined options) is enough to satisfy a
                    // "required" checkbox group.
                    $first = $this->firstRealChoice($field);
                    if ($first !== null) {
                        $values[$key.'_1'] = $first;
                    } else {
                        // No choices defined → fall back to legacy behaviour.
                        $values[$key.'_1'] = '1';
                    }
                    break;
                case 'select':
                case 'radio':
                case 'multiselect':
                    // Must match one of the predefined choices, otherwise GF
                    // rejects with "Invalid selection. Please select from the
                    // available choices." Picks the first non-placeholder
                    // choice with a non-empty value.
                    $choice = $this->firstRealChoice($field);
                    if ($choice !== null) {
                        $values[$key] = $type === 'multiselect' ? [$choice] : $choice;
                    }
                    break;
                case 'date':
                    // GF accepts MM/DD/YYYY by default; some forms use a 3-input
                    // composite (input_<id>_1=month, _2=day, _3=year). Try both —
                    // GF ignores the unused keys.
                    $values[$key] = gmdate('m/d/Y');
                    $values[$key.'_1'] = gmdate('m');
                    $values[$key.'_2'] = gmdate('d');
                    $values[$key.'_3'] = gmdate('Y');
                    break;
                case 'time':
                    // Composite: input_<id>_1=hour, _2=minute, _3=am/pm.
                    $values[$key.'_1'] = '12';
                    $values[$key.'_2'] = '00';
                    $values[$key.'_3'] = 'PM';
                    break;
                case 'phone':
                    // Tolerant format; GF's regex pattern accepts this.
                    $values[$key] = '555-555-5555';
                    break;
                case 'website':
                    $values[$key] = 'https://example.com/';
                    break;
                case 'number':
                    $values[$key] = '1';
                    break;
                case 'address':
                    // Composite: .1=street, .2=street2, .3=city, .4=state,
                    // .5=zip, .6=country. Filling all of them is overkill, but
                    // safe; GF won't complain about extras.
                    $values[$key.'_1'] = '1 Test St';
                    $values[$key.'_3'] = 'Atlanta';
                    $values[$key.'_4'] = 'GA';
                    $values[$key.'_5'] = '30301';
                    $values[$key.'_6'] = 'United States';
                    break;
                case 'hidden':
                case 'fileupload':
                case 'section':
                case 'html':
                case 'page':
                case 'captcha':
                    // Non-input or untestable field types — skip. GF preserves
                    // hidden field defaults automatically.
                    break;
                default:
                    // Unknown / new field type — fall back to the name string
                    // so the form has *something* in the key. Worst case it
                    // gets rejected; we already log the GF validation errors
                    // back into the test result for the operator to see.
                    $values[$key] = $payload['name'];
            }
        }

        return $values;
    }

    /**
     * Return the value of the first choice on this field that's a real option
     * (non-placeholder, non-empty). Returns null if the field has no choices
     * or every choice is empty/placeholder.
     *
     * Accepts `mixed` rather than `array` because Gravity Forms enumerates
     * its fields as `GF_Field_*` instances (ArrayAccess-implementing
     * objects), not plain arrays. `$field['choices']` works the same way
     * for both, but the strict array type hint refused the GF objects and
     * caused a TypeError fatal on every form that contained a choice-based
     * field.
     *
     * @param  mixed  $field  Plain array or any ArrayAccess (GF_Field_*).
     */
    private function firstRealChoice($field): ?string
    {
        $choices = $field['choices'] ?? null;
        if (! is_array($choices)) {
            return null;
        }
        foreach ($choices as $choice) {
            if (! is_array($choice)) {
                continue;
            }
            if (! empty($choice['isSelected']) || ! empty($choice['placeholder'])) {
                // Skip "Please select…" rows.
                continue;
            }
            $value = isset($choice['value']) ? (string) $choice['value'] : '';
            if ($value === '') {
                continue;
            }
            return $value;
        }
        // Fall back to the first non-empty value regardless of selected/placeholder
        // — some forms have a sole "selected by default" real option.
        foreach ($choices as $choice) {
            if (! is_array($choice)) {
                continue;
            }
            $value = isset($choice['value']) ? (string) $choice['value'] : '';
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }
}
