<?php
defined('SACCO') or exit;

/**
 * Minimal rule-based validator.
 *
 *   Validator::make($_POST, [
 *       'email'  => 'nullable|email|max:190',
 *       'amount' => 'required|numeric|min:1',
 *       'status' => 'required|in:active,inactive',
 *   ]);
 *
 * Returns only the validated keys (cast to int/float where numeric rules apply)
 * or throws ValidationError with per-field messages.
 */
final class Validator
{
    public static function make(array $data, array $rules, array $labels = []): array
    {
        $clean = [];
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $label = $labels[$field] ?? ucfirst(str_replace('_', ' ', preg_replace('/_id$/', '', $field)));
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            $isBool = in_array('bool', $ruleList, true);
            $empty = $value === null || $value === '' || (is_array($value) && $value === []);

            if ($empty) {
                if ($isBool) {
                    $clean[$field] = 0;
                } elseif (in_array('required', $ruleList, true)) {
                    $errors[$field] = "$label is required.";
                } else {
                    $clean[$field] = null;
                }
                continue;
            }

            $isNumeric = (bool) array_intersect(['numeric', 'integer'], $ruleList);

            foreach ($ruleList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = null;

                switch ($name) {
                    case 'string':
                        if (!is_scalar($value)) {
                            $error = "$label must be text.";
                        } else {
                            $value = (string) $value;
                        }
                        break;
                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $error = "$label must be a valid email address.";
                        } else {
                            $value = strtolower($value);
                        }
                        break;
                    case 'numeric':
                        if (!is_numeric($value)) {
                            $error = "$label must be a number.";
                        } else {
                            $value = (float) $value;
                        }
                        break;
                    case 'integer':
                        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                            $error = "$label must be a whole number.";
                        } else {
                            $value = (int) $value;
                        }
                        break;
                    case 'min':
                        if ($isNumeric ? (float) $value < (float) $arg : mb_strlen((string) $value) < (int) $arg) {
                            $error = $isNumeric ? "$label must be at least $arg." : "$label must be at least $arg characters.";
                        }
                        break;
                    case 'max':
                        if ($isNumeric ? (float) $value > (float) $arg : mb_strlen((string) $value) > (int) $arg) {
                            $error = $isNumeric ? "$label may not exceed $arg." : "$label may not exceed $arg characters.";
                        }
                        break;
                    case 'date':
                        $d = DateTime::createFromFormat('Y-m-d', (string) $value);
                        if (!$d || $d->format('Y-m-d') !== $value) {
                            $error = "$label must be a valid date.";
                        }
                        break;
                    case 'in':
                        if (!in_array((string) $value, explode(',', (string) $arg), true)) {
                            $error = "$label has an invalid value.";
                        }
                        break;
                    case 'phone':
                        if (!preg_match('/^\+?[0-9][0-9\s\-()]{6,19}$/', (string) $value)) {
                            $error = "$label must be a valid phone number.";
                        }
                        break;
                    case 'bool':
                        $value = in_array($value, ['1', 1, true, 'on', 'true', 'yes'], true) ? 1 : 0;
                        break;
                    case 'exists':
                        [$table, $col] = explode(',', (string) $arg);
                        if (!DB::value("SELECT 1 FROM `$table` WHERE `$col` = ? LIMIT 1", [$value])) {
                            $error = "Selected $label does not exist.";
                        }
                        break;
                }

                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }

            $clean[$field] = $value;
        }

        if ($errors) {
            throw new ValidationError($errors);
        }
        return $clean;
    }
}
