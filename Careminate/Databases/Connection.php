<?php 

namespace Careminate\Databases;

use PDO;
use PDOException;

class Connection
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (!self::$instance) {
            $config = config('database');
            
            try {
                self::$instance = new PDO(
                    "{$config['driver']}:host={$config['host']};dbname={$config['database']}",
                    $config['username'],
                    $config['password'],
                    $config['options']
                );
            } catch (PDOException $e) {
                throw new \RuntimeException("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$instance;
    }
}
 
