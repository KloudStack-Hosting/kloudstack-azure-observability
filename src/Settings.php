<?php

declare(strict_types=1);

namespace KloudStack\Observability;

defined('ABSPATH') || exit;

/**
 * Typed access to plugin settings.
 *
 * Every setting is filterable as kloudstack_obs_setting_{key}, so managed stacks and site owners
 * can override in code without touching the database. Values are read through here rather than
 * with get_option() scattered through the codebase, so the filter contract holds everywhere and
 * defaults live in one place.
 */
final class Settings
{
    /**
     * Defaults. Conservative by design — see the functional specification section 7. A site owner
     * can opt in to more, but nothing sensitive is transmitted because someone did not read the
     * settings page.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'enabled'                => true,

        /*
         * Browser telemetry is OPT-IN. It injects Microsoft's Application Insights JavaScript SDK
         * into every page and records visitor activity, which WordPress.org guidelines 7 and 9
         * require to be off until the site owner turns it on. Server-side telemetry above is a
         * different case: it transmits only to the owner's own Application Insights resource and
         * only once they supply a connection string, so nothing leaves a site that has not been
         * configured.
         */
        'client_enabled'         => false,

        'sampling_requests'      => 100,
        'sampling_dependencies'  => 100,
        'cloud_role'             => '',
        'anonymise_ip'           => true,
        'query_allowlist'        => null,
        'header_tracking'        => false,

        /*
         * Cookie-less by default. With cookies the SDK sets ai_user and ai_session, which in most
         * jurisdictions needs visitor consent before the first page view — an obligation the site
         * owner inherits simply by enabling browser telemetry. Defaulting off means switching
         * browser telemetry on cannot silently create that obligation.
         *
         * The trade-off is real and documented in readme.txt and README.md: without cookies there
         * is no session or user aggregation, so Application Insights reports page and dependency
         * timings but not "users" or "sessions". An owner who needs those can turn cookies on and
         * take on the consent duty, ideally driving the kloudstack_obs_has_consent filter from a
         * consent-management plugin.
         */
        'cookieless'             => true,
        'excluded_paths'         => [],
        'track_admin'            => true,
        'track_cron'             => false,
        'track_php_errors'       => false,
        'bundled_sdk'            => false,
        'debug_log'              => false,
    ];

    /** @var array<string, mixed> */
    private $cache = [];

    /**
     * @return mixed
     */
    public function get(string $key)
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $default = self::DEFAULTS[$key] ?? null;
        $value   = get_option(PREFIX . $key, $default);

        // An option that has never been written returns the default; an option written as an
        // empty string should still fall back for typed settings rather than becoming ''.
        if ($value === '' && $default !== '') {
            $value = $default;
        }

        return $this->cache[$key] = apply_filters('kloudstack_obs_setting_' . $key, $value);
    }

    public function bool(string $key): bool
    {
        $value = $this->get($key);

        // Options round-trip through the database as "1"/"" strings, so a plain cast is not
        // enough — "0" is truthy as a string in some code paths that hand values back.
        if (is_string($value)) {
            return $value !== '' && $value !== '0' && strtolower($value) !== 'false';
        }

        return (bool) $value;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @return array<int, string>
     */
    public function stringList(string $key): array
    {
        $value = $this->get($key);

        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $entry) {
            if (!is_scalar($entry)) {
                continue;
            }

            $entry = trim((string) $entry);

            if ($entry !== '') {
                $list[] = $entry;
            }
        }

        return $list;
    }

    /**
     * Sampling rate, clamped to a sane range.
     */
    public function samplingRate(string $key = 'sampling_requests'): int
    {
        $rate = $this->int($key);

        if ($rate < 1) {
            return 1;
        }

        return $rate > 100 ? 100 : $rate;
    }

    /**
     * The query-string allowlist, or null to use the built-in default.
     *
     * @return array<int, string>|null
     */
    public function queryAllowlist(): ?array
    {
        $value = $this->get('query_allowlist');

        if ($value === null || $value === '') {
            return null;
        }

        return $this->stringList('query_allowlist');
    }

    /**
     * Reset memoised values. Test seam.
     */
    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }
}
