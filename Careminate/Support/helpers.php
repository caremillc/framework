<?php 

if (!function_exists('base_path')) {
    /**
     * Get the base path with optional file.
     *
     * @param string|null $file The file name to append to the base path, or null for just the base path.
     * @return string The full base path.
     */
    function base_path(?string $file = null): string
    {
        return $file ? getcwd() . '/' . $file : getcwd();
    }
}

if (!function_exists('route_path')) {
    /**
     * Get the full route path with optional file.
     *
     * @param string|null $file The file name to append to the path, or null for just the base path.
     * @return string The full route path.
     */
    function route_path(?string $file = null): string
    {
        return !is_null($file) ? config('route.path') . '/' . $file : config('route.path');
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration value based on the dot notation.
     *
     * @param string|null $file The config file and key in dot notation (e.g., 'app.name').
     * @return mixed The configuration value or the file itself.
     */
    function config(?string $file = null)
    {
        // If $file is provided and contains dot notation
        if ($file) {
            $seprate = explode('.', $file);
            if (!empty($seprate) && count($seprate) > 1) {
                $file = include base_path('config/') . $seprate[0] . '.php';
                return isset($file[$seprate[1]]) ? $file[$seprate[1]] : $file;
            }
        }
        
        // If no file is provided, return the file (null value returns the file parameter)
        return $file;
    }
}

