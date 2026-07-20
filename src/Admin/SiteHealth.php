<?php

declare(strict_types=1);

namespace KloudStack\Observability\Admin;

use KloudStack\Observability\Config;
use KloudStack\Observability\Plugin;
use KloudStack\Observability\Settings;
use KloudStack\Observability\Support\Guard;

use const KloudStack\Observability\SLUG;
use const KloudStack\Observability\VERSION;

defined('ABSPATH') || exit;

/**
 * WordPress Site Health integration.
 *
 * Puts failures where an administrator will encounter them without having gone looking. Telemetry
 * silently stopping is exactly the kind of problem nobody notices until they need the data, which
 * is the worst possible moment.
 *
 * The Site Health test runs without the live round-trip: it executes on admin page loads, and
 * making an outbound HTTPS call every time someone opens the dashboard is not a cost this plugin
 * should impose. The full test, including the round-trip, stays on the settings page behind a
 * button.
 */
final class SiteHealth
{
    /** @var Config */
    private $config;

    /** @var Settings */
    private $settings;

    /** @var Plugin */
    private $plugin;

    public function __construct(Config $config, Settings $settings, Plugin $plugin)
    {
        $this->config   = $config;
        $this->settings = $settings;
        $this->plugin   = $plugin;
    }

    public function register(): void
    {
        add_filter('site_status_tests', Guard::wrap([$this, 'addTest'], 'sitehealth.tests', []));
        add_filter('debug_information', Guard::wrap([$this, 'addDebugInformation'], 'sitehealth.debug', []));
    }

    /**
     * @param array<string, array<string, mixed>> $tests
     *
     * @return array<string, array<string, mixed>>
     */
    public function addTest($tests): array
    {
        if (!is_array($tests)) {
            return [];
        }

        $tests['direct']['kloudstack_obs'] = [
            'label' => __('Azure telemetry', 'kloudstack-azure-observability'),
            'test'  => Guard::wrap([$this, 'runTest'], 'sitehealth.run', []),
        ];

        return $tests;
    }

    /**
     * @return array<string, mixed>
     */
    public function runTest(): array
    {
        $diagnostics = new Diagnostics($this->config, $this->settings, $this->plugin);
        $checks      = $diagnostics->run(false);

        $failures = [];
        $warnings = [];

        foreach ($checks as $check) {
            if ($check['status'] === Diagnostics::STATUS_FAIL) {
                $failures[] = $check;
            } elseif ($check['status'] === Diagnostics::STATUS_WARN) {
                $warnings[] = $check;
            }
        }

        if ($failures !== []) {
            return $this->result(
                'critical',
                'red',
                __('WordPress telemetry is not reaching Azure', 'kloudstack-azure-observability'),
                $failures
            );
        }

        if ($warnings !== []) {
            return $this->result(
                'recommended',
                'orange',
                __('Azure telemetry is running with limitations', 'kloudstack-azure-observability'),
                $warnings
            );
        }

        return $this->result(
            'good',
            'green',
            __('WordPress telemetry is reaching Azure', 'kloudstack-azure-observability'),
            []
        );
    }

    /**
     * @param array<int, array{id: string, label: string, status: string, message: string}> $items
     *
     * @return array<string, mixed>
     */
    private function result(string $status, string $colour, string $label, array $items): array
    {
        $description = '';

        foreach ($items as $item) {
            $description .= '<p><strong>' . esc_html($item['label']) . ':</strong> '
                . esc_html($item['message']) . '</p>';
        }

        if ($description === '') {
            $description = '<p>' . esc_html__(
                'Telemetry is configured and transmitting normally.',
                'kloudstack-azure-observability'
            ) . '</p>';
        }

        return [
            'label'       => $label,
            'status'      => $status,
            'badge'       => [
                'label' => __('Performance', 'kloudstack-azure-observability'),
                'color' => $colour,
            ],
            'description' => $description,
            'actions'     => sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('tools.php?page=' . SLUG)),
                esc_html__('Open settings and run the full self-test', 'kloudstack-azure-observability')
            ),
            'test'        => 'kloudstack_obs',
        ];
    }

    /**
     * Add plugin state to the Site Health information tab.
     *
     * Support requests arrive with a Site Health export far more often than with anything else,
     * so this is frequently the only diagnostic data available.
     *
     * @param array<string, array<string, mixed>> $info
     *
     * @return array<string, array<string, mixed>>
     */
    public function addDebugInformation($info): array
    {
        if (!is_array($info)) {
            return [];
        }

        $fields = [];

        foreach ((new Diagnostics($this->config, $this->settings, $this->plugin))->run(false) as $check) {
            $fields[$check['id']] = [
                'label' => $check['label'],
                'value' => strtoupper($check['status']) . ' — ' . $check['message'],
            ];
        }

        $fields['version'] = [
            'label' => __('Plugin version', 'kloudstack-azure-observability'),
            'value' => VERSION,
        ];

        $info['kloudstack_obs'] = [
            'label'       => __('KloudStack Observability for Azure', 'kloudstack-azure-observability'),
            'description' => __(
                'Telemetry configuration and health. Contains no credentials.',
                'kloudstack-azure-observability'
            ),
            'fields'      => $fields,
        ];

        return $info;
    }
}
