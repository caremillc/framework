<?php
namespace Careminate\Sessions;

interface SessionInterface
{
    /**
     * Make a session key with an optional value.
     *
     * @param string $key The session key.
     * @param mixed $value The value to store in the session.
     * @return mixed
     */
    public static function make(string $key, mixed $value = null): mixed;

    /**
     * Check if a session key exists.
     *
     * @param string $key The session key to check.
     * @return bool
     */
    public static function has(string $key): bool;

    /**
     * Set a session value temporarily (flash session).
     *
     * @param string $key The session key.
     * @param mixed $value The value to store in the session.
     * @return mixed
     */
    public static function flash(string $key, mixed $value = null): mixed;

    /**
     * Get the value from a session key.
     *
     * @param string $key The session key.
     * @return mixed
     */
    public static function get(string $key): mixed;

    /**
     * Remove a session key.
     *
     * @param string $key The session key to remove.
     * @return void
     */
    public static function forget(string $key): void;

    /**
     * Destroy the entire session.
     *
     * @return void
     */
    public static function destroy(): void;
}
