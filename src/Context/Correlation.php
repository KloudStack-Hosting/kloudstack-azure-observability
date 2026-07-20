<?php

declare(strict_types=1);

namespace KloudStack\Observability\Context;

use KloudStack\Observability\Telemetry\Envelope;

defined('ABSPATH') || exit;

/**
 * W3C Trace Context and operation naming.
 *
 * Two jobs, both of which the 1.x plugin did not do at all:
 *
 * 1. Correlation. Without a shared operation id, a browser page view and the server request that
 *    produced it are unrelated rows in Application Insights. Every "why was this page slow"
 *    question then requires guessing by timestamp. The Marketplace workbook cannot join them at
 *    all, which makes half of its intended queries impossible.
 *
 * 2. Operation naming. ai.operation.name must be a route template, not a URL. A site with 10,000
 *    posts otherwise produces 10,000 distinct operation names, and every Azure Monitor
 *    performance view becomes unusable at exactly the scale where it matters most.
 *
 * Specification: https://www.w3.org/TR/trace-context/
 */
final class Correlation
{
    /** A trace id of all zeros is explicitly invalid under the W3C specification. */
    private const INVALID_TRACE_ID = '00000000000000000000000000000000';
    private const INVALID_SPAN_ID  = '0000000000000000';

    /** @var string */
    private $traceId;

    /** @var string */
    private $spanId;

    /** @var string */
    private $parentSpanId;

    /** @var bool */
    private $sampledUpstream;

    public function __construct(?string $traceparent = null)
    {
        $parsed = self::parseTraceparent($traceparent ?? self::inboundTraceparent());

        if ($parsed !== null) {
            $this->traceId         = $parsed['trace_id'];
            $this->parentSpanId    = $parsed['span_id'];
            $this->sampledUpstream = $parsed['sampled'];
        } else {
            $this->traceId         = Envelope::id(16);
            $this->parentSpanId    = '';
            $this->sampledUpstream = true;
        }

        $this->spanId = Envelope::id(8);
    }

    /**
     * ai.operation.id — the trace this request belongs to.
     */
    public function operationId(): string
    {
        return $this->traceId;
    }

    /**
     * ai.operation.parentId — the span this request is a child of.
     *
     * For a request telemetry item this is the inbound caller's span when one exists, and this
     * request's own span otherwise, so browser telemetry stamped with it nests correctly.
     */
    public function parentId(): string
    {
        return $this->parentSpanId !== '' ? $this->parentSpanId : $this->spanId;
    }

    /**
     * This request's own span id, used as the request telemetry item's id.
     */
    public function spanId(): string
    {
        return $this->spanId;
    }

    public function hasInboundContext(): bool
    {
        return $this->parentSpanId !== '';
    }

    /**
     * Whether an upstream service asked for this trace to be sampled.
     *
     * Honoured so that a trace is not half-recorded: dropping a span whose parent was sampled
     * produces a broken trace, which is more misleading than no trace.
     */
    public function sampledUpstream(): bool
    {
        return $this->sampledUpstream;
    }

    /**
     * The traceparent header to propagate to outbound calls.
     */
    public function traceparent(): string
    {
        return sprintf(
            '00-%s-%s-%s',
            $this->traceId,
            $this->spanId,
            $this->sampledUpstream ? '01' : '00'
        );
    }

    /**
     * Correlation tags for a telemetry envelope.
     *
     * @return array<string, string>
     */
    public function tags(): array
    {
        return [
            'ai.operation.id'       => $this->operationId(),
            'ai.operation.parentId' => $this->parentId(),
        ];
    }

    /**
     * Parse a W3C traceparent header.
     *
     * Returns null for anything malformed. Being strict matters: adopting a garbage trace id
     * would scatter a site's telemetry across meaningless traces, which is worse than generating
     * a fresh one, and the header is client-controlled.
     *
     * Format: version-traceid-spanid-flags, e.g.
     * 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
     *
     * @return array{trace_id: string, span_id: string, sampled: bool}|null
     */
    public static function parseTraceparent(string $header): ?array
    {
        $header = trim($header);

        if ($header === '') {
            return null;
        }

        $parts = explode('-', $header);

        if (count($parts) !== 4) {
            return null;
        }

        [$version, $traceId, $spanId, $flags] = $parts;

        // Version ff is forbidden by the specification. Unknown future versions are accepted for
        // forward compatibility, as the specification requires, provided the rest parses.
        if (!preg_match('/^[0-9a-f]{2}$/', $version) || $version === 'ff') {
            return null;
        }

        if (!preg_match('/^[0-9a-f]{32}$/', $traceId) || $traceId === self::INVALID_TRACE_ID) {
            return null;
        }

        if (!preg_match('/^[0-9a-f]{16}$/', $spanId) || $spanId === self::INVALID_SPAN_ID) {
            return null;
        }

        if (!preg_match('/^[0-9a-f]{2}$/', $flags)) {
            return null;
        }

        return [
            'trace_id' => $traceId,
            'span_id'  => $spanId,
            // The low bit of the flags byte is "sampled".
            'sampled'  => (hexdec($flags) & 0x01) === 1,
        ];
    }

    /**
     * Normalise a request into a route template.
     *
     * Falls back through progressively less specific strategies. The caller supplies whatever
     * WordPress routing information is available; this method does not touch WordPress globals so
     * it stays unit testable.
     *
     * @param string $method       HTTP method.
     * @param string $path         Request path.
     * @param string $matchedRoute A REST route or rewrite-derived template, when known.
     */
    public static function operationName(string $method, string $path, string $matchedRoute = ''): string
    {
        $method = strtoupper(trim($method)) ?: 'GET';

        if ($matchedRoute !== '') {
            return $method . ' ' . self::normalisePath($matchedRoute);
        }

        return $method . ' ' . self::normalisePath(self::genericise($path));
    }

    /**
     * Replace identifying path segments with placeholders.
     *
     * Without this, every post, product and attachment is its own operation name.
     */
    private static function genericise(string $path): string
    {
        $segments = explode('/', $path);

        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }

            // Year segments are checked before generic numeric ones, because a four-digit year is
            // also a valid integer and "/{year}/{id}/{id}" reads as a date archive where
            // "/{id}/{id}/{id}" reads as nothing in particular.
            if (preg_match('/^(19|20)\d{2}$/', $segment)) {
                $segments[$index] = '{year}';

                continue;
            }

            // Numeric ids.
            if (ctype_digit($segment)) {
                $segments[$index] = '{id}';

                continue;
            }

            // UUIDs.
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment)) {
                $segments[$index] = '{uuid}';
            }
        }

        return implode('/', $segments);
    }

    /**
     * Tidy a path into a stable, bounded template.
     */
    private static function normalisePath(string $path): string
    {
        $path = '/' . ltrim(trim($path), '/');

        // Collapse repeated slashes, which produce distinct operation names for the same route.
        $path = (string) preg_replace('#/+#', '/', $path);

        // Preserve a single trailing slash distinction but nothing beyond it.
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }

        if ($path === '') {
            $path = '/';
        }

        // Operation names are a low-cardinality dimension by design; an absurdly long path is
        // either an attack or a bug, and either way must not become a unique operation name.
        return Envelope::truncate($path, 250);
    }

    /**
     * Read the inbound traceparent header.
     *
     * Front Door and other upstream services set this; adopting it links WordPress telemetry to
     * traces that started before the request reached PHP.
     */
    private static function inboundTraceparent(): string
    {
        if (!isset($_SERVER['HTTP_TRACEPARENT']) || !is_string($_SERVER['HTTP_TRACEPARENT'])) {
            return '';
        }

        return trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_TRACEPARENT'])));
    }
}
