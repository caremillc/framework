<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use PHPUnit\Framework\TestCase;

interface ContextLogger
{
    public function channel(): string;
}

final class AuditContextLogger implements ContextLogger
{
    public function channel(): string
    {
        return 'audit';
    }
}

final class ApplicationContextLogger implements ContextLogger
{
    public function channel(): string
    {
        return 'application';
    }
}

final class AuditService
{
    public function __construct(
        public readonly ContextLogger $logger,
        public readonly string $connectionName,
    ) {
    }
}

final class ApplicationService
{
    public function __construct(public readonly ContextLogger $logger)
    {
    }
}

final class ContextualBindingTest extends TestCase
{
    public function testConsumerReceivesContextSpecificImplementation(): void
    {
        $container = new Container();

        $container->bind(ContextLogger::class, ApplicationContextLogger::class);

        $container
            ->when(AuditService::class)
            ->needs(ContextLogger::class)
            ->give(AuditContextLogger::class);

        $container
            ->when(AuditService::class)
            ->needs('$connectionName')
            ->give('audit-database');

        $audit = $container->get(AuditService::class);
        $application = $container->get(ApplicationService::class);

        self::assertInstanceOf(AuditContextLogger::class, $audit->logger);
        self::assertSame('audit-database', $audit->connectionName);
        self::assertInstanceOf(
            ApplicationContextLogger::class,
            $application->logger,
        );
    }

    public function testPrimitiveBindingCanBeProducedByFactory(): void
    {
        $container = new Container();

        $container
            ->when(AuditService::class)
            ->needs(ContextLogger::class)
            ->give(AuditContextLogger::class);

        $container
            ->when(AuditService::class)
            ->needs('$connectionName')
            ->give(static fn (): string => 'generated-connection');

        $service = $container->get(AuditService::class);

        self::assertSame('generated-connection', $service->connectionName);
    }
}
