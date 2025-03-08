<?php
namespace Careminate\Sessions;

use Careminate\Hashes\Hash;

class Session implements SessionInterface
{
    public function __construct()
    {
       // var_dump(config('app.name'));
       session_save_path(config('session.session_save_path'));
       ini_set('session.gc_probability', 1);
       session_start([
           'cookie_lifetime' => config('session.expiration_timeout'),
       ]);
       Session::make('user','welcome');

    }
    
    /**
     * make
     *
     * @param  mixed $key
     * @param  mixed $value
     * @return mixed
     */
    public static function make(string $key, mixed $value = null): mixed
    {
        if (! is_null($value)) {
            $_SESSION[$key] = Hash::encrypt($value);
        }
        return isset($_SESSION[$key]) ? Hash::decrypt($_SESSION[$key]) : '';
    }
    
    /**
     * has
     *
     * @param  mixed $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }
    
    /**
     * flash
     *
     * @param  mixed $key
     * @param  mixed $value
     * @return mixed
     */
    public static function flash(string $key, mixed $value = null): mixed
    {
        if (! is_null($value)) {
            $_SESSION[$key] = $value;
        }
        $session = isset($_SESSION[$key]) ? Hash::decrypt($_SESSION[$key]) : '';
        self::forget($key);
        return $session;
    }
    
    /**
     * get
     *
     * @param  mixed $key
     * @return mixed
     */
    public static function get(string $key): mixed
    {
        return isset($_SESSION[$key]) ? Hash::decrypt($_SESSION[$key]) : $key;
    }

    public static function forget(string $key): void
    {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    /**
     * destroy
     *
     * @return void
     */
    public static function destroy(): void
    {
        session_destroy();
    }
    
    /**
     * __destruct
     *
     * @return void
     */
    public function __destruct()
    {
        session_write_close();
    }

}
