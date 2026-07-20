<?php

declare(strict_types=1);

namespace KloudStack\Observability;

use KloudStack\Observability\Support\Log;

defined('ABSPATH') || exit;

/**
 * Configuration resolution.
 *
 * Implements the precedence chain from the functional specification, section 4.1:
 *
 *   1. KLOUDSTACK_OBS_CONNECTION_STRING constant   (wp-config.php)
 *   2. APPLICATIONINSIGHTS_CONNECTION_STRING       (environment)
 *   3. APPINSIGHTS_INSTRUMENTATIONKEY              (environment, legacy)
 *   4. kloudstack_obs_connection_string            (option, settings page)
 *
 * Environment beats the database deliberately. On KloudStack managed stacks and on Marketplace
 * deployments the App Service application setting is authoritative, and a site administrator
 * should not be able to silently redirect a site's telemetry by editing an option.
 */
final class Config
{
    public const SOURCE_CONSTANT = 'constant';
    public const SOURCE_ENV      = 'environment';
    public const SOURCE_ENV_IKEY = 'environment-legacy';
    public const SOURCE_OPTION   = 'option';
    public const SOURCE_NONE     = 'none';

    private const DEFAULT_ENDPOINT = 'https://dc.services.visualstudio.com/';

    /** @var array<string, mixed>|null */
    private $resolved = null;

    /**
     * Resolved credentials, or null when the plugin is unconfigured.
     *
     * @return array{ikey: string, endpoint: string, connection_string: string, source: string}|null
     */
    public function credentials(): ?array
    {
        if ($this->resolved === null) {
            $this->resolved = $this->resolve() ?? [];
        }

        /** @var array{ikey: string, endpoint: string, connection_string: string, source: string}|array{} $resolved */
        $resolved = $this->resolved;

        return $resolved === [] ? null : $resolved;
    }

    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * Where the active configuration came from. Surfaced in the settings page and diagnostics so
     * an administrator can see why a value is read-only.
     */
    public function source(): string
    {
        $credentials = $this->credentials();

        return $credentials['source'] ?? self::SOURCE_NONE;
    }

    /**
     * True when configuration comes from a source the site administrator cannot edit in the
     * WordPress admin.
     */
    public function isLocked(): bool
    {
        return in_array(
            $this->source(),
            [self::SOURCE_CONSTANT, self::SOURCE_ENV, self::SOURCE_ENV_IKEY],
            true
        );
    }

    /**
     * True when the plugin is running under the KloudStack managed image, which suppresses
     * auto-update and hides connection configuration.
     */
    public function isManaged(): bool
    {
        return defined('KLOUDSTACK_OBS_MANAGED') && (bool) constant('KLOUDSTACK_OBS_MANAGED');
    }

    /**
     * Reset memoised state. Test seam.
     */
    public function reset(): void
    {
        $this->resolved = null;
    }

    /**
     * @return array{ikey: string, endpoint: string, connection_string: string, source: string}|null
     */
    private function resolve(): ?array
    {
        if (defined('KLOUDSTACK_OBS_CONNECTION_STRING')) {
            $candidate = (string) constant('KLOUDSTACK_OBS_CONNECTION_STRING');
            $resolved  = $this->fromConnectionString($candidate, self::SOURCE_CONSTANT);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        $env = $this->env('APPLICATIONINSIGHTS_CONNECTION_STRING');

        if ($env !== '') {
            $resolved = $this->fromConnectionString($env, self::SOURCE_ENV);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        $legacy = $this->env('APPINSIGHTS_INSTRUMENTATIONKEY');

        if ($legacy !== '' && $this->isValidIkey($legacy)) {
            Log::warning('Using deprecated APPINSIGHTS_INSTRUMENTATIONKEY. Migrate to a connection string.');

            return [
                'ikey'              => $legacy,
                'endpoint'          => self::DEFAULT_ENDPOINT . 'v2/track',
                'connection_string' => '',
                'source'            => self::SOURCE_ENV_IKEY,
            ];
        }

        $option = get_option(PREFIX . 'connection_string', '');

        if (is_string($option) && $option !== '') {
            return $this->fromConnectionString($option, self::SOURCE_OPTION);
        }

        return null;
    }

    /**
     * Build credentials from a connection string, validating the instrumentation key.
     *
     * A malformed connection string is treated as absent rather than passed through: telemetry
     * sent with an invalid key is silently discarded by Azure, which is far harder for a customer
     * to diagnose than the plugin reporting itself as unconfigured.
     *
     * @return array{ikey: string, endpoint: string, connection_string: string, source: string}|null
     */
    private function fromConnectionString(string $connectionString, string $source): ?array
    {
        $connectionString = trim($connectionString);

        if ($connectionString === '') {
            return null;
        }

        $parts = self::parseConnectionString($connectionString);
        $ikey  = $parts['instrumentationkey'] ?? '';

        if (!$this->isValidIkey($ikey)) {
            Log::warning('Connection string present but instrumentation key is invalid.', [
                'source' => $source,
            ]);

            return null;
        }

        return [
            'ikey'              => $ikey,
            'endpoint'          => self::resolveEndpoint($parts),
            'connection_string' => $connectionString,
            'source'            => $source,
        ];
    }

    /**
     * Parse a connection string into lower-cased keys.
     *
     * Format: "InstrumentationKey=abc;IngestionEndpoint=https://...;LiveEndpoint=https://..."
     *
     * @return array<string, string>
     */
    public static function parseConnectionString(string $connectionString): array
    {
        $parts = [];

        foreach (explode(';', $connectionString) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            $position = strpos($segment, '=');

            if ($position === false || $position === 0) {
                continue;
            }

            $key   = strtolower(trim(substr($segment, 0, $position)));
            $value = trim(substr($segment, $position + 1));

            if ($key !== '') {
                $parts[$key] = $value;
            }
        }

        return $parts;
    }

    /**
     * Determine the ingestion endpoint.
     *
     * Falls back to deriving from EndpointSuffix (sovereign clouds such as Azure Government and
     * Azure China set this instead of an explicit IngestionEndpoint), then to the global default.
     *
     * @param array<string, string> $parts
     */
    private static function resolveEndpoint(array $parts): string
    {
        $base = $parts['ingestionendpoint'] ?? '';

        if ($base === '' && isset($parts['endpointsuffix']) && $parts['endpointsuffix'] !== '') {
            $location = $parts['location'] ?? '';
            $base     = 'https://' . ($location !== '' ? $location . '.' : '') . 'dc.' . $parts['endpointsuffix'];
        }

        if ($base === '') {
            $base = self::DEFAULT_ENDPOINT;
        }

        // Only https endpoints are accepted — telemetry may carry request URLs and exception
        // messages, and must never traverse the network in plaintext.
        if (stripos($base, 'https://') !== 0) {
            Log::warning('Ingestion endpoint is not https; falling back to the default endpoint.');
            $base = self::DEFAULT_ENDPOINT;
        }

        return rtrim($base, '/') . '/v2/track';
    }

    /**
     * Azure instrumentation keys are GUIDs. Anything else is a configuration error.
     */
    private function isValidIkey(string $ikey): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $ikey
        );
    }

    /**
     * Read an environment variable.
     *
     * App Service exposes application settings through both getenv() and $_SERVER depending on
     * the SAPI and PHP configuration, so both are checked.
     */
    private function env(string $name): string
    {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return trim($value);
        }

        if (!isset($_SERVER[$name]) || !is_string($_SERVER[$name])) {
            return '';
        }

        // WordPress slash-escapes superglobals, so unslash before use. Sanitising is belt and
        // braces: the value is structurally validated by the caller — the instrumentation key
        // must be a GUID and the ingestion endpoint must be https — which is a stronger
        // guarantee than escaping alone.
        return trim(sanitize_text_field(wp_unslash($_SERVER[$name])));
    }
}
