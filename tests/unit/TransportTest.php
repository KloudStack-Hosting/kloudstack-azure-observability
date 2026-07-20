<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Transport\CircuitBreaker;
use KloudStack\Observability\Transport\ResponseRelease;
use KloudStack\Observability\Transport\Transport;
use PHPUnit\Framework\TestCase;
use WPStubs;

/**
 * The transport layer is where an Azure outage either stays an Azure problem or becomes a
 * WordPress outage. Without a breaker, every request on the site waits for a connection timeout,
 * and an exhausted PHP-FPM worker pool takes the site down as effectively as a failed database.
 *
 * @covers \KloudStack\Observability\Transport\Transport
 * @covers \KloudStack\Observability\Transport\CircuitBreaker
 * @covers \KloudStack\Observability\Transport\ResponseRelease
 */
final class TransportTest extends TestCase
{
    /** @var array<int, array{string, string, array<int, string>}> */
    private $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        WPStubs::reset();
        $this->sent = [];
    }

    /**
     * @param array<int, array{status: int, error?: string}> $responses
     */
    private function transport(array $responses, ?CircuitBreaker $breaker = null): Transport
    {
        $index = 0;

        $sender = function (string $endpoint, string $body, array $headers) use ($responses, &$index): array {
            $this->sent[] = [$endpoint, $body, $headers];
            $response     = $responses[$index] ?? end($responses);
            ++$index;

            return $response;
        };

        return new Transport(
            'https://dc.services.visualstudio.com/v2/track',
            $breaker ?? new CircuitBreaker(),
            $sender
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(int $count = 1): array
    {
        $items = [];

        for ($i = 0; $i < $count; ++$i) {
            $items[] = ['name' => 'Microsoft.ApplicationInsights.Request', 'seq' => $i];
        }

        return $items;
    }

    // ── Batching ────────────────────────────────────────────────────────────────────────────

    public function testEmptyBatchIsANoOp(): void
    {
        self::assertTrue($this->transport([['status' => 200]])->send([]));
        self::assertSame([], $this->sent, 'An empty batch must not produce a request.');
    }

    /**
     * Decode a sent body, transparently handling compression.
     *
     * @return array<int, array<string, mixed>>
     */
    private function decodeBody(int $index = 0): array
    {
        $body = $this->sent[$index][1];

        if (substr($body, 0, 2) === "\x1f\x8b") {
            $body = (string) gzdecode($body);
        }

        $decoded = json_decode($body, true);

        self::assertIsArray($decoded, 'Sent body must decode to an array.');

        return $decoded;
    }

    public function testAllItemsGoInASingleRequest(): void
    {
        // The whole point of buffering: N items become one outbound call, not N.
        $this->transport([['status' => 200]])->send($this->items(20));

        self::assertCount(1, $this->sent);
        self::assertCount(20, $this->decodeBody());
    }

    public function testCompressedPayloadsStillDecodeToTheOriginalItems(): void
    {
        // Compression must be transparent to the receiver; a corrupted gzip stream would be
        // rejected by Azure with no useful error.
        $this->transport([['status' => 200]])->send($this->items(200));

        $decoded = $this->decodeBody();

        self::assertCount(200, $decoded);
        self::assertSame(199, $decoded[199]['seq']);
    }

    public function testSuccessIsReported(): void
    {
        self::assertTrue($this->transport([['status' => 200]])->send($this->items()));
    }

    public function testPartialSuccessCountsAsSuccess(): void
    {
        // Application Insights returns 206 when some items in a batch were rejected.
        self::assertTrue($this->transport([['status' => 206]])->send($this->items()));
    }

    public function testLargePayloadsAreCompressed(): void
    {
        $items = [];

        for ($i = 0; $i < 200; ++$i) {
            $items[] = ['name' => 'Request', 'message' => str_repeat('x', 100), 'seq' => $i];
        }

        $this->transport([['status' => 200]])->send($items);

        self::assertContains('Content-Encoding: gzip', $this->sent[0][2]);
        self::assertSame("\x1f\x8b", substr($this->sent[0][1], 0, 2), 'Body must be a gzip stream.');
    }

    public function testSmallPayloadsAreNotCompressed(): void
    {
        // Below the threshold gzip costs more than it saves.
        $this->transport([['status' => 200]])->send($this->items(1));

        self::assertNotContains('Content-Encoding: gzip', $this->sent[0][2]);
    }

    // ── Failure classification ──────────────────────────────────────────────────────────────

    public function testConnectionFailureTripsTheBreakerAfterThreshold(): void
    {
        $breaker   = new CircuitBreaker(3, 300);
        $transport = $this->transport([['status' => 0, 'error' => 'connection refused']], $breaker);

        $transport->send($this->items());
        self::assertFalse($breaker->isOpen(), 'One failure must not suspend telemetry.');

        $transport->send($this->items());
        self::assertFalse($breaker->isOpen());

        $transport->send($this->items());
        self::assertTrue($breaker->isOpen(), 'Three consecutive failures must open the breaker.');
        self::assertSame('connection refused', $breaker->lastFailureReason());
    }

    public function testOpenBreakerStopsRequestsEntirely(): void
    {
        $breaker = new CircuitBreaker(1, 300);
        $breaker->recordFailure('down');

        $this->transport([['status' => 200]], $breaker)->send($this->items());

        self::assertSame([], $this->sent, 'An open breaker must not attempt a connection at all.');
    }

    public function testServerErrorsTripTheBreaker(): void
    {
        $breaker = new CircuitBreaker(1, 300);
        $this->transport([['status' => 503]], $breaker)->send($this->items());

        self::assertTrue($breaker->isOpen());
        self::assertSame('HTTP 503', $breaker->lastFailureReason());
    }

    public function testThrottlingTripsTheBreaker(): void
    {
        $breaker = new CircuitBreaker(1, 300);
        $this->transport([['status' => 429]], $breaker)->send($this->items());

        self::assertTrue($breaker->isOpen(), 'Being throttled is a reason to back off.');
    }

    public function testClientErrorsDoNotTripTheBreaker(): void
    {
        // A 400 means our payload is malformed. Retrying will not fix it, and suspending all
        // telemetry because of one bad item would be a far worse outcome.
        $breaker = new CircuitBreaker(1, 300);
        $result  = $this->transport([['status' => 400]], $breaker)->send($this->items());

        self::assertFalse($result);
        self::assertFalse($breaker->isOpen(), 'A malformed payload is our bug, not an outage.');
    }

    public function testSuccessAfterFailuresClearsTheBreaker(): void
    {
        $breaker   = new CircuitBreaker(3, 300);
        $transport = $this->transport([
            ['status' => 0, 'error' => 'timeout'],
            ['status' => 0, 'error' => 'timeout'],
            ['status' => 200],
        ], $breaker);

        $transport->send($this->items());
        $transport->send($this->items());
        self::assertSame(2, $breaker->failureCount());

        $transport->send($this->items());

        // Cleared entirely, not decremented: a recovered site must not sit one blip from tripping.
        self::assertSame(0, $breaker->failureCount());
        self::assertSame(CircuitBreaker::STATE_CLOSED, $breaker->state());
    }

    // ── Circuit breaker states ──────────────────────────────────────────────────────────────

    public function testBreakerStartsClosed(): void
    {
        $breaker = new CircuitBreaker();

        self::assertSame(CircuitBreaker::STATE_CLOSED, $breaker->state());
        self::assertTrue($breaker->allowsRequest());
    }

    public function testBreakerReportsHalfOpenBetweenFirstFailureAndThreshold(): void
    {
        $breaker = new CircuitBreaker(3, 300);
        $breaker->recordFailure('one');

        self::assertSame(CircuitBreaker::STATE_HALF_OPEN, $breaker->state());
        self::assertTrue($breaker->allowsRequest(), 'Below threshold, requests continue.');
    }

    public function testBreakerStateIsSharedNotPerInstance(): void
    {
        // PHP-FPM workers do not share memory. Per-process state would mean every worker
        // independently rediscovering an outage, which on a busy site is most of the problem.
        (new CircuitBreaker(1, 300))->recordFailure('down');

        self::assertTrue((new CircuitBreaker(1, 300))->isOpen());
    }

    public function testBreakerResetClearsState(): void
    {
        $breaker = new CircuitBreaker(1, 300);
        $breaker->recordFailure('down');
        $breaker->reset();

        self::assertFalse($breaker->isOpen());
        self::assertSame(0, $breaker->failureCount());
    }

    // ── Response release ────────────────────────────────────────────────────────────────────

    public function testReleaseMechanismIsIdentified(): void
    {
        self::assertContains(ResponseRelease::availableMechanism(), [
            ResponseRelease::MECHANISM_FASTCGI,
            ResponseRelease::MECHANISM_LITESPEED,
            ResponseRelease::MECHANISM_FLUSH,
            ResponseRelease::MECHANISM_NONE,
        ]);
    }

    public function testReliabilityReflectsTheMechanism(): void
    {
        // The flush fallback depends on upstream buffering and proxy behaviour that cannot be
        // verified from here, so it is not reported as a guarantee.
        $reliable = ResponseRelease::isReliable();

        self::assertSame(
            in_array(ResponseRelease::availableMechanism(), [
                ResponseRelease::MECHANISM_FASTCGI,
                ResponseRelease::MECHANISM_LITESPEED,
            ], true),
            $reliable
        );
    }

    public function testReleaseIsIdempotent(): void
    {
        $release = new ResponseRelease();

        self::assertFalse($release->hasReleased());

        $release->release();

        self::assertTrue($release->hasReleased());
        self::assertSame(
            ResponseRelease::MECHANISM_NONE,
            $release->release(),
            'A second release must do nothing.'
        );
    }

    public function testCliHasNoResponseToRelease(): void
    {
        // The test suite runs under CLI, where there is no response to finish.
        self::assertSame(ResponseRelease::MECHANISM_NONE, ResponseRelease::availableMechanism());
        self::assertFalse(ResponseRelease::isReliable());
    }
}
