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
 * unit tested without a network. The default sender uses cURL directly rather than
 * wp_remote_post, because by the time this runs the response is already closed and WordPress's
 * HTTP stack carries filter overhead and third-party filters that have no business running
 * against a telemetry endpoint.
 */
final class Transport
{
    /** Payloads above this size are compressed. Below it, gzip costs more than it saves. */
    private const GZIP_THRESHOLD = 1024;

    private const CONNECT_TIMEOUT = 2;
    private const TOTAL_TIMEOUT   = 5;

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

        $result = $this->dispatch($body, $headers);

        return $this->handleResult($result, count($items));
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
    private function handleResult(array $result, int $itemCount): bool
    {
        $status = $result['status'];
        $error  = $result['error'];

        // Accepted, including 206 partial success.
        if ($status >= 200 && $status < 300) {
            $this->breaker->recordSuccess();

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

        $this->breaker->recordSuccess();

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

        return $this->curl($body, $headers);
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array{status: int, error: string}
     */
    private function curl(string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'error' => 'curl unavailable'];
        }

        $handle = curl_init($this->endpoint);

        if ($handle === false) {
            return ['status' => 0, 'error' => 'curl_init failed'];
        }

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Telemetry must never follow a redirect: an ingestion endpoint does not redirect,
            // and following one would send the payload somewhere unintended.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($handle);
        $status   = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error    = $response === false ? (string) curl_error($handle) : '';

        curl_close($handle);

        return ['status' => $status, 'error' => $error];
    }
}
