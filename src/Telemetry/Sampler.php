<?php

declare(strict_types=1);

namespace KloudStack\Observability\Telemetry;

defined('ABSPATH') || exit;

/**
 * Fixed-rate sampling.
 *
 * Sampling is a cost control, and the cost being controlled is the customer's Azure bill, not
 * ours. A busy WooCommerce site emitting one telemetry item per request can ingest gigabytes a
 * month; at Log Analytics rates that is a real invoice, and a monitoring plugin that produces a
 * surprise bill gets uninstalled.
 *
 * Two properties matter and both are easy to get wrong:
 *
 * 1. Sampling is decided per *trace*, not per item. If a request is sampled out but its exception
 *    is sampled in, Application Insights shows an exception belonging to a request that does not
 *    exist. Deriving the decision from the operation id makes it consistent for everything in the
 *    trace, and consistent across the client and server halves of the same page view.
 *
 * 2. The sampleRate field must be set on every item that survives, or Azure reports the sampled
 *    counts as the real ones — a site sampled at 10% appears to have a tenth of its actual
 *    traffic, silently.
 */
final class Sampler
{
    /** @var int */
    private $rate;

    /**
     * @param int $rate Percentage of traces to keep, 1-100.
     */
    public function __construct(int $rate = 100)
    {
        $this->rate = self::clamp($rate);
    }

    public function rate(): int
    {
        return $this->rate;
    }

    public function isSampling(): bool
    {
        return $this->rate < 100;
    }

    /**
     * Whether a trace should be recorded.
     *
     * The decision is a pure function of the operation id, so every item in a trace agrees
     * without needing to share state, and a retried request with the same trace id decides the
     * same way.
     */
    public function shouldSample(string $operationId): bool
    {
        if ($this->rate >= 100) {
            return true;
        }

        if ($this->rate <= 0) {
            return false;
        }

        return self::score($operationId) < $this->rate;
    }

    /**
     * The value to place on an envelope's sampleRate field.
     *
     * Null when not sampling, so the field is omitted entirely rather than sent as 100.
     */
    public function sampleRate(): ?int
    {
        return $this->isSampling() ? $this->rate : null;
    }

    /**
     * Map an operation id onto 0-99.
     *
     * Uses the same approach as the Application Insights SDKs: hash the id and take a percentage
     * bucket. crc32 is used rather than a cryptographic hash because this is a bucketing
     * decision, not a security boundary, and it runs on every request.
     */
    private static function score(string $operationId): int
    {
        if ($operationId === '') {
            // No trace identity to be consistent with; fall back to a per-item decision rather
            // than always sampling in, which would defeat the rate entirely.
            return random_int(0, 99);
        }

        return crc32($operationId) % 100;
    }

    private static function clamp(int $rate): int
    {
        if ($rate < 0) {
            return 0;
        }

        return $rate > 100 ? 100 : $rate;
    }
}
