<?php

declare(strict_types=1);

namespace Careminate\Application\Runtime;

use Careminate\Contracts\Application\RuntimeInterface;
use Careminate\Contracts\Container\ScopedContainerInterface;
use Careminate\Exception\InvalidArgumentException;
use Closure;
use JsonException;
use Stringable;
use Throwable;

final class HttpRuntime implements RuntimeInterface
{
    private readonly Closure $emitter;

    private bool $terminated = false;

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $request
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     * @param null|callable(RuntimeResult): int $emitter
     */
    public function __construct(
        private readonly array $server,
        private readonly array $query,
        private readonly array $request,
        private readonly array $cookies,
        private readonly array $files,
        ?callable $emitter = null,
    ) {
        $this->emitter = $emitter === null
            ? static function (RuntimeResult $result): int {
                if ($result->code < 100 || $result->code > 599) {
                    throw new InvalidArgumentException(
                        'An HTTP runtime result code must be between 100 and 599.',
                    );
                }

                http_response_code($result->code);

                $payload = $result->payload;

                if ($payload === null) {
                    return 0;
                }

                if (is_string($payload)) {
                    echo $payload;

                    return 0;
                }

                if ($payload instanceof Stringable) {
                    echo (string) $payload;

                    return 0;
                }

                try {
                    echo json_encode(
                        $payload,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                    );
                } catch (JsonException $exception) {
                    throw $exception;
                }

                return 0;
            }
            : Closure::fromCallable($emitter);
    }

    public static function fromGlobals(): self
    {
        return new self(
            $_SERVER,
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES,
        );
    }

    public function type(): RuntimeType
    {
        return RuntimeType::Http;
    }

    public function createContext(
        ScopedContainerInterface $scope,
    ): RuntimeContext {
        return new RuntimeContext(
            bin2hex(random_bytes(16)),
            RuntimeType::Http,
            microtime(true),
            [
                'server' => $this->server,
                'query' => $this->query,
                'request' => $this->request,
                'cookies' => $this->cookies,
                'files' => $this->files,
            ],
            [
                'sapi' => PHP_SAPI,
            ],
            $scope,
        );
    }

    public function emit(RuntimeResult $result): int
    {
        $emitter = $this->emitter;

        return $emitter($result);
    }

    public function terminate(?Throwable $failure): void
    {
        unset($failure);

        $this->terminated = true;
    }

    public function isTerminated(): bool
    {
        return $this->terminated;
    }
}
