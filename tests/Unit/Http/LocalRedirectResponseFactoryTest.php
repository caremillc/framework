<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\LocalRedirectResponseFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalRedirectResponseFactoryTest extends TestCase
{
    public function testDefaultRedirectUsesSeeOther(): void
    {
        $response = LocalRedirectResponseFactory::create('/health');

        self::assertSame(303, $response->status());
        self::assertSame('', $response->body());
        self::assertSame(
            ['/health'],
            $response->headers()->get('Location'),
        );

        self::assertFalse(
            $response->headers()->has('Content-Length'),
        );
    }

    public function testEncodedPathAndQueryArePreservedExactly(): void
    {
        $target = '/hello/Ada%20Lovelace?style=formal&source=a%26b';

        $response = LocalRedirectResponseFactory::create($target);

        self::assertSame(
            [$target],
            $response->headers()->get('Location'),
        );
    }

    #[DataProvider('supportedStatuses')]
    public function testSupportedRedirectStatusesArePreserved(int $status): void
    {
        $response = LocalRedirectResponseFactory::create(
            '/health',
            $status,
        );

        self::assertSame($status, $response->status());
        self::assertSame('', $response->body());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function supportedStatuses(): iterable
    {
        yield 'moved permanently' => [301];
        yield 'found' => [302];
        yield 'see other' => [303];
        yield 'temporary redirect' => [307];
        yield 'permanent redirect' => [308];
    }

    #[DataProvider('unsupportedStatuses')]
    public function testUnsupportedStatusesAreRejected(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);

        LocalRedirectResponseFactory::create('/health', $status);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unsupportedStatuses(): iterable
    {
        yield 'success' => [200];
        yield 'multiple choices' => [300];
        yield 'not modified' => [304];
        yield 'bad request' => [400];
    }

    #[DataProvider('invalidTargets')]
    public function testInvalidTargetsAreRejected(string $target): void
    {
        $this->expectException(InvalidArgumentException::class);

        LocalRedirectResponseFactory::create($target);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTargets(): iterable
    {
        yield 'empty' => [''];
        yield 'relative path' => ['health'];
        yield 'absolute URL' => ['https://example.test/health'];
        yield 'network reference' => ['//example.test/health'];
        yield 'backslash' => ['/\\example.test/health'];
        yield 'header injection' => ["/health\r\nX-Injected: yes"];
        yield 'fragment' => ['/health#section'];
        yield 'invalid percent escape' => ['/health%GG'];
        yield 'unencoded space' => ['/hello/Ada Lovelace'];
    }
}
