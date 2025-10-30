<?php
/**
 * Plugin Name: YClients Price Accordion Pro
 * Description: Прайс YClients: несколько филиалов, аккордеон, специалисты (опционально), кэш, UTM/запись, lazy, прогрев.
 * Version: 1.7.0
 * Author: ChatGPT
 * License: GPLv2 or later
 */

defined('ABSPATH') || exit;

if (!defined('YC_PA_VER')) {
    define('YC_PA_VER', '1.7.0');
}

if (!defined('YC_PA_FILE')) {
    define('YC_PA_FILE', __FILE__);
}

if (!defined('YC_PA_DIR')) {
    define('YC_PA_DIR', plugin_dir_path(__FILE__));
}

if (!defined('YC_PA_URL')) {
    define('YC_PA_URL', plugin_dir_url(__FILE__));
}

final class YC_Price_Accordion_Pro {

    public static function boot() : void {
        add_filter('cron_schedules', [__CLASS__, 'register_custom_schedule']);
        add_action('plugins_loaded', [__CLASS__, 'load_dependencies']);
        add_action('plugins_loaded', [__CLASS__, 'init_components']);
        add_action('yc_pa_cron_prewarm', [__CLASS__, 'prewarm_cache']);
    }

    public static function register_custom_schedule(array $schedules) : array {
        if (!isset($schedules['ten_minutes'])) {
            $schedules['ten_minutes'] = [
                'interval' => 600,
                'display'  => __('Once every 10 minutes', 'yc-price-accordion'),
            ];
        }
        return $schedules;
    }

    public static function activate() : void {
        if (version_compare(PHP_VERSION, '7.2.0', '<')) {
            deactivate_plugins(plugin_basename(YC_PA_FILE));
            wp_die(__('YClients Price Accordion требует PHP 7.2 или выше.', 'yc-price-accordion'));
        }

        if (!wp_next_scheduled('yc_pa_cron_prewarm')) {
            wp_schedule_event(time() + 120, 'ten_minutes', 'yc_pa_cron_prewarm');
        }
    }

    public static function deactivate() : void {
        $timestamp = wp_next_scheduled('yc_pa_cron_prewarm');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'yc_pa_cron_prewarm');
        }
    }

    public static function load_dependencies() : void {
        require_once YC_PA_DIR . 'includes/helpers.php';
        require_once YC_PA_DIR . 'includes/class-yc-storage.php';
        require_once YC_PA_DIR . 'includes/class-yc-api.php';
        require_once YC_PA_DIR . 'public/class-yc-shortcode.php';

        if (is_admin()) {
            require_once YC_PA_DIR . 'admin/class-yc-admin.php';
            require_once YC_PA_DIR . 'admin/class-yc-admin-priority.php';
        }
    }

    public static function init_components() : void {
        if (class_exists('YC_Admin')) {
            YC_Admin::init();
        }

        if (class_exists('YC_Admin_Priority')) {
            YC_Admin_Priority::init();
        }

        if (class_exists('YC_Shortcode')) {
            YC_Shortcode::init();
        }
    }

    public static function prewarm_cache() : void {
        if (defined('WP_INSTALLING') && WP_INSTALLING) {
            return;
        }
        if (yc_pa_debug_enabled()) {
            return;
        }
        $branches = yc_get_branches();
        if (!is_array($branches) || empty($branches)) {
            return;
        }
        foreach ($branches as $branch) {
            $company_id = isset($branch['id']) ? (int) $branch['id'] : 0;
            if ($company_id <= 0) {
                continue;
            }
            YC_API::sync_company($company_id, false);
        }
        update_option('yc_pa_last_sync', time(), false);
    }
}

YC_Price_Accordion_Pro::boot();

register_activation_hook(YC_PA_FILE, ['YC_Price_Accordion_Pro', 'activate']);
register_deactivation_hook(YC_PA_FILE, ['YC_Price_Accordion_Pro', 'deactivate']);

