<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Settings;
use PHPUnit\Framework\TestCase;
use WPStubs;

/**
 * Settings come back from the database as strings, which is where the interesting bugs are: a
 * saved "0" that reads as true would silently turn a privacy control off.
 *
 * @covers \KloudStack\Observability\Settings
 */
final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WPStubs::reset();
    }

    public function testDefaultsAreConservative(): void
    {
        // These defaults are the difference between passing and failing a WordPress.org privacy
        // review, so they are asserted rather than assumed.
        $settings = new Settings();

        self::assertTrue($settings->bool('enabled'));
        self::assertTrue($settings->bool('anonymise_ip'), 'IP anonymisation must default on.');
        self::assertFalse($settings->bool('header_tracking'), 'Header capture must default off.');
        self::assertFalse($settings->bool('track_cron'));
        self::assertFalse($settings->bool('track_php_errors'));
        self::assertSame(100, $settings->samplingRate());
    }

    /**
     * @dataProvider booleanValues
     *
     * @param mixed $stored
     */
    public function testBooleanCoercionFromDatabaseValues($stored, bool $expected): void
    {
        WPStubs::$options['kloudstack_obs_header_tracking'] = $stored;

        self::assertSame($expected, (new Settings())->bool('header_tracking'));
    }

    /**
     * WordPress stores booleans as "1" and "". A naive cast reads the string "0" as true, which
     * for a privacy setting means silently transmitting what the site owner switched off.
     *
     * @return array<string, array{mixed, bool}>
     */
    public static function booleanValues(): array
    {
        return [
            'true'          => [true, true],
            'false'         => [false, false],
            'string one'    => ['1', true],
            'string zero'   => ['0', false],
            'empty string'  => ['', false],
            'string false'  => ['false', false],
            'integer one'   => [1, true],
            'integer zero'  => [0, false],
        ];
    }

    public function testSamplingRateIsClamped(): void
    {
        $settings = new Settings();

        WPStubs::$options['kloudstack_obs_sampling_requests'] = 0;
        self::assertSame(1, $settings->samplingRate(), 'Zero would disable telemetry silently.');

        $settings->reset();
        WPStubs::$options['kloudstack_obs_sampling_requests'] = 5000;
        self::assertSame(100, $settings->samplingRate());

        $settings->reset();
        WPStubs::$options['kloudstack_obs_sampling_requests'] = 25;
        self::assertSame(25, $settings->samplingRate());
    }

    public function testFiltersOverrideStoredValues(): void
    {
        // Managed stacks configure in code rather than through the database.
        WPStubs::$options['kloudstack_obs_enabled'] = true;
        WPStubs::$filters['kloudstack_obs_setting_enabled'] = static function (): bool {
            return false;
        };

        self::assertFalse((new Settings())->bool('enabled'));
    }

    public function testStringListAcceptsBothArraysAndTextareaInput(): void
    {
        $settings = new Settings();

        WPStubs::$options['kloudstack_obs_excluded_paths'] = "/health\n/ping,/status\n\n";

        self::assertSame(['/health', '/ping', '/status'], $settings->stringList('excluded_paths'));

        $settings->reset();
        WPStubs::$options['kloudstack_obs_excluded_paths'] = ['/a', '  /b  ', ''];

        self::assertSame(['/a', '/b'], $settings->stringList('excluded_paths'));
    }

    public function testQueryAllowlistFallsBackToBuiltInDefault(): void
    {
        // Null means "use the built-in list", which is different from an empty list meaning
        // "allow nothing".
        self::assertNull((new Settings())->queryAllowlist());

        $settings = new Settings();
        WPStubs::$options['kloudstack_obs_query_allowlist'] = ['page', 'cat'];

        self::assertSame(['page', 'cat'], $settings->queryAllowlist());
    }

    public function testValuesAreMemoisedAndResettable(): void
    {
        $settings = new Settings();

        self::assertTrue($settings->bool('enabled'));

        WPStubs::$options['kloudstack_obs_enabled'] = false;

        self::assertTrue($settings->bool('enabled'), 'Reads must be memoised per request.');

        $settings->reset();

        self::assertFalse($settings->bool('enabled'));
    }

    public function testEmptyStoredValueFallsBackToDefault(): void
    {
        // An option written as an empty string must not turn a typed setting into ''.
        WPStubs::$options['kloudstack_obs_sampling_requests'] = '';

        self::assertSame(100, (new Settings())->samplingRate());
    }
}
