<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Settings;
use KloudStack\Observability\Upgrade;
use PHPUnit\Framework\TestCase;
use WPStubs;

use const KloudStack\Observability\PREFIX;
use const KloudStack\Observability\VERSION;

/**
 * The upgrade routine exists because v2.0.1 changed the telemetry defaults from true to false and
 * every install that had never pressed "Save Changes" stopped sending telemetry without a word.
 * Nothing was reset — the settings had never been stored, so the change of default underneath them
 * changed their behaviour.
 *
 * These tests pin the property that prevents a repeat: after an upgrade runs, the database, not a
 * constant in the source, decides what an existing install does.
 *
 * @covers \KloudStack\Observability\Upgrade
 */
final class UpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WPStubs::reset();
    }

    public function testMaterialisesDefaultsSoALaterDefaultChangeCannotAlterBehaviour(): void
    {
        Upgrade::maybeRun();

        // The scalar settings must now exist as rows. Nulls and arrays are computed fallbacks that
        // are meant to track the code, so they are deliberately not frozen.
        self::assertSame('', get_option(PREFIX . 'enabled', null), 'A false default stores as "".');
        self::assertSame('1', get_option(PREFIX . 'anonymise_ip', null), 'A true default stores as "1".');
        self::assertSame('100', get_option(PREFIX . 'sampling_requests', null));

        self::assertNull(get_option(PREFIX . 'query_allowlist', null), 'Null defaults stay unwritten.');
        self::assertNull(get_option(PREFIX . 'excluded_paths', null), 'Array defaults stay unwritten.');
    }

    public function testNeverOverwritesAValueTheOwnerHasChosen(): void
    {
        update_option(PREFIX . 'enabled', '1');
        update_option(PREFIX . 'anonymise_ip', '');

        Upgrade::maybeRun();

        self::assertSame('1', get_option(PREFIX . 'enabled', null), 'A chosen value must survive.');
        self::assertSame(
            '',
            get_option(PREFIX . 'anonymise_ip', null),
            'A deliberately cleared toggle must not be restored to its default.'
        );
    }

    public function testRecordsTheVersionSoTheRoutineIsNotRepeated(): void
    {
        Upgrade::maybeRun();

        self::assertSame(VERSION, get_option(PREFIX . 'version', null));
    }

    public function testUpgradeFromAnUnmarkedInstallRaisesTheNotice(): void
    {
        // A site that predates the version marker but has settings stored is an upgrade, and its
        // owner never chose the new opt-in default — they inherited it.
        update_option(PREFIX . 'connection_string', 'InstrumentationKey=abc');

        Upgrade::maybeRun();

        self::assertTrue(Upgrade::noticeIsPending(), 'The owner must be told telemetry is now off.');
    }

    public function testNoNoticeWhenTelemetryIsAlreadyOn(): void
    {
        // Caught on kloudstack.dev: the notice announced "telemetry is currently off on this site"
        // on a site where both switches were plainly on. The condition tested only whether the
        // owner had saved the settings page, and that marker is introduced by this very version --
        // so it is absent on every existing install, and the notice fired for all of them.
        update_option(PREFIX . 'connection_string', 'InstrumentationKey=abc');
        update_option(PREFIX . 'enabled', '1');

        Upgrade::maybeRun();

        self::assertFalse(
            Upgrade::noticeIsPending(),
            'A site with telemetry switched on has lost nothing and must not be told otherwise.'
        );
    }

    public function testNoticeStillRaisedWhenTelemetryIsActuallyOff(): void
    {
        update_option(PREFIX . 'connection_string', 'InstrumentationKey=abc');
        update_option(PREFIX . 'enabled', '');

        Upgrade::maybeRun();

        self::assertTrue(Upgrade::noticeIsPending());
    }

    public function testTheNextUpgradeClearsAStaleNotice(): void
    {
        // A site that received the wrong notice must be released from it by the upgrade that fixes
        // the bug, not left to dismiss it by hand.
        update_option(PREFIX . 'connection_string', 'InstrumentationKey=abc');
        update_option(PREFIX . 'enabled', '1');
        update_option(PREFIX . 'optin_notice', '1');
        update_option(PREFIX . 'version', '2.0.8-dev-old');

        Upgrade::maybeRun();

        self::assertFalse(Upgrade::noticeIsPending());
    }

    public function testFirstInstallIsSilent(): void
    {
        Upgrade::maybeRun();

        self::assertFalse(
            Upgrade::noticeIsPending(),
            'A fresh install has lost nothing, so it has nothing to be warned about.'
        );
    }

    public function testAnOwnerWhoHasChosenIsNotNagged(): void
    {
        update_option(PREFIX . 'connection_string', 'InstrumentationKey=abc');
        Upgrade::recordUserSaved();

        Upgrade::maybeRun();

        self::assertFalse(Upgrade::noticeIsPending());
    }

    public function testTelemetryIsNeverSilentlyReEnabled(): void
    {
        // Restoring the old default would undo the WordPress.org opt-in requirement. The upgrade
        // path is allowed to inform, never to switch transmission back on.
        update_option(PREFIX . 'connection_string', 'InstrumentationKey=abc');

        Upgrade::maybeRun();

        $settings = new Settings();

        self::assertFalse($settings->bool('enabled'));
        self::assertFalse($settings->bool('client_enabled'));
    }

    public function testSavingSettingsClearsTheNotice(): void
    {
        update_option(PREFIX . 'connection_string', 'InstrumentationKey=abc');
        Upgrade::maybeRun();
        self::assertTrue(Upgrade::noticeIsPending());

        Upgrade::recordUserSaved();

        self::assertFalse(Upgrade::noticeIsPending());
    }

    public function testRunningTwiceIsANoOp(): void
    {
        Upgrade::maybeRun();
        update_option(PREFIX . 'enabled', '1');
        Upgrade::maybeRun();

        self::assertSame('1', get_option(PREFIX . 'enabled', null));
    }
}
