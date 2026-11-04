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
    private const int MAX_CONTEXT_DEPTH = 8;

    private const int MAX_STRING_LENGTH = 4096;

    private const int MAX_CONTEXT_ITEMS = 100;

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
        array $context = [],
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
            'message' => $this->normalizeMessage($message),
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

    private function normalizeMessage(string $message): string
    {
        return $this->redactString(
            substr($message, 0, self::MAX_STRING_LENGTH)
        );
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    private function sanitize(
        array $context,
        int $depth = 0,
    ): array {
        if ($depth >= self::MAX_CONTEXT_DEPTH) {
            return ['_truncated' => '[MAX_DEPTH]'];
        }

        $result = [];
        $count = 0;

        foreach ($context as $key => $value) {
            if ($count >= self::MAX_CONTEXT_ITEMS) {
                $result['_truncated'] = '[MAX_ITEMS]';

                break;
            }

            ++$count;

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
                $result[$key] = $this->sanitize(
                    $value,
                    $depth + 1
                );

                continue;
            }

            if (is_string($value)) {
                $result[$key] = $this->redactString(
                    substr(
                        $value,
                        0,
                        self::MAX_STRING_LENGTH
                    )
                );

                continue;
            }

            if (is_float($value) && !is_finite($value)) {
                $result[$key] = '[NON_FINITE]';

                continue;
            }

            if (
                is_int($value)
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

    private function redactString(string $value): string
    {
        $patterns = [
            '/Bearer\s+[A-Za-z0-9._~+\/=-]+/i',
            '/(password|passwd|secret|token|api[_-]?key)\s*[:=]\s*\S+/i',
        ];

        $redacted = preg_replace(
            $patterns,
            '[REDACTED]',
            $value
        );

        if ($redacted === null) {
            return '[REDACTION_ERROR]';
        }

        return $redacted;
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
