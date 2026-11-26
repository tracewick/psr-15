<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Psr15;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sink\SpoolSink;
use Tracewick\Transport\SpoolConfig;

final class TracewickMiddleware implements MiddlewareInterface
{
    private bool $handling = false;

    /** @param ?\Closure(ServerRequestInterface): ?string $routeResolver */
    public function __construct(
        private readonly UnitOfWorkProfiler $profiler,
        private readonly ?\Closure $routeResolver = null,
        private readonly bool $flushAfterHandler = true,
    ) {}

    /** @param ?\Closure(ServerRequestInterface): ?string $routeResolver */
    public static function withSpool(
        string $applicationName,
        SpoolConfig $spoolConfig,
        ProfilerConfig $profilerConfig = new ProfilerConfig(),
        ?\Closure $routeResolver = null,
    ): self {
        return new self(
            UnitOfWorkProfiler::create(new SpoolSink($applicationName, $spoolConfig), $profilerConfig),
            $routeResolver,
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->handling || $this->profiler->isRunning()) {
            return $handler->handle($request);
        }

        $this->handling = true;

        try {
            $this->profiler->start();
            if ($this->routeResolver !== null && $this->profiler->isRunning()) {
                $this->labelRoute($request, $this->routeResolver);
            }

            return $handler->handle($request);
        } finally {
            $this->profiler->stop();
            if ($this->flushAfterHandler) {
                $this->profiler->flush();
            }

            $this->handling = false;
        }
    }

    /** @param \Closure(ServerRequestInterface): ?string $routeResolver */
    private function labelRoute(ServerRequestInterface $request, \Closure $routeResolver): void
    {
        try {
            $route = $routeResolver($request);
            if ($route !== null && $route !== '') {
                $this->profiler->label('route', $route);
            }
        } catch (\Throwable) {
            // Route metadata is optional. Continue with the unlabelled profile.
        }
    }
}
