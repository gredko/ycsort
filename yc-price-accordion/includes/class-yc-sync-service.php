<?php
if (!defined('ABSPATH')) {
    exit;
}

class YC_Sync_Service {
    public static function sync_company(int $company_id, array $args = array()) : array {
        $result = array(
            'categories' => false,
            'services'   => false,
            'staff'      => false,
            'errors'     => array(),
            'stats'      => array(),
        );

        $download_photos = !isset($args['download_photos']) || (bool) $args['download_photos'];

        $categories_resp = YC_API::fetch_categories_remote($company_id);
        if (isset($categories_resp['error'])) {
            $result['errors'][] = 'categories: ' . $categories_resp['error'];
        } else {
            $raw_categories = isset($categories_resp['raw']) && is_array($categories_resp['raw']) ? $categories_resp['raw'] : array();
            YC_Repository::store_categories($company_id, $raw_categories);
            $result['categories'] = true;
            $result['stats']['categories'] = count($raw_categories);
        }

        $services_resp = YC_API::fetch_services_remote($company_id);
        $services_data = array();
        if (isset($services_resp['error'])) {
            $result['errors'][] = 'services: ' . $services_resp['error'];
        } else {
            $services_data = is_array($services_resp) ? $services_resp : array();
            $store = YC_Repository::store_services($company_id, $services_data);
            $result['services'] = true;
            $result['stats']['services'] = isset($store['total']) ? (int) $store['total'] : count($services_data);
        }

        $staff_resp = YC_API::fetch_staff_remote($company_id);
        $staff_data = array();
        if (isset($staff_resp['error'])) {
            $result['errors'][] = 'staff: ' . $staff_resp['error'];
        } else {
            $staff_data = is_array($staff_resp) ? $staff_resp : array();
        }

        if (empty($staff_data)) {
            $fallback = self::extract_staff_from_services($services_data);
            if (!empty($fallback)) {
                $staff_data = $fallback;
            }
        }

        if (!empty($staff_data)) {
            $stored = self::store_staff($company_id, $staff_data, $download_photos);
            $result['staff'] = true;
            $result['stats']['staff'] = isset($stored['total']) ? (int) $stored['total'] : count($staff_data);
        } else {
            $result['errors'][] = 'staff: empty response';
        }

        if ($result['categories'] || $result['services'] || $result['staff']) {
            update_option('yc_pa_last_sync', time(), false);
            update_option(YC_Admin::OPTION_LAST_SYNC, time(), false);
        }

        return $result;
    }

    protected static function extract_staff_from_services(array $services) : array {
        $map = array();
        foreach ($services as $service) {
            if (!is_array($service) || empty($service['staff']) || !is_array($service['staff'])) {
                continue;
            }
            foreach ($service['staff'] as $staff) {
                if (!is_array($staff)) {
                    continue;
                }
                $sid = isset($staff['id']) ? (int) $staff['id'] : 0;
                if ($sid <= 0) {
                    continue;
                }
                if (!isset($map[$sid])) {
                    $map[$sid] = $staff;
                } else {
                    if (empty($map[$sid]['image_url']) && !empty($staff['image_url'])) {
                        $map[$sid]['image_url'] = $staff['image_url'];
                    }
                    if (empty($map[$sid]['name']) && !empty($staff['name'])) {
                        $map[$sid]['name'] = $staff['name'];
                    }
                }
            }
        }
        return $map;
    }

    protected static function store_staff(int $company_id, array $staff_rows, bool $download_photos) : array {
        $existing = YC_Repository::get_staff_index($company_id);
        $normalized = array();
        $images = array();
        foreach ($staff_rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sid = isset($row['id']) ? (int) $row['id'] : 0;
            if ($sid <= 0) {
                continue;
            }
            $position = '';
            if (isset($row['position'])) {
                $position = yc_sanitize_position($row['position']);
            } elseif (isset($row['specialization'])) {
                $position = yc_sanitize_position($row['specialization']);
            }
            $about = isset($row['about']) ? $row['about'] : (isset($row['comment']) ? $row['comment'] : '');
            $image_url = '';
            if (isset($row['avatar_big']) && $row['avatar_big']) {
                $image_url = $row['avatar_big'];
            } elseif (isset($row['avatar']) && $row['avatar']) {
                $image_url = $row['avatar'];
            } elseif (isset($row['image_url']) && $row['image_url']) {
                $image_url = $row['image_url'];
            }
            if ($download_photos) {
                $images[$sid] = YC_Media::ensure_staff_image($company_id, $sid, $image_url, isset($existing[$sid]) ? $existing[$sid] : array());
            } else {
                $images[$sid] = array(
                    'id'   => isset($existing[$sid]['image_id']) ? (int) $existing[$sid]['image_id'] : 0,
                    'url'  => $image_url,
                    'hash' => $image_url !== '' ? md5($image_url) : '',
                );
            }
            $row['position'] = $position;
            $row['about']    = $about;
            $row['image_url'] = isset($images[$sid]['url']) && $images[$sid]['url'] !== '' ? $images[$sid]['url'] : $image_url;
            $row['id'] = $sid;
            if (!isset($row['name'])) {
                $row['name'] = '';
            }
            $normalized[] = $row;
        }
        return YC_Repository::store_staff($company_id, $normalized, $images);
    }
}
