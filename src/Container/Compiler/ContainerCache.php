<?php

declare(strict_types=1);

namespace Careminate\Container\Compiler;

use Careminate\Container\Container;
use Careminate\Exception\Container\ContainerCacheException;
use Throwable;

final class ContainerCache
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function write(string $path, array $snapshot): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) || !is_writable($directory)) {
            throw ContainerCacheException::directoryUnavailable($directory);
        }

        $content = sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nreturn %s;\n",
            var_export($snapshot, true),
        );

        $written = file_put_contents($path, $content, LOCK_EX);

        if ($written === false || $written !== strlen($content)) {
            throw ContainerCacheException::writeFailed($path);
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }

    public function load(string $path): Container
    {
        if (!is_file($path) || !is_readable($path)) {
            throw ContainerCacheException::readFailed($path);
        }

        try {
            $snapshot = (static function (string $cacheFile): mixed {
                return require $cacheFile;
            })($path);
        } catch (Throwable $exception) {
            throw ContainerCacheException::readFailed($path, $exception);
        }

        if (!is_array($snapshot)) {
            throw ContainerCacheException::readFailed($path);
        }

        return Container::fromSnapshot($snapshot);
    }
}
