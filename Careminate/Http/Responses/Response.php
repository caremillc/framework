<?php 
namespace Careminate\Http\Responses;

class Response 
{
    public static function send(string $content, int $statusCode = 200)
    {
        http_response_code($statusCode);
        echo $content;
        exit;
    }
}