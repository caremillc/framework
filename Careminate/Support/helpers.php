<?php 

use Careminate\Http\Requests\Request;

if (!function_exists('public_path')) {
    /**
     * Get the full public path with an optional file.
     *
     * @param string|null $file The file path to append to the public path, or null for just the base public path.
     * @return string The full public path.
     */
    function public_path(?string $file = null): string
    {
        return !empty($file) ? getcwd() . '/' . $file : getcwd();
    }
}

if (!function_exists('asset')) {
    /**
     * Generate the full URL to an asset.
     *
     * @param string|null $file The asset file path or null for the root public path.
     * @return string The full URL to the asset.
     */
    function asset(?string $file = null): string
    {
        // Get the public path using the previously defined public_path function
        $publicPath = public_path($file);
        
        // Replace the base directory of the project with the public URL root
        $relativePath = str_replace(getcwd() . '/', '/', $publicPath);
        
        // Get the base URL of the web server
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        
        // Return the full URL to the asset
        return $baseUrl . $relativePath;
    }
}

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
     * Get an environment variable, or return the default value if not found.
     *
     * Supports various data types.
     *
     * @param string $key The name of the environment variable.
     * @param mixed $default The default value to return if the environment variable is not found.
     * @return mixed The value of the environment variable or the default value.
     */
    function env(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return value($default);
        }

        $value = trim($value);
        $lowerValue = strtolower($value);

        // Handle special types
        switch ($lowerValue) {
            case 'true': return true;
            case 'false': return false;
            case 'null': return null;
            case 'empty': return '';
        }

        // Handle numeric and JSON values
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }

        if (preg_match('/^[\[\{].*[\]\}]$/', $value)) {
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $value;
    }
}

if (!function_exists('bcrypt')) {
    /**
     * Hash a string using bcrypt.
     *
     * @param string $str The string to hash.
     * @return string The hashed string.
     */
    function bcrypt(string $str): string
    {
        return \Careminate\Hashes\Hash::make($str);
    }
}

if (!function_exists('hash_check')) {
    /**
     * Check if a given password matches the hashed password.
     *
     * @param string $password The plain text password.
     * @param string $hashedPassword The hashed password to check against.
     * @return bool Whether the password matches the hashed value.
     */
    function hash_check(string $password, string $hashedPassword): bool
    {
        return \Careminate\Hashes\Hash::check($password, $hashedPassword);
    }
}

if (!function_exists('encrypt')) {
    /**
     * Encrypt a string value.
     *
     * @param string $value The string to encrypt.
     * @return string The encrypted string.
     */
    function encrypt(string $value): string
    {
        return \Careminate\Hashes\Hash::encrypt($value);
    }
}

if (!function_exists('decrypt')) {
    /**
     * Decrypt a string value.
     *
     * @param string $value The encrypted string to decrypt.
     * @return string The decrypted string.
     */
    function decrypt(string $value): string
    {
        return \Careminate\Hashes\Hash::decrypt($value);
    }
}

if (!function_exists('url')) {
    function url(string $url = ''): string
    {
        $scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
        
        // Ensure ROOT_DIR is defined properly or handle it better
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . (defined('ROOT_DIR') ? ROOT_DIR : '') . ltrim($url, '/');
    }
}

if (!function_exists('redirect')) {
    /**
     * Redirect to a given URL.
     *
     * @param string $url The URL to redirect to.
     * @return void
     */
    function redirect(string $url): void
    {
        header("Location: $url");
        exit; // Ensure no further code is executed after the redirect
    }
}

if (! function_exists('storage_path')) {
    function storage_path(?string $file = null)
    {
        return ! is_null($file) ? base_path('storage') . '/' . $file : '';
    }
}

if (!function_exists('view')) {
    /**
     * A simple helper function to load views
     *
     * @param string $view The view name
     * @param array|null $data Optional data to pass to the view
     * @return void
     */
    function view(string $view, ?array $data = [])
    {
        return \Careminate\Views\View::make($view, $data);
    }
}

if (!function_exists('trans')) {
    function trans(?string $trans = null, array|null $attributes = []): string|object
    {
        // Return a translation or an instance of the Lang class for chaining.
        if ($trans) {
            return \Careminate\Localization\Lang::get($trans, $attributes);
        }
        return new \Careminate\Localization\Lang;
    }
}

if (!function_exists('response')) {
    function response(string $content, int $statusCode = 200)
    {
        http_response_code($statusCode);
        echo $content;
        exit;
    }
}

if (!function_exists('request')) {
    function request(?string $name = null, mixed $default = null)
    {
        // Ensure you're working with the global request instance.
        $request = Request::createFromGlobals();
        if (empty($name)) {
            return $request->all();
        } else {
            return $request->get($name, $default);
        }
    }
}
