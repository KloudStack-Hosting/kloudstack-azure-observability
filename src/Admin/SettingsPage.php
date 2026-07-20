<?php

declare(strict_types=1);

namespace KloudStack\Observability\Admin;

use KloudStack\Observability\Config;
use KloudStack\Observability\Plugin;
use KloudStack\Observability\Settings;
use KloudStack\Observability\Support\Guard;
use KloudStack\Observability\Telemetry\Privacy;

use const KloudStack\Observability\PREFIX;
use const KloudStack\Observability\SLUG;

defined('ABSPATH') || exit;

/**
 * Settings screen, under Tools.
 *
 * This is a hard prerequisite for the Azure Marketplace offer: the deployment hands the customer
 * a connection string, and without somewhere to paste it the post-deployment step cannot be
 * completed on anything other than App Service.
 *
 * Where configuration arrives from the environment or a constant, the field is shown read-only
 * with its source named. Presenting an editable field whose value is silently ignored is worse
 * than showing no field at all.
 */
final class SettingsPage
{
    private const CAPABILITY = 'manage_options';
    private const NONCE      = 'kloudstack_obs_settings';

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
        add_action('admin_menu', Guard::wrap([$this, 'addPage'], 'admin.menu'));
        add_action('admin_post_kloudstack_obs_save', Guard::wrap([$this, 'handleSave'], 'admin.save'));
        add_action('admin_post_kloudstack_obs_selftest', Guard::wrap([$this, 'handleSelfTest'], 'admin.selftest'));
    }

    public function addPage(): void
    {
        add_management_page(
            __('KloudStack Observability', 'kloudstack-azure-observability'),
            __('KloudStack Observability', 'kloudstack-azure-observability'),
            self::CAPABILITY,
            SLUG,
            Guard::wrap([$this, 'render'], 'admin.render')
        );
    }

    /**
     * Boolean settings, and whether each is a privacy control.
     *
     * @return array<string, array{label: string, description: string, privacy: bool}>
     */
    private static function toggles(): array
    {
        return [
            'enabled'        => [
                'label'       => 'Enable telemetry',
                'description' => 'Master switch. Turning this off stops all collection without deactivating the plugin.',
                'privacy'     => false,
            ],
            'client_enabled' => [
                'label'       => 'Browser telemetry',
                'description' => 'Loads the Application Insights JavaScript SDK to record page views, AJAX calls and JavaScript errors.',
                'privacy'     => false,
            ],
            'anonymise_ip'   => [
                'label'       => 'Anonymise visitor IP addresses',
                'description' => 'Removes the final octet of IPv4 addresses and the final 80 bits of IPv6 addresses before transmission. Recommended, and required in most jurisdictions.',
                'privacy'     => true,
            ],
            'cookieless'     => [
                'label'       => 'Cookie-less browser telemetry',
                'description' => 'Stops the SDK setting ai_user and ai_session cookies, removing the consent obligation. Session and user aggregation become unavailable.',
                'privacy'     => true,
            ],
            'header_tracking' => [
                'label'       => 'Collect request and response headers',
                'description' => 'WARNING: transmits cookies and authorization headers to Azure. Leave off unless you have a specific need and have accounted for it in your privacy policy.',
                'privacy'     => true,
            ],
            'track_admin'    => [
                'label'       => 'Track admin requests',
                'description' => 'Records wp-admin and admin-ajax requests.',
                'privacy'     => false,
            ],
            'track_cron'     => [
                'label'       => 'Track WP-Cron requests',
                'description' => 'High volume and rarely useful. Enabling this increases Azure ingestion cost.',
                'privacy'     => false,
            ],
            'bundled_sdk'    => [
                'label'       => 'Serve the JavaScript SDK locally',
                'description' => 'Serves the SDK from this site instead of Microsoft\'s CDN. Useful under a strict Content Security Policy.',
                'privacy'     => false,
            ],
            'debug_log'      => [
                'label'       => 'Debug log',
                'description' => 'Writes plugin diagnostics to wp-content. Requires WP_DEBUG. For troubleshooting only.',
                'privacy'     => false,
            ],
        ];
    }

    /**
     * Persist submitted settings.
     */
    public function handleSave(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'kloudstack-azure-observability'));
        }

        check_admin_referer(self::NONCE);

        // Unchecked boxes are absent from the POST body entirely, so every known toggle is
        // written explicitly rather than inferred from what arrived.
        foreach (array_keys(self::toggles()) as $key) {
            update_option(PREFIX . $key, isset($_POST[$key]) ? '1' : '');
        }

        $rate = isset($_POST['sampling_requests']) ? (int) $_POST['sampling_requests'] : 100;
        update_option(PREFIX . 'sampling_requests', max(1, min(100, $rate)));

        update_option(
            PREFIX . 'cloud_role',
            isset($_POST['cloud_role']) ? sanitize_text_field(wp_unslash((string) $_POST['cloud_role'])) : ''
        );

        update_option(
            PREFIX . 'excluded_paths',
            isset($_POST['excluded_paths'])
                ? sanitize_textarea_field(wp_unslash((string) $_POST['excluded_paths']))
                : ''
        );

        // Only writable when the environment is not already authoritative — otherwise a stored
        // value would sit in the database looking active while being ignored.
        if (!$this->config->isLocked() && isset($_POST['connection_string'])) {
            update_option(
                PREFIX . 'connection_string',
                sanitize_text_field(wp_unslash((string) $_POST['connection_string']))
            );
        }

        $this->settings->reset();
        $this->config->reset();

        wp_safe_redirect(add_query_arg('kloudstack_obs_saved', '1', self::pageUrl()));
        exit;
    }

    /**
     * Run the self-test and stash the result for the next page render.
     */
    public function handleSelfTest(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to run diagnostics.', 'kloudstack-azure-observability'));
        }

        check_admin_referer(self::NONCE);

        $diagnostics = new Diagnostics($this->config, $this->settings, $this->plugin);

        set_transient(PREFIX . 'selftest_result', $diagnostics->run(true), 300);

        wp_safe_redirect(add_query_arg('kloudstack_obs_tested', '1', self::pageUrl()));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $locked = $this->config->isLocked();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('KloudStack Observability for Azure', 'kloudstack-azure-observability') . '</h1>';

        $this->renderNotices();
        $this->renderSelfTestResult();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="kloudstack_obs_save" />';
        wp_nonce_field(self::NONCE);

        echo '<h2>' . esc_html__('Connection', 'kloudstack-azure-observability') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->renderConnectionField($locked);
        $this->renderTextField(
            'cloud_role',
            __('Cloud role name', 'kloudstack-azure-observability'),
            __('Overrides the name this site reports as in Azure Monitor. Leave blank to derive it from the Azure site name.', 'kloudstack-azure-observability')
        );
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Telemetry', 'kloudstack-azure-observability') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->renderToggle('enabled');
        $this->renderToggle('client_enabled');
        $this->renderToggle('track_admin');
        $this->renderToggle('track_cron');
        $this->renderSamplingField();
        $this->renderTextareaField(
            'excluded_paths',
            __('Excluded paths', 'kloudstack-azure-observability'),
            __('One per line. Plain text matches anywhere in the path; wrap in # to use a regular expression.', 'kloudstack-azure-observability')
        );
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Privacy', 'kloudstack-azure-observability') . '</h2>';
        echo '<p class="description">' . esc_html__(
            'These controls govern what leaves your site. The defaults are the conservative option.',
            'kloudstack-azure-observability'
        ) . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->renderToggle('anonymise_ip');
        $this->renderToggle('cookieless');
        $this->renderToggle('header_tracking');
        echo '</tbody></table>';
        $this->renderRedactionNotice();

        echo '<h2>' . esc_html__('Advanced', 'kloudstack-azure-observability') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->renderToggle('bundled_sdk');
        $this->renderToggle('debug_log');
        echo '</tbody></table>';

        submit_button();
        echo '</form>';

        $this->renderSelfTestForm();

        echo '</div>';
    }

    private function renderNotices(): void
    {
        if (isset($_GET['kloudstack_obs_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Settings saved.', 'kloudstack-azure-observability')
                . '</p></div>';
        }

        if (!$this->config->isConfigured()) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__(
                    'No Application Insights connection string is configured, so no telemetry is being collected.',
                    'kloudstack-azure-observability'
                )
                . '</p></div>';
        }
    }

    private function renderConnectionField(bool $locked): void
    {
        $credentials = $this->config->credentials();

        echo '<tr><th scope="row"><label for="connection_string">'
            . esc_html__('Connection string', 'kloudstack-azure-observability')
            . '</label></th><td>';

        if ($locked) {
            // Masked: the key is a credential, and the settings page is visible to every
            // administrator on the site.
            echo '<code>' . esc_html(self::mask((string) ($credentials['connection_string'] ?: $credentials['ikey']))) . '</code>';
            echo '<p class="description">' . esc_html(sprintf(
                /* translators: %s: configuration source */
                __('Set by %s and cannot be changed here. This is deliberate: the hosting environment is authoritative.', 'kloudstack-azure-observability'),
                $this->config->source() === Config::SOURCE_CONSTANT ? 'a wp-config.php constant' : 'an environment variable'
            )) . '</p>';
        } else {
            $stored = (string) get_option(PREFIX . 'connection_string', '');

            echo '<input type="text" class="regular-text code" id="connection_string" name="connection_string" value="'
                . esc_attr($stored) . '" autocomplete="off" />';
            echo '<p class="description">' . esc_html__(
                'From your Application Insights resource in the Azure portal, or from the KloudStack Marketplace deployment output.',
                'kloudstack-azure-observability'
            ) . '</p>';
        }

        echo '</td></tr>';
    }

    private function renderToggle(string $key): void
    {
        $definition = self::toggles()[$key] ?? null;

        if ($definition === null) {
            return;
        }

        $checked = $this->settings->bool($key);

        echo '<tr><th scope="row">' . esc_html($definition['label']) . '</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr($key) . '" value="1" '
            . checked($checked, true, false) . ' /> ';
        echo esc_html__('Enabled', 'kloudstack-azure-observability') . '</label>';
        echo '<p class="description">' . esc_html($definition['description']) . '</p>';
        echo '</td></tr>';
    }

    private function renderSamplingField(): void
    {
        echo '<tr><th scope="row"><label for="sampling_requests">'
            . esc_html__('Sampling', 'kloudstack-azure-observability')
            . '</label></th><td>';
        echo '<input type="number" min="1" max="100" id="sampling_requests" name="sampling_requests" value="'
            . esc_attr((string) $this->settings->samplingRate()) . '" class="small-text" /> %';
        echo '<p class="description">' . esc_html__(
            'Percentage of requests recorded. Lower values reduce Azure ingestion cost; Azure extrapolates totals so counts stay approximately correct.',
            'kloudstack-azure-observability'
        ) . '</p>';
        echo '</td></tr>';
    }

    private function renderTextField(string $key, string $label, string $description): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="' . esc_attr($key) . '" name="' . esc_attr($key)
            . '" value="' . esc_attr($this->settings->string($key)) . '" />';
        echo '<p class="description">' . esc_html($description) . '</p>';
        echo '</td></tr>';
    }

    private function renderTextareaField(string $key, string $label, string $description): void
    {
        $value = implode("\n", $this->settings->stringList($key));

        echo '<tr><th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        echo '<textarea class="large-text code" rows="4" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '">'
            . esc_textarea($value) . '</textarea>';
        echo '<p class="description">' . esc_html($description) . '</p>';
        echo '</td></tr>';
    }

    /**
     * Make the always-redacted parameter list visible.
     *
     * Stating the protection explicitly is worth more than implying it — an administrator
     * assessing whether this plugin is safe to run should not have to read the source.
     */
    private function renderRedactionNotice(): void
    {
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: comma-separated list of query parameter names */
            __('Query parameters named %s are always redacted before transmission, regardless of these settings.', 'kloudstack-azure-observability'),
            implode(', ', array_slice(Privacy::alwaysRedacted(), 0, 10)) . ', …'
        )) . '</p>';
    }

    private function renderSelfTestForm(): void
    {
        echo '<hr /><h2>' . esc_html__('Diagnostics', 'kloudstack-azure-observability') . '</h2>';
        echo '<p>' . esc_html__(
            'Checks configuration, Azure environment, outbound connectivity and transmission, and sends one test item to Azure.',
            'kloudstack-azure-observability'
        ) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="kloudstack_obs_selftest" />';
        wp_nonce_field(self::NONCE);
        submit_button(__('Run self-test', 'kloudstack-azure-observability'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderSelfTestResult(): void
    {
        $result = get_transient(PREFIX . 'selftest_result');

        if (!is_array($result) || $result === []) {
            return;
        }

        echo '<h2>' . esc_html__('Self-test result', 'kloudstack-azure-observability') . '</h2>';
        echo '<table class="widefat striped" style="max-width:60em"><tbody>';

        foreach ($result as $check) {
            if (!is_array($check)) {
                continue;
            }

            $status = (string) ($check['status'] ?? Diagnostics::STATUS_UNKNOWN);

            echo '<tr>';
            echo '<td style="width:6em"><strong>' . esc_html(self::statusLabel($status)) . '</strong></td>';
            echo '<td style="width:12em">' . esc_html((string) ($check['label'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($check['message'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        $diagnostics = new Diagnostics($this->config, $this->settings, $this->plugin);

        echo '<p><label for="kloudstack_obs_report">' . esc_html__(
            'Copy this when contacting support:',
            'kloudstack-azure-observability'
        ) . '</label></p>';
        echo '<textarea id="kloudstack_obs_report" class="large-text code" rows="12" readonly>'
            . esc_textarea($diagnostics->asText(false)) . '</textarea>';
    }

    private static function statusLabel(string $status): string
    {
        switch ($status) {
            case Diagnostics::STATUS_PASS:
                return '✓ Pass';

            case Diagnostics::STATUS_WARN:
                return '! Warn';

            case Diagnostics::STATUS_FAIL:
                return '✗ Fail';

            default:
                return '? Unknown';
        }
    }

    /**
     * Mask a credential for display.
     */
    public static function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (strlen($value) <= 12) {
            return str_repeat('•', strlen($value));
        }

        return substr($value, 0, 8) . str_repeat('•', 12) . substr($value, -4);
    }

    private static function pageUrl(): string
    {
        return admin_url('tools.php?page=' . SLUG);
    }
}
