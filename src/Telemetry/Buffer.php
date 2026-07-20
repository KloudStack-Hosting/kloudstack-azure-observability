<?php

declare(strict_types=1);

namespace KloudStack\Observability\Telemetry;

defined('ABSPATH') || exit;

/**
 * Per-request telemetry accumulation.
 *
 * Telemetry is collected during the request and transmitted once, after the response has been
 * released to the visitor. Buffering is what makes that possible, and it is also what turns N
 * outbound HTTP calls per page into one.
 *
 * The hard cap is the important part. A page that throws a thousand warnings, or a plugin
 * conflict that produces an error in a loop, must not turn into a thousand telemetry items and a
 * multi-megabyte payload. Dropping telemetry is always preferable to degrading the site, so the
 * buffer bounds itself and reports what it discarded rather than discarding silently.
 */
final class Buffer
{
    public const DEFAULT_LIMIT = 50;

    /** @var array<int, array<string, mixed>> */
    private $items = [];

    /** @var int */
    private $limit;

    /** @var int */
    private $dropped = 0;

    public function __construct(int $limit = self::DEFAULT_LIMIT)
    {
        $this->limit = $limit > 0 ? $limit : self::DEFAULT_LIMIT;
    }

    /**
     * Add a telemetry envelope.
     *
     * @param array<string, mixed> $envelope
     *
     * @return bool True when accepted, false when the buffer is full.
     */
    public function add(array $envelope): bool
    {
        if (count($this->items) >= $this->limit) {
            ++$this->dropped;

            return false;
        }

        $this->items[] = $envelope;

        return true;
    }

    /**
     * Return everything buffered and empty the buffer.
     *
     * Emptying on read matters: the shutdown handler can run more than once in some SAPI
     * configurations, and re-sending the same items would double-count every request.
     *
     * @return array<int, array<string, mixed>>
     */
    public function flush(): array
    {
        $items       = $this->items;
        $this->items = [];

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function peek(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isFull(): bool
    {
        return count($this->items) >= $this->limit;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * How many items were discarded because the buffer was full.
     *
     * Surfaced in diagnostics. Silent truncation would make a site look healthy precisely when it
     * is producing the most telemetry, which is the opposite of useful.
     */
    public function dropped(): int
    {
        return $this->dropped;
    }

    public function reset(): void
    {
        $this->items   = [];
        $this->dropped = 0;
    }
}
