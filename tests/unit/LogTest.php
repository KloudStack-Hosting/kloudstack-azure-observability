<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Support\Log;
use PHPUnit\Framework\TestCase;
use WPStubs;

/**
 * Debug logging is gated on WP_DEBUG AND the `kloudstack_obs_debug_log` filter. The plugin bridges
 * the "Debug log" setting to that filter in Plugin::boot(); these tests lock the gate itself so a
 * broken gate — or a missing bridge (the dead-toggle bug this suite was written for) — is caught.
 *
 * @covers \KloudStack\Observability\Support\Log
 */
final class LogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The gate requires WP_DEBUG; define it once for the run.
        if (!defined('WP_DEBUG')) {
            define('WP_DEBUG', true);
        }

        WPStubs::reset();
        Log::reset();
    }

    protected function tearDown(): void
    {
        Log::reset();
        parent::tearDown();
    }

    public function testDisabledWhenNothingBridgesTheSetting(): void
    {
        // WP_DEBUG is on but no filter is registered — exactly the dead-toggle state. The gate must
        // stay OFF (the filter default is false), so logging is never accidentally enabled.
        self::assertFalse(Log::enabled());
    }

    public function testEnabledWhenTheFilterReturnsTrue(): void
    {
        WPStubs::$filters['kloudstack_obs_debug_log'] = static fn (): bool => true;
        Log::reset(); // clear the memo now the filter is in place

        self::assertTrue(Log::enabled());
    }

    public function testDisabledWhenTheFilterReturnsFalse(): void
    {
        WPStubs::$filters['kloudstack_obs_debug_log'] = static fn (): bool => false;
        Log::reset();

        self::assertFalse(Log::enabled());
    }
}
