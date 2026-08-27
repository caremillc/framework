<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use Careminate\Application\ApplicationBuilder;
use Careminate\Application\ApplicationState;
use Careminate\Application\Bootstrap\BootstrapContext;
use Careminate\Contracts\Application\BootstrapperInterface;
use Careminate\Exception\Application\LifecycleException;
use PHPUnit\Framework\TestCase;

final class RegisterApplicationName implements BootstrapperInterface
{
    public function bootstrap(BootstrapContext $context): void
    {
        $context->container->instance('application.name', 'Caremi');
    }
}

final class ApplicationLifecycleTest extends TestCase
{
    public function testApplicationBootstrapsOnlyOnce(): void
    {
        $application = ApplicationBuilder::fromBasePath('/var/www/caremi')
            ->bootstrapper(new RegisterApplicationName())
            ->build();

        self::assertSame(ApplicationState::Created, $application->state());

        $application->bootstrap();
        $application->bootstrap();

        self::assertSame(
            ApplicationState::Bootstrapped,
            $application->state(),
        );

        self::assertSame(
            'Caremi',
            $application->container()->get('application.name'),
        );

        self::assertNotNull($application->bootstrappedAt());
    }

    public function testApplicationCanTerminateBeforeBootstrap(): void
    {
        $application = ApplicationBuilder::fromBasePath('/var/www/caremi')
            ->build();

        $application->terminate();

        self::assertSame(
            ApplicationState::Terminated,
            $application->state(),
        );
    }

    public function testBuilderCannotBuildTwice(): void
    {
        $builder = ApplicationBuilder::fromBasePath('/var/www/caremi');

        $builder->build();

        $this->expectException(LifecycleException::class);

        $builder->build();
    }
}
