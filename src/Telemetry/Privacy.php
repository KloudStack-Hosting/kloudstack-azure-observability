<?php

declare(strict_types=1);

namespace KloudStack\Observability\Telemetry;

defined('ABSPATH') || exit;

/**
 * Privacy controls — functional specification section 7.
 *
 * The 1.x plugin transmitted client IP addresses, complete request URIs including query strings,
 * and (because request and response header tracking were both enabled) cookies and authorization
 * headers, to a third-party service, with no disclosure. That is a WordPress.org review failure
 * and, for European sites, a GDPR problem for the site owner.
 *
 * The defaults here are deliberately conservative. A site owner can opt in to more, but nothing
 * sensitive leaves the site because someone did not read the settings page.
 */
final class Privacy
{
    public const REDACTED = '{redacted}';

    /**
     * Query parameters that are never transmitted, regardless of the allowlist.
     *
     * These carry credentials or single-use tokens. A password-reset link in Application Insights
     * is a live account-takeover vector for anyone with read access to the workspace, and reset
     * links routinely end up in telemetry because they are ordinary GET requests.
     */
    private const ALWAYS_REDACT = [
        'key',
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'auth',
        'authorization',
        'password',
        'pass',
        'pwd',
        'secret',
        'client_secret',
        'api_key',
        'apikey',
        'nonce',
        '_wpnonce',
        'session',
        'sessionid',
        'sig',
        'signature',
        'code',
        'state',
    ];

    /**
     * Query parameters retained by default.
     *
     * Chosen because they change what the server does, so a request URL without them is
     * misleading when reading a slow-request trace. None identifies a person.
     */
    private const DEFAULT_ALLOWLIST = [
        'p',
        'page',
        'page_id',
        'paged',
        'post_type',
        'cat',
        'tag',
        'taxonomy',
        'term',
        'order',
        'orderby',
        'feed',
        'preview',
        'lang',
    ];

    /** @var bool */
    private $anonymiseIp;

    /** @var array<int, string> */
    private $allowlist;

    /** @var bool */
    private $captureHeaders;

    /**
     * @param array<int, string>|null $allowlist Null uses the default allowlist.
     */
    public function __construct(
        bool $anonymiseIp = true,
        ?array $allowlist = null,
        bool $captureHeaders = false
    ) {
        $this->anonymiseIp    = $anonymiseIp;
        $this->captureHeaders = $captureHeaders;
        $this->allowlist      = array_map(
            'strtolower',
            $allowlist ?? self::DEFAULT_ALLOWLIST
        );
    }

    /**
     * Whether telemetry may be collected for this request.
     *
     * Defaults to GRANTED, and that is safe only because it is not the outer gate: nothing is
     * collected at all unless the site owner has turned telemetry on, which is off by default.
     * This filter exists so a consent-management plugin can withdraw permission per visitor —
     * `add_filter('kloudstack_obs_has_consent', ...)` returning false suppresses collection for
     * that request, including the client-side SDK and its cookies.
     *
     * Defaulting it to false instead would mean a site owner who deliberately enabled telemetry
     * still got nothing, with no error and no explanation, until they discovered an undocumented
     * filter. That is a worse failure than the one it would prevent, because the opt-in has
     * already been made explicitly one layer up.
     */
    public static function hasConsent(): bool
    {
        return (bool) apply_filters('kloudstack_obs_has_consent', true);
    }

    /**
     * Anonymise an IP address.
     *
     * IPv4 drops the final octet; IPv6 drops the final 80 bits, keeping the /48 prefix. This
     * matches the approach Google Analytics and Matomo use and is what European data protection
     * authorities have accepted as rendering an address non-identifying.
     */
    public function anonymiseIp(string $ip): string
    {
        $ip = trim($ip);

        if ($ip === '') {
            return '';
        }

        if (!$this->anonymiseIp) {
            return filter_var($ip, FILTER_VALIDATE_IP) === false ? '' : $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);

            if ($packed === false) {
                return '';
            }

            // Keep the first 48 bits (6 bytes), zero the remaining 80.
            $masked = substr($packed, 0, 6) . str_repeat("\0", 10);
            $result = inet_ntop($masked);

            return is_string($result) ? $result : '';
        }

        // Not a valid address — emit nothing rather than passing an unknown value through.
        return '';
    }

    /**
     * Scrub a URL's query string against the allowlist.
     *
     * The path is preserved: it is what makes a trace useful. Values of non-allowlisted
     * parameters are replaced rather than removed, so the shape of the request is still visible
     * when diagnosing — "this request carried a token" is useful, the token itself is not.
     */
    public function scrubUrl(string $url): string
    {
        $queryPosition = strpos($url, '?');

        if ($queryPosition === false) {
            return $url;
        }

        $base  = substr($url, 0, $queryPosition);
        $query = substr($url, $queryPosition + 1);

        // Preserve a fragment if one somehow reached the server.
        $fragment      = '';
        $hashPosition  = strpos($query, '#');

        if ($hashPosition !== false) {
            $fragment = substr($query, $hashPosition);
            $query    = substr($query, 0, $hashPosition);
        }

        $scrubbed = $this->scrubQueryString($query);

        return $scrubbed === '' ? $base . $fragment : $base . '?' . $scrubbed . $fragment;
    }

    /**
     * Scrub a raw query string, preserving parameter order and repetition.
     */
    public function scrubQueryString(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $separator = strpos($pair, '=');

            if ($separator === false) {
                // A valueless flag carries no data beyond its own name.
                $pairs[] = $this->isAllowed(rawurldecode($pair)) ? $pair : self::REDACTED;

                continue;
            }

            $name = substr($pair, 0, $separator);

            $pairs[] = $this->isAllowed(rawurldecode($name))
                ? $pair
                : $name . '=' . self::REDACTED;
        }

        return implode('&', $pairs);
    }

    /**
     * Whether a query parameter's value may be transmitted.
     */
    public function isAllowed(string $name): bool
    {
        $normalised = strtolower(trim($name));

        if ($normalised === '') {
            return false;
        }

        // The blocklist wins over the allowlist, always. A site owner who allowlists "token"
        // has almost certainly not thought it through, and the cost of being wrong is a
        // credential in a third-party system.
        if (in_array($normalised, self::ALWAYS_REDACT, true)) {
            return false;
        }

        return in_array($normalised, $this->allowlist, true);
    }

    /**
     * Whether request and response headers may be collected.
     *
     * Off by default. Enabling it transmits Cookie and Authorization headers to Application
     * Insights, which the settings page states explicitly rather than burying.
     */
    public function capturesHeaders(): bool
    {
        return $this->captureHeaders;
    }

    /**
     * The list of parameters that are never transmitted. Exposed for the settings page, so the
     * protection is visible rather than implied.
     *
     * @return array<int, string>
     */
    public static function alwaysRedacted(): array
    {
        return self::ALWAYS_REDACT;
    }

    /**
     * @return array<int, string>
     */
    public static function defaultAllowlist(): array
    {
        return self::DEFAULT_ALLOWLIST;
    }

    /**
     * Resolve the client IP address.
     *
     * On Azure, requests reach PHP through App Service's front end and often Front Door, so
     * REMOTE_ADDR is a proxy address and the client address is the first entry in
     * X-Forwarded-For.
     *
     * X-Forwarded-For is client-controlled and trivially spoofed. That is tolerable here and
     * nowhere else: this value is used only as an anonymised telemetry dimension, never for
     * authentication, rate limiting or access control. It must never be reused for those.
     */
    public function clientIp(): string
    {
        $forwarded = self::server('HTTP_X_FORWARDED_FOR');

        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);

            // App Service appends a port to forwarded addresses: "1.2.3.4:57123".
            if (substr_count($first, ':') === 1 && strpos($first, '.') !== false) {
                $first = strstr($first, ':', true) ?: $first;
            }

            $anonymised = $this->anonymiseIp($first);

            if ($anonymised !== '') {
                return $anonymised;
            }
        }

        return $this->anonymiseIp(self::server('REMOTE_ADDR'));
    }

    private static function server(string $key): string
    {
        if (!isset($_SERVER[$key]) || !is_string($_SERVER[$key])) {
            return '';
        }

        return trim(sanitize_text_field(wp_unslash($_SERVER[$key])));
    }
}
