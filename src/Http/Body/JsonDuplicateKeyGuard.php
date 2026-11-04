<?php

declare(strict_types=1);

namespace Careminate\Http\Body;

use Careminate\Http\Exception\HttpException;
use JsonException;
use LogicException;

/**
 * Reject repeated object member names in already-validated JSON.
 *
 * Call only after json_decode(..., JSON_THROW_ON_ERROR)
 * succeeds with the configured byte and depth limits.
 */
final class JsonDuplicateKeyGuard
{
    public static function assertUnique(string $json): void
    {
        $offset = 0;

        self::scanValue($json, $offset);
    }

    private static function scanValue(
        string $json,
        int &$offset,
    ): void {
        self::skipWhitespace($json, $offset);

        $character = $json[$offset];

        if ($character === '{') {
            self::scanObject($json, $offset);

            return;
        }

        if ($character === '[') {
            self::scanArray($json, $offset);

            return;
        }

        if ($character === '"') {
            self::readString($json, $offset);

            return;
        }

        $length = strlen($json);

        // Skip a validated number, boolean, or null token.
        while (
            $offset < $length
            && !str_contains(
                ",]} \t\r\n",
                $json[$offset],
            )
        ) {
            ++$offset;
        }
    }

    private static function scanObject(
        string $json,
        int &$offset,
    ): void {
        ++$offset; // Opening {

        self::skipWhitespace($json, $offset);

        if ($json[$offset] === '}') {
            ++$offset;

            return;
        }

        /** @var array<string, true> $seen */
        $seen = [];

        while (true) {
            $rawKey = self::readString(
                $json,
                $offset,
            );

            try {
                $key = json_decode(
                    $rawKey,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                throw new LogicException(
                    'JSON must be validated before duplicate-key scanning.',
                    0,
                    $exception,
                );
            }

            if (!is_string($key)) {
                throw new LogicException(
                    'Expected a JSON string property name.',
                );
            }

            // Prefix prevents PHP from converting numeric-looking
            // string keys into integer array indexes.
            $identity = "\0" . $key;

            if (isset($seen[$identity])) {
                throw new HttpException(
                    400,
                    'Duplicate JSON object member.',
                );
            }

            $seen[$identity] = true;

            self::skipWhitespace($json, $offset);

            ++$offset; // Colon

            self::scanValue($json, $offset);

            self::skipWhitespace($json, $offset);

            if ($json[$offset] === '}') {
                ++$offset;

                return;
            }

            ++$offset; // Comma

            self::skipWhitespace($json, $offset);
        }
    }

    private static function scanArray(
        string $json,
        int &$offset,
    ): void {
        ++$offset; // Opening [

        self::skipWhitespace($json, $offset);

        if ($json[$offset] === ']') {
            ++$offset;

            return;
        }

        while (true) {
            self::scanValue($json, $offset);

            self::skipWhitespace($json, $offset);

            if ($json[$offset] === ']') {
                ++$offset;

                return;
            }

            ++$offset; // Comma
        }
    }

    private static function readString(
        string $json,
        int &$offset,
    ): string {
        $start = $offset;
        $length = strlen($json);

        ++$offset; // Opening quote

        while ($offset < $length) {
            if ($json[$offset] === '\\') {
                // The next byte is escaped and cannot close
                // the current JSON string.
                $offset += 2;

                continue;
            }

            if ($json[$offset] === '"') {
                ++$offset;

                return substr(
                    $json,
                    $start,
                    $offset - $start,
                );
            }

            ++$offset;
        }

        throw new LogicException(
            'JSON must be validated before duplicate-key scanning.',
        );
    }

    private static function skipWhitespace(
        string $json,
        int &$offset,
    ): void {
        $length = strlen($json);

        while (
            $offset < $length
            && str_contains(
                " \t\r\n",
                $json[$offset],
            )
        ) {
            ++$offset;
        }
    }
}
