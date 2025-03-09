<?php
namespace Careminate\Sessions;

use Careminate\Hashes\Hash;

class Session implements SessionInterface
{
    public function __construct(){}
 
    public static function start(){
		if(session_status() === PHP_SESSION_NONE){
			$handler = new SessionHandler(config('session.session_save_path'),config('session.session_prefix'));
			$handler->gc(config('session.expiration_timeout'));
			session_set_save_handler($handler,true);
			session_name(config('session.session_prefix'));
			session_start();
		}
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
        static::start();
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
        static::start();
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
        static::start();
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
        static::start();
        return isset($_SESSION[$key]) ? Hash::decrypt($_SESSION[$key]) : $key;
    }

    public static function forget(string $key): void
    {
        static::start();
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
        static::start();
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
