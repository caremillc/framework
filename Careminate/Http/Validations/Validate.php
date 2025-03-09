<?php 
namespace Careminate\Http\Validations;

use Careminate\Logs\Log;
use Careminate\Http\Validations\Types\QueryValidations;
use Careminate\Http\Validations\Types\DataTypeValidations;
use Careminate\Http\Validations\Types\CheeckInArrayValidations;

class Validate
{
    use DataTypeValidations, CheeckInArrayValidations, QueryValidations;

    protected static $errors = [];
    protected static $validated = [];

    public static function request($key, $requests): mixed
    {
        return $requests[$key] ?? null;
    }

    public static function make(array|object $requests, array $rules, array|null $attributes = []): self
    {
        foreach ($rules as $rule_key => $rule_value) {
            $value = self::request($rule_key, $requests);
            $real_rule_key = explode('.', $rule_key)[0];
            $attribute = self::attribute($attributes, $real_rule_key);

            foreach (array_values(self::rule($rule_value)) as $rule) {
                $method = self::getMethodName($rule);

                if (!method_exists(new self, $method)) {
                    throw new Log('There is no validation called ' . $method);
                }

                // Handle the rule matching and errors
                self::processValidation($rule, $rule_key, $value, $method, $attribute);
            }
        }
        return new self;
    }

    protected static function processValidation($rule, $rule_key, $value, $method, $attribute)
    {
        // Handle dynamic rules like min, max, etc.
        if (preg_match('/^in:|^unique:|^exists:/i', $rule)) {
            if (self::$method($rule, $value)) {
                self::add_error($rule_key, $method, $attribute);
            }
        } elseif (preg_match('/^min:/i', $rule)) {
            $minValue = explode(':', $rule)[1];
            if ($value < $minValue) {
                self::add_error($rule_key, 'min', $attribute . ' (' . $minValue . ')');
            }
        } elseif (preg_match('/^max:/i', $rule)) {
            $maxValue = explode(':', $rule)[1];
            if ($value > $maxValue) {
                self::add_error($rule_key, 'max', $attribute . ' (' . $maxValue . ')');
            }
        } elseif (self::$method($value)) {
            self::add_error($rule_key, $rule, $attribute);
        } else {
            self::$validated[$rule_key] = $value;
        }
    }

    public static function validated(): array
    {
        return static::$validated;
    }

    public static function failed(): array
    {
        return static::$errors;
    }

    public static function errors(): array
    {
        return static::$errors;
    }

    protected static function getMethodName($rule): string
    {
        // Handle dynamic rules like min:18, max:100, etc.
        if (preg_match('/^min:/i', $rule)) {
            return 'min';
        } elseif (preg_match('/^max:/i', $rule)) {
            return 'max';
        } elseif (preg_match('/^in:/i', $rule)) {
            return 'in';
        } elseif (preg_match('/^unique:/i', $rule)) {
            return 'unique';
        } elseif (preg_match('/^exists:/i', $rule)) {
            return 'exists';
        } elseif (preg_match('/^required/i', $rule)) {
            return 'required';
        }

        // Default case, return rule as method name
        return $rule;
    }

    private static function add_error($key, $rule, $attribute): void
    {
        static::$errors[$key][] = trans('validation.' . $rule, ['attribute' => $attribute]);
    }

    private static function rule($rule): array
    {
        return is_array($rule) ? $rule : explode('|', $rule);
    }

    private static function attribute($attributes, $key): string
    {
        return $attributes[$key] ?? $key;
    }
}
