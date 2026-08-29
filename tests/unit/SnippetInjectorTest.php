<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Client\SnippetInjector;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WPStubs;

/**
 * The rule this pins: a value that changes per request must not be written into HTML that a page
 * cache will store and replay.
 *
 * Observed in production before the fix — three requests to the same site with different user
 * agents returned an identical operationId, and a real Chrome page view in Application Insights
 * carried the same id as the cached HTML. Every visitor was reported as part of one trace that ran
 * once, when the cache entry was built.
 *
 * `CorrelationTest::testTraceIdsAreUniquePerRequest` passes and always did. It asserts the right
 * property at the wrong boundary: ids ARE unique per PHP request. The defect is that one request's
 * output is served to thousands of visitors, which no test at that boundary can see.
 *
 * @covers \KloudStack\Observability\Client\SnippetInjector
 */
final class SnippetInjectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WPStubs::reset();
        unset($_SERVER['REQUEST_METHOD']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    private static function survivesCaching(): bool
    {
        // Tested through reflection rather than by widening the API: this is an internal rule about
        // when it is safe to emit, not a contract the plugin offers anyone.
        $method = new ReflectionMethod(SnippetInjector::class, 'correlationSurvivesCaching');

        // Required on PHP 7.4, a no-op from 8.1, and deprecated from 8.5 — where the notice is
        // emitted as unexpected output and PHPUnit fails the test outright.
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return (bool) $method->invoke(null);
    }

    public function testAnonymousGetIsTreatedAsCacheable(): void
    {
        // The default case, and the one that caused the defect. A page cache may store this
        // response, so the request's trace context must not travel with it.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        WPStubs::$userLoggedIn     = false;

        self::assertFalse(self::survivesCaching());
    }

    public function testAssumesCacheableWhenTheRequestMethodIsUnknown(): void
    {
        // Absent REQUEST_METHOD must not read as "not a GET" and quietly re-enable the defect.
        self::assertFalse(self::survivesCaching());
    }

    public function testLoggedInRequestsKeepCorrelation(): void
    {
        // Every major page cache skips logged-in users, so PHP genuinely runs for these and a real
        // server span exists to correlate with.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        WPStubs::$userLoggedIn     = true;

        self::assertTrue(self::survivesCaching());
    }

    public function testNonGetRequestsKeepCorrelation(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        self::assertTrue(self::survivesCaching());
    }

    public function testLowercaseMethodIsRecognised(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';

        self::assertTrue(self::survivesCaching());
    }

    /**
     * A constant cannot be undefined, so this runs in its own process rather than leaking
     * DONOTCACHEPAGE into every test that follows it.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDoNotCachePageIsHonoured(): void
    {
        // The nearest thing to a cross-plugin standard — W3TC, WP Rocket, LiteSpeed, WP Super Cache
        // and WP Fastest Cache all respect it.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        WPStubs::$userLoggedIn     = false;

        self::assertFalse(self::survivesCaching(), 'Precondition: cacheable before the constant.');

        define('DONOTCACHEPAGE', true);

        self::assertTrue(self::survivesCaching());
    }

    public function testAHostCanAssertItsOwnCachingThroughTheFilter(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        WPStubs::$filters['kloudstack_obs_response_is_uncacheable'] = static fn (): bool => true;

        self::assertTrue(self::survivesCaching());
    }
}
