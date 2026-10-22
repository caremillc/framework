<?php

declare(strict_types=1);

namespace Careminate\Logging;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class JsonLogger
{
    /**
     * @var resource
     */
    private $stream;

    /**
     * @param resource $stream
     */
    public function __construct($stream)
    {
        if (
            !is_resource($stream)
            || get_resource_type($stream) !== 'stream'
        ) {
            throw new InvalidArgumentException(
                'Logger requires a writable stream resource.'
            );
        }

        $metadata = stream_get_meta_data($stream);
        $mode = $metadata['mode'];

        if (strpbrk($mode, 'waxc+') === false) {
            throw new InvalidArgumentException(
                'Logger stream must be writable.'
            );
        }

        $this->stream = $stream;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(
        LogLevel $level,
        string $message,
        array $context = []
    ): void {
        if (!is_resource($this->stream)) {
            throw new RuntimeException(
                'Logger stream is no longer available.'
            );
        }

        $record = [
            'timestamp' => new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC')
            )->format('Y-m-d\TH:i:s.u\Z'),
            'level' => $level->value,
            'message' => $message,
            'context' => $this->sanitize($context),
        ];

        try {
            $json = json_encode(
                $record,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Unable to encode log record.',
                previous: $exception
            );
        }

        $payload = $json . "\n";
        $length = strlen($payload);
        $written = 0;

        while ($written < $length) {
            if (!is_resource($this->stream)) {
                throw new RuntimeException(
                    'Logger stream is no longer available.'
                );
            }

            $result = @fwrite(
                $this->stream,
                substr($payload, $written)
            );

            if ($result === false || $result === 0) {
                throw new RuntimeException(
                    'Unable to write log record.'
                );
            }

            $written += $result;
        }
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    private function sanitize(array $context): array
    {
        $result = [];

        foreach ($context as $key => $value) {
            if (
                is_string($key)
                && $this->isSensitiveKey($key)
            ) {
                $result[$key] = '[REDACTED]';

                continue;
            }

            if ($value instanceof Throwable) {
                $result[$key] = [
                    'type' => $value::class,
                    'message' => '[REDACTED]',
                ];

                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->sanitize($value);

                continue;
            }

            if (
                is_string($value)
                || is_int($value)
                || is_float($value)
                || is_bool($value)
                || $value === null
            ) {
                $result[$key] = $value;

                continue;
            }

            $result[$key] = '[UNSUPPORTED]';
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(
            str_replace(['-', '_', '.'], '', $key)
        );

        foreach (
            [
                'password',
                'passwd',
                'secret',
                'token',
                'authorization',
                'apikey',
                'cookie',
                'credential',
                'privatekey',
            ] as $sensitive
        ) {
            if (str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
