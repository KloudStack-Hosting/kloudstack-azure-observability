<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Telemetry\Privacy;
use PHPUnit\Framework\TestCase;
use WPStubs;

/**
 * Privacy failures are silent and expensive: nobody notices a password-reset token in
 * Application Insights until someone goes looking, and by then it has been retained for the
 * workspace's full retention period.
 *
 * @covers \KloudStack\Observability\Telemetry\Privacy
 */
final class PrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WPStubs::reset();
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    // ── IP anonymisation ────────────────────────────────────────────────────────────────────

    public function testIpv4LosesFinalOctet(): void
    {
        self::assertSame('203.0.113.0', (new Privacy())->anonymiseIp('203.0.113.42'));
    }

    public function testIpv6LosesFinalEightyBits(): void
    {
        // Keeps the /48 prefix, which is the network, and drops everything identifying below it.
        self::assertSame('2001:db8:1234::', (new Privacy())->anonymiseIp('2001:db8:1234:5678:9abc:def0:1234:5678'));
    }

    public function testAnonymisationCanBeDisabled(): void
    {
        self::assertSame('203.0.113.42', (new Privacy(false))->anonymiseIp('203.0.113.42'));
    }

    /**
     * @dataProvider invalidAddresses
     */
    public function testInvalidAddressesEmitNothing(string $input): void
    {
        // Passing an unrecognised value through would leak whatever a client put in the header.
        self::assertSame('', (new Privacy())->anonymiseIp($input));
        self::assertSame('', (new Privacy(false))->anonymiseIp($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAddresses(): array
    {
        return [
            'empty'        => [''],
            'whitespace'   => ['   '],
            'not an ip'    => ['not-an-ip'],
            'partial'      => ['203.0.113'],
            'injected'     => ['203.0.113.42; DROP TABLE'],
            'hostname'     => ['example.com'],
        ];
    }

    // ── Query string scrubbing ──────────────────────────────────────────────────────────────

    public function testAllowlistedParametersSurvive(): void
    {
        self::assertSame(
            '/shop?post_type=product&orderby=price',
            (new Privacy())->scrubUrl('/shop?post_type=product&orderby=price')
        );
    }

    public function testUnknownParametersAreRedacted(): void
    {
        // The parameter name is kept so the request's shape stays diagnosable; the value is not.
        self::assertSame(
            '/search?q=' . Privacy::REDACTED,
            (new Privacy())->scrubUrl('/search?q=confidential+search+term')
        );
    }

    /**
     * @dataProvider credentialParameters
     */
    public function testCredentialsAreAlwaysRedacted(string $parameter): void
    {
        $scrubbed = (new Privacy())->scrubUrl('/reset?' . $parameter . '=SECRETVALUE');

        self::assertStringNotContainsString('SECRETVALUE', $scrubbed);
        self::assertStringContainsString(Privacy::REDACTED, $scrubbed);
    }

    /**
     * A password-reset link in Application Insights is a live account-takeover vector for anyone
     * with read access to the workspace, and reset links are ordinary GET requests.
     *
     * @return array<string, array{string}>
     */
    public static function credentialParameters(): array
    {
        return [
            'reset key'     => ['key'],
            'token'         => ['token'],
            'access token'  => ['access_token'],
            'password'      => ['password'],
            'api key'       => ['api_key'],
            'wp nonce'      => ['_wpnonce'],
            'oauth code'    => ['code'],
            'oauth state'   => ['state'],
            'signature'     => ['sig'],
        ];
    }

    public function testBlocklistBeatsAllowlist(): void
    {
        // A site owner who allowlists "token" has probably not thought it through, and the cost
        // of being wrong is a credential sitting in a third-party system.
        $privacy = new Privacy(true, ['token', 'password', 'page']);

        self::assertFalse($privacy->isAllowed('token'));
        self::assertFalse($privacy->isAllowed('password'));
        self::assertTrue($privacy->isAllowed('page'));
    }

    public function testCaseInsensitiveMatching(): void
    {
        $scrubbed = (new Privacy())->scrubUrl('/x?TOKEN=abc&Access_Token=def');

        self::assertStringNotContainsString('abc', $scrubbed);
        self::assertStringNotContainsString('def', $scrubbed);
    }

    public function testUrlWithoutQueryStringIsUntouched(): void
    {
        self::assertSame('/about/team/', (new Privacy())->scrubUrl('/about/team/'));
    }

    public function testPathIsAlwaysPreserved(): void
    {
        // The path is what makes a slow-request trace useful; only values are removed.
        self::assertStringStartsWith(
            'https://example.com/very/specific/path',
            (new Privacy())->scrubUrl('https://example.com/very/specific/path?secret=x')
        );
    }

    public function testValuelessFlagsAreHandled(): void
    {
        self::assertSame('/x?preview', (new Privacy())->scrubUrl('/x?preview'));
        self::assertSame('/x?' . Privacy::REDACTED, (new Privacy())->scrubUrl('/x?mysteryflag'));
    }

    public function testRepeatedParametersArePreservedIndividually(): void
    {
        self::assertSame(
            '/x?cat=1&cat=2',
            (new Privacy())->scrubUrl('/x?cat=1&cat=2')
        );
    }

    public function testEncodedParameterNamesAreDecodedBeforeMatching(): void
    {
        // Percent-encoding a parameter name must not slip it past the blocklist.
        $scrubbed = (new Privacy())->scrubUrl('/x?%74%6f%6b%65%6e=SECRETVALUE');

        self::assertStringNotContainsString('SECRETVALUE', $scrubbed);
    }

    // ── Headers and consent ─────────────────────────────────────────────────────────────────

    public function testHeaderCaptureIsOffByDefault(): void
    {
        // Enabling this transmits Cookie and Authorization headers to Azure.
        self::assertFalse((new Privacy())->capturesHeaders());
        self::assertTrue((new Privacy(true, null, true))->capturesHeaders());
    }

    public function testConsentDefaultsToGranted(): void
    {
        self::assertTrue(Privacy::hasConsent());
    }

    public function testConsentCanBeWithdrawnByFilter(): void
    {
        WPStubs::$filters['kloudstack_obs_has_consent'] = static function (): bool {
            return false;
        };

        self::assertFalse(Privacy::hasConsent());
    }

    // ── Client IP resolution ────────────────────────────────────────────────────────────────

    public function testClientIpPrefersForwardedHeaderOnAzure(): void
    {
        // On App Service and behind Front Door, REMOTE_ADDR is a proxy address.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.42, 70.41.3.18';
        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';

        self::assertSame('203.0.113.0', (new Privacy())->clientIp());
    }

    public function testClientIpStripsAppServicePortSuffix(): void
    {
        // App Service appends a source port: "1.2.3.4:57123".
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.42:57123';

        self::assertSame('203.0.113.0', (new Privacy())->clientIp());
    }

    public function testClientIpFallsBackToRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        self::assertSame('198.51.100.0', (new Privacy())->clientIp());
    }

    public function testSpoofedForwardedHeaderFallsBackRatherThanLeaking(): void
    {
        // X-Forwarded-For is client-controlled. Garbage must not reach telemetry verbatim.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
        $_SERVER['REMOTE_ADDR']          = '198.51.100.7';

        self::assertSame('198.51.100.0', (new Privacy())->clientIp());
    }

    public function testClientIpIsEmptyWhenNothingIsAvailable(): void
    {
        self::assertSame('', (new Privacy())->clientIp());
    }
}
