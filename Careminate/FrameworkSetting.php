<?php 
namespace Careminate;

class FrameworkSetting
{
   /**
     * Sets the default timezone for the application.
     *
     * @return void
     */
  
    public static function setTimeZone(): void
    {
        $timezone = config('app.timezone');
        if (!self::isValidTimezone($timezone)) {
            // Fall back to default timezone if invalid
            $timezone = config('app.fallback_timezone');
        }

        date_default_timezone_set($timezone);
    }


    /**
     * Gets the current timezone of the application.
     *
     * @return string
     */
    public static function getTimeZone(): string
    {
        return date_default_timezone_get();
    }


     /**
     * Checks if a timezone is valid.
     *
     * @param string $timezone
     * @return bool
     */
    private static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list());
    }


}
