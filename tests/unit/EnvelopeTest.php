<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Telemetry\Envelope;
use PHPUnit\Framework\TestCase;

/**
 * The envelope format fails silently: Azure accepts malformed items with HTTP 200 and discards
 * them server-side, so a formatting regression produces no error anywhere. These tests are the
 * only detection mechanism.
 *
 * @covers \KloudStack\Observability\Telemetry\Envelope
 */
final class EnvelopeTest extends TestCase
{
    private const IKEY = '11111111-2222-3333-4444-555555555555';

    private function envelope(): Envelope
    {
        return new Envelope(self::IKEY, 'php:kloudstack_2.0.0');
    }

    public function testNameEmbedsInstrumentationKeyWithoutHyphens(): void
    {
        $built = $this->envelope()->build('Request', 'RequestData', []);

        self::assertSame(
            'Microsoft.ApplicationInsights.11111111222233334444555555555555.Request',
            $built['name'],
            'Azure discards items whose name does not match this exact shape.'
        );
    }

    public function testBaseDataAlwaysCarriesSchemaVersion(): void
    {
        $built = $this->envelope()->build('Request', 'RequestData', ['name' => 'GET /']);

        self::assertSame(2, $built['data']['baseData']['ver']);
        self::assertSame('GET /', $built['data']['baseData']['name']);
        self::assertSame('RequestData', $built['data']['baseType']);
    }

    public function testSdkVersionTagIsAlwaysPresentAndOverridable(): void
    {
        $built = $this->envelope()->build('Request', 'RequestData', [], [
            'ai.cloud.role' => 'mysite',
        ]);

        self::assertSame('php:kloudstack_2.0.0', $built['tags']['ai.internal.sdkVersion']);
        self::assertSame('mysite', $built['tags']['ai.cloud.role']);
    }

    public function testSampleRateIsOmittedWhenNotSampling(): void
    {
        self::assertArrayNotHasKey('sampleRate', $this->envelope()->build('Request', 'RequestData', []));
        self::assertArrayNotHasKey('sampleRate', $this->envelope()->build('Request', 'RequestData', [], [], 100));
    }

    public function testSampleRateIsEmittedWhenSampling(): void
    {
        $built = $this->envelope()->build('Request', 'RequestData', [], [], 25);

        self::assertSame(25, $built['sampleRate'], 'Azure extrapolates totals from this value.');
    }

    /**
     * @dataProvider durations
     */
    public function testDurationFormatting(float $milliseconds, string $expected): void
    {
        self::assertSame($expected, Envelope::duration($milliseconds));
    }

    /**
     * The fractional component is in ticks (100ns units), so 1ms is 10,000 ticks. Getting this
     * wrong by a factor of ten is the classic failure and is invisible in production.
     *
     * @return array<string, array{float, string}>
     */
    public static function durations(): array
    {
        return [
            'zero'              => [0.0, '0.00:00:00.0000000'],
            'one millisecond'   => [1.0, '0.00:00:00.0010000'],
            'half a second'     => [500.0, '0.00:00:00.5000000'],
            'one second'        => [1000.0, '0.00:00:01.0000000'],
            'typical page load' => [3200.0, '0.00:00:03.2000000'],
            'one minute'        => [60000.0, '0.00:01:00.0000000'],
            'one hour'          => [3600000.0, '0.01:00:00.0000000'],
            'one day'           => [86400000.0, '1.00:00:00.0000000'],
            'sub millisecond'   => [0.1234, '0.00:00:00.0001234'],
            'negative clamped'  => [-50.0, '0.00:00:00.0000000'],
        ];
    }

    public function testDurationCarriesTicksIntoSecondsRatherThanOverflowing(): void
    {
        // 999.99999ms rounds to a full second in ticks; emitting ".10000000" would be invalid.
        $formatted = Envelope::duration(999.99999);

        self::assertSame('0.00:00:01.0000000', $formatted);
        self::assertMatchesRegularExpression('/^\d+\.\d{2}:\d{2}:\d{2}\.\d{7}$/', $formatted);
    }

    public function testTimestampFormat(): void
    {
        // 1784718932 == 2026-07-22T11:15:32 UTC
        self::assertSame('2026-07-22T11:15:32.482Z', Envelope::timestamp(1784718932.4823));
    }

    public function testTimestampCarriesMillisecondsIntoSeconds(): void
    {
        // .9999 rounds to 1000ms; ".1000Z" would be malformed.
        self::assertSame('2026-07-22T11:15:33.000Z', Envelope::timestamp(1784718932.9999));
    }

    public function testTimestampMatchesRequiredShape(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            Envelope::timestamp()
        );
    }

    public function testIdIsHexAndUnique(): void
    {
        $first = Envelope::id();

        self::assertSame(16, strlen($first));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $first);
        self::assertNotSame($first, Envelope::id());
        self::assertSame(32, strlen(Envelope::id(16)));
    }

    /**
     * @dataProvider dimensionValues
     *
     * @param mixed $value
     */
    public function testDimensionCoercion($value, string $expected): void
    {
        self::assertSame($expected, Envelope::dimension($value));
    }

    /**
     * Booleans are the important case: passed through unconverted they arrive as "1" and "",
     * which makes any workbook query filtering on them silently wrong.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function dimensionValues(): array
    {
        return [
            'true'          => [true, 'true'],
            'false'         => [false, 'false'],
            'null'          => [null, ''],
            'integer'       => [42, '42'],
            'zero'          => [0, '0'],
            'string'        => ['front', 'front'],
            'float'         => [48.25, '48.25'],
            'trailing zero' => [48.0, '48'],
            'empty string'  => ['', ''],
        ];
    }

    public function testTruncatePreservesShortValues(): void
    {
        self::assertSame('short', Envelope::truncate('short', 100));
        self::assertSame('', Envelope::truncate('anything', 0));
    }

    public function testTruncateLimitsLongValues(): void
    {
        self::assertSame('abcde', Envelope::truncate('abcdefghij', 5));
    }

    public function testTruncateDoesNotSplitMultibyteCharacters(): void
    {
        if (!function_exists('mb_substr')) {
            self::markTestSkipped('mbstring not available.');
        }

        $truncated = Envelope::truncate('日本語のテキスト', 3);

        self::assertSame('日本語', $truncated);
        self::assertSame($truncated, mb_convert_encoding($truncated, 'UTF-8', 'UTF-8'), 'Must remain valid UTF-8.');
    }
}
