<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Config;

use Careminate\Config\Environment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testParsesAssignmentsAndComments(): void
    {
        $values = Environment::parse(
            "# Comment\nAPP_NAME=Careminate\nAPP_DEBUG=false\n"
        );

        self::assertSame('Careminate', $values['APP_NAME']);
        self::assertSame('false', $values['APP_DEBUG']);
    }

    public function testParsesQuotedAndEmptyValues(): void
    {
        $values = Environment::parse(
            "APP_NAME=\"Careminate Framework\"\nEMPTY=\n"
        );

        self::assertSame('Careminate Framework', $values['APP_NAME']);
        self::assertSame('', $values['EMPTY']);
    }

    public function testPrecedenceIsDeterministic(): void
    {
        $environment = new Environment(
            ['APP_NAME' => 'default'],
            ['APP_NAME' => 'file'],
            ['APP_NAME' => 'external'],
            ['APP_NAME' => 'override']
        );

        self::assertSame('override', $environment->required('APP_NAME'));
    }

    public function testExternalOverridesFile(): void
    {
        $environment = new Environment(
            [],
            ['APP_NAME' => 'file'],
            ['APP_NAME' => 'external']
        );

        self::assertSame('external', $environment->required('APP_NAME'));
    }

    public function testMissingFileAllowsExternalEnvironment(): void
    {
        $environment = Environment::fromFile(
            __DIR__ . '/nonexistent.env',
            ['APP_NAME' => 'deployment']
        );

        self::assertSame('deployment', $environment->required('APP_NAME'));
    }

    public function testBooleanConversion(): void
    {
        $environment = new Environment(
            ['APP_DEBUG' => 'false', 'FEATURE_ENABLED' => 'yes']
        );

        self::assertFalse($environment->boolean('APP_DEBUG'));
        self::assertTrue($environment->boolean('FEATURE_ENABLED'));
    }

    public function testIntegerConversion(): void
    {
        $environment = new Environment(
            ['APP_PORT' => '8080']
        );

        self::assertSame(8080, $environment->integer('APP_PORT'));
    }

    public function testInvalidBooleanIsRejected(): void
    {
        $environment = new Environment(
            ['APP_DEBUG' => 'perhaps']
        );

        $this->expectException(InvalidArgumentException::class);

        $environment->boolean('APP_DEBUG');
    }

    public function testInvalidIntegerIsRejected(): void
    {
        $environment = new Environment(
            ['APP_PORT' => 'eight']
        );

        $this->expectException(InvalidArgumentException::class);

        $environment->integer('APP_PORT');
    }

    public function testMissingRequiredValueIsRejected(): void
    {
        $environment = new Environment();

        $this->expectException(InvalidArgumentException::class);

        $environment->required('APP_KEY');
    }

    public function testInvalidAssignmentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Environment::parse('INVALID LINE');
    }

    public function testUnterminatedQuoteIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Environment::parse('APP_NAME="unfinished');
    }
}
