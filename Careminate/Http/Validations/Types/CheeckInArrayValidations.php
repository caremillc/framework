<?php 
namespace Careminate\Http\Validations\Types;

trait CheeckInArrayValidations
{
    protected static function in(mixed $rule, mixed $value)
    {
        $values = isset(explode(':', $rule)[1]) ? explode(',', explode(':', $rule)[1]) : [];
        return !in_array($value, $values);
    }

    protected static function notIn(mixed $rule, mixed $value)
    {
        $values = isset(explode(':', $rule)[1]) ? explode(',', explode(':', $rule)[1]) : [];
        return in_array($value, $values);
    }
}
