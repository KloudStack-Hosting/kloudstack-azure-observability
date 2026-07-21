<?php

declare(strict_types=1);

namespace KloudStack\Observability\Telemetry;

use KloudStack\Observability\Context\AzureContext;
use KloudStack\Observability\Context\Correlation;
use KloudStack\Observability\Context\WordPressContext;
use KloudStack\Observability\Support\Guard;
use KloudStack\Observability\Transport\ResponseRelease;
use KloudStack\Observability\Transport\Transport;

defined('ABSPATH') || exit;

/**
 * Assembles telemetry and hands it to the transport.
 *
 * The collectors know what happened; this knows how to describe it to Application Insights. It
 * owns the sampling decision, the context tags and the buffer, so a collector never has to think
 * about any of them.
 *
 * The ordering in flush() is the whole performance argument of the plugin: release the response
 * to the visitor first, then transmit. Nothing here may run before the response is out.
 */
final class Reporter
{
    /** @var Envelope */
    private $envelope;

    /** @var Buffer */
    private $buffer;

    /** @var Sampler */
    private $sampler;

    /** @var Transport */
    private $transport;

    /** @var ResponseRelease */
    private $release;

    /** @var AzureContext */
    private $azure;

    /** @var WordPressContext */
    private $wordpress;

    /** @var Correlation */
    private $correlation;

    /** @var bool */
    private $flushed = false;

    public function __construct(
        Envelope $envelope,
        Buffer $buffer,
        Sampler $sampler,
        Transport $transport,
        ResponseRelease $release,
        AzureContext $azure,
        WordPressContext $wordpress,
        Correlation $correlation
    ) {
        $this->envelope    = $envelope;
        $this->buffer      = $buffer;
        $this->sampler     = $sampler;
        $this->transport   = $transport;
        $this->release     = $release;
        $this->azure       = $azure;
        $this->wordpress   = $wordpress;
        $this->correlation = $correlation;
    }

    public function correlation(): Correlation
    {
        return $this->correlation;
    }

    public function buffer(): Buffer
    {
        return $this->buffer;
    }

    /**
     * Whether this request's telemetry is being recorded.
     *
     * Decided once per request from the operation id, so every item in the trace agrees.
     */
    public function isSampledIn(): bool
    {
        return $this->sampler->shouldSample($this->correlation->operationId());
    }

    /**
     * Record a request.
     *
     * @param array<string, string> $dimensions Extra custom dimensions.
     * @param string                $clientIp   Already-anonymised client address, or empty.
     */
    public function trackRequest(
        string $name,
        string $url,
        float $durationMs,
        int $responseCode,
        array $dimensions = [],
        string $clientIp = ''
    ): void {
        $tags = ['ai.operation.name' => Envelope::truncate($name, 1024)];

        /*
         * ai.location.ip is the field Application Insights actually reads. Without it the Users,
         * Sessions and Location views stay empty and the application map has no geography,
         * because Azure falls back to the socket address — which for server-side telemetry is the
         * App Service itself, identical for every request.
         *
         * Putting the address only in a custom dimension, as this plugin previously did, keeps it
         * queryable but invisible to every built-in view. A live site made that obvious: the Users
         * chart fell from ~130/hour to zero at the moment this plugin took over.
         *
         * The value is anonymised before it reaches here (last IPv4 octet zeroed, last 80 bits of
         * IPv6), so what Azure receives is already non-identifying. Azure then masks it again on
         * ingestion unless DisableIpMasking is set, using it for geo lookup and discarding it.
         */
        if ($clientIp !== '') {
            $tags['ai.location.ip'] = $clientIp;
        }

        $this->track('Request', 'RequestData', [
            'id'           => $this->correlation->spanId(),
            'name'         => Envelope::truncate($name, 1024),
            'url'          => Envelope::truncate($url, 2048),
            'duration'     => Envelope::duration($durationMs),
            'responseCode' => (string) $responseCode,
            'success'      => $responseCode < 400,
            'properties'   => $this->dimensions($dimensions),
        ], $tags);
    }

    /**
     * Record an exception.
     *
     * @param array<int, array<string, mixed>> $parsedStack
     * @param array<string, string>            $dimensions
     */
    public function trackException(
        string $type,
        string $message,
        array $parsedStack = [],
        int $severity = Envelope::SEVERITY_ERROR,
        array $dimensions = []
    ): void {
        $this->track('Exception', 'ExceptionData', [
            'exceptions' => [[
                'id'           => 1,
                'typeName'     => Envelope::truncate($type, 1024),
                'message'      => Envelope::truncate($message, 32768),
                'hasFullStack' => $parsedStack !== [],
                'parsedStack'  => $parsedStack,
            ]],
            'severityLevel' => $severity,
            'properties'    => $this->dimensions($dimensions),
        ]);
    }

    /**
     * Build and buffer a telemetry item.
     *
     * @param array<string, mixed>  $baseData
     * @param array<string, string> $extraTags
     */
    private function track(string $typeName, string $baseType, array $baseData, array $extraTags = []): void
    {
        Guard::run(function () use ($typeName, $baseType, $baseData, $extraTags): void {
            if (!$this->isSampledIn()) {
                return;
            }

            $tags = array_merge(
                $this->correlation->tags(),
                [
                    'ai.cloud.role'         => $this->azure->role(),
                    'ai.cloud.roleInstance' => $this->azure->roleInstance(),
                ],
                $extraTags
            );

            $this->buffer->add(
                $this->envelope->build($typeName, $baseType, $baseData, $tags, $this->sampler->sampleRate())
            );
        }, 'reporter.track');
    }

    /**
     * Merge collector dimensions with the standing schema v1 context.
     *
     * @param array<string, string> $dimensions
     *
     * @return array<string, string>
     */
    private function dimensions(array $dimensions): array
    {
        return array_merge(
            $this->wordpress->dimensions(),
            $this->azure->dimensions(),
            $dimensions
        );
    }

    /**
     * Release the response, then transmit everything buffered.
     *
     * The order is not negotiable. Transmitting first would put Azure's latency on the visitor's
     * critical path, which is the defect this plugin exists to not repeat.
     */
    public function flush(): void
    {
        if ($this->flushed) {
            return;
        }

        $this->flushed = true;

        Guard::run(function (): void {
            if ($this->buffer->isEmpty()) {
                // Still release: another shutdown handler may be waiting behind us, and there is
                // no reason to hold the response for a buffer with nothing in it.
                $this->release->release();

                return;
            }

            $this->release->release();
            $this->transport->send($this->buffer->flush());
        }, 'reporter.flush');
    }

    public function hasFlushed(): bool
    {
        return $this->flushed;
    }
}
