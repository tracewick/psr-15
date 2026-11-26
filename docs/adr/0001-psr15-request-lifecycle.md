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

A route resolver reads the request received by this middleware. Applications
can run the middleware after routing to use the matched route name or template.
An absent or failing resolver leaves the base labels in place.
