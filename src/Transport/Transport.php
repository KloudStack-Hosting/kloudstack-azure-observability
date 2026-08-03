<?php

declare(strict_types=1);

namespace KloudStack\Observability\Transport;

use KloudStack\Observability\Support\Log;

defined('ABSPATH') || exit;

/**
 * Telemetry transmission.
 *
 * Sends a batch of envelopes to the Application Insights ingestion endpoint as a single request,
 * after the response has been released to the visitor, behind a circuit breaker.
 *
 * The HTTP call is injectable so the batching, encoding and failure-classification logic can be
 * unit tested without a network. The default sender uses wp_remote_post (the WordPress HTTP API);
 * it runs after the response has already been released to the visitor, so its overhead is off the
 * critical path, and the circuit breaker bounds any worker time it costs.
 */
final class Transport
{
    /** Payloads above this size are compressed. Below it, gzip costs more than it saves. */
    private const GZIP_THRESHOLD = 1024;

    private const TOTAL_TIMEOUT = 5;

    /** @var string */
    private $endpoint;

    /** @var CircuitBreaker */
    private $breaker;

    /** @var callable|null */
    private $sender;

    /**
     * @param callable|null $sender Injectable HTTP sender for testing. Receives
     *                              (string $endpoint, string $body, array $headers) and returns
     *                              ['status' => int, 'error' => string].
     */
    public function __construct(string $endpoint, CircuitBreaker $breaker, ?callable $sender = null)
    {
        $this->endpoint = $endpoint;
        $this->breaker  = $breaker;
        $this->sender   = $sender;
    }

    /**
     * Send a batch of telemetry envelopes.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return bool True when accepted by the endpoint.
     */
    public function send(array $items): bool
    {
        if ($items === []) {
            return true;
        }

        if (!$this->breaker->allowsRequest()) {
            Log::debug('Telemetry dropped: circuit breaker open.', ['items' => count($items)]);

            return false;
        }

        $body = wp_json_encode($items);

        if (!is_string($body) || $body === '') {
            // Malformed telemetry is a bug in this plugin, not an endpoint failure. It must not
            // count against the breaker, or a single bad item would suspend all telemetry.
            Log::warning('Telemetry payload could not be encoded; dropping batch.');

            return false;
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if (strlen($body) > self::GZIP_THRESHOLD && function_exists('gzencode')) {
            $compressed = gzencode($body, 6);

            if (is_string($compressed) && strlen($compressed) < strlen($body)) {
                $body      = $compressed;
                $headers[] = 'Content-Encoding: gzip';
            }
        }

        // Timed because the breaker acts on slowness, not only on failure. This is the only place
        // that knows how long a worker was held.
        $startedAt  = microtime(true);
        $result     = $this->dispatch($body, $headers);
        $durationMs = (microtime(true) - $startedAt) * 1000;

        return $this->handleResult($result, count($items), $durationMs);
    }

    /**
     * Interpret the transport result.
     *
     * The distinction that matters is between "the endpoint rejected this payload" and "the
     * endpoint is unreachable". Only the latter is a circuit-breaker failure. A 400 means our
     * telemetry is malformed and retrying forever will not fix it, while tripping the breaker on
     * it would suspend all telemetry because of one bad item.
     *
     * @param array{status: int, error: string} $result
     */
    private function handleResult(array $result, int $itemCount, float $durationMs = 0.0): bool
    {
        $status = $result['status'];
        $error  = $result['error'];

        // Accepted, including 206 partial success. The duration decides whether this counts as a
        // clean success or as a degradation signal — a 200 that took three seconds is not healthy.
        if ($status >= 200 && $status < 300) {
            $this->breaker->recordSuccess($durationMs);

            return true;
        }

        // Transport-level failure: DNS, connection refused, TLS, timeout.
        if ($status === 0) {
            $this->breaker->recordFailure($error !== '' ? $error : 'connection failed');

            return false;
        }

        // Throttling and server errors are endpoint-side and worth backing off from.
        if ($status === 429 || $status >= 500) {
            $this->breaker->recordFailure('HTTP ' . $status);

            return false;
        }

        // 4xx other than 429: our payload is wrong. Log it, do not trip the breaker.
        Log::warning('Telemetry rejected by the ingestion endpoint.', [
            'status' => $status,
            'items'  => $itemCount,
        ]);

        $this->breaker->recordSuccess($durationMs);

        return false;
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array{status: int, error: string}
     */
    private function dispatch(string $body, array $headers): array
    {
        if ($this->sender !== null) {
            $result = ($this->sender)($this->endpoint, $body, $headers);

            return [
                'status' => (int) ($result['status'] ?? 0),
                'error'  => (string) ($result['error'] ?? ''),
            ];
        }

        return $this->remote($body, $headers);
    }

    /**
     * @param array<int, string> $headers Numeric "Header: value" list.
     *
     * @return array{status: int, error: string}
     */
    private function remote(string $body, array $headers): array
    {
        // wp_remote_post wants an associative header map; convert the "Header: value" list.
        $mapped = [];
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $mapped[trim($parts[0])] = trim($parts[1]);
            }
        }

        $response = wp_remote_post($this->endpoint, [
            'body'        => $body,
            'headers'     => $mapped,
            'timeout'     => self::TOTAL_TIMEOUT,
            // Telemetry must never follow a redirect: an ingestion endpoint does not redirect,
            // and following one would send the payload somewhere unintended.
            'redirection' => 0,
            'sslverify'   => true,
            'blocking'    => true,
        ]);

        if (is_wp_error($response)) {
            return ['status' => 0, 'error' => $response->get_error_message()];
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'error'  => '',
        ];
    }
}
