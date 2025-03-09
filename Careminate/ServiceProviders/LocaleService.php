<?php 
namespace Careminate\ServiceProviders;

use Careminate\FrameworkSetting;

class LocaleService
{
    /**
     * Set the locale for the application.
     *
     * @param string $locale
     * @return void
     */
    public function setLocale(string $locale): void
    {
        FrameworkSetting::setLocale($locale);
    }

    /**
     * Get the current locale for the application.
     *
     * @return string
     */
    public function getLocale(): string
    {
        return FrameworkSetting::getLocale();
    }
}
