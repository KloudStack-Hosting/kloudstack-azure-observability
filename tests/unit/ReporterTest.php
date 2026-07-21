<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Context\AzureContext;
use KloudStack\Observability\Context\Correlation;
use KloudStack\Observability\Context\WordPressContext;
use KloudStack\Observability\Telemetry\Buffer;
use KloudStack\Observability\Telemetry\Envelope;
use KloudStack\Observability\Telemetry\Reporter;
use KloudStack\Observability\Telemetry\Sampler;
use KloudStack\Observability\Transport\CircuitBreaker;
use KloudStack\Observability\Transport\ResponseRelease;
use KloudStack\Observability\Transport\Transport;
use PHPUnit\Framework\TestCase;
use WPStubs;

/**
 * The reporter is where the pieces meet, so this is where an integration mistake shows up —
 * correlation tags missing from an item, dimensions not merged, or the response being held while
 * telemetry is transmitted.
 *
 * @covers \KloudStack\Observability\Telemetry\Reporter
 */
final class ReporterTest extends TestCase
{
    private const IKEY = '11111111-2222-3333-4444-555555555555';

    /** @var array<int, array<int, array<string, mixed>>> */
    private $batches = [];

    protected function setUp(): void
    {
        parent::setUp();
        WPStubs::reset();
        $this->batches = [];
    }

    private function reporter(int $samplingRate = 100, ?Buffer $buffer = null): Reporter
    {
        $sender = function (string $endpoint, string $body, array $headers): array {
            if (substr($body, 0, 2) === "\x1f\x8b") {
                $body = (string) gzdecode($body);
            }

            $decoded = json_decode($body, true);

            $this->batches[] = is_array($decoded) ? $decoded : [];

            return ['status' => 200, 'error' => ''];
        };

        return new Reporter(
            new Envelope(self::IKEY, 'php:kloudstack_2.0.0'),
            $buffer ?? new Buffer(),
            new Sampler($samplingRate),
            new Transport('https://example.invalid/v2/track', new CircuitBreaker(), $sender),
            new ResponseRelease(),
            new AzureContext('contoso-wp', 'example.com'),
            new WordPressContext(1, '2.0.0', 'standard'),
            new Correlation('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')
        );
    }

    public function testRequestTelemetryCarriesTheExpectedShape(): void
    {
        $reporter = $this->reporter();
        $reporter->trackRequest('GET /shop', 'https://example.com/shop', 123.5, 200);
        $reporter->flush();

        self::assertCount(1, $this->batches);

        $item = $this->batches[0][0];

        self::assertSame('RequestData', $item['data']['baseType']);
        self::assertSame('GET /shop', $item['data']['baseData']['name']);
        self::assertSame('0.00:00:00.1235000', $item['data']['baseData']['duration']);
        self::assertSame('200', $item['data']['baseData']['responseCode']);
        self::assertTrue($item['data']['baseData']['success']);
    }

    public function testFailedRequestsAreMarkedUnsuccessful(): void
    {
        $reporter = $this->reporter();
        $reporter->trackRequest('GET /missing', 'https://example.com/missing', 10.0, 404);
        $reporter->flush();

        self::assertFalse($this->batches[0][0]['data']['baseData']['success']);
    }

    public function testEveryItemCarriesCorrelationAndRoleTags(): void
    {
        // Without these an item cannot be joined to its trace or attributed to a site.
        $reporter = $this->reporter();
        $reporter->trackRequest('GET /', 'https://example.com/', 1.0, 200);
        $reporter->flush();

        $tags = $this->batches[0][0]['tags'];

        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $tags['ai.operation.id']);
        self::assertSame('00f067aa0ba902b7', $tags['ai.operation.parentId']);
        self::assertSame('contoso-wp', $tags['ai.cloud.role']);
        self::assertSame('GET /', $tags['ai.operation.name']);
        self::assertArrayHasKey('ai.cloud.roleInstance', $tags);
    }

    public function testClientIpIsSentAsTheLocationTagAzureActuallyReads(): void
    {
        // A live site proved this matters: with the address only in a custom dimension, the Users
        // chart fell from ~130/hour to zero the moment this plugin took over. Azure reads
        // ai.location.ip and nothing else for Users, Sessions and Location.
        $reporter = $this->reporter();
        $reporter->trackRequest('GET /', 'https://example.com/', 1.0, 200, [], '203.0.113.0');
        $reporter->flush();

        self::assertSame('203.0.113.0', $this->batches[0][0]['tags']['ai.location.ip']);
    }

    public function testLocationTagIsOmittedWhenNoAddressIsAvailable(): void
    {
        // An empty tag would be worse than none: Azure would treat it as a real value.
        $reporter = $this->reporter();
        $reporter->trackRequest('GET /', 'https://example.com/', 1.0, 200, [], '');
        $reporter->flush();

        self::assertArrayNotHasKey('ai.location.ip', $this->batches[0][0]['tags']);
    }

    public function testSchemaDimensionsAreAttachedToEveryItem(): void
    {
        // The Marketplace workbook queries bind to these.
        $reporter = $this->reporter();
        $reporter->trackRequest('GET /', 'https://example.com/', 1.0, 200, ['custom_thing' => 'kept']);
        $reporter->flush();

        $properties = $this->batches[0][0]['data']['baseData']['properties'];

        self::assertSame('1', $properties['schema_version']);
        self::assertSame('2.0.0', $properties['plugin_version']);
        self::assertSame('standard', $properties['load_mode']);
        self::assertSame('kept', $properties['custom_thing'], 'Collector dimensions must survive the merge.');
        self::assertArrayHasKey('azure_environment', $properties);
    }

    public function testExceptionTelemetryCarriesStackAndSeverity(): void
    {
        $reporter = $this->reporter();
        $reporter->trackException('RuntimeException', 'Something broke', [[
            'level'    => 0,
            'method'   => 'Foo::bar',
            'fileName' => '/app/foo.php',
            'line'     => 42,
        ]], Envelope::SEVERITY_CRITICAL);
        $reporter->flush();

        $baseData = $this->batches[0][0]['data']['baseData'];

        self::assertSame('ExceptionData', $this->batches[0][0]['data']['baseType']);
        self::assertSame('RuntimeException', $baseData['exceptions'][0]['typeName']);
        self::assertSame('Something broke', $baseData['exceptions'][0]['message']);
        self::assertTrue($baseData['exceptions'][0]['hasFullStack']);
        self::assertSame(Envelope::SEVERITY_CRITICAL, $baseData['severityLevel']);
    }

    public function testExceptionWithoutStackReportsNoFullStack(): void
    {
        $reporter = $this->reporter();
        $reporter->trackException('RuntimeException', 'No trace');
        $reporter->flush();

        self::assertFalse($this->batches[0][0]['data']['baseData']['exceptions'][0]['hasFullStack']);
    }

    public function testAllItemsInARequestGoOutInOneBatch(): void
    {
        $reporter = $this->reporter();
        $reporter->trackException('RuntimeException', 'first');
        $reporter->trackRequest('GET /', 'https://example.com/', 1.0, 500);
        $reporter->flush();

        self::assertCount(1, $this->batches, 'One request must produce one outbound call.');
        self::assertCount(2, $this->batches[0]);
    }

    public function testSampledOutRequestsProduceNothing(): void
    {
        $reporter = $this->reporter(0);
        $reporter->trackRequest('GET /', 'https://example.com/', 1.0, 200);
        $reporter->flush();

        self::assertSame([], $this->batches);
    }

    public function testFlushIsIdempotent(): void
    {
        // Shutdown handlers can run more than once; a second flush must not resend.
        $reporter = $this->reporter();
        $reporter->trackRequest('GET /', 'https://example.com/', 1.0, 200);

        $reporter->flush();
        $reporter->flush();

        self::assertCount(1, $this->batches);
        self::assertTrue($reporter->hasFlushed());
    }

    public function testFlushWithNothingBufferedSendsNothing(): void
    {
        $this->reporter()->flush();

        self::assertSame([], $this->batches);
    }

    public function testBufferCapIsRespectedByTheReporter(): void
    {
        $reporter = $this->reporter(100, new Buffer(3));

        for ($i = 0; $i < 10; ++$i) {
            $reporter->trackException('RuntimeException', 'error ' . $i);
        }

        $reporter->flush();

        self::assertCount(3, $this->batches[0], 'The buffer cap must bound the payload.');
    }

    public function testLongValuesAreTruncatedRatherThanSent(): void
    {
        $reporter = $this->reporter();
        $reporter->trackRequest(str_repeat('a', 5000), 'https://example.com/' . str_repeat('b', 5000), 1.0, 200);
        $reporter->flush();

        $baseData = $this->batches[0][0]['data']['baseData'];

        self::assertLessThanOrEqual(1024, strlen($baseData['name']));
        self::assertLessThanOrEqual(2048, strlen($baseData['url']));
    }
}
