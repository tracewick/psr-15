<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;
use Tracewick\Bridge\Psr15\TracewickMiddleware;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sink\SpoolSink;
use Tracewick\Transport\SpoolConfig;

require __DIR__ . '/vendor/autoload.php';

$spoolDirectory = getenv('TRACEWICK_SPOOL_DIR') ?: sys_get_temp_dir() . '/tracewick-roadrunner';
$smoke = getenv('TRACEWICK_RR_SMOKE') === '1';

$factory = new Psr17Factory();
$server = new PSR7Worker(Worker::create(), $factory, $factory, $factory);
$profiler = UnitOfWorkProfiler::create(
    new SpoolSink('tracewick-roadrunner', new SpoolConfig($spoolDirectory)),
    new ProfilerConfig(sampleRate: $smoke ? 1.0 : ProfilerConfig::DEFAULT_SAMPLE_RATE),
);
$profiler->registerShutdown();

$middleware = new TracewickMiddleware(
    $profiler,
    routeResolver: static fn(ServerRequestInterface $request): ?string => $request->getAttribute('route_name'),
    flushAfterHandler: false,
);
$handler = new class ($smoke) implements RequestHandlerInterface {
    public function __construct(private readonly bool $smoke) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->smoke) {
            $deadline = hrtime(true) + 180_000_000;
            while (hrtime(true) < $deadline) {
                hash('sha256', 'tracewick');
            }
        }

        return match ($request->getAttribute('route_name')) {
            'items.show' => new Response(200, [], 'item'),
            'items.failed' => throw new RuntimeException('Demo handler failure.'),
            'health.show' => new Response(200, [], 'ok'),
            default => new Response(404, [], 'not found'),
        };
    }
};

while (($request = $server->waitRequest()) !== null) {
    $route = match ($request->getUri()->getPath()) {
        '/items/42' => 'items.show',
        '/items/fail' => 'items.failed',
        '/health' => 'health.show',
        default => null,
    };
    $request = $request->withAttribute('route_name', $route);

    try {
        try {
            $response = $middleware->process($request, $handler);
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            $response = new Response(500, [], 'handler failed');
        }

        if ($smoke) {
            $response = $response
                ->withHeader('X-Worker-Pid', (string) getmypid())
                ->withHeader('X-Buffered-Profiles', (string) $profiler->bufferedProfiles())
                ->withHeader('X-Collected-Profiles', (string) $profiler->diagnostics()->collectedProfiles);
        }
        $server->respond($response);
    } finally {
        $profiler->flush();
    }
}

if ($smoke) {
    file_put_contents($spoolDirectory . '/worker-stopped', (string) getmypid());
}
