<?php 
namespace Careminate\Views;

class View
{
    protected static $cacheDir;

    /**
     * Set up cache directory from the configuration.
     */
    public static function cache(): void
    {
        // Check if caching is enabled in the config
        if (!config('view.cache')) {
            return;
        }

        // Set the cache directory from the configuration
        static::$cacheDir = config('view.cache_directory');
        // dd(static::$cacheDir);
        // Check if the cache directory exists, create it if not
        if (!is_dir(static::$cacheDir) && !mkdir(static::$cacheDir, 0755, true)) {
            // If directory creation fails, throw an exception
            throw new \RuntimeException("Failed to create cache directory: " . static::$cacheDir);
        }
    }

    /**
     * Get a consistent cache file path for a given view.
     */
    protected static function getCacheFilePath(string $view): string
    {
        // Generate a consistent cache filename based on the view name (e.g., view hash)
        $cacheFileName = md5($view) . '.cache.php';  // Using md5 hash to create a unique but consistent name
    
        // Return the full cache file path
        return static::$cacheDir . DIRECTORY_SEPARATOR . $cacheFileName;
    }

    /**
     * Generate the view output by including the view file and passing the data.
     */
    protected static function generateViewOutput(string $view, array $data): string
    {
        $viewFile = static::getViewFilePath($view);

        // Start output buffering
        ob_start();
        extract($data);
        include $viewFile; // Safely include the view
        return ob_get_clean(); // Return the captured output
    }

    /**
     * Check if the cache file is valid and hasn't expired.
     */
    protected static function isCacheValid(string $file, string $view): bool
    {
        $expiryTime = config('view.cache_expiry'); // Cache expiry time in seconds

        // Check if the cache file exists
        if (file_exists($file)) {
            // Check if the cache file has expired
            $fileModificationTime = filemtime($file);
            if ((time() - $fileModificationTime) < $expiryTime) {
                // Now, check if the view file itself has been updated
                $viewFile = static::getViewFilePath($view);

                // If the view file is newer than the cache, invalidate the cache
                if (filemtime($viewFile) > $fileModificationTime) {
                    return false; // Cache is invalidated
                }

                return true; // Cache is valid
            }
        }

        return false; // Cache is either invalid or expired
    }

    /**
     * Get the file path for the view.
     */
    protected static function getViewFilePath(string $view): string
    {
        $view = str_replace('.', DIRECTORY_SEPARATOR, $view);
        $path = config('view.path');
        $viewFile = $path . DIRECTORY_SEPARATOR . $view . '.tpl.php';

        // Ensure the view file exists
        if (!file_exists($viewFile)) {
            throw new \RuntimeException("View file '{$viewFile}' not found.");
        }

        return $viewFile;
    }

    /**
     * Render a view with optional data.
     */
    public static function make(string $view, ?array $data = []): string
    {
        // If caching is enabled, attempt to cache the view output
        if (config('view.cache')) {
            static::cache(); // Ensure cache directory is set up

            // Get cache file path for the view
            $cacheFile = static::getCacheFilePath($view);

            // Return cached output if it's valid (not expired) and the view hasn't been updated
            if (static::isCacheValid($cacheFile, $view)) {
                return file_get_contents($cacheFile); // Return cached content
            }

            // Generate the view output and cache it
            $output = static::generateViewOutput($view, $data);
            file_put_contents($cacheFile, $output); // Save output to cache
            return $output;
        }

        // If caching is not enabled, render the view directly without caching
        return static::generateViewOutput($view, $data);
    }
	
	 /**
     * A static helper function to make views simpler to load
     *
     * @param string $view The view name
     * @param array|null $data Optional data to pass to the view
     * @return void
     */
    public static function render($view, ?array $data = [])
    {
        return self::make($view, $data);
    }
}
