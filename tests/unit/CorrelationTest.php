<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Context\Correlation;
use PHPUnit\Framework\TestCase;

/**
 * Correlation is what allows a browser page view to be joined to the server request that
 * produced it. Without it the Marketplace workbook cannot answer "why was this page slow" at
 * all, and 1.x could not.
 *
 * @covers \KloudStack\Observability\Context\Correlation
 */
final class CorrelationTest extends TestCase
{
    private const VALID = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['HTTP_TRACEPARENT']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_TRACEPARENT']);
        parent::tearDown();
    }

    // ── Trace identity ──────────────────────────────────────────────────────────────────────

    public function testGeneratesValidTraceContextWhenNoneInbound(): void
    {
        $correlation = new Correlation('');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $correlation->operationId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $correlation->spanId());
        self::assertFalse($correlation->hasInboundContext());
    }

    public function testTraceIdsAreUniquePerRequest(): void
    {
        self::assertNotSame((new Correlation(''))->operationId(), (new Correlation(''))->operationId());
    }

    public function testAdoptsInboundTraceContext(): void
    {
        // Front Door and upstream services set traceparent; adopting it links WordPress telemetry
        // to traces that began before the request reached PHP.
        $correlation = new Correlation(self::VALID);

        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $correlation->operationId());
        self::assertSame('00f067aa0ba902b7', $correlation->parentId());
        self::assertTrue($correlation->hasInboundContext());
        self::assertTrue($correlation->sampledUpstream());
    }

    public function testReadsTraceparentFromRequestHeaders(): void
    {
        $_SERVER['HTTP_TRACEPARENT'] = self::VALID;

        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', (new Correlation())->operationId());
    }

    public function testOwnSpanDiffersFromParentSpan(): void
    {
        $correlation = new Correlation(self::VALID);

        self::assertNotSame($correlation->parentId(), $correlation->spanId());
    }

    public function testParentIdFallsBackToOwnSpanWhenNoInboundContext(): void
    {
        // Browser telemetry stamped with this value must still nest correctly.
        $correlation = new Correlation('');

        self::assertSame($correlation->spanId(), $correlation->parentId());
    }

    public function testUpstreamSampledFlagIsHonoured(): void
    {
        // Dropping a span whose parent was sampled produces a broken trace, which misleads more
        // than no trace at all.
        self::assertFalse((new Correlation('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00'))->sampledUpstream());
        self::assertTrue((new Correlation('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'))->sampledUpstream());
    }

    public function testTraceparentHeaderIsWellFormedForPropagation(): void
    {
        $correlation = new Correlation(self::VALID);

        self::assertSame(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-' . $correlation->spanId() . '-01',
            $correlation->traceparent()
        );
        self::assertNotNull(Correlation::parseTraceparent($correlation->traceparent()));
    }

    public function testTagsCarryBothCorrelationFields(): void
    {
        $tags = (new Correlation(self::VALID))->tags();

        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $tags['ai.operation.id']);
        self::assertSame('00f067aa0ba902b7', $tags['ai.operation.parentId']);
    }

    // ── Header parsing ──────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider malformedHeaders
     */
    public function testMalformedTraceparentIsRejected(string $header): void
    {
        self::assertNull(Correlation::parseTraceparent($header));
    }

    /**
     * The header is client-controlled. Adopting a garbage trace id scatters a site's telemetry
     * across meaningless traces, which is worse than generating a fresh one.
     *
     * @return array<string, array{string}>
     */
    public static function malformedHeaders(): array
    {
        return [
            'empty'              => [''],
            'too few parts'      => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7'],
            'too many parts'     => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra'],
            'short trace id'     => ['00-4bf92f35-00f067aa0ba902b7-01'],
            'short span id'      => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067-01'],
            'non hex trace id'   => ['00-zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz-00f067aa0ba902b7-01'],
            'all zero trace id'  => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
            'all zero span id'   => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'],
            'forbidden version'  => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
            'injection attempt'  => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01"><script>'],
        ];
    }

    public function testRejectedHeaderProducesAFreshTrace(): void
    {
        $correlation = new Correlation('garbage');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $correlation->operationId());
        self::assertFalse($correlation->hasInboundContext());
    }

    public function testFutureVersionsAreAcceptedForForwardCompatibility(): void
    {
        // The W3C specification requires accepting unknown versions whose fields still parse.
        $parsed = Correlation::parseTraceparent('01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

        self::assertNotNull($parsed);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $parsed['trace_id']);
    }

    // ── Operation naming ────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider routes
     */
    public function testOperationNameNormalisation(string $method, string $path, string $expected): void
    {
        self::assertSame($expected, Correlation::operationName($method, $path));
    }

    /**
     * A site with 10,000 posts must not produce 10,000 operation names, or every Azure Monitor
     * performance view becomes unusable at exactly the scale where it matters.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function routes(): array
    {
        return [
            'home'            => ['GET', '/', 'GET /'],
            'numeric id'      => ['GET', '/2451/', 'GET /{id}'],
            'uuid'            => ['GET', '/orders/f47ac10b-58cc-4372-a567-0e02b2c3d479', 'GET /orders/{uuid}'],
            'date archive'    => ['GET', '/2026/07/20/', 'GET /{year}/{id}/{id}'],
            'lowercase verb'  => ['post', '/wp-json/wp/v2/posts', 'POST /wp-json/wp/v2/posts'],
            'repeated slash'  => ['GET', '/shop//product', 'GET /shop/product'],
            'trailing slash'  => ['GET', '/about/', 'GET /about'],
            'no leading slash' => ['GET', 'about', 'GET /about'],
            'empty method'    => ['', '/x', 'GET /x'],
        ];
    }

    public function testMatchedRouteWinsOverPathGuessing(): void
    {
        // A REST route template is authoritative and far better than inferring from the URL.
        self::assertSame(
            'GET /wp/v2/posts/(?P<id>[\d]+)',
            Correlation::operationName('GET', '/wp-json/wp/v2/posts/451', '/wp/v2/posts/(?P<id>[\d]+)')
        );
    }

    public function testAbsurdlyLongPathsAreBounded(): void
    {
        // Either an attack or a bug; either way it must not become a unique operation name.
        $name = Correlation::operationName('GET', '/' . str_repeat('a', 5000));

        self::assertLessThanOrEqual(255, strlen($name));
    }
}
