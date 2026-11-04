<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Config;

use Careminate\Config\Configuration;
use Careminate\Config\ConfigurationValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigurationValidatorTest extends TestCase
{
    public function testValidConfigurationPasses(): void
    {
        $configuration = new Configuration([
            'app' => [
                'name' => 'Careminate',
                'debug' => false,
                'port' => 8080,
                'features' => ['routing'],
            ],
        ]);

        new ConfigurationValidator()->validate(
            $configuration,
            [
                'app.name' => 'non-empty-string',
                'app.debug' => 'boolean',
                'app.port' => 'integer',
                'app.features' => 'array',
            ]
        );

        self::assertSame('Careminate', $configuration->string('app.name'));
        self::assertFalse($configuration->boolean('app.debug'));
        self::assertSame(8080, $configuration->integer('app.port'));
        self::assertSame(['routing'], $configuration->array('app.features'));
    }


    public function testMissingRequiredKeyFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Required configuration key "app.name" is missing.'
        );

        new ConfigurationValidator()->validate(
            new Configuration(),
            ['app.name' => 'string']
        );
    }

    public function testWrongTypeFails(): void
    {
        $configuration = new Configuration([
            'app' => ['debug' => 'false'],
        ]);

        $this->expectException(InvalidArgumentException::class);

        new ConfigurationValidator()->validate(
            $configuration,
            ['app.debug' => 'boolean']
        );
    }

    public function testEmptyStringFailsNonEmptyRule(): void
    {
        $configuration = new Configuration([
            'app' => ['name' => '  '],
        ]);

        $this->expectException(InvalidArgumentException::class);

        new ConfigurationValidator()->validate(
            $configuration,
            ['app.name' => 'non-empty-string']
        );
    }

    public function testNullDoesNotSatisfyStringRule(): void
    {
        $configuration = new Configuration([
            'app' => ['name' => null],
        ]);

        $this->expectException(InvalidArgumentException::class);

        new ConfigurationValidator()->validate(
            $configuration,
            ['app.name' => 'string']
        );
    }

    public function testErrorDoesNotRevealSecretValue(): void
    {
        $secret = 'sensitive-value-123';

        $configuration = new Configuration([
            'app' => ['secret' => $secret],
        ]);

        try {
            new ConfigurationValidator()->validate(
                $configuration,
                ['app.secret' => 'integer']
            );

            self::fail('Expected validation failure.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString(
                $secret,
                $exception->getMessage()
            );
        }
    }
}
