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

        // Telemetry is OFF until the site owner turns it on. This flipped in 2.0.1: the
        // WordPress.org review would not accept "it only goes to the owner's own Azure resource"
        // as grounds for a default, because a default is not an informed choice. Flipping this
        // back on is a review failure, not a preference.
        self::assertFalse($settings->bool('enabled'), 'Telemetry must default OFF (opt-in).');
        self::assertTrue($settings->bool('anonymise_ip'), 'IP anonymisation must default on.');
        self::assertFalse($settings->bool('header_tracking'), 'Header capture must default off.');
        self::assertFalse($settings->bool('track_cron'));
        self::assertFalse($settings->bool('track_php_errors'));
        self::assertSame(100, $settings->samplingRate());

        // Browser telemetry is the one that loads a third-party SDK into every visitor's browser,
        // so WordPress.org guidelines 7 and 9 require it opt-in. Flipping either of these back on
        // by default is a review failure, not a preference.
        self::assertFalse(
            $settings->bool('client_enabled'),
            'Browser telemetry must default OFF — it injects the Application Insights SDK for visitors.'
        );
        self::assertTrue(
            $settings->bool('cookieless'),
            'Cookie-less must default ON so enabling browser telemetry cannot silently set ai_user/ai_session.'
        );
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
        // Driven from a STORED value rather than the default, so this keeps testing memoisation
        // and does not quietly become a second assertion about what the default happens to be.
        WPStubs::$options['kloudstack_obs_enabled'] = true;

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

    /**
     * @dataProvider togglesThatDefaultOn
     */
    public function testAToggleThatDefaultsOnCanActuallyBeTurnedOff(string $key): void
    {
        // The settings form writes '' for an unchecked box. Coercing '' back to the default made
        // every toggle whose default is true impossible to switch off: the value round-tripped to
        // true and the checkbox redrew itself ticked. Nothing covered this, because the two
        // existing cases happen to use an int setting and a toggle that defaults to false.
        //
        // cookieless is the one that mattered most — the settings page documents turning it off as
        // the way to get session and returning-visitor aggregation, and that was unreachable.
        WPStubs::$options['kloudstack_obs_' . $key] = '';

        self::assertFalse(
            (new Settings())->bool($key),
            sprintf('"%s" defaults on, so an empty stored value must mean the owner cleared it.', $key)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public function togglesThatDefaultOn(): array
    {
        return [
            'cookieless'   => ['cookieless'],
            'anonymise_ip' => ['anonymise_ip'],
            'track_admin'  => ['track_admin'],
        ];
    }

    public function testAnUnwrittenToggleStillReadsItsDefault(): void
    {
        // The other half of the same rule: absent must still mean the default, or the fix would
        // have turned IP anonymisation off for every site that never opened the settings page.
        $settings = new Settings();

        self::assertTrue($settings->bool('cookieless'));
        self::assertTrue($settings->bool('anonymise_ip'));
        self::assertTrue($settings->bool('track_admin'));
    }
}
