<?php

declare(strict_types=1);

namespace KloudStack\Observability\Context;

defined('ABSPATH') || exit;

/**
 * Azure environment detection and cloud role naming.
 *
 * This is the plugin's main reason to exist as an Azure-specific tool rather than a generic
 * Application Insights bridge. Correct cloud role naming is what makes a single Application
 * Insights resource usable across an agency's sites and across deployment slots — without it,
 * every site reports as an anonymous hostname and Azure Monitor cannot separate them. The 1.x
 * plugin set only ai.cloud.roleInstance, which is why fleet-wide views were never usable.
 *
 * Detection is environment-variable based and therefore free. No network calls are made: Azure
 * IMDS would be the canonical source on a VM, but a metadata request on every page load is not a
 * cost this plugin is willing to impose. VM detection is best-effort and falls back to unknown.
 */
final class AzureContext
{
    public const ENV_APP_SERVICE    = 'app-service';
    public const ENV_CONTAINER_APPS = 'container-apps';
    public const ENV_AKS            = 'aks';
    public const ENV_VM             = 'vm';
    public const ENV_UNKNOWN        = 'unknown';

    /** App Service sets this on production; slots set their own name. */
    private const PRODUCTION_SLOT = 'production';

    /** @var array<string, string>|null */
    private $detected = null;

    /** @var string */
    private $roleOverride;

    /** @var string */
    private $siteHost;

    /**
     * @param string $roleOverride Explicit cloud role name from settings; wins when non-empty.
     * @param string $siteHost     Fallback role name, normally the WordPress site host.
     */
    public function __construct(string $roleOverride = '', string $siteHost = '')
    {
        $this->roleOverride = trim($roleOverride);
        $this->siteHost     = trim($siteHost);
    }

    /**
     * Which Azure environment this site is running in.
     */
    public function environment(): string
    {
        return $this->detect()['environment'];
    }

    public function isAzure(): bool
    {
        return $this->environment() !== self::ENV_UNKNOWN;
    }

    /**
     * The Azure site or workload name, empty when not detectable.
     */
    public function siteName(): string
    {
        return $this->detect()['site_name'];
    }

    /**
     * Deployment slot. "production" when not running in a named slot.
     */
    public function slot(): string
    {
        return $this->detect()['slot'];
    }

    public function isProductionSlot(): bool
    {
        return $this->slot() === self::PRODUCTION_SLOT;
    }

    public function region(): string
    {
        return $this->detect()['region'];
    }

    /**
     * ai.cloud.role — the logical application name.
     *
     * Resolution order:
     *   1. Explicit override from settings, for agencies with their own naming scheme.
     *   2. Azure site name, slot-qualified when not production.
     *   3. WordPress site host.
     *   4. "WordPress" as a last resort — never empty, because an empty role name renders as a
     *      blank entry in Azure Monitor's application map rather than as a missing value.
     *
     * Slot qualification matters: without it a staging slot's telemetry merges into production's
     * and skews every percentile on the production dashboard.
     */
    public function role(): string
    {
        if ($this->roleOverride !== '') {
            return $this->roleOverride;
        }

        $siteName = $this->siteName();

        if ($siteName !== '') {
            return $this->isProductionSlot()
                ? $siteName
                : $siteName . '-' . $this->slot();
        }

        if ($this->siteHost !== '') {
            return $this->siteHost;
        }

        return 'WordPress';
    }

    /**
     * ai.cloud.roleInstance — which instance produced this telemetry.
     *
     * On a scaled-out App Service plan this is what allows a problem isolated to one instance to
     * be distinguished from a problem affecting the whole site.
     */
    public function roleInstance(): string
    {
        $instanceId = $this->detect()['instance_id'];

        if ($instanceId !== '') {
            // App Service instance ids are 64-character hashes. The leading 8 characters are
            // unique enough to distinguish instances and keep Azure Monitor's UI readable.
            return substr($instanceId, 0, 8);
        }

        $hostname = gethostname();

        return is_string($hostname) && $hostname !== '' ? $hostname : 'unknown';
    }

    /**
     * Azure dimensions for the telemetry schema (specification section 8.1).
     *
     * @return array<string, string>
     */
    public function dimensions(): array
    {
        $detected = $this->detect();

        $dimensions = ['azure_environment' => $detected['environment']];

        // Only emit dimensions that carry information. Empty custom dimensions are billed as
        // ingested data and clutter every workbook query with blank facet values.
        foreach (['site_name' => 'azure_site_name', 'region' => 'azure_region'] as $key => $dimension) {
            if ($detected[$key] !== '') {
                $dimensions[$dimension] = $detected[$key];
            }
        }

        // Slots exist only on App Service. The internal default is "production" so that role
        // naming has something to compare against, but emitting it elsewhere would assert a
        // deployment slot that does not exist — a site on a VM is not "in production slot".
        if ($detected['environment'] === self::ENV_APP_SERVICE) {
            $dimensions['azure_slot'] = $detected['slot'];
        }

        return $dimensions;
    }

    /**
     * Reset memoised detection. Test seam.
     */
    public function reset(): void
    {
        $this->detected = null;
    }

    /**
     * @return array<string, string>
     */
    private function detect(): array
    {
        if ($this->detected !== null) {
            return $this->detected;
        }

        $context = [
            'environment' => self::ENV_UNKNOWN,
            'site_name'   => '',
            'slot'        => self::PRODUCTION_SLOT,
            'region'      => '',
            'instance_id' => '',
        ];

        // App Service — WEBSITE_SITE_NAME is set on every App Service instance, including
        // containers. Checked first because it is the primary target and the most specific.
        $siteName = self::env('WEBSITE_SITE_NAME');

        if ($siteName !== '') {
            $slot = strtolower(self::env('WEBSITE_SLOT_NAME'));

            $context['environment'] = self::ENV_APP_SERVICE;
            $context['site_name']   = $siteName;
            $context['slot']        = $slot !== '' ? $slot : self::PRODUCTION_SLOT;
            $context['region']      = self::normaliseRegion(self::env('REGION_NAME'));
            $context['instance_id'] = self::env('WEBSITE_INSTANCE_ID');

            return $this->detected = $context;
        }

        // Container Apps.
        $containerApp = self::env('CONTAINER_APP_NAME');

        if ($containerApp !== '') {
            $context['environment'] = self::ENV_CONTAINER_APPS;
            $context['site_name']   = $containerApp;
            $context['region']      = self::normaliseRegion(self::env('CONTAINER_APP_ENV_DNS_SUFFIX'));
            $context['instance_id'] = self::env('CONTAINER_APP_REPLICA_NAME');

            return $this->detected = $context;
        }

        // AKS — KUBERNETES_SERVICE_HOST is injected into every pod. It does not prove Azure
        // specifically, so the workload name is taken from the pod's own identity and the
        // environment is only claimed as AKS when an Azure-specific marker is also present.
        if (self::env('KUBERNETES_SERVICE_HOST') !== '') {
            $context['environment'] = self::ENV_AKS;
            $context['site_name']   = self::env('APP_NAME') ?: self::env('HOSTNAME');
            $context['region']      = self::normaliseRegion(self::env('AZURE_REGION'));
            $context['instance_id'] = self::env('HOSTNAME');

            return $this->detected = $context;
        }

        // Virtual machine — no reliable environment marker exists without querying IMDS, which
        // this class deliberately does not do. A site can declare itself via wp-config.php.
        if (defined('KLOUDSTACK_OBS_AZURE_VM') && constant('KLOUDSTACK_OBS_AZURE_VM')) {
            $context['environment'] = self::ENV_VM;
            $context['region']      = self::normaliseRegion(self::env('AZURE_REGION'));
        }

        return $this->detected = $context;
    }

    /**
     * Azure region names appear in several forms depending on the variable that carries them:
     * "Australia East", "australiaeast" and DNS suffixes such as
     * "australiaeast.azurecontainerapps.io". Normalise to the compact form Azure uses in resource
     * identifiers, so telemetry can be joined against other Azure data.
     */
    private static function normaliseRegion(string $region): string
    {
        if ($region === '') {
            return '';
        }

        // Take the leading label from a DNS suffix.
        if (strpos($region, '.') !== false) {
            $region = strstr($region, '.', true) ?: $region;
        }

        $normalised = strtolower(str_replace(' ', '', $region));

        // Guard against a region variable carrying something unexpected.
        return preg_match('/^[a-z0-9]{1,40}$/', $normalised) === 1 ? $normalised : '';
    }

    /**
     * Read an environment variable, checking both getenv() and $_SERVER.
     *
     * App Service exposes application settings through either depending on the SAPI and PHP
     * configuration.
     */
    private static function env(string $name): string
    {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return trim($value);
        }

        if (!isset($_SERVER[$name]) || !is_string($_SERVER[$name])) {
            return '';
        }

        return trim(sanitize_text_field(wp_unslash($_SERVER[$name])));
    }
}
