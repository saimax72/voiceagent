<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight validator. Rules: required, nullable, email, url, domain, min:n, max:n, between:a,b,
 * numeric, integer, in:a,b,c, boolean, confirmed, alpha_dash, regex:/.../, array, string, hex_color
 */
final class Validator
{
    private array $errors = [];
    private array $validated = [];

    private function __construct(private array $data, private array $rules, private array $labels = [])
    {
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        $v = new self($data, $rules, $labels);
        $v->run();
        return $v;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $messages) {
            return $messages[0] ?? null;
        }
        return null;
    }

    public function validated(): array
    {
        return $this->validated;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace(['_', '-'], ' ', $field));
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $nullable = in_array('nullable', $rules, true);
            $isEmpty = $value === null || $value === '' || $value === [];
            $label = $this->label($field);

            if ($isEmpty) {
                if (in_array('required', $rules, true)) {
                    $this->addError($field, "{$label} is required.");
                } else {
                    $this->validated[$field] = ($value === '' || $value === null) ? ($nullable ? null : '') : $value;
                }
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                switch ($name) {
                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL) || strlen((string) $value) > 190) {
                            $this->addError($field, "{$label} must be a valid email address.");
                        }
                        break;
                    case 'url':
                        $candidate = (string) $value;
                        if (!preg_match('~^https?://~i', $candidate)) {
                            $candidate = 'https://' . $candidate;
                        }
                        if (!filter_var($candidate, FILTER_VALIDATE_URL) || !preg_match('~^https?://[^/\s]+\.[a-z0-9-]{2,}~i', $candidate)) {
                            $this->addError($field, "{$label} must be a valid URL.");
                        }
                        break;
                    case 'domain':
                        if (!preg_match('/^(\*\.)?([a-z0-9-]+\.)+[a-z]{2,}$/i', (string) $value) && $value !== 'localhost') {
                            $this->addError($field, "{$label} must be a valid domain.");
                        }
                        break;
                    case 'min':
                        if (is_numeric($value) && !is_string($value)) {
                            if ($value < (float) $arg) {
                                $this->addError($field, "{$label} must be at least {$arg}.");
                            }
                        } elseif (is_array($value)) {
                            if (count($value) < (int) $arg) {
                                $this->addError($field, "{$label} must have at least {$arg} items.");
                            }
                        } elseif (mb_strlen((string) $value) < (int) $arg) {
                            $this->addError($field, "{$label} must be at least {$arg} characters.");
                        }
                        break;
                    case 'max':
                        if (is_numeric($value) && !is_string($value)) {
                            if ($value > (float) $arg) {
                                $this->addError($field, "{$label} may not be greater than {$arg}.");
                            }
                        } elseif (is_array($value)) {
                            if (count($value) > (int) $arg) {
                                $this->addError($field, "{$label} may not have more than {$arg} items.");
                            }
                        } elseif (mb_strlen((string) $value) > (int) $arg) {
                            $this->addError($field, "{$label} may not be longer than {$arg} characters.");
                        }
                        break;
                    case 'numeric':
                        if (!is_numeric($value)) {
                            $this->addError($field, "{$label} must be a number.");
                        }
                        break;
                    case 'integer':
                        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                            $this->addError($field, "{$label} must be a whole number.");
                        }
                        break;
                    case 'between':
                        [$lo, $hi] = array_map('floatval', explode(',', (string) $arg));
                        if (!is_numeric($value) || $value < $lo || $value > $hi) {
                            $this->addError($field, "{$label} must be between {$lo} and {$hi}.");
                        }
                        break;
                    case 'in':
                        $options = explode(',', (string) $arg);
                        if (!in_array((string) $value, $options, true)) {
                            $this->addError($field, "{$label} is invalid.");
                        }
                        break;
                    case 'boolean':
                        if (!in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off'], true)) {
                            $this->addError($field, "{$label} must be true or false.");
                        }
                        break;
                    case 'confirmed':
                        if (($this->data[$field . '_confirmation'] ?? null) !== $value) {
                            $this->addError($field, "{$label} confirmation does not match.");
                        }
                        break;
                    case 'alpha_dash':
                        if (!preg_match('/^[A-Za-z0-9_-]+$/', (string) $value)) {
                            $this->addError($field, "{$label} may only contain letters, numbers, dashes and underscores.");
                        }
                        break;
                    case 'regex':
                        if (!preg_match((string) $arg, (string) $value)) {
                            $this->addError($field, "{$label} format is invalid.");
                        }
                        break;
                    case 'array':
                        if (!is_array($value)) {
                            $this->addError($field, "{$label} must be a list.");
                        }
                        break;
                    case 'string':
                        if (!is_string($value)) {
                            $this->addError($field, "{$label} must be text.");
                        }
                        break;
                    case 'hex_color':
                        if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', (string) $value)) {
                            $this->addError($field, "{$label} must be a valid colour.");
                        }
                        break;
                }
            }
            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $value;
            }
        }
    }
}
