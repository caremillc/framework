<?php

declare (strict_types=1);

namespace Careminate\Tests\Unit\Http\Emission;

use Careminate\Http\Emission\BufferedResponseOutput;
use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Emission\ResponseOutput;
use Careminate\Http\Response;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResponseEmitterTest extends TestCase
{
    public function testEmitsStatusHeadersAndBody(): void
    {
        $output  = new BufferedResponseOutput();
        $emitter = new ResponseEmitter($output);

        $emitter->emit(
            new Response(
                201,
                ['Content-Type' => 'application/json'],
                '{"created":true}',
            ),
        );

        self::assertSame(201, $output->status());
        self::assertSame(
            'application/json',
            $output->headers()['content-type'],
        );
        self::assertSame(
            '{"created":true}',
            $output->body(),
        );
    }

    public function testEmitterStartsUnused(): void
    {
        $emitter = new ResponseEmitter(
            new BufferedResponseOutput(),
        );

        self::assertFalse($emitter->hasEmitted());
    }

    public function testEmitterIsMarkedUsedAfterEmission(): void
    {
        $emitter = new ResponseEmitter(
            new BufferedResponseOutput(),
        );

        $emitter->emit(new Response());

        self::assertTrue($emitter->hasEmitted());
    }

    public function testRejectsDuplicateEmission(): void
    {
        $emitter = new ResponseEmitter(
            new BufferedResponseOutput(),
        );

        $emitter->emit(new Response());

        $this->expectException(LogicException::class);

        $emitter->emit(new Response());
    }

    public function testPreservesBinaryBody(): void
    {
        $output  = new BufferedResponseOutput();
        $emitter = new ResponseEmitter($output);

        $body = "\x00\x01\xFF";

        $emitter->emit(
            new Response(200, [], $body),
        );

        self::assertSame($body, $output->body());
    }

    public function testEmitsEmptyBody(): void
    {
        $output  = new BufferedResponseOutput();
        $emitter = new ResponseEmitter($output);

        $emitter->emit(new Response(204));

        self::assertSame(204, $output->status());
        self::assertSame('', $output->body());
    }

    public function testEmitsMultipleHeaders(): void
    {
        $output  = new BufferedResponseOutput();
        $emitter = new ResponseEmitter($output);

        $emitter->emit(
            new Response(
                200,
                [
                    'Content-Type'  => 'text/plain',
                    'Cache-Control' => 'no-store',
                ],
            ),
        );

        self::assertCount(2, $output->headers());
        self::assertSame(
            'no-store',
            $output->headers()['cache-control'],
        );
    }

    public function testRejectsAlreadyStartedOutput(): void
    {
        $output = new BufferedResponseOutput();

        $output->sendStatus(200);

        $emitter = new ResponseEmitter($output);

        $this->expectException(LogicException::class);

        $emitter->emit(new Response());
    }

    public function testFailedPreflightConsumesEmitter(): void
    {
        $output = new BufferedResponseOutput();

        $output->sendStatus(200);

        $emitter = new ResponseEmitter($output);

        try {
            $emitter->emit(new Response());
        } catch (LogicException) {
            // Expected preflight failure.
        }

        self::assertTrue($emitter->hasEmitted());
    }

    public function testOutputFailurePropagates(): void
    {
        $output = new class () implements ResponseOutput {
            public function assertReady(): void
            {
            }

            public function sendStatus(int $status): void
            {
                throw new RuntimeException('Output failed');
            }

            public function sendHeader(
                string $name,
                string $value,
            ): void {
            }

            public function write(string $body): void
            {
            }
        };

        $emitter = new ResponseEmitter($output);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Output failed');

        $emitter->emit(new Response());
    }

    public function testFailurePreventsRetry(): void
    {
        $output = new class () implements ResponseOutput {
            public function assertReady(): void
            {
            }

            public function sendStatus(int $status): void
            {
                throw new RuntimeException('Output failed');
            }

            public function sendHeader(
                string $name,
                string $value,
            ): void {
            }

            public function write(string $body): void
            {
            }
        };

        $emitter = new ResponseEmitter($output);

        try {
            $emitter->emit(new Response());
        } catch (RuntimeException) {
            // Expected.
        }

        $this->expectException(LogicException::class);

        $emitter->emit(new Response());
    }

    public function testBufferedOutputRequiresStatusBeforeHeader(): void
    {
        $output = new BufferedResponseOutput();

        $this->expectException(LogicException::class);

        $output->sendHeader('Content-Type', 'text/plain');
    }

    public function testBufferedOutputRequiresStatusBeforeBody(): void
    {
        $output = new BufferedResponseOutput();

        $this->expectException(LogicException::class);

        $output->write('body');
    }

    public function testBufferedOutputRejectsDuplicateStatus(): void
    {
        $output = new BufferedResponseOutput();

        $output->sendStatus(200);

        $this->expectException(LogicException::class);

        $output->sendStatus(201);
    }

    public function testBufferedOutputRejectsHeaderAfterBody(): void
    {
        $output = new BufferedResponseOutput();

        $output->sendStatus(200);
        $output->write('body');

        $this->expectException(LogicException::class);

        $output->sendHeader('X-Test', 'value');
    }

    public function testBufferedOutputStartsEmpty(): void
    {
        $output = new BufferedResponseOutput();

        self::assertNull($output->status());
        self::assertSame([], $output->headers());
        self::assertSame('', $output->body());
    }
}
