<?php
declare(strict_types=1);

namespace Careminate\Http\Responses;

use InvalidArgumentException;
use JsonException;

class Response
{
    // Common HTTP status codes
    public const HTTP_CONTINUE = 100;
    public const HTTP_OK = 200;
    public const HTTP_CREATED = 201;
    public const HTTP_NO_CONTENT = 204;
    public const HTTP_MOVED_PERMANENTLY = 301;
    public const HTTP_FOUND = 302;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_FORBIDDEN = 403;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_METHOD_NOT_ALLOWED = 405;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;
    public const HTTP_NOT_IMPLEMENTED = 501;

    private string $content;
    private int $status;
    private array $headers = [];
    private bool $headersSent = false;

    public function __construct(string $content = '',int $status = self::HTTP_OK,array $headers = []) {
        $this->content = $content;
        $this->setStatus($status);
        $this->setHeaders($headers);
    }

    /**
     * Set a response header (case-insensitive)
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }

    /**
     * Get a header value (case-insensitive)
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Merge multiple headers (case-insensitive)
     */
    public function setHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->setHeader((string)$name, (string)$value);
        }
        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @throws InvalidArgumentException for invalid status codes
     */
    public function setStatus(int $status): self
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException("Invalid HTTP status code: $status");
        }
        $this->status = $status;
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Send headers and content
     */
    public function send(): void
    {
        if ($this->headersSent) {
            return;
        }

        $this->sendHeaders();
        $this->sendContent();
        $this->headersSent = true;
    }

    /**
     * Create JSON response with proper error handling
     * 
     * @throws JsonException When encoding fails
     */
    public static function json(
        mixed $data,
        int $status = self::HTTP_OK,
        array $headers = [],
        int $options = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT,
        int $depth = 512
    ): self {
        $headers['Content-Type'] = 'application/json; charset=UTF-8';
        
        $json = json_encode($data, $options | JSON_THROW_ON_ERROR, $depth);
        
        return new self($json, $status, $headers);
    }

    public static function html(string $html, int $status = self::HTTP_OK, array $headers = []): self
    {
        $headers['Content-Type'] = 'text/html; charset=UTF-8';
        return new self($html, $status, $headers);
    }

    public static function redirect(string $url, int $status = self::HTTP_FOUND, array $headers = []): self
    {
        $headers['Location'] = $url;
        return new self('', $status, $headers);
    }

    public static function xml(string $xml, int $status = self::HTTP_OK, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/xml; charset=UTF-8';
        return new self($xml, $status, $headers);
    }

    /**
     * Quick access methods for common statuses
     */
    public static function notFound(string $message = 'Not Found'): self
    {
        return new self($message, self::HTTP_NOT_FOUND);
    }

    public static function serverError(string $message = 'Server Error'): self
    {
        return new self($message, self::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($this->status);
        
        foreach ($this->headers as $name => $value) {
            header("$name: $value", true);
        }
    }

    protected function sendContent(): void
    {
        echo $this->content;
    }
}
