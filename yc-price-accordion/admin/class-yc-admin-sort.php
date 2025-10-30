<?php
/**
 * Separate admin page for Staff ordering (safe, no heredoc, no raw HTML)
 */
if ( ! defined('ABSPATH') ) { exit; }

if ( ! class_exists('YC_Admin_Sort') ) {

class YC_Admin_Sort {

    public static function boot() : void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'settings']);
    }

    public static function menu() : void {
        /* removed yc-price-staff-order page */
    }

    public static function settings() : void {
        register_setting('yc_staff_ordering', 'yc_staff_order');
        register_setting('yc_staff_ordering', 'yc_staff_sort_rest_alpha');
        register_setting('yc_staff_ordering', 'yc_staff_priority_map');

        add_option('yc_staff_sort_rest_alpha', 1);
    }

    public static function render() : void {
        if ( ! current_user_can('manage_options') ) return;

        $priority_map = esc_textarea( get_option('yc_staff_priority_map','') );
        $order_raw    = esc_textarea( get_option('yc_staff_order','') );
        $alpha        = get_option('yc_staff_sort_rest_alpha', 1) ? 1 : 0;
        $alpha_checked = checked(1, $alpha, false);

        echo '<div class="wrap">';
        echo '<h1>YC — Порядок отображения специалистов</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('yc_staff_ordering');

        echo '<style>
            .yc-admin-card{background:#fff;border:1px solid #e3e6ea;border-radius:12px;padding:18px;margin:20px 0;}
            .yc-admin-card h2{margin:0 0 12px;font-size:18px;line-height:1.3}
            .yc-admin-help{color:#555;margin:4px 0 14px}
            .yc-admin-table th{width:260px;vertical-align:top;padding-top:8px}
            .yc-admin-input{width:520px;max-width:100%}
        </style>';

        echo '
';

        submit_button();
        echo '</form></div>';
    }
}
YC_Admin_Sort::boot();
}
