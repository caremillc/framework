<?php 
namespace Careminate;

use Careminate\Sessions\Session;

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

/**
     * change locale lang
     * @param string $locale
     * 
     * @return string
     */
    public static function setLocale(string $locale):string
    {
    
        Session::make('locale', $locale);
   
        return Session::get('locale');
    }

     /**
     * get current locale lang 
     * @return string
     */
    public static function getLocale(): string
    {
        return Session::has('locale') && !empty(Session::get('locale')) ?Session::get('locale'):config('app.locale');
    }
}
