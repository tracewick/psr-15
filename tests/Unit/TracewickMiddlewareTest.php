<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Psr15\Tests\Unit;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tracewick\Bridge\Psr15\TracewickMiddleware;
use Tracewick\Profile\Profile;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\Sampler;
use Tracewick\Sink\Sink;
use Tracewick\Transport\Delivery;

#[CoversClass(TracewickMiddleware::class)]
final class TracewickMiddlewareTest extends TestCase
{
    public function testProfilesARequestAndReturnsTheSameResponse(): void
    {
        $sink = $this->createMock(Sink::class);
        $labels = [];
        $sink->expects(self::once())->method('send')->willReturnCallback(
            static function (Profile $profile) use (&$labels): Delivery {
                $labels = $profile->labels->toArray();

                return Delivery::delivered();
            },
        );
        $profiler = new UnitOfWorkProfiler($this->sampler(), $sink, new ProfilerConfig(sampleRate: 1.0));
        $middleware = new TracewickMiddleware($profiler, static function (ServerRequestInterface $request): ?string {
            $route = $request->getAttribute('route_name');

            return \is_string($route) ? $route : null;
        });
        $request = (new ServerRequest('GET', '/orders/123?token=secret'))->withAttribute('route_name', 'orders.show');
        $response = new Response(201, ['X-Test' => 'kept'], 'body');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::identicalTo($request))->willReturnCallback(
            static function () use ($profiler, $response): Response {
                self::assertTrue($profiler->isRunning());

                return $response;
            },
        );

        self::assertSame($response, $middleware->process($request, $handler));
        self::assertFalse($profiler->isRunning());
        self::assertSame(['route' => 'orders.show'], $labels);
    }

    public function testPreservesHandlerErrorAndResetsStateWhenTheSinkFails(): void
    {
        $seenLabels = [];
        $sink = $this->createMock(Sink::class);
        $sink->expects(self::exactly(2))->method('send')->willReturnCallback(
            static function (Profile $profile) use (&$seenLabels): Delivery {
                $seenLabels[] = $profile->labels->toArray();
                throw new \RuntimeException('Spool failed');
            },
        );
        $profiler = new UnitOfWorkProfiler($this->sampler(2), $sink, new ProfilerConfig(sampleRate: 1.0));
        $middleware = new TracewickMiddleware($profiler, static function (ServerRequestInterface $request): string {
            if ($request->getUri()->getPath() === '/broken-resolver') {
                throw new \LogicException('Could not resolve the route');
            }

            return 'orders.show';
        });
        $request = new ServerRequest('GET', '/orders/123');
        $applicationError = new \Error('Application failed');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willThrowException($applicationError);

        try {
            $middleware->process($request, $handler);
            self::fail('Expected the handler error.');
        } catch (\Error $error) {
            self::assertSame($applicationError, $error);
        }

        self::assertFalse($profiler->isRunning());

        $response = new Response(404);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn($response);

        self::assertSame($response, $middleware->process(new ServerRequest('GET', '/broken-resolver'), $handler));
        self::assertSame([['route' => 'orders.show'], []], $seenLabels);
    }

    public function testDoesNotResolveRoutesWhenSamplingIsDisabled(): void
    {
        $sink = $this->createMock(Sink::class);
        $sink->expects(self::never())->method('send');
        $sampler = $this->createMock(Sampler::class);
        $sampler->expects(self::never())->method('start');
        $profiler = new UnitOfWorkProfiler($sampler, $sink, new ProfilerConfig(sampleRate: 0.0));
        $resolverCalls = 0;
        $middleware = new TracewickMiddleware($profiler, static function () use (&$resolverCalls): ?string {
            ++$resolverCalls;

            return null;
        });
        $response = new Response(200);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn($response);

        self::assertSame($response, $middleware->process(new ServerRequest('GET', '/'), $handler));
        self::assertSame(0, $resolverCalls);
    }

    public function testNestedMiddlewareSharingTheRuntimeLeavesTheOuterProfileAlone(): void
    {
        $sink = $this->createMock(Sink::class);
        $labels = [];
        $sink->expects(self::once())->method('send')->willReturnCallback(
            static function (Profile $profile) use (&$labels): Delivery {
                $labels = $profile->labels->toArray();

                return Delivery::delivered();
            },
        );
        $profiler = new UnitOfWorkProfiler($this->sampler(), $sink, new ProfilerConfig(sampleRate: 1.0));
        $outer = new TracewickMiddleware($profiler, static fn(): string => 'outer');
        $inner = new TracewickMiddleware($profiler, static fn(): string => 'inner');
        $response = new Response(200);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn($response);
        $outerHandler = $this->createMock(RequestHandlerInterface::class);
        $outerHandler->expects(self::once())->method('handle')->willReturnCallback(
            static fn(ServerRequestInterface $request) => $inner->process($request, $handler),
        );

        self::assertSame($response, $outer->process(new ServerRequest('GET', '/'), $outerHandler));
        self::assertSame(['route' => 'outer'], $labels);
    }

    public function testCanFlushAfterResponseEmission(): void
    {
        $sink = $this->createMock(Sink::class);
        $sink->expects(self::once())->method('send')->willReturn(Delivery::delivered());
        $profiler = new UnitOfWorkProfiler($this->sampler(), $sink, new ProfilerConfig(sampleRate: 1.0));
        $middleware = new TracewickMiddleware($profiler, flushAfterHandler: false);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(new Response(200));

        $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertFalse($profiler->isRunning());
        self::assertSame(1, $profiler->bufferedProfiles());
        $profiler->flush();
        self::assertSame(0, $profiler->bufferedProfiles());
    }

    private function sampler(int $requests = 1): Sampler
    {
        $sampler = $this->createMock(Sampler::class);
        $sampler->expects(self::exactly($requests))->method('start');
        $sampler->expects(self::exactly($requests))->method('stop');
        $sampler->method('isRunning')->willReturn(true);
        $sampler->expects(self::exactly(2 * $requests))->method('flush')->willReturn(
            new SampleBatch(EventType::Wall, 10, 100, 120, [new Sample([new Frame('handler')], 1, 110)]),
        );

        return $sampler;
    }
}
