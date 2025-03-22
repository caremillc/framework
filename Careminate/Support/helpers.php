<?php 

if (!function_exists('value')) {
    /**
     * Resolve a value from a Closure or return the value directly.
     * 
     * Supports any callable type and allows passing parameters.
     *
     * @param mixed $value Value or callable to resolve
     * @param mixed ...$args Arguments to pass to the callable
     * @return mixed
     */
    function value($value, ...$args)
    {
        if (is_callable($value)) {
            return $value(...$args);
        }

        return $value;
    }
}
