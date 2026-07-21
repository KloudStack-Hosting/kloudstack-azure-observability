<?php

declare(strict_types=1);

namespace KloudStack\Observability\Collector;

use KloudStack\Observability\Context\Correlation;
use KloudStack\Observability\Context\WordPressContext;
use KloudStack\Observability\Settings;
use KloudStack\Observability\Support\Guard;
use KloudStack\Observability\Telemetry\Privacy;
use KloudStack\Observability\Telemetry\Reporter;

defined('ABSPATH') || exit;

/**
 * Request telemetry.
 *
 * Registers a shutdown handler at construction time so it fires for every request, including
 * those that end before WordPress finishes initialising — a fatal during plugin load still
 * produces a request item.
 *
 * The exclusion rules matter more than the collection does. Cron, heartbeat and health checks are
 * high-volume and low-value: including them inflates the customer's ingestion bill and drags
 * every performance percentile toward background work that no visitor is waiting on.
 */
final class RequestCollector
{
    /**
     * Paths excluded regardless of settings.
     *
     * These are machine traffic. A site owner who wants them can add them back through the
     * kloudstack_obs_exclude_request filter.
     */
    private const ALWAYS_EXCLUDE = [
        '#/wp-cron\.php#',
        '#/xmlrpc\.php#',
        '#/wp-admin/admin-ajax\.php.*action=heartbeat#',
    ];

    /** @var Reporter */
    private $reporter;

    /** @var Settings */
    private $settings;

    /** @var Privacy */
    private $privacy;

    /** @var float */
    private $startTime;

    /** @var bool */
    private $registered = false;

    public function __construct(Reporter $reporter, Settings $settings, Privacy $privacy)
    {
        $this->reporter  = $reporter;
        $this->settings  = $settings;
        $this->privacy   = $privacy;
        $this->startTime = self::requestStart();
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        register_shutdown_function(Guard::wrap([$this, 'onShutdown'], 'request.shutdown'));
    }

    /**
     * Collect the request and flush.
     *
     * Always flushes, even when this request is excluded: an exception collected earlier in the
     * same request still needs transmitting, and the response still needs releasing.
     */
    public function onShutdown(): void
    {
        if (!$this->isExcluded()) {
            $this->collect();
        }

        $this->reporter->flush();
    }

    private function collect(): void
    {
        $method     = self::method();
        $path       = self::path();
        $url        = $this->privacy->scrubUrl(self::url());
        $status     = self::responseCode();
        $durationMs = (microtime(true) - $this->startTime) * 1000;

        $name = Correlation::operationName($method, $path, self::matchedRoute());

        $dimensions = [];

        $clientIp = $this->privacy->clientIp();

        if ($clientIp !== '') {
            $dimensions['client_ip'] = $clientIp;
        }

        $this->reporter->trackRequest($name, $url, $durationMs, $status, $dimensions);
    }

    /**
     * Whether this request should produce telemetry.
     */
    public function isExcluded(): bool
    {
        // Non-web contexts never produce request telemetry. WP-CLI in particular would otherwise
        // report every command as a request with a nonsensical duration.
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return true;
        }

        $context = WordPressContext::requestContext();

        if ($context === WordPressContext::CONTEXT_CLI) {
            return true;
        }

        if ($context === WordPressContext::CONTEXT_CRON && !$this->settings->bool('track_cron')) {
            return true;
        }

        if (
            !$this->settings->bool('track_admin')
            && in_array($context, [WordPressContext::CONTEXT_ADMIN, WordPressContext::CONTEXT_AJAX], true)
        ) {
            return true;
        }

        $target = self::path() . '?' . self::queryString();

        foreach (self::ALWAYS_EXCLUDE as $pattern) {
            if (preg_match($pattern, $target) === 1) {
                return true;
            }
        }

        foreach ($this->settings->stringList('excluded_paths') as $pattern) {
            if (self::matchesUserPattern($pattern, $target)) {
                return true;
            }
        }

        return (bool) apply_filters('kloudstack_obs_exclude_request', false, $target, $context);
    }

    /**
     * Match a user-supplied exclusion pattern.
     *
     * Patterns are treated as substrings unless delimited as a regular expression. An invalid
     * regular expression from the settings page must not produce a PHP warning on every request,
     * so the match is attempted defensively and a failure means "does not match" rather than an
     * error.
     */
    private static function matchesUserPattern(string $pattern, string $target): bool
    {
        if ($pattern === '') {
            return false;
        }

        $isRegex = strlen($pattern) > 2
            && $pattern[0] === '#'
            && substr($pattern, -1) === '#';

        if (!$isRegex) {
            return strpos($target, $pattern) !== false;
        }

        $result = @preg_match($pattern, $target); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        return $result === 1;
    }

    /**
     * The request start time.
     *
     * REQUEST_TIME_FLOAT is set by PHP when the request began, which is earlier and more accurate
     * than anything this plugin could observe — it predates WordPress loading at all.
     */
    private static function requestStart(): float
    {
        if (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])) {
            return (float) $_SERVER['REQUEST_TIME_FLOAT'];
        }

        return microtime(true);
    }

    private static function responseCode(): int
    {
        $code = http_response_code();

        return is_int($code) && $code > 0 ? $code : 200;
    }

    private static function method(): string
    {
        return strtoupper(self::server('REQUEST_METHOD')) ?: 'GET';
    }

    private static function url(): string
    {
        $scheme = self::isHttps() ? 'https' : 'http';
        $host   = self::server('HTTP_HOST') ?: 'unknown';
        $uri    = self::server('REQUEST_URI') ?: '/';

        return $scheme . '://' . $host . $uri;
    }

    private static function path(): string
    {
        $uri  = self::server('REQUEST_URI') ?: '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private static function queryString(): string
    {
        return self::server('QUERY_STRING');
    }

    /**
     * Whether the request reached the site over TLS.
     *
     * Behind Front Door and App Service the connection to PHP is plain HTTP, so the forwarded
     * protocol header is authoritative for what the visitor actually used.
     */
    private static function isHttps(): bool
    {
        $forwarded = strtolower(self::server('HTTP_X_FORWARDED_PROTO'));

        if ($forwarded !== '') {
            return $forwarded === 'https';
        }

        $https = strtolower(self::server('HTTPS'));

        return $https !== '' && $https !== 'off';
    }

    /**
     * A route template from WordPress, when one is available.
     *
     * REST routes are authoritative; rewrite rules are the next best thing. Both are far better
     * than inferring structure from the URL.
     */
    private static function matchedRoute(): string
    {
        if (isset($GLOBALS['kloudstack_obs_rest_route']) && is_string($GLOBALS['kloudstack_obs_rest_route'])) {
            return $GLOBALS['kloudstack_obs_rest_route'];
        }

        if (isset($GLOBALS['wp']) && is_object($GLOBALS['wp'])) {
            $wp = $GLOBALS['wp'];

            if (isset($wp->matched_rule) && is_string($wp->matched_rule) && $wp->matched_rule !== '') {
                return self::readableRule($wp->matched_rule);
            }
        }

        return '';
    }

    /**
     * Turn a rewrite rule into something readable.
     *
     * WordPress rewrite rules are regular expressions such as
     * "([0-9]{4})/([0-9]{1,2})/([^/]+)/?$". As an operation name that is accurate but unreadable,
     * so capture groups become placeholders.
     *
     * Collapsing groups needs to be iterative. A single pass over "\([^)]*\)" cannot handle nested
     * groups such as "(?:/([0-9]+))?" — it consumes the inner group and leaves the outer closing
     * ")?" stranded. A live site produced the operation name "GET /{x}{x})?" that way, which is
     * both meaningless and leaks regex syntax into Azure Monitor.
     */
    public static function readableRule(string $rule): string
    {
        // Collapse innermost groups repeatedly until none remain. Bounded because each pass must
        // shorten the string, and a malformed rule must not spin.
        for ($pass = 0; $pass < 10; ++$pass) {
            $collapsed = preg_replace('/\((?:\?[:=!][^()]*|[^()]*)\)(?:\{[\d,]+\}|[*+?])?/', '{x}', $rule);

            if (!is_string($collapsed) || $collapsed === $rule) {
                break;
            }

            $rule = $collapsed;
        }

        // Strip regex anchors and quantifiers that survive at the edges.
        $rule = str_replace(['?$', '$', '^'], '', $rule);

        // Collapse runs of adjacent placeholders: "{x}{x}" reads as one unknown segment, not two.
        $rule = (string) preg_replace('/(\{x\})+/', '{x}', $rule);

        // Anything still carrying regex punctuation is not usable as a name. Better to report a
        // generic route than a malformed one that fragments the operation-name dimension.
        if (preg_match('/[()\[\]|\\\\]/', $rule) === 1) {
            return '';
        }

        $rule = trim($rule, '/');

        return $rule === '' ? '/' : '/' . $rule;
    }

    private static function server(string $key): string
    {
        if (!isset($_SERVER[$key]) || !is_string($_SERVER[$key])) {
            return '';
        }

        return trim(sanitize_text_field(wp_unslash($_SERVER[$key])));
    }
}
