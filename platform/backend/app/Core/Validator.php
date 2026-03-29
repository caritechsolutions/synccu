<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Input validator supporting common rules.
 *
 * Usage:
 *   $v = new Validator($request->all(), [
 *       'email'    => 'required|email',
 *       'password' => 'required|min:8|max:128',
 *       'role'     => 'required|in:admin,member,teller',
 *   ]);
 *
 *   if ($v->fails()) {
 *       return Response::validationError($v->errors());
 *   }
 *
 *   $clean = $v->validated();
 */
final class Validator
{
    private array $errors = [];
    private array $validated = [];

    /**
     * @param array<string, mixed>  $data  Input data
     * @param array<string, string> $rules Field => pipe-separated rules
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
    ) {
        $this->validate();
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * Return structured errors keyed by field.
     *
     * @return array<string, string[]>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Return only the validated (and present) data.
     */
    public function validated(): array
    {
        return $this->validated;
    }

    // ------------------------------------------------------------------
    // Validation Engine
    // ------------------------------------------------------------------

    private function validate(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $ruleList  = explode('|', $ruleString);
            $value     = $this->data[$field] ?? null;
            $isPresent = array_key_exists($field, $this->data);
            $nullable  = in_array('nullable', $ruleList, true);

            foreach ($ruleList as $rule) {
                if ($rule === 'nullable') {
                    continue;
                }

                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                // Skip further checks on nullable fields with null/empty value
                if ($nullable && ($value === null || $value === '')) {
                    break;
                }

                $error = $this->applyRule($field, $value, $isPresent, $rule, $params);
                if ($error !== null) {
                    $this->errors[$field][] = $error;
                }
            }

            // Collect validated data only for passing fields
            if (empty($this->errors[$field]) && $isPresent) {
                $this->validated[$field] = $value;
            }
        }
    }

    private function applyRule(string $field, mixed $value, bool $present, string $rule, array $params): ?string
    {
        $label = str_replace('_', ' ', $field);

        return match ($rule) {
            'required'  => $this->ruleRequired($label, $value, $present),
            'email'     => $this->ruleEmail($label, $value),
            'min'       => $this->ruleMin($label, $value, (int) ($params[0] ?? 0)),
            'max'       => $this->ruleMax($label, $value, (int) ($params[0] ?? PHP_INT_MAX)),
            'numeric'   => $this->ruleNumeric($label, $value),
            'string'    => $this->ruleString($label, $value),
            'in'        => $this->ruleIn($label, $value, $params),
            'uuid'      => $this->ruleUuid($label, $value),
            'date'      => $this->ruleDate($label, $value),
            'boolean'   => $this->ruleBoolean($label, $value),
            'confirmed' => $this->ruleConfirmed($field, $label, $value),
            'regex'     => $this->ruleRegex($label, $value, $params[0] ?? ''),
            'integer'   => $this->ruleInteger($label, $value),
            'positive'  => $this->rulePositive($label, $value),
            default     => null, // unknown rules are silently skipped
        };
    }

    // ------------------------------------------------------------------
    // Individual Rules
    // ------------------------------------------------------------------

    private function ruleRequired(string $label, mixed $value, bool $present): ?string
    {
        if (!$present || $value === null || $value === '' || $value === []) {
            return "The {$label} field is required.";
        }
        return null;
    }

    private function ruleEmail(string $label, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return "The {$label} field must be a valid email address.";
        }
        return null;
    }

    private function ruleMin(string $label, mixed $value, int $min): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && mb_strlen($value) < $min) {
            return "The {$label} field must be at least {$min} characters.";
        }
        if (is_numeric($value) && (float) $value < $min) {
            return "The {$label} field must be at least {$min}.";
        }
        return null;
    }

    private function ruleMax(string $label, mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        // String values: always check character length (even numeric strings like phone numbers)
        if (is_string($value)) {
            if (mb_strlen($value) > $max) {
                return "The {$label} field must not exceed {$max} characters.";
            }
            return null;
        }
        if (is_numeric($value) && (float) $value > $max) {
            return "The {$label} field must not exceed {$max}.";
        }
        return null;
    }

    private function ruleNumeric(string $label, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return "The {$label} field must be numeric.";
        }
        return null;
    }

    private function ruleInteger(string $label, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return "The {$label} field must be an integer.";
        }
        return null;
    }

    private function rulePositive(string $label, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value <= 0) {
            return "The {$label} field must be a positive number.";
        }
        return null;
    }

    private function ruleString(string $label, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return "The {$label} field must be a string.";
        }
        return null;
    }

    private function ruleIn(string $label, mixed $value, array $allowed): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!in_array((string) $value, $allowed, true)) {
            return "The {$label} field must be one of: " . implode(', ', $allowed) . '.';
        }
        return null;
    }

    private function ruleUuid(string $label, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        if (!preg_match($pattern, (string) $value)) {
            return "The {$label} field must be a valid UUID.";
        }
        return null;
    }

    private function ruleDate(string $label, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (strtotime((string) $value) === false) {
            return "The {$label} field must be a valid date.";
        }
        return null;
    }

    private function ruleBoolean(string $label, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            return "The {$label} field must be true or false.";
        }
        return null;
    }

    private function ruleConfirmed(string $field, string $label, mixed $value): ?string
    {
        $confirmation = $this->data["{$field}_confirmation"] ?? null;
        if ($value !== $confirmation) {
            return "The {$label} confirmation does not match.";
        }
        return null;
    }

    private function ruleRegex(string $label, mixed $value, string $pattern): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($pattern === '' || !preg_match($pattern, (string) $value)) {
            return "The {$label} field format is invalid.";
        }
        return null;
    }
}
