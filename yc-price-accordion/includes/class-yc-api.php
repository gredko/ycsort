<?php
if (!defined('ABSPATH')) {
    exit;
}

class YC_API {
    const API_ROOT = 'https://api.yclients.com/api/v1';
    public static $debug_log = array();

    protected static function headers() : array {
        $partner = defined('PARTNER_TOKEN') ? trim((string) PARTNER_TOKEN) : '';
        $user    = defined('USER_TOKEN') ? trim((string) USER_TOKEN) : '';
        if ($partner === '') {
            $partner = (string) get_option('yc_partner_token', '');
        }
        if ($user === '') {
            $user = (string) get_option('yc_user_token', '');
        }
        $auth = 'Bearer ' . $partner . ', User ' . $user;
        return array(
            'Accept'        => 'application/vnd.yclients.v2+json',
            'Content-Type'  => 'application/json',
            'Authorization' => $auth,
        );
    }

    protected static function request(string $path, array $params = array()) {
        $url = rtrim(self::API_ROOT, '/') . '/' . ltrim($path, '/');
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }
        $args = array(
            'headers' => self::headers(),
            'timeout' => 20,
        );
        $start = microtime(true);
        $response = wp_remote_get($url, $args);
        $elapsed = round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            if (yc_pa_debug_enabled()) {
                self::$debug_log[] = array(
                    'url'        => $url,
                    'code'       => null,
                    'elapsed_ms' => $elapsed,
                    'error'      => $response->get_error_message(),
                    'body'       => null,
                    'count'      => null,
                );
            }
            return array('error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if (yc_pa_debug_enabled()) {
            self::$debug_log[] = array(
                'url'        => $url,
                'code'       => $code,
                'elapsed_ms' => $elapsed,
                'error'      => null,
                'count'      => (is_array($json) && isset($json['data']) && is_array($json['data'])) ? count($json['data']) : null,
                'body'       => is_string($body) ? (strlen($body) > 1500 ? substr($body, 0, 1500) . '…' : $body) : null,
            );
        }

        if ($code >= 200 && $code < 300 && is_array($json)) {
            return $json;
        }

        return array('error' => 'HTTP ' . $code, 'raw' => $body);
    }

    public static function fetch_categories_remote(int $company_id) {
        $json = self::request('/company/' . $company_id . '/service_categories');
        if (isset($json['error'])) {
            return $json;
        }
        $data = isset($json['data']) && is_array($json['data']) ? $json['data'] : array();
        $map = array();
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cid = isset($row['id']) ? (int) $row['id'] : 0;
            $title = isset($row['title']) ? (string) $row['title'] : (isset($row['name']) ? (string) $row['name'] : '');
            if ($cid > 0 && $title !== '') {
                $map[$cid] = $title;
            }
        }
        return array('map' => $map, 'raw' => $data);
    }

    public static function fetch_services_remote(int $company_id) {
        $json = self::request('/company/' . $company_id . '/services');
        if (isset($json['error'])) {
            return $json;
        }
        return isset($json['data']) && is_array($json['data']) ? $json['data'] : array();
    }

    public static function fetch_staff_remote(int $company_id) {
        $resp = self::request('/company/' . $company_id . '/staff');
        if (isset($resp['error'])) {
            return $resp;
        }
        $staffs = array();
        if (!empty($resp['data']) && is_array($resp['data'])) {
            foreach ($resp['data'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sid = isset($row['id']) ? (int) $row['id'] : 0;
                if ($sid <= 0) {
                    continue;
                }
                $pos = '';
                if (isset($row['position'])) {
                    $pos = yc_sanitize_position($row['position']);
                } elseif (isset($row['specialization'])) {
                    $pos = yc_sanitize_position($row['specialization']);
                }
                $about = isset($row['about']) ? $row['about'] : (isset($row['comment']) ? $row['comment'] : '');
                if (!isset($row['avatar_big']) && isset($row['avatar'])) {
                    $row['avatar_big'] = $row['avatar'];
                }
                if (!isset($row['image_url']) && isset($row['avatar_big'])) {
                    $row['image_url'] = $row['avatar_big'];
                }
                $row['id'] = $sid;
                $row['position'] = $pos;
                $row['about'] = $about;
                if (!isset($row['name'])) {
                    $row['name'] = '';
                }
                $staffs[$sid] = $row;
            }
        }
        return $staffs;
    }

    public static function get_categories($company_id, $force = false) {
        $cid = (int) $company_id;
        if ($cid <= 0) {
            return array();
        }
        return YC_Repository::get_categories($cid);
    }

    public static function get_services($company_id, $category_id = null, $force = false) {
        $cid = (int) $company_id;
        if ($cid <= 0) {
            return array();
        }
        return YC_Repository::get_services($cid, $category_id);
    }

    public static function get_staff($company_id, $force = false) {
        $cid = (int) $company_id;
        if ($cid <= 0) {
            return array();
        }
        return YC_Repository::get_staff($cid);
    }

    public static function sync_company($company_id, $args = array()) {
        $cid = (int) $company_id;
        if ($cid <= 0) {
            return array(
                'categories' => false,
                'services'   => false,
                'staff'      => false,
                'errors'     => array('invalid company'),
            );
        }
        return YC_Sync_Service::sync_company($cid, $args);
    }
}
