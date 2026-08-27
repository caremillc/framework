<?php

declare(strict_types=1);

namespace Careminate\Container\Compiler;

use Careminate\Container\Container;
use Careminate\Exception\Container\ContainerCompilationException;

final readonly class ContainerCompiler
{
    public function __construct(
        private ContainerCache $cache = new ContainerCache(),
    ) {
    }

    public function compile(Container $container, string $path): void
    {
        $snapshot = $container->snapshot();

        foreach (array_keys($snapshot['definitions']) as $id) {
            $diagnostic = $container->diagnose($id);

            if (!$diagnostic->resolvable) {
                throw ContainerCompilationException::invalidSnapshot(
                    sprintf(
                        'Definition "%s" is not resolvable: %s',
                        $id,
                        $diagnostic->error ?? 'unknown reason',
                    ),
                );
            }
        }

        foreach ($snapshot['aliases'] as $alias => $target) {
            if (!$container->has($alias)) {
                throw ContainerCompilationException::invalidSnapshot(
                    sprintf(
                        'Alias "%s" targets missing entry "%s".',
                        $alias,
                        $target,
                    ),
                );
            }
        }

        foreach ($snapshot['tags'] as $tag => $ids) {
            foreach ($ids as $id) {
                if (!$container->has($id)) {
                    throw ContainerCompilationException::invalidSnapshot(
                        sprintf(
                            'Tag "%s" references missing entry "%s".',
                            $tag,
                            $id,
                        ),
                    );
                }
            }
        }

        $this->cache->write($path, $snapshot);
    }

    public function load(string $path): Container
    {
        return $this->cache->load($path);
    }
}
