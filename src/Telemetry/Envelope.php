<?php

declare(strict_types=1);

namespace KloudStack\Observability\Telemetry;

defined('ABSPATH') || exit;

/**
 * Application Insights telemetry envelope construction.
 *
 * The wire format is unforgiving and fails silently: an envelope with a malformed duration, a
 * timestamp in the wrong shape or a mismatched name field is accepted by the ingestion endpoint
 * with HTTP 200 and then discarded server-side. There is no error the customer can see. That is
 * why this class is small, pure and heavily unit tested — it is the one place where being wrong
 * produces no signal at all.
 *
 * Schema reference: https://github.com/microsoft/ApplicationInsights-dotnet
 */
final class Envelope
{
    /** Severity levels as defined by the Application Insights schema. */
    public const SEVERITY_VERBOSE     = 0;
    public const SEVERITY_INFORMATION = 1;
    public const SEVERITY_WARNING     = 2;
    public const SEVERITY_ERROR       = 3;
    public const SEVERITY_CRITICAL    = 4;

    /** @var string */
    private $ikey;

    /** @var string */
    private $sdkVersion;

    public function __construct(string $ikey, string $sdkVersion)
    {
        $this->ikey       = $ikey;
        $this->sdkVersion = $sdkVersion;
    }

    /**
     * Build a complete envelope ready for transmission.
     *
     * @param string               $typeName   Short type name, e.g. "Request" or "Exception".
     * @param string               $baseType   Full base type, e.g. "RequestData".
     * @param array<string, mixed> $baseData   The telemetry payload, merged with {"ver": 2}.
     * @param array<string, string> $tags      ai.* context tags.
     * @param int|null             $sampleRate Percentage of traffic this item represents.
     *
     * @return array<string, mixed>
     */
    public function build(
        string $typeName,
        string $baseType,
        array $baseData,
        array $tags = [],
        ?int $sampleRate = null
    ): array {
        $envelope = [
            // The name field embeds the instrumentation key with hyphens stripped. Azure rejects
            // items whose name does not match this exact shape.
            'name' => 'Microsoft.ApplicationInsights.' . str_replace('-', '', $this->ikey) . '.' . $typeName,
            'time' => self::timestamp(),
            'iKey' => $this->ikey,
            'tags' => array_merge(
                ['ai.internal.sdkVersion' => $this->sdkVersion],
                $tags
            ),
            'data' => [
                'baseType' => $baseType,
                'baseData' => array_merge(['ver' => 2], $baseData),
            ],
        ];

        // Only emit sampleRate when sampling is active. Sending 100 explicitly is valid but
        // needlessly inflates every payload.
        if ($sampleRate !== null && $sampleRate < 100) {
            $envelope['sampleRate'] = $sampleRate;
        }

        return $envelope;
    }

    /**
     * Current UTC time in the format Application Insights expects.
     *
     * Example: 2026-07-20T09:15:32.482Z
     */
    public static function timestamp(?float $microtime = null): string
    {
        $time         = $microtime ?? microtime(true);
        $seconds      = (int) $time;
        $milliseconds = (int) round(($time - $seconds) * 1000);

        // Rounding can carry into the next second; normalise rather than emitting ".1000".
        if ($milliseconds >= 1000) {
            ++$seconds;
            $milliseconds = 0;
        }

        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', $milliseconds);
    }

    /**
     * Format a duration for the RequestData and RemoteDependencyData schemas.
     *
     * The expected shape is "D.HH:MM:SS.fffffff" where the fractional part is in ticks
     * (100-nanosecond units), so one millisecond is 10,000 ticks.
     *
     * Negative durations are clamped to zero: clock adjustments during a request can otherwise
     * produce a negative value, which Azure discards.
     */
    public static function duration(float $milliseconds): string
    {
        if ($milliseconds < 0 || is_nan($milliseconds) || is_infinite($milliseconds)) {
            $milliseconds = 0.0;
        }

        $totalSeconds = (int) ($milliseconds / 1000);
        $ticks        = (int) round(fmod($milliseconds, 1000) * 10000);

        // round() on the fractional millisecond can reach a full second in ticks.
        if ($ticks >= 10000000) {
            ++$totalSeconds;
            $ticks = 0;
        }

        return sprintf(
            '%d.%02d:%02d:%02d.%07d',
            intdiv($totalSeconds, 86400),
            intdiv($totalSeconds % 86400, 3600),
            intdiv($totalSeconds % 3600, 60),
            $totalSeconds % 60,
            $ticks
        );
    }

    /**
     * Generate a telemetry item id.
     *
     * Used for request and dependency ids, which must be unique within an operation. Not a
     * security token — random_bytes is used because it is the correct tool, but uniqueness rather
     * than unpredictability is what matters here.
     */
    public static function id(int $bytes = 8): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Exception $e) {
            // random_bytes can throw if no source of randomness is available. Telemetry ids are
            // not a security boundary, so a weaker fallback is acceptable and far better than
            // failing the request.
            return substr(md5(uniqid('', true)), 0, $bytes * 2);
        }
    }

    /**
     * Coerce an arbitrary value into a custom-dimension string.
     *
     * Application Insights custom dimensions are string-to-string. Booleans passed through
     * unconverted would arrive as "1" and "" rather than "true" and "false", which makes
     * workbook queries filtering on them silently wrong.
     *
     * @param mixed $value
     */
    public static function dimension($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) wp_json_encode($value);
    }

    /**
     * Truncate a string to an Application Insights field limit, preserving valid UTF-8.
     *
     * Over-length fields are truncated by the service rather than rejected, but doing it here
     * keeps payload sizes predictable and avoids shipping bytes that will be discarded.
     */
    public static function truncate(string $value, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $limit) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }

        if (!function_exists('mb_strlen') && strlen($value) > $limit) {
            return substr($value, 0, $limit);
        }

        return $value;
    }
}
