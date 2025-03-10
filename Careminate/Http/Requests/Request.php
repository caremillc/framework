<?php 
namespace Careminate\Http\Requests;

use Careminate\Support\Arr;

class Request extends FileRequest
{
    private array $getParams;
    private array $postParams;
    private array $cookies;
    private array $files;
    private array $server;
    public readonly array $inputParams;
    public readonly string $rawInput;

    public function __construct(
        array $getParams = [],
        array $postParams = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        array $inputParams = [],
        string $rawInput = ''
    ) {
        $this->getParams = $getParams;
        $this->postParams = $postParams;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->server = $server;
        $this->inputParams = $inputParams;
        $this->rawInput = $rawInput;
    }

    /**
     * Create a Request instance from PHP superglobals
     */
    public static function createFromGlobals(): static
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isJson = stripos($contentType, 'application/json') !== false;
        $rawInput = file_get_contents('php://input');
        $inputParams = [];

        if ($requestMethod === 'POST' && $isJson) {
            $inputParams = json_decode($rawInput, true) ?? [];
        } elseif (!in_array($requestMethod, ['GET', 'POST'])) {
            if ($isJson) {
                $inputParams = json_decode($rawInput, true) ?? [];
            } else {
                parse_str($rawInput, $inputParams);
            }
        }

        return new static(
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES,
            $_SERVER,
            $inputParams,
            $rawInput
        );
    }

      // Assuming this method returns the full URI of the request
      public function getUri(): string
      {
          return $_SERVER['REQUEST_URI']; // Example, you might adjust this based on your framework's setup
      }

      
    /**
     * Get the HTTP request method (GET, POST, PUT, DELETE, etc.)
     */
    public function getMethod(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');

        // Handle method spoofing in POST requests
        if ($method === 'POST') {
            $spoofedMethod = strtoupper($this->postParams['_method'] ??
                $this->header('X-HTTP-Method-Override') ?? '');

            if (in_array($spoofedMethod, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoofedMethod;
            }
        }

        return $method;
    }


    /**
     * Retrieve the value of a specific HTTP header
     */
    public function header(string $name): ?string
    {
        $name = strtoupper(str_replace('-', '_', $name));
        $serverKey = match ($name) {
            'CONTENT_TYPE', 'CONTENT_LENGTH' => $name,
            default => 'HTTP_' . $name
        };
        return $this->server[$serverKey] ?? null;
    }

    /**
     * Get all headers from the request
     */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[substr($key, 5)] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    /**
     * Get the full URL of the request
     */
    public function fullUrl(): string
    {
        $scheme = 'http';
        if (($this->server['HTTPS'] ?? '') === 'on' ||
            ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            $scheme = 'https';
        }

        return $scheme .
            '://' .
            ($this->server['HTTP_HOST'] ?? '') .
            ($this->server['REQUEST_URI'] ?? '');
    }

    /**
     * Get the request path (without query string)
     */
    public function getPathInfo(): string
    {
        return parse_url($this->server['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    }

    /**
     * Retrieve a parameter from GET, POST, or input params
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getParams[$key] ??
            $this->postParams[$key] ??
            $this->inputParams[$key] ??
            $default;
    }

    /**
     * Check if a parameter exists in GET, POST, or input params
     */
    public function has(string $key): bool
    {
        return isset($this->getParams[$key]) ||
            isset($this->postParams[$key]) ||
            isset($this->inputParams[$key]);
    }

    /**
     * Get a cookie value
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Get a file uploaded in the request
     */
    // public function file(string $key): ?array
    // {
    //     return $this->files[$key] ?? null;
    // }

    /**
     * Check if a file is uploaded
     */
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]['tmp_name']) &&
            is_uploaded_file($this->files[$key]['tmp_name']);
    }

    /**
     * Get all uploaded files
     */
    public function allFiles(): array
    {
        return $this->files;
    }

    /**
     * Retrieve all request parameters (GET, POST, and input params)
     */
    public function all(): array
    {
        return array_merge($this->getParams, $this->postParams, $this->inputParams);
    }

    /**
     * Retrieve a POST or input parameter
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->postParams[$key] ?? $this->inputParams[$key] ?? $default;
    }

    /**
     * Retrieve a GET parameter
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->getParams[$key] ?? $default;
    }

    /**
     * Retrieve a POST parameter
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->postParams[$key] ?? $default;
    }

    /**
     * Retrieve a server parameter
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * Get raw input data
     */
    public function getRawInput(): string
    {
        return $this->rawInput;
    }

    /**
     * Check if the request is JSON
     */
    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type') ?? '', '/json');
    }

    /**
     * Check if the request expects JSON
     */
    public function wantsJson(): bool
    {
        $accept = $this->header('Accept') ?? '';
        return str_contains($accept, '/json') || str_contains($accept, '+json');
    }

    /**
     * Check if the request is secure (HTTPS)
     */
    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on' ||
            ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * Retrieve the client's IP address
     */
    public function ip(): string
    {
        return $this->server['HTTP_CLIENT_IP'] ??
            $this->server['HTTP_X_FORWARDED_FOR'] ??
            $this->server['REMOTE_ADDR'] ?? '';
    }

    /**
     * Retrieve the user agent of the request
     */
    public function userAgent(): ?string
    {
        return $this->header('User-Agent');
    }

    /**
     * Retrieve only the specified parameters
     */
    public function only(array|string $keys): array
    {
        return Arr::only($this->all(), is_string($keys) ? func_get_args() : $keys);
    }

    /**
     * Retrieve all parameters except the specified ones
     */
    public function except(array|string $keys): array
    {
        return Arr::except($this->all(), is_string($keys) ? func_get_args() : $keys);
    }

    /**
     * Check if the request method is of the specified type (e.g., POST, GET)
     */
    public function isMethod(string $method): bool
    {
        return strtoupper($method) === $this->getMethod();
    }

    // HTTP method shortcuts
    public function isPost(): bool { return $this->isMethod('POST'); }
    public function isGet(): bool { return $this->isMethod('GET'); }
    public function isPut(): bool { return $this->isMethod('PUT'); }
    public function isPatch(): bool { return $this->isMethod('PATCH'); }
    public function isDelete(): bool { return $this->isMethod('DELETE'); }
    public function isHead(): bool { return $this->isMethod('HEAD'); }
    public function isOptions(): bool { return $this->isMethod('OPTIONS'); }

    
}
