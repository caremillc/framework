<?php

declare(strict_types=1);

namespace Careminate\Application\Runtime;

use Careminate\Contracts\Application\RuntimeInterface;
use Careminate\Contracts\Container\ScopedContainerInterface;
use Closure;
use JsonException;
use RuntimeException;
use Stringable;
use Throwable;

final class ConsoleRuntime implements RuntimeInterface
{
    private readonly Closure $standardOutput;

    private readonly Closure $errorOutput;

    private bool $terminated = false;

    /**
     * @param list<string>          $arguments
     * @param null|callable(string): void $standardOutput
     * @param null|callable(string): void $errorOutput
     */
    public function __construct(
        private readonly array $arguments,
        ?callable $standardOutput = null,
        ?callable $errorOutput = null,
    ) {
        $this->standardOutput = $standardOutput === null
            ? static function (string $message): void {
                if (fwrite(STDOUT, $message) === false) {
                    throw new RuntimeException(
                        'Unable to write console output.',
                    );
                }
            }
            : Closure::fromCallable($standardOutput);

        $this->errorOutput = $errorOutput === null
            ? static function (string $message): void {
                if (fwrite(STDERR, $message) === false) {
                    throw new RuntimeException(
                        'Unable to write console error output.',
                    );
                }
            }
            : Closure::fromCallable($errorOutput);
    }

    /**
     * @param list<string> $arguments
     */
    public static function fromArguments(array $arguments): self
    {
        return new self($arguments);
    }

    public function type(): RuntimeType
    {
        return RuntimeType::Console;
    }

    public function createContext(
        ScopedContainerInterface $scope,
    ): RuntimeContext {
        return new RuntimeContext(
            bin2hex(random_bytes(16)),
            RuntimeType::Console,
            microtime(true),
            [
                'arguments' => $this->arguments,
            ],
            [
                'sapi' => PHP_SAPI,
            ],
            $scope,
        );
    }

    /**
     * @throws JsonException
     */
    public function emit(RuntimeResult $result): int
    {
        $output = $this->normalizeOutput($result->payload);

        if ($output !== '') {
            $writer = $result->code === 0
                ? $this->standardOutput
                : $this->errorOutput;

            $writer($output);
        }

        return $result->code;
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

    /**
     * @throws JsonException
     */
    private function normalizeOutput(mixed $payload): string
    {
        if ($payload === null) {
            return '';
        }

        if (is_string($payload)) {
            return $payload;
        }

        if ($payload instanceof Stringable) {
            return (string) $payload;
        }

        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }
}
