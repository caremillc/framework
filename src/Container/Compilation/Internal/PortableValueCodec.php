<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation\Internal;

use Careminate\Container\Compilation\Exception\PortableValueException;
use JsonException;

/**
 * @internal
 */
final class PortableValueCodec
{
    private const int MAX_DEPTH = 32;

    private const int MAX_NODES = 10000;

    private const int MAX_BYTES = 1048576;

    public function encode(mixed $value): string
    {
        $nodes = 0;
        $stringBytes = 0;

        $node = $this->encodeNode($value, 0, $nodes, $stringBytes);

        try {
            $encoded = json_encode(
                $node,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                256,
            );
        } catch (JsonException $previous) {
            throw new PortableValueException(
                message: 'The portable value could not be encoded.',
                previous: $previous,
            );
        }

        $this->checkBytes(strlen($encoded));

        return $encoded;
    }

    public function decode(string $encoded): mixed
    {
        $this->checkBytes(strlen($encoded));

        try {
            $node = json_decode(
                $encoded,
                associative: false,
                depth: 256,
                flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (JsonException $previous) {
            throw new PortableValueException(
                message: 'The portable value contains invalid JSON.',
                previous: $previous,
            );
        }

        $nodes = 0;

        return $this->decodeNode($node, 0, $nodes);
    }

    /**
     * @return array{string, mixed}
     */
    private function encodeNode(
        mixed $value,
        int $depth,
        int &$nodes,
        int &$stringBytes,
    ): array {
        $this->visit($depth, $nodes);

        if ($value === null) {
            return ['null', null];
        }

        if (is_bool($value)) {
            return ['bool', $value];
        }

        if (is_int($value)) {
            return ['int', $value];
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new PortableValueException(
                    'Non-finite floats are not portable values.',
                );
            }

            return ['float', bin2hex(pack('E', $value))];
        }

        if (is_string($value)) {
            $length = strlen($value);

            if ($length > self::MAX_BYTES - $stringBytes) {
                throw new PortableValueException(
                    'The portable value exceeds its string byte limit.',
                );
            }

            $stringBytes += $length;

            return ['string', base64_encode($value)];
        }

        if (!is_array($value)) {
            throw new PortableValueException(
                'The value contains an unsupported type.',
            );
        }

        $entries = [];

        foreach ($value as $key => $entry) {
            $entries[] = [
                $this->encodeNode($key, $depth + 1, $nodes, $stringBytes),
                $this->encodeNode($entry, $depth + 1, $nodes, $stringBytes),
            ];
        }

        return ['array', $entries];
    }

    private function decodeNode(
        mixed $node,
        int $depth,
        int &$nodes,
    ): mixed {
        $this->visit($depth, $nodes);

        if (
            !is_array($node)
            || !array_is_list($node)
            || count($node) !== 2
            || !is_string($node[0])
        ) {
            throw new PortableValueException(
                'The portable value has an invalid node structure.',
            );
        }

        [$type, $value] = $node;

        return match ($type) {
            'null' => $value === null
                ? null
                : throw new PortableValueException(
                    'The null node has an invalid payload.',
                ),
            'bool' => is_bool($value)
                ? $value
                : throw new PortableValueException(
                    'The boolean node has an invalid payload.',
                ),
            'int' => is_int($value)
                ? $value
                : throw new PortableValueException(
                    'The integer node has an invalid or out-of-range payload.',
                ),
            'float' => $this->decodeFloat($value),
            'string' => $this->decodeString($value),
            'array' => $this->decodeArray($value, $depth, $nodes),
            default => throw new PortableValueException(
                'The portable value contains an unknown node type.',
            ),
        };
    }

    private function decodeFloat(mixed $value): float
    {
        if (
            !is_string($value)
            || preg_match('/\A[0-9a-f]{16}\z/', $value) !== 1
        ) {
            throw new PortableValueException(
                'The float node has an invalid payload.',
            );
        }

        $bytes = hex2bin($value);

        if ($bytes === false) {
            throw new PortableValueException(
                'The float node could not be decoded.',
            );
        }

        $unpacked = unpack('Evalue', $bytes);
        $number = $unpacked === false ? null : ($unpacked['value'] ?? null);

        if (!is_float($number) || !is_finite($number)) {
            throw new PortableValueException(
                'The float node must contain a finite value.',
            );
        }

        return $number;
    }

    private function decodeString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new PortableValueException(
                'The string node has an invalid payload.',
            );
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false || base64_encode($decoded) !== $value) {
            throw new PortableValueException(
                'The string node must contain canonical base64.',
            );
        }

        return $decoded;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeArray(
        mixed $entries,
        int $depth,
        int &$nodes,
    ): array {
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new PortableValueException(
                'The array node must contain an entry list.',
            );
        }

        $result = [];

        foreach ($entries as $entry) {
            if (
                !is_array($entry)
                || !array_is_list($entry)
                || count($entry) !== 2
            ) {
                throw new PortableValueException(
                    'The array node contains an invalid entry.',
                );
            }

            $key = $this->decodeNode($entry[0], $depth + 1, $nodes);

            if (!is_int($key) && !is_string($key)) {
                throw new PortableValueException(
                    'An array key must be an integer or string.',
                );
            }

            // Reject encoded string keys that PHP would coerce to integers.
            $probe = [$key => true];

            if (array_key_first($probe) !== $key) {
                throw new PortableValueException(
                    'An array key would change type during decoding.',
                );
            }

            if (array_key_exists($key, $result)) {
                throw new PortableValueException(
                    'The array node contains a duplicate key.',
                );
            }

            $result[$key] = $this->decodeNode(
                $entry[1],
                $depth + 1,
                $nodes,
            );
        }

        return $result;
    }

    private function visit(int $depth, int &$nodes): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new PortableValueException(
                'The portable value exceeds its nesting limit.',
            );
        }

        ++$nodes;

        if ($nodes > self::MAX_NODES) {
            throw new PortableValueException(
                'The portable value exceeds its node limit.',
            );
        }
    }

    private function checkBytes(int $bytes): void
    {
        if ($bytes > self::MAX_BYTES) {
            throw new PortableValueException(
                'The portable value exceeds its encoded byte limit.',
            );
        }
    }
}
