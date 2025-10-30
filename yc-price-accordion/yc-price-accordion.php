<?php
/**
 * Plugin Name:       YClients Price Accordion Pro
 * Description:       Прайс YClients с кешированием, аккордеоном услуг и сеткой специалистов.
 * Version:           2.0.0
 * Author:            ChatGPT
 * License:           GPL-2.0-or-later
 */

if (! defined('ABSPATH')) {
    exit;
}

define('YC_PA_VERSION', '2.0.0');
define('YC_PA_DIR', plugin_dir_path(__FILE__));
define('YC_PA_URL', plugin_dir_url(__FILE__));
define('YC_PA_CACHE_GROUP', 'yc_price_cache');

require_once YC_PA_DIR . 'includes/helpers.php';
require_once YC_PA_DIR . 'includes/class-yc-api.php';
require_once YC_PA_DIR . 'public/class-yc-shortcode.php';
require_once YC_PA_DIR . 'admin/class-yc-admin.php';
require_once YC_PA_DIR . 'admin/class-yc-admin-priority.php';
require_once YC_PA_DIR . 'admin/class-yc-admin-sort.php';

/**
 * Core plugin bootstrapper.
 */
final class YC_Price_Accordion_Plugin
{
    /** @var YC_Price_Accordion_Plugin|null */
    private static $instance = null;

    /**
     * Register hooks and make sure plugin is initialised exactly once.
     */
    public static function register(): void
    {
        if (null !== self::$instance) {
            return;
        }

        self::$instance = new self();
    }

    private function __construct()
    {
        add_filter('cron_schedules', [self::class, 'filter_cron_schedules']);

        register_activation_hook(__FILE__, [self::class, 'activate']);
        register_deactivation_hook(__FILE__, [self::class, 'deactivate']);

        add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
        add_action('yc_pa_cron_prewarm', [$this, 'handle_cron_prewarm']);
    }

    public static function activate(): void
    {
        if (version_compare(PHP_VERSION, '7.2', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('YClients Price Accordion требует PHP 7.2 или выше.');
        }

        if (! wp_next_scheduled('yc_pa_cron_prewarm')) {
            wp_schedule_event(time() + 120, 'ten_minutes', 'yc_pa_cron_prewarm');
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled('yc_pa_cron_prewarm');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'yc_pa_cron_prewarm');
        }
    }

    /**
     * Bootstrap plugin components once WordPress finished loading plugins.
     */
    public function on_plugins_loaded(): void
    {
        if (is_admin()) {
            YC_Admin::boot();
            YC_Admin_Priority::boot();
            YC_Admin_Sort::boot();
        }

        YC_Shortcode::boot();
    }

    /**
     * Register custom cron schedule used for cache prewarm.
     */
    public static function filter_cron_schedules(array $schedules): array
    {
        if (! isset($schedules['ten_minutes'])) {
            $schedules['ten_minutes'] = [
                'interval' => 600,
                'display'  => __('Every Ten Minutes', 'yc-price-accordion'),
            ];
        }

        return $schedules;
    }

    /**
     * Cron handler – refresh cache for every configured branch unless debug mode is enabled.
     */
    public function handle_cron_prewarm(): void
    {
        if (defined('WP_INSTALLING') && WP_INSTALLING) {
            return;
        }

        if (yc_pa_debug_enabled()) {
            return;
        }

        foreach (yc_get_branches() as $branch) {
            $branch_id = isset($branch['id']) ? (int) $branch['id'] : 0;
            if ($branch_id <= 0) {
                continue;
            }

            YC_API::prewarm_branch($branch_id, $branch);
        }
    }
}

YC_Price_Accordion_Plugin::register();
