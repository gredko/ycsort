<?php
if (!defined('ABSPATH')) exit;

class YC_Admin_Priority {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'settings']);
    }
    public static function settings() {
        register_setting('yc_staff_priority_group', 'yc_staff_admin_order_map', ['type'=>'string','sanitize_callback'=> 'wp_kses_post']);
        add_settings_section('yc_staff_priority_section', '', '__return_false', 'yc_staff_priority');
        add_settings_field('yc_staff_admin_order_map', __('Порядок специалистов (Имя=число)','ycpa'), [__CLASS__,'field_order'], 'yc_staff_priority', 'yc_staff_priority_section');
    }
    public static function menu() {
        add_options_page('YC Сортировка специалистов', 'YC Сортировка специалистов', 'manage_options', 'yc-staff-priority', [__CLASS__, 'render']);
    }
    public static function field_order() {
        $val = get_option('yc_staff_admin_order_map','');
        echo '<textarea name="yc_staff_admin_order_map" rows="10" class="large-text code" placeholder="Иванова Мария=1\nПетрова Анна=1\nСидоров Алексей=2">'
            . esc_textarea($val) . '</textarea>';
        echo '<p class="description">'.esc_html__('Одинаковый номер допускается. Эта сортировка имеет высший приоритет.','ycpa').'</p>';
    }
    public static function render() {
        echo '<div class="wrap"><h1>YC Сортировка специалистов</h1><form method="post" action="options.php">';
        settings_fields('yc_staff_priority_group');
        do_settings_sections('yc_staff_priority');
        submit_button();
        echo '</form></div>';
    }
}
YC_Admin_Priority::init();
