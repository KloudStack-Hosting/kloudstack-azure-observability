<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Config;
use PHPUnit\Framework\TestCase;
use WPStubs;

/**
 * Configuration resolution is invisible at runtime when it goes wrong — a bad instrumentation
 * key produces telemetry that Azure silently discards. These tests are the only place that
 * failure mode is caught.
 *
 * @covers \KloudStack\Observability\Config
 */
final class ConfigTest extends TestCase
{
    private const VALID_IKEY = '11111111-2222-3333-4444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        WPStubs::reset();
        $this->clearEnvironment();
    }

    protected function tearDown(): void
    {
        $this->clearEnvironment();
        parent::tearDown();
    }

    private function clearEnvironment(): void
    {
        foreach (['APPLICATIONINSIGHTS_CONNECTION_STRING', 'APPINSIGHTS_INSTRUMENTATIONKEY'] as $name) {
            putenv($name);
            unset($_SERVER[$name]);
        }
    }

    public function testUnconfiguredReturnsNull(): void
    {
        $config = new Config();

        self::assertNull($config->credentials());
        self::assertFalse($config->isConfigured());
        self::assertSame(Config::SOURCE_NONE, $config->source());
        self::assertFalse($config->isLocked());
    }

    public function testResolvesFromEnvironmentConnectionString(): void
    {
        putenv('APPLICATIONINSIGHTS_CONNECTION_STRING=InstrumentationKey=' . self::VALID_IKEY
            . ';IngestionEndpoint=https://australiaeast-1.in.applicationinsights.azure.com/');

        $credentials = (new Config())->credentials();

        self::assertNotNull($credentials);
        self::assertSame(self::VALID_IKEY, $credentials['ikey']);
        self::assertSame(
            'https://australiaeast-1.in.applicationinsights.azure.com/v2/track',
            $credentials['endpoint']
        );
        self::assertSame(Config::SOURCE_ENV, $credentials['source']);
    }

    public function testEnvironmentBeatsOption(): void
    {
        // A site administrator must not be able to redirect a managed stack's telemetry by
        // editing an option. This ordering is a security property, not a convenience.
        putenv('APPLICATIONINSIGHTS_CONNECTION_STRING=InstrumentationKey=' . self::VALID_IKEY);
        WPStubs::$options['kloudstack_obs_connection_string'] =
            'InstrumentationKey=99999999-9999-9999-9999-999999999999';

        $config = new Config();

        self::assertSame(self::VALID_IKEY, $config->credentials()['ikey']);
        self::assertSame(Config::SOURCE_ENV, $config->source());
        self::assertTrue($config->isLocked());
    }

    public function testFallsBackToOptionWhenEnvironmentIsAbsent(): void
    {
        WPStubs::$options['kloudstack_obs_connection_string'] =
            'InstrumentationKey=' . self::VALID_IKEY;

        $config = new Config();

        self::assertSame(self::VALID_IKEY, $config->credentials()['ikey']);
        self::assertSame(Config::SOURCE_OPTION, $config->source());
        self::assertFalse($config->isLocked(), 'An option-sourced value must remain editable.');
    }

    public function testLegacyInstrumentationKeyIsAccepted(): void
    {
        putenv('APPINSIGHTS_INSTRUMENTATIONKEY=' . self::VALID_IKEY);

        $credentials = (new Config())->credentials();

        self::assertSame(self::VALID_IKEY, $credentials['ikey']);
        self::assertSame('https://dc.services.visualstudio.com/v2/track', $credentials['endpoint']);
        self::assertSame(Config::SOURCE_ENV_IKEY, $credentials['source']);
    }

    public function testConnectionStringBeatsLegacyKey(): void
    {
        putenv('APPLICATIONINSIGHTS_CONNECTION_STRING=InstrumentationKey=' . self::VALID_IKEY);
        putenv('APPINSIGHTS_INSTRUMENTATIONKEY=99999999-9999-9999-9999-999999999999');

        self::assertSame(Config::SOURCE_ENV, (new Config())->source());
    }

    /**
     * @dataProvider invalidConnectionStrings
     */
    public function testMalformedConfigurationIsTreatedAsUnconfigured(string $connectionString): void
    {
        putenv('APPLICATIONINSIGHTS_CONNECTION_STRING=' . $connectionString);

        // Passing a bad key through would produce telemetry Azure discards without any error the
        // customer can see. Reporting "unconfigured" is far more diagnosable.
        self::assertNull((new Config())->credentials(), 'Expected malformed configuration to be rejected.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidConnectionStrings(): array
    {
        return [
            'empty'             => [''],
            'whitespace'        => ['   '],
            'no key'            => ['IngestionEndpoint=https://example.com/'],
            'not a guid'        => ['InstrumentationKey=not-a-guid'],
            'truncated guid'    => ['InstrumentationKey=11111111-2222-3333-4444'],
            'no delimiter'      => ['InstrumentationKey'],
            'empty key value'   => ['InstrumentationKey='],
        ];
    }

    public function testSovereignCloudEndpointIsDerivedFromSuffix(): void
    {
        putenv('APPLICATIONINSIGHTS_CONNECTION_STRING=InstrumentationKey=' . self::VALID_IKEY
            . ';EndpointSuffix=applicationinsights.us;Location=usgovvirginia');

        self::assertSame(
            'https://usgovvirginia.dc.applicationinsights.us/v2/track',
            (new Config())->credentials()['endpoint']
        );
    }

    public function testPlaintextEndpointIsRejected(): void
    {
        // Telemetry carries request URLs and exception messages; it must not traverse the
        // network in plaintext, even if the connection string asks for it.
        putenv('APPLICATIONINSIGHTS_CONNECTION_STRING=InstrumentationKey=' . self::VALID_IKEY
            . ';IngestionEndpoint=http://insecure.example.com/');

        self::assertSame(
            'https://dc.services.visualstudio.com/v2/track',
            (new Config())->credentials()['endpoint']
        );
    }

    public function testServerSuperglobalIsReadWhenGetenvIsUnavailable(): void
    {
        // Some App Service PHP configurations expose application settings only via $_SERVER.
        $_SERVER['APPLICATIONINSIGHTS_CONNECTION_STRING'] = 'InstrumentationKey=' . self::VALID_IKEY;

        self::assertSame(Config::SOURCE_ENV, (new Config())->source());
    }

    public function testParseConnectionStringLowercasesKeysAndPreservesValues(): void
    {
        $parts = Config::parseConnectionString(
            'InstrumentationKey=ABC;IngestionEndpoint=https://example.com/path;Extra=a=b'
        );

        self::assertSame('ABC', $parts['instrumentationkey']);
        self::assertSame('https://example.com/path', $parts['ingestionendpoint']);
        self::assertSame('a=b', $parts['extra'], 'Values containing = must not be truncated.');
    }

    public function testCredentialsAreMemoised(): void
    {
        putenv('APPLICATIONINSIGHTS_CONNECTION_STRING=InstrumentationKey=' . self::VALID_IKEY);
        $config = new Config();

        self::assertSame(self::VALID_IKEY, $config->credentials()['ikey']);

        $this->clearEnvironment();

        self::assertSame(
            self::VALID_IKEY,
            $config->credentials()['ikey'],
            'Resolution must happen once per request, not once per call.'
        );

        $config->reset();

        self::assertNull($config->credentials());
    }
}
