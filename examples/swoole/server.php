<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request as HttpRequest;
use Swoole\Http\Response as HttpResponse;
use Swoole\Http\Server;
use Tracewick\Profile\Labels;
use Tracewick\Runtime\ContinuousProfiler;
use Tracewick\Runtime\ContinuousProfilerConfig;
use Tracewick\Runtime\SpoolAggregationWindow;
use Tracewick\Sink\SpoolSink;
use Tracewick\Transport\SpoolConfig;

require __DIR__ . '/vendor/autoload.php';

function sampleWork(): void
{
    $deadline = hrtime(true) + 220_000_000;
    while (hrtime(true) < $deadline) {
        hash('sha256', 'tracewick');
    }
}

function sampleBeforeStop(): void
{
    sampleWork();
}

$smoke = getenv('TRACEWICK_SWOOLE_SMOKE') === '1';
$spoolDirectory = getenv('TRACEWICK_SPOOL_DIR') ?: sys_get_temp_dir() . '/tracewick-swoole';
$applicationName = 'tracewick-swoole';

$server = new Server('0.0.0.0', 8080);
$server->set([
    'worker_num' => 1,
    'enable_coroutine' => true,
    'max_wait_time' => 5,
]);

$profiler = null;
$lastPulseSecond = null;
$application = null;

$server->on('WorkerStart', static function () use (&$profiler, &$application, $applicationName, $spoolDirectory, $smoke): void {
    $profiler = ContinuousProfiler::create(
        new SpoolSink($applicationName, new SpoolConfig($spoolDirectory)),
        new ContinuousProfilerConfig(
            $applicationName,
            new Labels(['runtime' => 'swoole']),
            new SpoolAggregationWindow($smoke ? SpoolAggregationWindow::MIN_SECONDS : SpoolAggregationWindow::DEFAULT_SECONDS),
        ),
    );
    $profiler->start();
    $profiler->registerShutdown();

    $application = new class ($smoke) implements RequestHandlerInterface {
        private ?Channel $firstReady;
        private ?Channel $secondReady;

        public function __construct(bool $smoke)
        {
            $this->firstReady = $smoke ? new Channel(1) : null;
            $this->secondReady = $smoke ? new Channel(1) : null;
        }

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return match ($request->getUri()->getPath()) {
                '/overlap/first' => $this->firstRequest(),
                '/overlap/second' => $this->secondRequest(),
                default => new Response(404, [], 'not found'),
            };
        }

        private function firstRequest(): ResponseInterface
        {
            if ($this->firstReady !== null && $this->secondReady !== null) {
                // Both requests must enter before either proceeds.
                if (!$this->firstReady->push(true, 4.0) || $this->secondReady->pop(4.0) !== true) {
                    throw new RuntimeException('The second coroutine did not overlap.');
                }

                sampleWork();
            }

            return new Response(200, [], 'first');
        }

        private function secondRequest(): ResponseInterface
        {
            if ($this->firstReady !== null && $this->secondReady !== null) {
                if (!$this->secondReady->push(true, 4.0) || $this->firstReady->pop(4.0) !== true) {
                    throw new RuntimeException('The first coroutine did not overlap.');
                }

                sampleWork();
            }

            return new Response(200, [], 'second');
        }
    };
});

$server->on('Request', static function (HttpRequest $request, HttpResponse $response) use (
    &$application,
    &$profiler,
    &$lastPulseSecond,
    $smoke,
): void {
    $path = $request->server['request_uri'] ?? '/';
    if ($path === '/__ready') {
        $response->status(204);
        $response->end();

        return;
    }

    try {
        try {
            // This GET-only example leaves full HTTP conversion to the application's Swoole adapter.
            $psrResponse = $application->handle(new ServerRequest($request->server['request_method'] ?? 'GET', $path));
        } catch (Throwable $error) {
            error_log($error->getMessage());
            $psrResponse = new Response(500, [], 'handler failed');
        }

        if ($smoke) {
            $response->header('X-Worker-Pid', (string) getmypid());
        }
        $response->status($psrResponse->getStatusCode());
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $response->header($name, $values);
        }
        $response->end((string) $psrResponse->getBody());
    } finally {
        // Pulse outside Excimer callbacks. An idle worker needs no recurring event-loop timer.
        $second = intdiv(hrtime(true), 1_000_000_000);
        if ($second !== $lastPulseSecond) {
            $lastPulseSecond = $second;
            $profiler->pulse();
        }
    }
});

$server->on('WorkerStop', static function () use (&$profiler, $smoke, $spoolDirectory): void {
    if ($smoke) {
        // This frame can only reach the spool through the worker's shutdown drain.
        sampleBeforeStop();
    }
    $profiler?->stop();
    if ($smoke) {
        file_put_contents($spoolDirectory . '/worker-stopped', (string) getmypid());
    }
});

$server->start();
