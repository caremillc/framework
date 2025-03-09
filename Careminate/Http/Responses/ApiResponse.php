<?php 
namespace Careminate\Http\Responses;

class ApiResponse 
{
    // HTTP Status Codes
    public const HTTP_OK = 200;
    
    private ?string $content;
    private int $status;
    private array $headers;

    // Constructor to initialize content, status, and headers with default values
    public function __construct(
        ?string $content = '',
        int $status = self::HTTP_OK,
        array $headers = []
    ) {
        $this->content = $content;
        $this->status = $status;
        $this->headers = $headers;
    }

    public static function json($data, $status = 200)
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }

    public static function error($message, $status = 400)
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode(['error' => $message]);
        exit;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function send(): void
    {
        // Set HTTP status code
        http_response_code($this->status);

        // Set all the response headers
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        // Output the content
        echo $this->content;
    }
}
