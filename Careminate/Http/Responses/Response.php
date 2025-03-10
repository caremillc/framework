<?php
namespace Careminate\Http\Responses;

class Response
{
    // HTTP Status Codes
    public const HTTP_OK = 200;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;
    public const HTTP_FORBIDDEN = 403;
    public const HTTP_FOUND = 302;

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

    /**
     * Set a header for the response.
     *
     * @param string $name
     * @param string $value
     */
    public function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    /**
     * Get a specific header from the response.
     *
     * @param string $header
     * @return mixed
     */
    public function getHeader(string $header): mixed
    {
        return $this->headers[$header] ?? null;
    }

    /**
     * Set multiple headers at once.
     *
     * @param array $headers
     */
    public function setHeaders(array $headers): void
    {
        $this->headers = array_merge($this->headers, $headers);
    }

    /**
     * Get all headers of the response.
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Set the HTTP response content.
     *
     * @param string $content
     */
    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    /**
     * Get the response content.
     *
     * @return string
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * Set the HTTP status code for the response.
     *
     * @param int $status
     */
    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    /**
     * Get the response status code.
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Send the headers and content of the response.
     */
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

    /**
     * Sends a response with the given content and status code.
     *
     * @param string $content    The content to send in the response.
     * @param int    $statusCode The HTTP status code (default: 200).
     */
    public static function sendResponse(string $content, int $statusCode = self::HTTP_OK): void
    {
        http_response_code($statusCode);
        echo $content;
        exit;
    }

    /**
     * Redirect the user back to the previous page.
     *
     * @return void
     * @throws \RuntimeException
     */
    public function back(): void
    {
        if (!headers_sent()) {
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit; // Stop further processing after redirect
        }

        // Handle error if headers are already sent
        throw new \RuntimeException('Headers already sent, cannot redirect.');
    }

    /**
     * Create a new Response with JSON content.
     *
     * @param mixed $data
     * @param int $status
     * @param array $headers
     * @return static
     */
    public static function json(mixed $data, int $status = self::HTTP_OK, array $headers = []): static
    {
        $headers['Content-Type'] = 'application/json';
        $content = json_encode($data);
        return new static($content, $status, $headers);
    }

    /**
     * Create a new Response with HTML content.
     *
     * @param string $html
     * @param int $status
     * @param array $headers
     * @return static
     */
    public static function html(string $html, int $status = self::HTTP_OK, array $headers = []): static
    {
        $headers['Content-Type'] = 'text/html; charset=UTF-8';
        return new static($html, $status, $headers);
    }

    /**
     * Create a new Response for a Redirect.
     *
     * @param string $url
     * @param int $status
     * @param array $headers
     * @return static
     */
    public static function redirect(string $url, int $status = self::HTTP_FOUND, array $headers = []): static
    {
        $headers['Location'] = $url;
        return new static('', $status, $headers);
    }

    /**
     * Create a new Response with XML content.
     *
     * @param string $xml
     * @param int $status
     * @param array $headers
     * @return static
     */
    public static function xml(string $xml, int $status = self::HTTP_OK, array $headers = []): static
    {
        $headers['Content-Type'] = 'application/xml; charset=UTF-8';
        return new static($xml, $status, $headers);
    }
}
