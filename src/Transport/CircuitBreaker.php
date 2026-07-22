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
 *
 * The breaker opens on *slowness* as well as failure. An endpoint answering successfully in three
 * seconds occupies a worker for three seconds on every request, indefinitely, and counting only
 * hard failures let that run unchecked — the C4 benchmark measured p95 rising by 1.7 s with the
 * breaker never once tripping, because every one of those sends succeeded. Slow-but-working is
 * what throttling and regional latency actually look like, and it is a likelier production
 * condition than an outage.
 */
final class CircuitBreaker
{
    public const STATE_CLOSED    = 'closed';
    public const STATE_OPEN      = 'open';
    public const STATE_HALF_OPEN = 'half-open';

    public const TRANSIENT = 'kloudstack_obs_breaker';

    private const FAILURE_THRESHOLD = 3;
    private const OPEN_SECONDS      = 300;

    /**
     * How long the endpoint may stay continuously bad before the breaker opens regardless of the
     * strike count.
     *
     * The strike counter cannot be trusted under load. A transient is a read-modify-write, so when
     * sixteen workers all fail at the same moment they each read the same value and each write
     * back the same increment: sixteen bad sends, one strike. Measured, not theorised — five
     * concurrent slow sends produced a count of 1.
     *
     * That skew is worst exactly when the breaker matters most, because concurrency is what
     * exhausts the worker pool in the first place. A wall-clock signal has no such failure mode:
     * every worker computes the same answer from the same timestamp without coordinating.
     */
    public const SUSTAINED_SECONDS = 5;

    /**
     * A send taking longer than this is treated as a degradation signal.
     *
     * Set well below the 5 s transport timeout: waiting for the timeout means the worker has
     * already been held for five seconds, which is the outcome this is meant to prevent rather
     * than detect. One second is far longer than a healthy ingestion round trip and short enough
     * that the pool is still mostly free when the breaker opens.
     */
    public const SLOW_MS = 1000.0;

    /** @var int */
    private $threshold;

    /** @var int */
    private $openSeconds;

    /** @var string */
    private $key;

    /** @var float */
    private $slowMs;

    /** @var int */
    private $sustainedSeconds;

    /**
     * @param string $key    Transient key. Overridden by the diagnostics self-test so that a
     *                       failed test cannot pollute production breaker state — sharing the key
     *                       would mean running the self-test against an unreachable endpoint
     *                       counted towards suspending the site's real telemetry.
     * @param float  $slowMs Injectable so tests can exercise the slow path without sleeping for
     *                       a real second.
     */
    public function __construct(
        int $threshold = self::FAILURE_THRESHOLD,
        int $openSeconds = self::OPEN_SECONDS,
        string $key = self::TRANSIENT,
        float $slowMs = self::SLOW_MS,
        int $sustainedSeconds = self::SUSTAINED_SECONDS
    ) {
        $this->threshold        = max(1, $threshold);
        $this->openSeconds      = max(1, $openSeconds);
        $this->key              = $key !== '' ? $key : self::TRANSIENT;
        $this->slowMs           = max(1.0, $slowMs);
        $this->sustainedSeconds = max(0, $sustainedSeconds);
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

        // Set when the endpoint has been continuously bad for longer than SUSTAINED_SECONDS. The
        // decision is made at write time, by whichever worker observed it, so a single blip
        // followed by silence can never age into an open breaker.
        if (!empty($stored['sustained'])) {
            return self::STATE_OPEN;
        }

        // Failures and slow sends are counted separately but weigh the same. They are different
        // symptoms of the same thing -- an endpoint that should be left alone for a while -- and
        // keeping them apart is only so diagnostics can say which one happened.
        $strikes = max((int) ($stored['failures'] ?? 0), (int) ($stored['slow'] ?? 0));

        if ($strikes >= $this->threshold) {
            return self::STATE_OPEN;
        }

        return $strikes > 0 ? self::STATE_HALF_OPEN : self::STATE_CLOSED;
    }

    public function isOpen(): bool
    {
        return $this->state() === self::STATE_OPEN;
    }

    /**
     * Record a successful transmission.
     *
     * A *prompt* success clears state entirely rather than decrementing. A single success means
     * the endpoint is reachable, and carrying historical failures forward would keep a recovered
     * site one blip away from tripping again.
     *
     * A success that took longer than SLOW_MS does not clear anything. It counts towards opening
     * the breaker, because the cost being defended against is worker occupancy, and a slow success
     * occupies a worker exactly as thoroughly as a slow failure does.
     *
     * @param float $durationMs How long the send took. Zero means "not measured", which is
     *                          treated as prompt — callers that cannot time the send should not
     *                          have their telemetry suspended on suspicion.
     */
    public function recordSuccess(float $durationMs = 0.0): void
    {
        if ($durationMs >= $this->slowMs) {
            $this->recordSlow($durationMs);

            return;
        }

        if ($this->stored() !== null) {
            delete_transient($this->key);
        }
    }

    /**
     * Record a transmission that succeeded but took too long.
     */
    private function recordSlow(float $durationMs): void
    {
        $this->recordBad('slow', sprintf('slow endpoint: %d ms', (int) round($durationMs)));
    }

    /**
     * Record a failed transmission.
     */
    public function recordFailure(string $reason = ''): void
    {
        $this->recordBad('failure', $reason);
    }

    /**
     * Shared writer for both kinds of bad send.
     *
     * Two independent reasons to open, because neither is sufficient alone:
     *
     *  - **Strike count.** Fast and precise when requests are serialised, which is the case on a
     *    quiet site and in the test suite. Undercounts badly under concurrency.
     *  - **Sustained badness.** Immune to the counting race, since it compares timestamps rather
     *    than accumulating. Costs up to SUSTAINED_SECONDS before it fires, which is why it is the
     *    backstop and not the primary signal.
     *
     * @param string $kind 'failure' or 'slow'
     */
    private function recordBad(string $kind, string $reason): void
    {
        $stored = $this->stored();
        $now    = time();

        // When the current run of badness started. Concurrent writers racing on this all write
        // approximately the same value, which is harmless -- unlike racing on a counter, where
        // they all write the same increment and the tally silently stops growing.
        $since = (int) ($stored['since'] ?? 0);

        if ($since <= 0 || $since > $now) {
            $since = $now;
        }

        $failures = (int) ($stored['failures'] ?? 0) + ($kind === 'failure' ? 1 : 0);
        $slow     = (int) ($stored['slow'] ?? 0) + ($kind === 'slow' ? 1 : 0);
        $strikes  = max($failures, $slow);

        $sustained = !empty($stored['sustained']) || ($now - $since) >= $this->sustainedSeconds;
        $opening   = !$this->wasOpen($stored) && ($sustained || $strikes >= $this->threshold);

        set_transient(
            $this->key,
            [
                'failures'  => $failures,
                'slow'      => $slow,
                'since'     => $since,
                'sustained' => $sustained,
                'reason'    => $reason,
            ],
            $this->openSeconds
        );

        // Logged once, when the breaker trips, rather than on every failure. A telemetry outage
        // that fills the debug log with one line per request is its own small incident.
        if ($opening) {
            Log::warning('Telemetry circuit breaker opened; transmission suspended.', [
                'cause'     => $kind === 'slow' ? 'endpoint too slow' : 'endpoint unreachable',
                'reason'    => $reason,
                'failures'  => $failures,
                'slow'      => $slow,
                'trigger'   => $sustained ? 'sustained' : 'strike count',
                'bad_for_s' => $now - $since,
                'seconds'   => $this->openSeconds,
            ]);
        }
    }

    /**
     * Whether the given stored state already represented an open breaker.
     *
     * @param array<string, mixed>|null $stored
     */
    private function wasOpen(?array $stored): bool
    {
        if ($stored === null) {
            return false;
        }

        if (!empty($stored['sustained'])) {
            return true;
        }

        return max((int) ($stored['failures'] ?? 0), (int) ($stored['slow'] ?? 0)) >= $this->threshold;
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

    /**
     * Consecutive slow-but-successful sends. Surfaced in diagnostics so "telemetry stopped" can be
     * told apart from "telemetry stopped because Azure went slow", which look identical otherwise.
     */
    public function slowCount(): int
    {
        return (int) ($this->stored()['slow'] ?? 0);
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
