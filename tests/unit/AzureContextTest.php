<?php

declare(strict_types=1);

namespace KloudStack\Observability\Tests\Unit;

use KloudStack\Observability\Context\AzureContext;
use PHPUnit\Framework\TestCase;

/**
 * Cloud role naming is what makes one Application Insights resource usable across many sites and
 * across slots. Getting it wrong is not a crash — it silently merges unrelated sites, or merges a
 * staging slot into production and skews every percentile on the production dashboard.
 *
 * @covers \KloudStack\Observability\Context\AzureContext
 */
final class AzureContextTest extends TestCase
{
    private const VARIABLES = [
        'WEBSITE_SITE_NAME',
        'WEBSITE_SLOT_NAME',
        'WEBSITE_INSTANCE_ID',
        'REGION_NAME',
        'CONTAINER_APP_NAME',
        'CONTAINER_APP_ENV_DNS_SUFFIX',
        'CONTAINER_APP_REPLICA_NAME',
        'KUBERNETES_SERVICE_HOST',
        'AZURE_REGION',
        'APP_NAME',
        'HOSTNAME',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearEnvironment();
    }

    protected function tearDown(): void
    {
        $this->clearEnvironment();
        parent::tearDown();
    }

    private function clearEnvironment(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
            unset($_SERVER[$name]);
        }
    }

    /**
     * @param array<string, string> $variables
     */
    private function withEnvironment(array $variables): void
    {
        foreach ($variables as $name => $value) {
            putenv($name . '=' . $value);
        }
    }

    public function testDetectsAppService(): void
    {
        $this->withEnvironment([
            'WEBSITE_SITE_NAME'   => 'contoso-wp',
            'REGION_NAME'         => 'Australia East',
            'WEBSITE_INSTANCE_ID' => str_repeat('a', 64),
        ]);

        $context = new AzureContext();

        self::assertSame(AzureContext::ENV_APP_SERVICE, $context->environment());
        self::assertTrue($context->isAzure());
        self::assertSame('contoso-wp', $context->siteName());
        self::assertSame('australiaeast', $context->region());
        self::assertSame('aaaaaaaa', $context->roleInstance());
    }

    public function testProductionSlotIsNotSuffixed(): void
    {
        $this->withEnvironment(['WEBSITE_SITE_NAME' => 'contoso-wp']);

        $context = new AzureContext();

        self::assertSame('production', $context->slot());
        self::assertTrue($context->isProductionSlot());
        self::assertSame('contoso-wp', $context->role());
    }

    public function testNamedSlotQualifiesTheRole(): void
    {
        // Without this, staging telemetry merges into production and skews its percentiles.
        $this->withEnvironment([
            'WEBSITE_SITE_NAME' => 'contoso-wp',
            'WEBSITE_SLOT_NAME' => 'Staging',
        ]);

        $context = new AzureContext();

        self::assertSame('staging', $context->slot());
        self::assertFalse($context->isProductionSlot());
        self::assertSame('contoso-wp-staging', $context->role());
    }

    public function testDetectsContainerApps(): void
    {
        $this->withEnvironment([
            'CONTAINER_APP_NAME'           => 'kloudstack-wp',
            'CONTAINER_APP_ENV_DNS_SUFFIX' => 'australiaeast.azurecontainerapps.io',
            'CONTAINER_APP_REPLICA_NAME'   => 'kloudstack-wp--abc123',
        ]);

        $context = new AzureContext();

        self::assertSame(AzureContext::ENV_CONTAINER_APPS, $context->environment());
        self::assertSame('kloudstack-wp', $context->siteName());
        self::assertSame('australiaeast', $context->region(), 'DNS suffix must reduce to the region label.');
    }

    public function testDetectsAks(): void
    {
        $this->withEnvironment([
            'KUBERNETES_SERVICE_HOST' => '10.0.0.1',
            'APP_NAME'                => 'wordpress',
            'AZURE_REGION'            => 'westeurope',
        ]);

        $context = new AzureContext();

        self::assertSame(AzureContext::ENV_AKS, $context->environment());
        self::assertSame('wordpress', $context->siteName(), 'Must echo the APP_NAME value verbatim.');
        self::assertSame('westeurope', $context->region());
    }

    public function testAppServiceTakesPrecedenceOverOtherMarkers(): void
    {
        // A WordPress container on App Service can carry unrelated markers. The most specific
        // signal must win, or the site reports as the wrong environment.
        $this->withEnvironment([
            'WEBSITE_SITE_NAME'       => 'contoso-wp',
            'KUBERNETES_SERVICE_HOST' => '10.0.0.1',
            'CONTAINER_APP_NAME'      => 'something-else',
        ]);

        self::assertSame(AzureContext::ENV_APP_SERVICE, (new AzureContext())->environment());
    }

    public function testNonAzureEnvironment(): void
    {
        $context = new AzureContext('', 'example.com');

        self::assertSame(AzureContext::ENV_UNKNOWN, $context->environment());
        self::assertFalse($context->isAzure());
        self::assertSame('', $context->siteName());
        self::assertSame('example.com', $context->role(), 'Falls back to the site host.');
    }

    public function testRoleNeverReturnsEmpty(): void
    {
        // An empty role renders as a blank entry in Azure Monitor's application map rather than
        // as a missing value, which is worse than a generic name.
        self::assertSame('WordPress', (new AzureContext())->role());
    }

    public function testOverrideWinsOverEverything(): void
    {
        $this->withEnvironment([
            'WEBSITE_SITE_NAME' => 'contoso-wp',
            'WEBSITE_SLOT_NAME' => 'staging',
        ]);

        self::assertSame('Client A - Blog', (new AzureContext('Client A - Blog', 'example.com'))->role());
    }

    public function testDimensionsOmitEmptyValues(): void
    {
        // Empty custom dimensions are billed as ingested data and add blank facet values to every
        // workbook query.
        $dimensions = (new AzureContext())->dimensions();

        self::assertSame(['azure_environment' => 'unknown'], $dimensions);
    }

    public function testDimensionsForAppService(): void
    {
        $this->withEnvironment([
            'WEBSITE_SITE_NAME' => 'contoso-wp',
            'WEBSITE_SLOT_NAME' => 'staging',
            'REGION_NAME'       => 'Australia East',
        ]);

        self::assertSame([
            'azure_environment' => 'app-service',
            'azure_site_name'   => 'contoso-wp',
            'azure_region'      => 'australiaeast',
            'azure_slot'        => 'staging',
        ], (new AzureContext())->dimensions());
    }

    public function testSlotDimensionIsOnlyEmittedOnAppService(): void
    {
        // Slots are an App Service concept. Emitting "production" on a VM or in Container Apps
        // would assert a deployment slot that does not exist.
        $this->withEnvironment(['CONTAINER_APP_NAME' => 'kloudstack-wp']);

        self::assertArrayNotHasKey('azure_slot', (new AzureContext())->dimensions());
    }

    /**
     * @dataProvider regionForms
     */
    public function testRegionNormalisation(string $raw, string $expected): void
    {
        $this->withEnvironment(['WEBSITE_SITE_NAME' => 'site', 'REGION_NAME' => $raw]);

        self::assertSame($expected, (new AzureContext())->region());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function regionForms(): array
    {
        return [
            'display form'  => ['Australia East', 'australiaeast'],
            'compact form'  => ['australiaeast', 'australiaeast'],
            'mixed case'    => ['WestUS2', 'westus2'],
            'dns suffix'    => ['eastus.azurecontainerapps.io', 'eastus'],
            'unexpected'    => ['not a region!', ''],
            'empty'         => ['', ''],
        ];
    }

    public function testRoleInstanceFallsBackToHostname(): void
    {
        $instance = (new AzureContext())->roleInstance();

        self::assertNotSame('', $instance);
        self::assertSame(gethostname() ?: 'unknown', $instance);
    }

    public function testDetectionIsMemoisedAndResettable(): void
    {
        $this->withEnvironment(['WEBSITE_SITE_NAME' => 'contoso-wp']);
        $context = new AzureContext();

        self::assertSame('contoso-wp', $context->siteName());

        $this->clearEnvironment();

        self::assertSame('contoso-wp', $context->siteName(), 'Detection must run once per request.');

        $context->reset();

        self::assertSame('', $context->siteName());
    }
}
