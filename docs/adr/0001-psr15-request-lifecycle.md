# 1. PSR-15 request lifecycle

PSR-15 owns the handler call. The application owns routing, error recovery
and response emission. The core unit-of-work runtime owns request sampling.

Use a single `UnitOfWorkProfiler` for sequential requests and stop it in
`finally`. A registration that encounters an already-running shared
profiler passes through. An instance also passes through when invoked
recursively during its own request.

The spool factory uses the core's local spool sink and sampling defaults.
Flush after the handler by default. Applications with a post-response hook
can turn off the inline flush and call the shared profiler after emission.

For FrankenPHP, the worker script owns its profiler for the lifetime of the
worker thread. The request callback uses `frankenphp_finish_request()` to finish
the response. The worker loop flushes after `frankenphp_handle_request()` returns.

A route resolver reads the request received by this middleware. Applications
can run the middleware after routing to use the matched route name or template.
An absent or failing resolver leaves the base labels in place.

Swoole coroutine requests share one `ContinuousProfiler` per HTTP worker
process. `WorkerStart` initializes it after the fork. The PSR-15 pipeline runs
under process-level labels. Once the server has sent the response, the worker
may pulse to capture samples and write closed windows. The pulse is throttled
to once per second so the event loop can stay idle without a recurring timer.
`WorkerStop` drains the remaining windows. A registered shutdown callback
provides a fallback.
