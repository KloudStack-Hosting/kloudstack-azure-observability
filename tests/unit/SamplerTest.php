<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Telemetry\Buffer;
use KloudStack\Observability\Telemetry\Sampler;
use PHPUnit\Framework\TestCase;

/**
 * @covers \KloudStack\Observability\Telemetry\Sampler
 * @covers \KloudStack\Observability\Telemetry\Buffer
 */
final class SamplerTest extends TestCase
{
    // ── Sampler ─────────────────────────────────────────────────────────────────────────────

    public function testFullRateKeepsEverything(): void
    {
        $sampler = new Sampler(100);

        self::assertFalse($sampler->isSampling());
        self::assertNull($sampler->sampleRate(), 'sampleRate must be omitted, not sent as 100.');

        for ($i = 0; $i < 50; ++$i) {
            self::assertTrue($sampler->shouldSample('trace' . $i));
        }
    }

    public function testZeroRateKeepsNothing(): void
    {
        $sampler = new Sampler(0);

        for ($i = 0; $i < 50; ++$i) {
            self::assertFalse($sampler->shouldSample('trace' . $i));
        }
    }

    public function testRateIsClamped(): void
    {
        self::assertSame(100, (new Sampler(150))->rate());
        self::assertSame(0, (new Sampler(-10))->rate());
    }

    public function testDecisionIsConsistentForTheSameTrace(): void
    {
        // If a request is sampled out but its exception is sampled in, Application Insights shows
        // an exception belonging to a request that does not exist.
        $sampler     = new Sampler(50);
        $operationId = '4bf92f3577b34da6a3ce929d0e0e4736';
        $decision    = $sampler->shouldSample($operationId);

        for ($i = 0; $i < 20; ++$i) {
            self::assertSame($decision, $sampler->shouldSample($operationId));
        }
    }

    public function testDecisionIsStableAcrossSamplerInstances(): void
    {
        // The client-side SDK and the server must agree about the same trace, and they do not
        // share state.
        $operationId = '4bf92f3577b34da6a3ce929d0e0e4736';

        self::assertSame(
            (new Sampler(25))->shouldSample($operationId),
            (new Sampler(25))->shouldSample($operationId)
        );
    }

    public function testSamplingRateIsApproximatelyHonoured(): void
    {
        $sampler = new Sampler(25);
        $kept    = 0;
        $total   = 4000;

        for ($i = 0; $i < $total; ++$i) {
            if ($sampler->shouldSample(hash('sha256', (string) $i))) {
                ++$kept;
            }
        }

        // Bucketing by hash is approximate, not exact. A wide tolerance still catches the
        // failures that matter: keeping everything, or keeping nothing.
        $ratio = $kept / $total;

        self::assertGreaterThan(0.15, $ratio, 'Sampling kept far too little.');
        self::assertLessThan(0.35, $ratio, 'Sampling kept far too much.');
    }

    public function testSampleRateIsReportedWhenSampling(): void
    {
        // Without this on every surviving item, Azure reports sampled counts as real ones and a
        // site sampled at 10% silently appears to have a tenth of its traffic.
        self::assertSame(10, (new Sampler(10))->sampleRate());
        self::assertTrue((new Sampler(10))->isSampling());
    }

    public function testEmptyOperationIdStillSamples(): void
    {
        // Falling back to "always keep" would defeat the configured rate entirely.
        $sampler = new Sampler(1);
        $kept    = 0;

        for ($i = 0; $i < 200; ++$i) {
            if ($sampler->shouldSample('')) {
                ++$kept;
            }
        }

        self::assertLessThan(50, $kept, 'An absent trace id must not bypass sampling.');
    }

    // ── Buffer ──────────────────────────────────────────────────────────────────────────────

    public function testBufferAccumulatesAndFlushes(): void
    {
        $buffer = new Buffer();

        self::assertTrue($buffer->isEmpty());
        self::assertTrue($buffer->add(['name' => 'a']));
        self::assertTrue($buffer->add(['name' => 'b']));
        self::assertSame(2, $buffer->count());

        $flushed = $buffer->flush();

        self::assertCount(2, $flushed);
        self::assertSame('a', $flushed[0]['name']);
    }

    public function testFlushEmptiesTheBuffer(): void
    {
        // The shutdown handler can run more than once in some SAPI configurations; re-sending
        // would double-count every request.
        $buffer = new Buffer();
        $buffer->add(['name' => 'a']);

        self::assertCount(1, $buffer->flush());
        self::assertCount(0, $buffer->flush());
        self::assertTrue($buffer->isEmpty());
    }

    public function testBufferEnforcesItsCap(): void
    {
        // A plugin conflict producing errors in a loop must not become a multi-megabyte payload.
        $buffer = new Buffer(3);

        self::assertTrue($buffer->add(['n' => 1]));
        self::assertTrue($buffer->add(['n' => 2]));
        self::assertTrue($buffer->add(['n' => 3]));
        self::assertFalse($buffer->add(['n' => 4]));
        self::assertFalse($buffer->add(['n' => 5]));

        self::assertSame(3, $buffer->count());
        self::assertTrue($buffer->isFull());
    }

    public function testDroppedItemsAreCountedNotSilentlyDiscarded(): void
    {
        // Silent truncation makes a site look healthy exactly when it is producing the most
        // telemetry.
        $buffer = new Buffer(2);

        $buffer->add(['n' => 1]);
        $buffer->add(['n' => 2]);
        $buffer->add(['n' => 3]);
        $buffer->add(['n' => 4]);

        self::assertSame(2, $buffer->dropped());
    }

    public function testInvalidLimitFallsBackToDefault(): void
    {
        self::assertSame(Buffer::DEFAULT_LIMIT, (new Buffer(0))->limit());
        self::assertSame(Buffer::DEFAULT_LIMIT, (new Buffer(-5))->limit());
    }

    public function testResetClearsItemsAndDropCount(): void
    {
        $buffer = new Buffer(1);
        $buffer->add(['n' => 1]);
        $buffer->add(['n' => 2]);
        $buffer->reset();

        self::assertTrue($buffer->isEmpty());
        self::assertSame(0, $buffer->dropped());
    }
}
