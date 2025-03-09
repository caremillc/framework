<?php 
namespace Careminate\Http\Validations\Types;

trait QueryValidations
{
    protected static function unique(mixed $rule, mixed $value = null)
    {
        $unique_rule = explode(':', $rule);
        if (!empty($value)) {
            if (isset($unique_rule[1])) {
                $table = explode(',', $unique_rule[1])[0];
                $column = explode(',', $unique_rule[1])[1] ?? '';
                $validation_value = explode(',', $unique_rule[1])[2] ?? '';
                return $value == $validation_value;
            }
        }
        return false;
    }

    protected static function exists(mixed $rule, mixed $value = null)
    {
        $unique_rule = explode(':', $rule);
        if (!empty($value)) {
            if (isset($unique_rule[1])) {
                $table = explode(',', $unique_rule[1])[0];
                $column = explode(',', $unique_rule[1])[1] ?? '';
                return true;
            }
        }
        return false;
    }
}
