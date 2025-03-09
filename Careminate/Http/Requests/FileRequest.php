<?php 
namespace Careminate\Http\Requests;

class FileRequest
{
    protected static array $file;
    protected static string $name;
    protected static string $ext;

    /**
     * @param string $name
     * @param mixed|null $default
     * 
     * @return string
     */
    // public static function get(string $name, mixed $default = null): string
    // {
    //     return isset($_REQUEST[$name]) && !empty($_REQUEST[$name]) ? $_REQUEST[$name] : $default ?? '';
    // }

    // public static function all()
    // {
    //     return count($_REQUEST) > 0 ? $_REQUEST : new self;
    // }

    public static function file(string $name): object
    {
        if (isset($_FILES[$name])) {
            static::$file = $_FILES[$name];
            $file_info = explode('.', $_FILES[$name]['name']);
            static::$name = $file_info[0];
            static::$ext = end($file_info);
        }
        return new self;
    }

    /**
     * @return string
     */
    public static function ext(): string
    {
        return static::$ext;
    }

    /**
     * @return string
     */
    public static function name(string $name = null): string
    {
        return !empty($name) ? static::$name = $name : static::$name;
    }

    /**
     * @param string $to
     * 
     * @return bool
     */
    public static function store(string $to): bool|string
    {
        $from = static::$file;
        if (isset($from['tmp_name'])) {
            $to_path = '/' . ltrim($to, '/');
            $path = config('storage.storage_path') . $to_path;
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
            $file = $path . '/' . static::$name . '.' . static::$ext;
            move_uploaded_file($from['tmp_name'], $file);
            return ltrim($to_path . '/' . static::$name . '.' . static::$ext, '/');
        }
        return false;
    }
}
