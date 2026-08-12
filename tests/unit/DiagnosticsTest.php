<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Admin\Diagnostics;
use KloudStack\Observability\Config;
use KloudStack\Observability\Plugin;
use KloudStack\Observability\Settings;
use PHPUnit\Framework\TestCase;
use WPStubs;

/**
 * The self-test is what turns "telemetry isn't appearing" from a support conversation into a
 * pasted report. Its cardinal rule is that it must never claim things are fine when it does not
 * know — a confidently wrong self-test is worse than none.
 *
 * @covers \KloudStack\Observability\Admin\Diagnostics
 */
final class DiagnosticsTest extends TestCase
{
    private const VALID_CONNECTION = 'InstrumentationKey=11111111-2222-3333-4444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        WPStubs::reset();
        $this->clearEnvironment();

        // Telemetry defaults OFF from 2.0.1 (opt-in). The "disabled" check short-circuits the
        // ones after it, so without this every test here would exercise the disabled path
        // instead of the branch it names. Tests that care about the disabled path set it back
        // to false themselves.
        WPStubs::$options['kloudstack_obs_enabled'] = true;
    }

    protected function tearDown(): void
    {
        $this->clearEnvironment();
        parent::tearDown();
    }

    private function clearEnvironment(): void
    {
        foreach (
            [
            'APPLICATIONINSIGHTS_CONNECTION_STRING',
            'APPINSIGHTS_INSTRUMENTATIONKEY',
            'ApplicationInsightsAgent_EXTENSION_VERSION',
            'WEBSITE_SITE_NAME',
            'REGION_NAME',
            ] as $name
        ) {
            putenv($name);
            unset($_SERVER[$name]);
        }
    }

    /**
     * @param array{status: int, error?: string}|null $response
     */
    private function diagnostics(?array $response = null): Diagnostics
    {
        $sender = $response === null
            ? null
            : static function () use ($response): array {
                return $response;
            };

        return new Diagnostics(new Config(), new Settings(), new Plugin(), $sender);
    }

    /**
     * @param array<int, array{id: string, label: string, status: string, message: string}> $checks
     *
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function find(array $checks, string $id): array
    {
        foreach ($checks as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }

        self::fail('No check with id ' . $id);
    }

    // ── Configuration ───────────────────────────────────────────────────────────────────────

    public function testUnconfiguredSiteFailsWithActionableAdvice(): void
    {
        $check = $this->find($this->diagnostics()->run(false), 'configuration');

        self::assertSame(Diagnostics::STATUS_FAIL, $check['status']);
        self::assertStringContainsString('APPLICATIONINSIGHTS_CONNECTION_STRING', $check['message']);
    }

    public function testConfiguredSitePassesAndNamesTheSource(): void
    {
        putenv('APPLICATIONINSIGHTS_CONNECTION_STRING=' . self::VALID_CONNECTION);

        $check = $this->find($this->diagnostics()->run(false), 'configuration');

        self::assertSame(Diagnostics::STATUS_PASS, $check['status']);
        self::assertStringContainsString('environment variable', $check['message']);
    }

    public function testLegacyInstrumentationKeyWarns(): void
    {
        putenv('APPINSIGHTS_INSTRUMENTATIONKEY=11111111-2222-3333-4444-555555555555');

        $check = $this->find($this->diagnostics()->run(false), 'configuration');

        self::assertSame(Diagnostics::STATUS_WARN, $check['status']);
        self::assertStringContainsString('deprecated', $check['message']);
    }

    // ── The checks that catch real support cases ────────────────────────────────────────────

    public function testDuplicateInstrumentationIsDetected(): void
    {
        // Both the extension and this plugin recording requests means double telemetry and double
        // ingestion cost, and the customer sees a doubled request count with no obvious cause.
        putenv('ApplicationInsightsAgent_EXTENSION_VERSION=~3');

        $check = $this->find($this->diagnostics()->run(false), 'duplicate');

        self::assertSame(Diagnostics::STATUS_FAIL, $check['status']);
        self::assertStringContainsString('twice', $check['message']);
    }

    public function testLegacyMuPluginIsDetectedAsDuplicate(): void
    {
        // A live stack proved this: the 1.x file was renamed to .disabled, the platform re-pushed
        // it 40 minutes later, and both versions recorded every request while this check reported
        // no conflict. It is invisible from the Plugins screen, so the self-test is the only
        // place an administrator can find out.
        $dir = sys_get_temp_dir() . '/ksobs-mu-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/kloudstack-appinsights.php', '<?php // 1.x');

        if (!defined('WPMU_PLUGIN_DIR')) {
            define('WPMU_PLUGIN_DIR', $dir);
        }

        $check = $this->find($this->diagnostics()->run(false), 'duplicate');

        unlink($dir . '/kloudstack-appinsights.php');
        rmdir($dir);

        if (WPMU_PLUGIN_DIR !== $dir) {
            self::markTestSkipped('WPMU_PLUGIN_DIR already defined by another test.');
        }

        self::assertSame(Diagnostics::STATUS_FAIL, $check['status'], 'Double-billing is a failure, not a warning.');
        self::assertStringContainsString('1.x must-use plugin', $check['message']);
        self::assertStringContainsString('twice', $check['message']);
    }

    public function testDisabledExtensionIsNotReportedAsDuplicate(): void
    {
        // App Service writes "~0" rather than removing the variable when the extension is off.
        putenv('ApplicationInsightsAgent_EXTENSION_VERSION=~0');

        self::assertSame(
            Diagnostics::STATUS_PASS,
            $this->find($this->diagnostics()->run(false), 'duplicate')['status']
        );
    }

    public function testTelemetryDisabledIsSurfacedRatherThanLookingHealthy(): void
    {
        WPStubs::$options['kloudstack_obs_enabled'] = false;

        $check = $this->find($this->diagnostics()->run(false), 'enabled');

        self::assertSame(Diagnostics::STATUS_WARN, $check['status']);
    }

    public function testWithheldConsentIsSurfaced(): void
    {
        // Browser telemetry is opt-in, so it has to be switched on to reach the consent branch at
        // all — the "disabled" check short-circuits ahead of it. Without this the test silently
        // asserts the wrong thing.
        WPStubs::$options['kloudstack_obs_client_enabled'] = true;

        // Otherwise this presents as "browser telemetry mysteriously missing".
        WPStubs::$filters['kloudstack_obs_has_consent'] = static function (): bool {
            return false;
        };

        $check = $this->find($this->diagnostics()->run(false), 'client');

        self::assertSame(Diagnostics::STATUS_WARN, $check['status']);
        self::assertStringContainsString('consent', $check['message']);
    }

    public function testSamplingIsExplainedRatherThanLookingLikeDataLoss(): void
    {
        WPStubs::$options['kloudstack_obs_sampling_requests'] = 10;

        $check = $this->find($this->diagnostics()->run(false), 'sampling');

        self::assertSame(Diagnostics::STATUS_PASS, $check['status']);
        self::assertStringContainsString('10%', $check['message']);
    }

    public function testNonAzureHostingWarnsWithoutClaimingFailure(): void
    {
        $check = $this->find($this->diagnostics()->run(false), 'azure');

        self::assertSame(Diagnostics::STATUS_WARN, $check['status']);
        self::assertStringContainsString('still works', $check['message']);
    }

    public function testAzureContextIsReportedWhenDetected(): void
    {
        putenv('WEBSITE_SITE_NAME=contoso-wp');
        putenv('REGION_NAME=Australia East');

        $check = $this->find($this->diagnostics()->run(false), 'azure');

        self::assertSame(Diagnostics::STATUS_PASS, $check['status']);
        self::assertStringContainsString('contoso-wp', $check['message']);
        self::assertStringContainsString('australiaeast', $check['message']);
    }

    // ── Live round trip ─────────────────────────────────────────────────────────────────────

    public function testLiveCheckIsSkippedWhenUnconfigured(): void
    {
        // Nothing to send to, and reporting a failure would be noise on top of the real problem.
        foreach ($this->diagnostics()->run(true) as $check) {
            self::assertNotSame('live', $check['id']);
        }
    }

    public function testLiveCheckPassesWhenAzureAccepts(): void
    {
        putenv('APPLICATIONINSIGHTS_CONNECTION_STRING=' . self::VALID_CONNECTION);

        $check = $this->find($this->diagnostics(['status' => 200])->run(true), 'live');

        self::assertSame(Diagnostics::STATUS_PASS, $check['status']);
        self::assertStringContainsString('customEvents', $check['message'], 'Must say where to look.');
    }

    public function testLiveCheckFailsWithTheUnderlyingReason(): void
    {
        putenv('APPLICATIONINSIGHTS_CONNECTION_STRING=' . self::VALID_CONNECTION);

        $check = $this->find(
            $this->diagnostics(['status' => 0, 'error' => 'Could not resolve host'])->run(true),
            'live'
        );

        self::assertSame(Diagnostics::STATUS_FAIL, $check['status']);
        self::assertStringContainsString('Could not resolve host', $check['message']);
    }

    public function testLiveCheckDoesNotTripTheProductionBreaker(): void
    {
        // A failing self-test must not suspend the site's real telemetry.
        putenv('APPLICATIONINSIGHTS_CONNECTION_STRING=' . self::VALID_CONNECTION);

        $this->diagnostics(['status' => 0, 'error' => 'down'])->run(true);

        self::assertArrayNotHasKey(
            'kloudstack_obs_breaker',
            WPStubs::$transients,
            'The self-test must use its own breaker.'
        );
    }

    // ── Reporting ───────────────────────────────────────────────────────────────────────────

    public function testHasFailuresReflectsCheckStatuses(): void
    {
        self::assertTrue($this->diagnostics()->hasFailures(false), 'Unconfigured is a failure.');

        putenv('APPLICATIONINSIGHTS_CONNECTION_STRING=' . self::VALID_CONNECTION);

        self::assertFalse($this->diagnostics()->hasFailures(false));
    }

    public function testTextReportIsPasteableAndCarriesVersions(): void
    {
        $text = $this->diagnostics()->asText(false);

        self::assertStringContainsString('KloudStack Observability for Azure', $text);
        self::assertStringContainsString('PHP ' . PHP_VERSION, $text);
        self::assertStringContainsString('[FAIL] Connection', $text);
    }

    public function testTextReportCanRenderAPreComputedResult(): void
    {
        // The settings page has already run the full check including the live round-trip. Passing
        // it back avoids both re-running the round-trip and silently dropping it from the report.
        $checks = [
            ['id' => 'live', 'label' => 'Test telemetry', 'status' => Diagnostics::STATUS_PASS, 'message' => 'Accepted by Azure in 210ms.'],
        ];

        $text = $this->diagnostics()->asText(false, $checks);

        self::assertStringContainsString('[PASS] Test telemetry: Accepted by Azure in 210ms.', $text);
        self::assertStringNotContainsString('Connection', $text, 'Only the supplied checks should be rendered.');
    }

    public function testEveryCheckReportsAKnownStatus(): void
    {
        foreach ($this->diagnostics()->run(false) as $check) {
            self::assertContains($check['status'], [
                Diagnostics::STATUS_PASS,
                Diagnostics::STATUS_WARN,
                Diagnostics::STATUS_FAIL,
                Diagnostics::STATUS_UNKNOWN,
            ], 'Check ' . $check['id'] . ' reported an unrecognised status.');

            self::assertNotSame('', $check['message'], 'Check ' . $check['id'] . ' gave no explanation.');
        }
    }
}
