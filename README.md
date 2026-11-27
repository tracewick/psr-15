# Tracewick PSR-15 middleware

Profiles HTTP requests in a PSR-15 application using the
[Tracewick sampling profiler](https://github.com/tracewick/tracewick). The middleware
returns the handler's response and writes sampled profiles to a local spool.
A separate drain process or Go agent delivers them to Pyroscope.

## Installation

The package targets PHP 8.2–8.5 and accepts PSR-7 message interfaces 1.x and 2.x.
Your application provides its PSR-7 implementation. Install
[Excimer](https://www.mediawiki.org/wiki/Excimer) to collect profiles. Requests
continue to work without it.

Once this bridge is tagged and published, install the bridge and core together.
When using a core release candidate with `minimum-stability: stable`, opt in to
that version explicitly in your application's Composer command.

## Usage

Register the middleware once in your application's PSR-15 pipeline.

```php
use Psr\Http\Message\ServerRequestInterface;
use Tracewick\Bridge\Psr15\TracewickMiddleware;
use Tracewick\Transport\SpoolConfig;

$middleware = TracewickMiddleware::withSpool(
    'my-app',
    new SpoolConfig('/var/spool/tracewick'),
    routeResolver: static function (ServerRequestInterface $request): ?string {
        $routeName = $request->getAttribute('route_name');

        return is_string($routeName) ? $routeName : null;
    },
);
```

The route attribute in this example must be set by your router or application.
Register the middleware **after routing** if it needs resolved route metadata.
Use a bounded set of names or route templates. Omit the resolver for stacks
without that metadata. If the resolver returns null or an empty string, or
throws, profiling continues with the configured base labels. The default
adds no route label. The hook is for metadata lookup and should be cheap.

Keep your application's error handler outside the profiling middleware. Tracewick
stops sampling and propagates a thrown handler error to that error handler.
If a router or earlier middleware returns a response directly, the request is
profiled only if it reached the profiling middleware first.

The default sample rate is 10%. Pass a `ProfilerConfig` to change it. Set
`TRACEWICK_ENABLED=0` before creating the profiler to disable collection.
Use one HTTP profiler per request pipeline. Disable Tracewick's auto-prepend
HTTP profiling and other HTTP bridges for requests covered by this middleware.

### Spool and response timing

Put the spool on a writable local filesystem and run one
[PHP drain](https://github.com/tracewick/tracewick/blob/v1.0.0-rc.1/docs/drain.md)
or [Go agent](https://github.com/tracewick/agent) against it. Give the web
process and the reader compatible permissions. The middleware's default flush
writes to disk after the handler completes and before the application emits the
response.

To move the disk write after emission, construct the middleware with a
spool-backed `UnitOfWorkProfiler` that you own and set
`flushAfterHandler: false`. Call `$profiler->flush()` from the application's
post-response hook. Arrange for that hook to run after error responses as well.
The middleware stops sampling before the hook. Flush after each request to keep
the runtime buffer from filling.

This bridge uses the unit-of-work runtime for sequential requests. In a
long-lived sequential worker, initialize the middleware and profiler once per
worker after it starts. Concurrent runtimes need a process-level profiler and
their own lifecycle wiring.

### Swoole coroutine worker

The [Swoole example](https://github.com/tracewick/psr-15/tree/develop/examples/swoole)
starts a `ContinuousProfiler` once after each HTTP worker forks. The worker runs
the PSR-15 handler as usual. All profiles carry the worker's configured process
labels because overlapping coroutines share a sampler.

After sending a response, the worker pulses at most once per second to send
closed aggregation windows to the local spool. An idle worker leaves pending
profiles for the next request or graceful shutdown. `WorkerStop` stops sampling
and writes the remaining windows.

From a Git checkout with Docker, PHP, Composer and `curl`, run the smoke test:

```bash
composer install --working-dir=examples/swoole
bash examples/swoole/smoke.sh
```

The test builds Swoole 6.3.0 and Excimer 1.2.7 for PHP 8.5. It checks overlapping
requests in one worker, samples from both handler stacks, closed-window delivery
during requests, and the final spool write on graceful shutdown.

### RoadRunner

The [sequential worker example](https://github.com/tracewick/psr-15/tree/develop/examples/roadrunner) creates one
profiler and middleware per PHP worker. The middleware ends sampling when the
handler returns or throws. The worker emits the PSR-7 response with
`PSR7Worker::respond()` and then flushes to the local spool in `finally`.
Register the profiler's shutdown callback once per worker as a fallback.

From a Git checkout, with RoadRunner 2025.1.15 and PHP with Excimer and sockets
installed, run the example's HTTP smoke test:

```bash
composer install --working-dir=examples/roadrunner
ROADRUNNER_BINARY=/path/to/rr bash examples/roadrunner/smoke.sh
```

The smoke test uses one worker on `127.0.0.1:17850`. It checks that the same
profiler handles a success, an exception, a 404, and a subsequent success. It
verifies post-response flush, isolated route labels, and graceful shutdown.

## Development

Run `composer update` and `composer ci`. The integration suite verifies
spool output when the Excimer extension is loaded.

On PHP 8.5 with Excimer and PCOV enabled, use `composer test:coverage` to generate
the Clover report. Install Infection 0.35.8 and run `composer infection` for mutation
tests. CI enforces 80% of all mutations and 80% of covered mutations.
