<?php 
namespace Careminate\Http\Validations\Types;

trait DataTypeValidations
{
    protected static function required(mixed $value, bool $allowNull = false): bool
    {
        if ($allowNull && is_null($value)) {
            return false; // Null is allowed
        }

        return is_null($value) || 
               (is_array($value) && empty($value)) || 
               (is_string($value) && trim($value) === '') || 
               (isset($value['tmp_name']) && empty($value['tmp_name']));
    }

    protected static function string(mixed $value): bool
    {
        return !is_string($value) || (is_numeric($value) && !is_bool($value));
    }

    protected static function integer(mixed $value): bool
    {
        return !is_numeric($value) || (filter_var($value, FILTER_VALIDATE_INT) === false);
    }

    protected static function numeric(mixed $value): bool
    {
        return !is_numeric($value) || !preg_match('/^[0-9]+$/', $value);
    }

    protected static function json(mixed $value): bool
    {
        json_decode($value);
        return json_last_error() !== JSON_ERROR_NONE;
    }

    protected static function array(mixed $value): bool
    {
        return !is_array($value);
    }

    protected static function email(mixed $value): bool
    {
        return !filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    protected static function url(mixed $value): bool
    {
        return !filter_var($value, FILTER_VALIDATE_URL);
    }

    protected static function boolean(mixed $value): bool
    {
        return !is_bool($value);
    }

    protected static function date(mixed $value): bool
    {
        return !strtotime($value);
    }

    protected static function file(mixed $value): bool
    {
        return !isset($value['tmp_name']) || !is_uploaded_file($value['tmp_name']);
    }

    protected static function min(mixed $value, $min): bool
    {
        if (is_string($value)) {
            return strlen($value) < $min;
        }

        if (is_numeric($value)) {
            return $value < $min;
        }

        return false;
    }

    protected static function max(mixed $value, $max): bool
    {
        if (is_string($value)) {
            return strlen($value) > $max;
        }

        if (is_numeric($value)) {
            return $value > $max;
        }

        return false;
    }

    protected static function confirmed(mixed $value, mixed $confirmationValue): bool
    {
        return $value !== $confirmationValue;
    }
}
