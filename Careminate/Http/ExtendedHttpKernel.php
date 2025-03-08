<?php 
namespace Careminate\Http;

class ExtendedHttpKernel 
{
     // Define framework version and PHP version compatibility
     const FRAMEWORK_VERSION = '1.0.0';
     const REQUIRED_PHP_VERSION = '8.2';
     const MAINTENANCE_FILE = BASE_PATH . '/maintenance.flag'; // Path to maintenance file
 
     /**
      * Constructor
      * 
      * @throws \RuntimeException if PHP version is not compatible or app is in maintenance mode
      */
     public function __construct()
     {
         $this->checkPhpVersion();
         $this->checkMaintenanceMode();
     }
 
     /**
      * Checks if the current PHP version is compatible
      * 
      * @throws \RuntimeException if PHP version is not compatible
      */
     private function checkPhpVersion(): void
     {
         if (version_compare(PHP_VERSION, self::REQUIRED_PHP_VERSION, '<')) {
             throw new \RuntimeException(sprintf(
                 'This framework requires PHP version %s or higher. Current version: %s',
                 self::REQUIRED_PHP_VERSION,
                 PHP_VERSION
             ));
         }
     }
 
     /**
      * Checks if the application is in maintenance mode
      * 
      * @throws \RuntimeException if the application is in maintenance mode
      */
     private function checkMaintenanceMode(): void
     {
         if (file_exists(self::MAINTENANCE_FILE)) {
             http_response_code(503); // Set HTTP response code to 503 Service Unavailable
             throw new \RuntimeException('The application is currently in maintenance mode. Please try again later.');
         }
     }
 
}
