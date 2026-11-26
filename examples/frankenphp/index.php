<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tracewick\Bridge\Psr15\TracewickMiddleware;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sink\SpoolSink;
use Tracewick\Transport\SpoolConfig;

require __DIR__ . '/vendor/autoload.php';

$spoolDirectory = getenv('TRACEWICK_SPOOL_DIR') ?: sys_get_temp_dir() . '/tracewick-frankenphp';
$smoke = getenv('TRACEWICK_FRANKENPHP_SMOKE') === '1';
$workerId = $smoke ? bin2hex(random_bytes(8)) : '';

$factory = new Psr17Factory();
$requests = new ServerRequestCreator($factory, $factory, $factory, $factory);
$profiler = UnitOfWorkProfiler::create(
    new SpoolSink('tracewick-frankenphp', new SpoolConfig($spoolDirectory)),
    new ProfilerConfig(sampleRate: $smoke ? 1.0 : ProfilerConfig::DEFAULT_SAMPLE_RATE),
);
$profiler->registerShutdown();

$middleware = new TracewickMiddleware(
    $profiler,
    routeResolver: static fn(ServerRequestInterface $request): ?string => $request->getAttribute('route_name'),
    flushAfterHandler: false,
);
$application = new class ($smoke) implements RequestHandlerInterface {
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

$handle = static function () use ($requests, $middleware, $application, $profiler, $smoke, $workerId, $spoolDirectory): void {
    $request = $requests->fromGlobals();
    $route = match ($request->getUri()->getPath()) {
        '/items/42' => 'items.show',
        '/items/fail' => 'items.failed',
        '/health' => 'health.show',
        default => null,
    };

    try {
        $response = $middleware->process($request->withAttribute('route_name', $route), $application);
    } catch (Throwable $error) {
        error_log($error->getMessage());
        $response = new Response(500, [], 'handler failed');
    }

    if ($smoke) {
        $response = $response
            ->withHeader('X-Worker-Id', $workerId)
            ->withHeader('X-Buffered-Profiles', (string) $profiler->bufferedProfiles())
            ->withHeader('X-Collected-Profiles', (string) $profiler->diagnostics()->collectedProfiles);
    }

    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header($name . ': ' . $value, false);
        }
    }
    echo $response->getBody();
    frankenphp_finish_request();

    if ($smoke && $profiler->diagnostics()->collectedProfiles === 1) {
        // The smoke client acknowledges receipt before the outer loop may write the first profile.
        $allowFlush = $spoolDirectory . '/allow-flush';
        $deadline = hrtime(true) + 10_000_000_000;
        while (!is_file($allowFlush)) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('The client did not acknowledge response receipt.');
            }
            usleep(10_000);
            clearstatcache(true, $allowFlush);
        }
    }
};

while (true) {
    try {
        $keepRunning = frankenphp_handle_request($handle);
    } finally {
        $profiler->flush();
    }

    if (!$keepRunning) {
        break;
    }
}

if ($smoke) {
    file_put_contents($spoolDirectory . '/worker-stopped', $workerId);
}
