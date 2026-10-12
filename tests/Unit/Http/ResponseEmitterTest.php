<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\Headers;
use Flint\Http\Response;
use Flint\Http\ResponseEmitter;
use Flint\Http\ResponseFactory;
use Flint\Tests\Fixtures\Http\RecordingResponseOutput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResponseEmitterTest extends TestCase
{
    public function testEmitsPreparedHeadersStatusAndBodyInOrder(): void
    {
        $output = new RecordingResponseOutput();
        $emitter = new ResponseEmitter($output);

        $emitter->emit(ResponseFactory::text('Hello', status: 201));

        self::assertSame(
            [
                ['begin'],
                ['header', 'content-type', 'text/plain; charset=utf-8', true],
                ['header', 'content-length', '5', true],
                ['status', 201],
                ['body', 'Hello'],
            ],
            $output->events,
        );
    }

    public function testHeadPreservesLengthWithoutWritingBody(): void
    {
        $output = new RecordingResponseOutput();
        $response = ResponseFactory::text('Hello');

        new ResponseEmitter($output)->emit(
            $response,
            headRequest: true,
        );

        self::assertSame(
            [
                ['begin'],
                ['header', 'content-type', 'text/plain; charset=utf-8', true],
                ['header', 'content-length', '5', true],
                ['status', 200],
            ],
            $output->events,
        );

        self::assertSame('Hello', $response->body());
        self::assertFalse($response->headers()->has('Content-Length'));
    }

    public function testRepeatedCookiesAreWrittenSeparately(): void
    {
        $output = new RecordingResponseOutput();

        $response = new Response(
            headers: new Headers()->with(
                'Set-Cookie',
                'first=1',
                'second=2',
            ),
        );

        new ResponseEmitter($output)->emit($response);

        self::assertSame(
            [
                ['begin'],
                ['header', 'set-cookie', 'first=1', true],
                ['header', 'set-cookie', 'second=2', false],
                ['header', 'content-length', '0', true],
                ['status', 200],
            ],
            $output->events,
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function bodylessStatuses(): iterable
    {
        yield 'no content' => [204];
        yield 'reset content' => [205];
        yield 'not modified' => [304];
    }

    #[DataProvider('bodylessStatuses')]
    public function testBodylessResponseDoesNotWriteBody(int $status): void
    {
        $output = new RecordingResponseOutput();

        new ResponseEmitter($output)->emit(
            new Response(status: $status),
        );

        $expected = [['begin']];

        if ($status === 205) {
            $expected[] = ['header', 'content-length', '0', true];
        }

        $expected[] = ['status', $status];

        self::assertSame($expected, $output->events);
    }

    public function testPreparationFailureDoesNotTouchOutput(): void
    {
        $output = new RecordingResponseOutput();

        $response = new Response(
            body: 'Hello',
            headers: new Headers()->with('Transfer-Encoding', 'chunked'),
        );

        try {
            new ResponseEmitter($output)->emit($response);

            self::fail('Unsupported transfer encoding must fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Transfer-Encoding is not supported for buffered responses.',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $output->events);
    }

    public function testBeginFailurePreventsFurtherWrites(): void
    {
        $failure = new RuntimeException('Output is unavailable.');
        $output = new RecordingResponseOutput($failure);

        try {
            new ResponseEmitter($output)->emit(
                ResponseFactory::text('Hello'),
            );

            self::fail('Output initialization must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame([], $output->events);
    }

    public function testZeroStringIsWrittenAsContent(): void
    {
        $output = new RecordingResponseOutput();

        new ResponseEmitter($output)->emit(
            new Response(body: '0'),
        );

        self::assertSame(
            [
                ['begin'],
                ['header', 'content-length', '1', true],
                ['status', 200],
                ['body', '0'],
            ],
            $output->events,
        );
    }
}
