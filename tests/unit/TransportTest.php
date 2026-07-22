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

    // ── Slowness classification ─────────────────────────────────────────────────────────────
    //
    // The gap these cover: before this, an endpoint answering successfully in three seconds was
    // recorded as a success and the breaker never tripped, so every request kept paying three
    // seconds of worker occupancy indefinitely. The C4 benchmark measured p95 climbing by 1.7 s
    // while the breaker sat closed throughout.

    public function testSlowButSuccessfulSendsOpenTheBreaker(): void
    {
        $breaker = new CircuitBreaker(3, 300, CircuitBreaker::TRANSIENT, 1000.0);

        $breaker->recordSuccess(3000.0);
        self::assertFalse($breaker->isOpen(), 'One slow send is not yet a pattern.');

        $breaker->recordSuccess(3000.0);
        self::assertFalse($breaker->isOpen());

        $breaker->recordSuccess(3000.0);
        self::assertTrue($breaker->isOpen(), 'Three slow sends must suspend transmission.');
        self::assertSame(3, $breaker->slowCount());
        self::assertStringContainsString('slow endpoint', $breaker->lastFailureReason());
    }

    public function testAPromptSuccessClearsAccumulatedSlowness(): void
    {
        $breaker = new CircuitBreaker(3, 300, CircuitBreaker::TRANSIENT, 1000.0);

        $breaker->recordSuccess(3000.0);
        $breaker->recordSuccess(3000.0);
        self::assertSame(2, $breaker->slowCount());

        // The endpoint recovered. Carrying the strikes forward would leave a healthy site one
        // slow send away from suspending telemetry.
        $breaker->recordSuccess(20.0);
        self::assertSame(0, $breaker->slowCount());
        self::assertFalse($breaker->isOpen());
    }

    public function testAnUntimedSuccessIsTreatedAsPrompt(): void
    {
        // Callers that cannot measure the send must not have telemetry suspended on suspicion.
        $breaker = new CircuitBreaker(1, 300, CircuitBreaker::TRANSIENT, 1000.0);
        $breaker->recordSuccess();

        self::assertFalse($breaker->isOpen());
        self::assertSame(0, $breaker->slowCount());
    }

    public function testSlownessAndFailuresBothCountTowardsTheSameThreshold(): void
    {
        $breaker = new CircuitBreaker(2, 300, CircuitBreaker::TRANSIENT, 1000.0);

        $breaker->recordFailure('timeout');
        $breaker->recordSuccess(3000.0);

        // Neither count reached 2 on its own, and a degraded endpoint that alternates between
        // the two symptoms should not be able to stay under the limit forever.
        self::assertSame(1, $breaker->failureCount());
        self::assertSame(1, $breaker->slowCount());
        self::assertFalse($breaker->isOpen());

        $breaker->recordSuccess(3000.0);
        self::assertTrue($breaker->isOpen());
    }

    public function testTransportTimesTheSendAndReportsSlowness(): void
    {
        // Proves the duration is actually measured in Transport and handed to the breaker,
        // rather than the breaker merely being capable of acting on one.
        $breaker = new CircuitBreaker(1, 300, CircuitBreaker::TRANSIENT, 10.0);

        $slowSender = function (string $endpoint, string $body, array $headers): array {
            usleep(30000); // 30 ms, comfortably over the 10 ms threshold set above.

            return ['status' => 200];
        };

        $transport = new Transport('https://example.invalid/v2/track', $breaker, $slowSender);
        $result    = $transport->send($this->items());

        self::assertTrue($result, 'The send did succeed — slowness is not failure.');
        self::assertTrue($breaker->isOpen(), 'A slow send must still suspend transmission.');
        self::assertSame(1, $breaker->slowCount());
    }

    public function testSustainedBadnessOpensTheBreakerEvenWhenTheCountIsWrong(): void
    {
        // Encodes a bug found by the C4 benchmark rather than by reading the code.
        //
        // The strike count is a read-modify-write on a transient, so concurrent workers all read
        // the same value and all write back the same increment. Five simultaneous slow sends were
        // measured producing a count of exactly 1, and the breaker never opened — under load,
        // which is precisely when worker exhaustion is dangerous.
        //
        // A threshold of 100 here stands in for that undercounting: the strike arm can never fire,
        // so if the breaker opens at all it is the wall-clock arm doing it.
        $breaker = new CircuitBreaker(100, 300, CircuitBreaker::TRANSIENT, 1000.0, 1);

        $breaker->recordFailure('timeout');
        self::assertFalse($breaker->isOpen(), 'Badness has not been sustained yet.');

        sleep(2);

        $breaker->recordFailure('timeout');
        self::assertTrue(
            $breaker->isOpen(),
            'An endpoint bad for longer than the window must open the breaker regardless of count.'
        );
    }

    public function testASingleBlipDoesNotAgeIntoAnOpenBreaker(): void
    {
        // The wall-clock arm must not mean "one failure, then wait". On a quiet site a lone
        // failure followed by no traffic would otherwise suspend telemetry once enough time had
        // simply elapsed. The decision is taken when a bad send is observed, not when state is read.
        $breaker = new CircuitBreaker(100, 300, CircuitBreaker::TRANSIENT, 1000.0, 1);

        $breaker->recordFailure('one blip');
        sleep(2);

        self::assertFalse($breaker->isOpen(), 'Elapsed time alone must not open the breaker.');
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
