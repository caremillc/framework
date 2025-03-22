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

if (!function_exists('env')) {
    /**
     * Gets an environment variable with type conversion and default fallback.
     * 
     * Supports boolean, null, numeric, and string values with proper type conversion.
     * Checks multiple environment sources for compatibility.
     *
     * @param string $key
     * @param mixed $default = null
     * @return mixed
     */
    function env(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        
        if ($value === false || $value === null) {
            return value($default);
        }

        $value = trim($value);
        $lowerValue = strtolower($value);

        // Convert special string values to proper types
        switch ($lowerValue) {
            case 'true':
                return true;
            case 'false':
                return false;
            case 'null':
                return null;
            case 'empty':
                return '';
        }

        // Handle numeric values
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }

        // Handle JSON encoded values
        if (preg_match('/^[\[\{].*[\]\}]$/', $value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }
}

if (! function_exists('required_env')) {
    function required_env(string $key)
    {
        if ($value = env($key)) {
            return $value;
        }

        throw new RuntimeException("Missing required environment variable: $key");
    }
}

