<?php
/**
 * Plugin Name: YClients Price Accordion Pro
 * Description: Прайс YClients: несколько филиалов, аккордеон, специалисты (опционально), кэш, UTM/запись, lazy, прогрев.
 * Version: 1.6.45
 * Author: ChatGPT
 * License: GPLv2 or later
 */
if (!defined('ABSPATH')) exit;

define('YC_PA_VER','1.6.12');
define('YC_PA_DIR',plugin_dir_path(__FILE__));
define('YC_PA_URL',plugin_dir_url(__FILE__));

add_filter('cron_schedules', function($s){
  if (!isset($s['ten_minutes'])) $s['ten_minutes']=['interval'=>600,'display'=>'Once every 10 minutes'];
  return $s;
});

register_activation_hook(__FILE__, function(){
  if (version_compare(PHP_VERSION,'5.4.0','<')){
    deactivate_plugins(plugin_basename(__FILE__));
    wp_die('YClients Price Accordion требует PHP 5.4+');
  }
  if (!wp_next_scheduled('yc_pa_cron_prewarm')){
    wp_schedule_event(time()+120,'ten_minutes','yc_pa_cron_prewarm');
  }
});

register_deactivation_hook(__FILE__, function(){
  $t = wp_next_scheduled('yc_pa_cron_prewarm');
  if ($t) wp_unschedule_event($t,'yc_pa_cron_prewarm');
});

require_once YC_PA_DIR . 'admin/class-yc-admin.php';
require_once YC_PA_DIR . 'includes/helpers.php';
require_once YC_PA_DIR . 'includes/class-yc-api.php';
require_once YC_PA_DIR . 'public/class-yc-shortcode.php';

add_action('plugins_loaded', function(){
  YC_Admin::init();
  YC_Shortcode::init();
});

add_action('yc_pa_cron_prewarm', function(){
  if (defined('WP_INSTALLING') && WP_INSTALLING) return;
  if (yc_pa_debug_enabled()) return;
  $branches = yc_get_branches();
  if (!is_array($branches)) return;
  foreach($branches as $b){
    $cid = intval($b['id']);
    if ($cid<=0) continue;
    YC_API::get_categories($cid);
    YC_API::get_services($cid, null);
    YC_API::get_staff($cid);
  }
});

// Added by v1.6.0: separate admin page for staff ordering
if ( is_admin() ) { require_once plugin_dir_path(__FILE__) . 'admin/class-yc-admin-sort.php'; }

add_action('wp_enqueue_scripts', function(){
    // keep existing styles/scripts enqueued by plugin (if any)
    // add sorting script
    wp_register_script('yc-staff-sort', plugin_dir_url(__FILE__) . 'public/yc-staff-sort.js', array(), defined('YC_PA_VER') ? YC_PA_VER : '1.6.0', true);
        // v1.6.10 safe localization block
        $ycpa_localize = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
        );
        $ycpa_admin_order = get_option('yc_staff_order_map', '');
        if (!empty($ycpa_admin_order)) {
            $ycpa_localize['adminOrder'] = $ycpa_admin_order;
        }
        wp_localize_script('yc-staff-sort', 'YCStaffOrder', $ycpa_localize);
        });

// v1.6.2: enqueue staff grid CSS (3-in-row)

add_action('wp_enqueue_scripts', function(){
    wp_enqueue_style('yc-staff-grid', plugin_dir_url(__FILE__) . 'public/yc-staff-grid.css', array(), defined('YC_PA_VER') ? YC_PA_VER : '1.6.2');
}, 11);


// v1.6.7: admin simple sort map field
add_action('admin_init', function(){
    register_setting('yc_staff_ordering', 'yc_staff_admin_order_map');
});


add_action('wp_enqueue_scripts', function(){
    // Register & enqueue public assets
    wp_register_style('yc-staff-grid', plugin_dir_url(__FILE__) . 'public/yc-staff-grid.css', array(), defined('YC_PA_VER') ? YC_PA_VER : '1.0.0');
    wp_enqueue_style('yc-staff-grid');

    wp_register_script('yc-staff-sort', plugin_dir_url(__FILE__) . 'public/yc-staff-sort.js', array(), defined('YC_PA_VER') ? YC_PA_VER : '1.0.0', true);
    wp_enqueue_script('yc-staff-sort');

    // Safe localization
    $ycpa_localize = array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
    );
    $ycpa_admin_order = get_option('yc_staff_order_map', '');
    if (!empty($ycpa_admin_order)) {
        $ycpa_localize['adminOrder'] = $ycpa_admin_order;
    }
    wp_localize_script('yc-staff-sort', 'YCStaffOrder', $ycpa_localize);
}, 10);

