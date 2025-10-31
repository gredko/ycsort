<?php
if (!defined('ABSPATH')) {
    exit;
}

class YC_Sync_Service {
    public static function sync_company(int $company_id, array $args = array()) : array {
        $mode = isset($args['mode']) ? (string) $args['mode'] : 'branch';
        switch ($mode) {
            case 'staff_list':
                return self::sync_staff_list($company_id);
            case 'staff_photos':
                return self::sync_staff_photos($company_id, $args);
            case 'services_init':
                return self::sync_services_page($company_id, $args, true);
            case 'services_batch':
                return self::sync_services_page($company_id, $args, false);
        }

        return self::full_sync($company_id, $args);
    }

    protected static function full_sync(int $company_id, array $args = array()) : array {
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

    protected static function sync_staff_list(int $company_id) : array {
        $result = array(
            'mode'       => 'staff_list',
            'categories' => false,
            'services'   => false,
            'staff'      => false,
            'errors'     => array(),
            'stats'      => array(),
        );

        $staff_resp = YC_API::fetch_staff_remote($company_id);
        $staff_data = array();
        if (isset($staff_resp['error'])) {
            $result['errors'][] = 'staff: ' . $staff_resp['error'];
        } else {
            $staff_data = is_array($staff_resp) ? $staff_resp : array();
        }

        if (!empty($staff_data)) {
            $stored = self::store_staff($company_id, $staff_data, false);
            $result['staff'] = true;
            $result['stats']['staff'] = isset($stored['total']) ? (int) $stored['total'] : count($staff_data);
        } else {
            $services_resp = YC_API::fetch_services_remote($company_id);
            if (!isset($services_resp['error'])) {
                $fallback = self::extract_staff_from_services(is_array($services_resp) ? $services_resp : array());
                if (!empty($fallback)) {
                    $stored = self::store_staff($company_id, $fallback, false);
                    $result['staff'] = true;
                    $result['stats']['staff'] = isset($stored['total']) ? (int) $stored['total'] : count($fallback);
                }
            }
        }

        if (empty($result['stats'])) {
            $result['stats']['staff'] = 0;
        }

        $result['state'] = array(
            'total' => $result['stats']['staff'],
        );

        return $result;
    }

    protected static function sync_staff_photos(int $company_id, array $args) : array {
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        $limit  = isset($args['limit']) ? max(1, min(50, (int) $args['limit'])) : 5;

        $batch = YC_Repository::get_staff_batch($company_id, $offset, $limit);
        $rows  = isset($batch['rows']) && is_array($batch['rows']) ? $batch['rows'] : array();
        $total = isset($batch['total']) ? (int) $batch['total'] : 0;

        $existing = YC_Repository::get_staff_index($company_id);
        $images   = array();
        $processed = 0;

        foreach ($rows as $row) {
            $sid = isset($row['staff_id']) ? (int) $row['staff_id'] : 0;
            if ($sid <= 0) {
                continue;
            }
            $image_url = '';
            if (!empty($row['raw_data'])) {
                $raw = json_decode($row['raw_data'], true);
                if (is_array($raw)) {
                    if (isset($raw['avatar_big']) && $raw['avatar_big']) {
                        $image_url = $raw['avatar_big'];
                    } elseif (isset($raw['avatar']) && $raw['avatar']) {
                        $image_url = $raw['avatar'];
                    } elseif (isset($raw['image_url']) && $raw['image_url']) {
                        $image_url = $raw['image_url'];
                    }
                }
            }
            if ($image_url === '' && isset($row['image_url']) && $row['image_url'] !== '') {
                $image_url = $row['image_url'];
            }
            $existing_row = isset($existing[$sid]) ? $existing[$sid] : array();
            $images[$sid] = YC_Media::ensure_staff_image($company_id, $sid, (string) $image_url, $existing_row);
            $processed++;
        }

        YC_Repository::update_staff_images($company_id, $images);

        $next_offset = $offset + $processed;
        $done = ($processed === 0) || ($total > 0 && $next_offset >= $total);

        return array(
            'mode'       => 'staff_photos',
            'categories' => false,
            'services'   => false,
            'staff'      => true,
            'errors'     => array(),
            'stats'      => array('photos' => $processed),
            'state'      => array(
                'offset'     => $offset,
                'processed'  => $processed,
                'completed'  => min($next_offset, $total ? $total : $next_offset),
                'next_offset'=> $next_offset,
                'total'      => $total,
                'limit'      => $limit,
                'done'       => $done,
            ),
        );
    }

    protected static function sync_services_page(int $company_id, array $args, bool $initial) : array {
        $limit = isset($args['limit']) ? max(1, min(100, (int) $args['limit'])) : 50;
        $page  = isset($args['page']) ? max(1, (int) $args['page']) : 1;

        $result = array(
            'mode'       => $initial ? 'services_init' : 'services_batch',
            'categories' => false,
            'services'   => false,
            'staff'      => false,
            'errors'     => array(),
            'stats'      => array(),
            'state'      => array(),
        );

        if ($initial) {
            $categories_resp = YC_API::fetch_categories_remote($company_id);
            if (isset($categories_resp['error'])) {
                $result['errors'][] = 'categories: ' . $categories_resp['error'];
            } else {
                $raw_categories = isset($categories_resp['raw']) && is_array($categories_resp['raw']) ? $categories_resp['raw'] : array();
                YC_Repository::store_categories($company_id, $raw_categories);
                $result['categories'] = true;
                $result['stats']['categories'] = count($raw_categories);
            }
            YC_Repository::begin_service_sync($company_id);
        }

        $services_resp = YC_API::fetch_services_page($company_id, $page, $limit);
        if (isset($services_resp['error'])) {
            $result['errors'][] = 'services: ' . $services_resp['error'];
            return $result;
        }

        $services = isset($services_resp['data']) && is_array($services_resp['data']) ? $services_resp['data'] : array();
        $meta     = isset($services_resp['meta']) && is_array($services_resp['meta']) ? $services_resp['meta'] : array();
        $total    = isset($meta['total']) && $meta['total'] ? (int) $meta['total'] : null;

        if ($initial && isset($meta['total'])) {
            YC_Repository::set_service_sync_total($company_id, (int) $meta['total']);
        }

        $reset_relations = $initial && $page === 1;
        $stored = YC_Repository::store_services_partial($company_id, $services, $reset_relations);
        $state  = isset($stored['state']) ? $stored['state'] : YC_Repository::get_service_sync_state($company_id);
        if (isset($state['ids'])) {
            unset($state['ids']);
        }

        if ($total !== null) {
            YC_Repository::set_service_sync_total($company_id, $total);
            $state['total'] = $total;
        } elseif (isset($state['total'])) {
            $total = $state['total'];
        }

        $processed_total = isset($state['processed']) ? (int) $state['processed'] : (isset($state['ids']) && is_array($state['ids']) ? count($state['ids']) : 0);
        $processed_batch = isset($stored['processed']) ? (int) $stored['processed'] : count($services);

        $result['services'] = true;
        $result['stats']['services'] = $processed_total;
        $result['stats']['services_batch'] = $processed_batch;
        if (isset($stored['relations'])) {
            $result['stats']['relations'] = (int) $stored['relations'];
        }

        $has_more = false;
        if ($total !== null) {
            $has_more = $processed_total < $total;
        } else {
            $has_more = $processed_batch >= $limit && !empty($services);
        }

        $next_page = $page + 1;
        if (!$has_more) {
            YC_Repository::complete_service_sync($company_id);
        }

        $result['state'] = array(
            'page'       => $page,
            'next_page'  => $has_more ? $next_page : null,
            'limit'      => $limit,
            'processed'  => $processed_batch,
            'completed'  => $processed_total,
            'total'      => $total,
            'has_more'   => $has_more,
        );

        return $result;
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
