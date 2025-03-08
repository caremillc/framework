<?php
namespace Careminate\Routing;

class Segment
{
    private static $uri;
    private static $segments;

    public static function uri(): string
    {
        if (self::$uri === null) {
            self::$uri = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        }
        return self::$uri;
    }

    public static function get(int $offset): string
    {
        return self::getSegments()[$offset] ?? '';
    }

    public static function all(): array
    {
        return self::getSegments();
    }

    private static function getSegments(): array
    {
        if (self::$segments === null) {
            self::$segments = explode('/', self::uri());
        }
        return self::$segments;
    }
}
