<?php
if (!defined('ABSPATH')) {
    exit;
}

class YC_Sync_Controller {
    const ROUTE_NAMESPACE = 'yc-pa/v1';

    public static function init() : void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() : void {
        if (!function_exists('register_rest_route')) {
            return;
        }

        $readable = 'GET';
        $creatable = 'POST';
        if (class_exists('WP_REST_Server')) {
            $readable = WP_REST_Server::READABLE;
            $creatable = WP_REST_Server::CREATABLE;
        }

        register_rest_route(self::ROUTE_NAMESPACE, '/sync', array(
            array(
                'methods'             => $readable,
                'callback'            => [__CLASS__, 'get_status'],
                'permission_callback' => [__CLASS__, 'check_permissions'],
            ),
            array(
                'methods'             => $creatable,
                'callback'            => [__CLASS__, 'handle_sync'],
                'permission_callback' => [__CLASS__, 'check_permissions'],
                'args'                => array(
                    'mode' => array(
                        'type'              => 'string',
                        'default'           => 'plan',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'company_id' => array(
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                ),
            ),
        ));
    }

    public static function check_permissions() : bool {
        return current_user_can('manage_options');
    }

    public static function get_status() : WP_REST_Response {
        try {
            $branches = function_exists('yc_get_branches') ? yc_get_branches() : get_option('yc_branches', array());
            if (!is_array($branches)) {
                $branches = array();
            }
        } catch (Throwable $e) {
            return new WP_REST_Response(array(
                'branches'  => array(),
                'last_sync' => (int) get_option('yc_pa_last_sync', 0),
                'error'     => $e->getMessage(),
            ), 500);
        }

        $last_sync = (int) get_option('yc_pa_last_sync', 0);

        return new WP_REST_Response(array(
            'branches'  => $branches,
            'last_sync' => $last_sync,
        ));
    }

    public static function handle_sync(WP_REST_Request $request) {
        try {
            $mode = $request->get_param('mode');
            if ($mode === 'plan') {
                return self::get_status();
            }

            $company_id = (int) $request->get_param('company_id');
            if ($company_id <= 0) {
                return new WP_Error('yc_pa_invalid_company', __('Некорректный идентификатор филиала', 'yc-price-accordion'), array('status' => 400));
            }

            $args = $request->get_json_params();
            if (!is_array($args)) {
                $args = array();
            }

            $result = YC_Sync_Service::sync_company($company_id, $args);

            return new WP_REST_Response(array(
                'company_id' => $company_id,
                'result'     => $result,
            ));
        } catch (Throwable $e) {
            return new WP_Error('yc_pa_sync_exception', $e->getMessage(), array('status' => 500));
        }
    }
}
