<?php

declare(strict_types=1);

namespace KloudStack\Observability;

defined('ABSPATH') || exit;

/**
 * Version marker and upgrade routine.
 *
 * Why this exists at all: v2.0.1 changed `enabled` and `client_enabled` from true to false, because
 * WordPress.org guidelines 7 and 9 require transmission to be off until the site owner chooses it.
 * The review was right. The delivery was not.
 *
 * Settings are stored as individual options and read as get_option(PREFIX . $key, $default). A site
 * that had never pressed "Save Changes" had NO rows in the options table — it ran entirely on the
 * defaults in code. So the update did not reset anyone's settings; it changed the value underneath
 * settings that were never stored, and telemetry stopped on every such install without a word.
 *
 * The plugin could not have handled it, because it had no way to know an upgrade had happened:
 * there was no stored version, no upgrade routine, and `kloudstack_obs_schema_version` appeared
 * only in uninstall.php while never being written anywhere.
 *
 * The fix is two-part and the first part is the durable one:
 *
 *   1. Materialise defaults into the options table, so the database is the source of truth and a
 *      future default change cannot retroactively alter an existing install.
 *   2. Tell the owner, rather than silently re-enabling. Restoring telemetry without asking would
 *      recreate precisely what the WordPress.org review objected to.
 *
 * On hook choice: register_activation_hook does NOT fire on update — WordPress does not deactivate
 * and reactivate a plugin it is updating — and it never fires at all for a must-use plugin, which
 * is how managed stacks load this one. upgrader_process_complete fires while the OLD version's code
 * is still loaded, so it cannot run a migration shipped in the new version. A stored version
 * compared on plugins_loaded is what remains, and it is what WordPress core itself does with
 * db_version and wp_upgrade().
 */
final class Upgrade
{
    /** Stored plugin version. Distinct from SCHEMA_VERSION, which versions the telemetry contract. */
    public const VERSION_OPTION = PREFIX . 'version';

    /** Set once the owner has saved the settings page, so we can tell a choice from a default. */
    public const USER_SAVED_OPTION = PREFIX . 'user_saved';

    /** Set when an upgrade found telemetry off without the owner ever having chosen that. */
    public const NOTICE_OPTION = PREFIX . 'optin_notice';

    /**
     * Run pending upgrade work. Cheap and idempotent: one option read on the common path.
     */
    public static function maybeRun(): void
    {
        $stored = (string) get_option(self::VERSION_OPTION, '');

        if ($stored === VERSION) {
            return;
        }

        // An install that predates the version marker but already has settings stored is an
        // upgrade, not a first run. Checked before defaults are materialised, because doing that
        // creates the very rows this looks for.
        $isUpgrade = $stored !== '' || self::hasStoredSettings();

        self::materialiseDefaults();

        // Three conditions, and the last one is the one this originally got wrong: the notice
        // announced that telemetry was off on sites where it was plainly on, because it tested only
        // whether the owner had saved the settings page — and the marker for that is new, so it is
        // absent on every existing install. Read after materialiseDefaults(), so the option exists
        // either way, and through Settings so a host forcing it on by filter also counts.
        if ($isUpgrade && !self::ownerHasChosen() && !(new Settings())->bool('enabled')) {
            update_option(self::NOTICE_OPTION, '1');
        } else {
            // Clears a notice that no longer applies -- including one left behind by the version
            // that raised it wrongly. Without this, a site that was told telemetry was off while it
            // was on keeps being told so until someone dismisses it by hand.
            delete_option(self::NOTICE_OPTION);
        }

        update_option(self::VERSION_OPTION, VERSION);
    }

    /**
     * Write current defaults for any setting not already stored.
     *
     * add_option() does not overwrite, so a value the owner has chosen is never touched. Null and
     * array defaults are skipped: they are computed fallbacks rather than user-facing settings, and
     * writing them would freeze a fallback that is meant to track the code.
     */
    private static function materialiseDefaults(): void
    {
        foreach (Settings::DEFAULTS as $key => $default) {
            if ($default === null || is_array($default)) {
                continue;
            }

            add_option(PREFIX . $key, self::forStorage($default));
        }
    }

    /**
     * WordPress stores a boolean false as an empty string. Writing '1'/'' explicitly keeps the
     * stored shape identical to what the settings form writes, so there is one representation to
     * reason about rather than two.
     *
     * @param bool|int|string $value
     */
    private static function forStorage($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        return (string) $value;
    }

    /**
     * Whether any setting is already stored, i.e. this is not a first run.
     */
    private static function hasStoredSettings(): bool
    {
        foreach (array_keys(Settings::DEFAULTS) as $key) {
            // A sentinel is required: get_option() cannot otherwise distinguish "absent" from
            // "stored as an empty string", and an unchecked toggle is stored as exactly that.
            if (get_option(PREFIX . $key, null) !== null) {
                return true;
            }
        }

        return get_option(PREFIX . 'connection_string', null) !== null;
    }

    /**
     * Whether the owner has ever made a deliberate choice, as opposed to inheriting a default.
     */
    private static function ownerHasChosen(): bool
    {
        return get_option(self::USER_SAVED_OPTION, '') === '1';
    }

    /**
     * Record that the settings page has been saved, so later upgrades can tell a choice from a
     * default and stay quiet.
     */
    public static function recordUserSaved(): void
    {
        update_option(self::USER_SAVED_OPTION, '1');
        delete_option(self::NOTICE_OPTION);
    }

    public static function noticeIsPending(): bool
    {
        return get_option(self::NOTICE_OPTION, '') === '1';
    }

    public static function dismissNotice(): void
    {
        delete_option(self::NOTICE_OPTION);
    }
}
