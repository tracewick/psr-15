<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Psr15\Tests\Integration;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Tracewick\Bridge\Psr15\TracewickMiddleware;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Sink\SpoolFile;
use Tracewick\Transport\SpoolConfig;

#[CoversClass(TracewickMiddleware::class)]
final class SpoolTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/tracewick-psr15-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $path) {
            self::assertInstanceOf(\SplFileInfo::class, $path);
            if ($path->isDir()) {
                rmdir($path->getPathname());
            } else {
                unlink($path->getPathname());
            }
        }
        rmdir($this->directory);
    }

    #[RequiresPhpExtension('excimer')]
    public function testSamplesHandlerAndWritesTheRouteToSpool(): void
    {
        $middleware = TracewickMiddleware::withSpool(
            'psr15-smoke',
            new SpoolConfig($this->directory),
            new ProfilerConfig(sampleRate: 1.0),
            routeResolver: static fn(): string => 'orders.show',
        );
        $response = new Response(200, [], 'ok');
        $handler = $this->busyHandler($response);

        self::assertSame($response, $middleware->process(new ServerRequest('GET', '/orders/1'), $handler));

        $files = glob($this->directory . '/*/*.spool');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $bytes = file_get_contents($files[0]);
        self::assertIsString($bytes);

        $profile = SpoolFile::fromBytes($bytes);
        self::assertSame('psr15-smoke', $profile->application);
        self::assertSame(['route' => 'orders.show'], $profile->labels);
        self::assertNotSame('', $profile->payload);
    }

    #[RequiresPhpExtension('excimer')]
    public function testUnwritableSpoolDoesNotReplaceTheResponse(): void
    {
        $path = $this->directory . '/blocked';
        self::assertNotFalse(file_put_contents($path, 'file'));

        $middleware = TracewickMiddleware::withSpool(
            'psr15-smoke',
            new SpoolConfig($path),
            new ProfilerConfig(sampleRate: 1.0),
        );
        $response = new Response(202);

        self::assertSame($response, $middleware->process(new ServerRequest('GET', '/'), $this->busyHandler($response)));
        self::assertSame('file', file_get_contents($path));
    }

    public function testKillSwitchMakesSpoolANoOp(): void
    {
        $previous = $_SERVER['TRACEWICK_ENABLED'] ?? null;
        $_SERVER['TRACEWICK_ENABLED'] = '0';

        try {
            $this->assertSpoolIsUnused();
        } finally {
            if ($previous === null) {
                unset($_SERVER['TRACEWICK_ENABLED']);
            } else {
                $_SERVER['TRACEWICK_ENABLED'] = $previous;
            }
        }
    }

    public function testMissingExcimerMakesSpoolANoOp(): void
    {
        if (extension_loaded('excimer')) {
            self::markTestSkipped('Run this test without Excimer.');
        }

        $this->assertSpoolIsUnused();
    }

    private function assertSpoolIsUnused(): void
    {
        $middleware = TracewickMiddleware::withSpool(
            'psr15-smoke',
            new SpoolConfig($this->directory),
            new ProfilerConfig(sampleRate: 1.0),
        );
        $response = new Response(200);

        self::assertSame($response, $middleware->process(new ServerRequest('GET', '/'), $this->busyHandler($response)));
        self::assertSame(['.', '..'], scandir($this->directory));
    }

    private function busyHandler(Response $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturnCallback(static function () use ($response): Response {
            $deadline = hrtime(true) + 100_000_000;
            while (hrtime(true) < $deadline) {
                hash('sha256', 'tracewick');
            }

            return $response;
        });

        return $handler;
    }
}
