<?php

declare(strict_types=1);

namespace KloudStack\Observability\Transport;

use KloudStack\Observability\Support\Log;

defined('ABSPATH') || exit;

/**
 * Circuit breaker for the ingestion endpoint.
 *
 * When Application Insights becomes unreachable, the naive behaviour is for every request on the
 * site to attempt a connection and wait for a timeout. Even with the response already released to
 * the visitor, that ties up a PHP-FPM worker for the timeout duration on every single request,
 * and a worker pool exhausted by telemetry retries takes the site down just as effectively as a
 * slow database. Under load, an Azure outage would become a WordPress outage.
 *
 * State lives in a transient so it is shared across PHP-FPM workers. Per-process state would mean
 * each worker independently rediscovering the outage, which on a busy site is most of the
 * problem still present.
 */
final class CircuitBreaker
{
    public const STATE_CLOSED    = 'closed';
    public const STATE_OPEN      = 'open';
    public const STATE_HALF_OPEN = 'half-open';

    public const TRANSIENT = 'kloudstack_obs_breaker';

    private const FAILURE_THRESHOLD = 3;
    private const OPEN_SECONDS      = 300;

    /** @var int */
    private $threshold;

    /** @var int */
    private $openSeconds;

    /** @var string */
    private $key;

    /**
     * @param string $key Transient key. Overridden by the diagnostics self-test so that a failed
     *                    test cannot pollute production breaker state — sharing the key would
     *                    mean running the self-test against an unreachable endpoint counted
     *                    towards suspending the site's real telemetry.
     */
    public function __construct(
        int $threshold = self::FAILURE_THRESHOLD,
        int $openSeconds = self::OPEN_SECONDS,
        string $key = self::TRANSIENT
    ) {
        $this->threshold   = max(1, $threshold);
        $this->openSeconds = max(1, $openSeconds);
        $this->key         = $key !== '' ? $key : self::TRANSIENT;
    }

    /**
     * Whether transmission should be attempted.
     */
    public function allowsRequest(): bool
    {
        return $this->state() !== self::STATE_OPEN;
    }

    /**
     * Current breaker state.
     *
     * The transient's own expiry provides the open-to-half-open transition: once it lapses there
     * is no stored state, so the next request probes naturally. No timestamp arithmetic and no
     * scheduled task.
     */
    public function state(): string
    {
        $stored = $this->stored();

        if ($stored === null) {
            return self::STATE_CLOSED;
        }

        if (($stored['failures'] ?? 0) >= $this->threshold) {
            return self::STATE_OPEN;
        }

        return ($stored['failures'] ?? 0) > 0 ? self::STATE_HALF_OPEN : self::STATE_CLOSED;
    }

    public function isOpen(): bool
    {
        return $this->state() === self::STATE_OPEN;
    }

    /**
     * Record a successful transmission.
     *
     * Clears state entirely rather than decrementing. A single success means the endpoint is
     * reachable, and carrying historical failures forward would keep a recovered site one blip
     * away from tripping again.
     */
    public function recordSuccess(): void
    {
        if ($this->stored() !== null) {
            delete_transient($this->key);
        }
    }

    /**
     * Record a failed transmission.
     */
    public function recordFailure(string $reason = ''): void
    {
        $stored   = $this->stored();
        $failures = (int) ($stored['failures'] ?? 0) + 1;

        set_transient(
            $this->key,
            [
                'failures' => $failures,
                'reason'   => $reason,
            ],
            $this->openSeconds
        );

        // Logged once, when the breaker trips, rather than on every failure. A telemetry outage
        // that fills the debug log with one line per request is its own small incident.
        if ($failures === $this->threshold) {
            Log::warning('Telemetry circuit breaker opened; transmission suspended.', [
                'failures' => $failures,
                'reason'   => $reason,
                'seconds'  => $this->openSeconds,
            ]);
        }
    }

    /**
     * Why the breaker last failed. Surfaced in diagnostics so an administrator sees the actual
     * error rather than only that telemetry stopped.
     */
    public function lastFailureReason(): string
    {
        $stored = $this->stored();

        return is_string($stored['reason'] ?? null) ? $stored['reason'] : '';
    }

    public function failureCount(): int
    {
        return (int) ($this->stored()['failures'] ?? 0);
    }

    public function reset(): void
    {
        delete_transient($this->key);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stored(): ?array
    {
        $stored = get_transient($this->key);

        return is_array($stored) ? $stored : null;
    }
}
