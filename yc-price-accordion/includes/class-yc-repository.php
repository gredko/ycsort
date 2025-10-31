<?php
if (!defined('ABSPATH')) {
    exit;
}

class YC_Repository {
    const VERSION = '1.0.0';
    const OPTION_VERSION = 'yc_pa_repository_version';
    const SERVICE_SYNC_OPTION_PREFIX = 'yc_pa_service_sync_';

    public static function init() : void {
        add_action('plugins_loaded', [__CLASS__, 'maybe_upgrade']);
    }

    public static function maybe_upgrade() : void {
        $stored = get_option(self::OPTION_VERSION, '');
        if ($stored !== self::VERSION) {
            self::install();
        }
    }

    public static function install() : void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $services = self::table_services();
        $staff    = self::table_staff();
        $pivot    = self::table_service_staff();
        $cats     = self::table_categories();

        $sql = [];

        $sql[] = "CREATE TABLE $services (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            category_name VARCHAR(255) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            description LONGTEXT NULL,
            price_min DECIMAL(10,2) NOT NULL DEFAULT 0,
            price_max DECIMAL(10,2) NOT NULL DEFAULT 0,
            duration INT UNSIGNED NOT NULL DEFAULT 0,
            raw_data LONGTEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY company_service (company_id, service_id),
            KEY company_category (company_id, category_id)
        ) $charset;";

        $sql[] = "CREATE TABLE $staff (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            staff_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL DEFAULT '',
            position VARCHAR(255) NOT NULL DEFAULT '',
            about LONGTEXT NULL,
            image_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            image_url VARCHAR(255) NOT NULL DEFAULT '',
            image_hash VARCHAR(64) NOT NULL DEFAULT '',
            raw_data LONGTEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY company_staff (company_id, staff_id),
            KEY company (company_id)
        ) $charset;";

        $sql[] = "CREATE TABLE $pivot (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            staff_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY company_service_staff (company_id, service_id, staff_id),
            KEY company_service (company_id, service_id),
            KEY company_staff (company_id, staff_id)
        ) $charset;";

        $sql[] = "CREATE TABLE $cats (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            title VARCHAR(255) NOT NULL DEFAULT '',
            raw_data LONGTEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY company_category (company_id, category_id),
            KEY company (company_id)
        ) $charset;";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        update_option(self::OPTION_VERSION, self::VERSION, false);
    }

    public static function table_services() : string {
        global $wpdb;
        return $wpdb->prefix . 'yc_pa_services';
    }

    public static function table_staff() : string {
        global $wpdb;
        return $wpdb->prefix . 'yc_pa_staff';
    }

    public static function table_service_staff() : string {
        global $wpdb;
        return $wpdb->prefix . 'yc_pa_service_staff';
    }

    public static function table_categories() : string {
        global $wpdb;
        return $wpdb->prefix . 'yc_pa_categories';
    }

    protected static function now() : string {
        return current_time('mysql', true);
    }

    public static function get_staff_index(int $company_id) : array {
        global $wpdb;
        $table = self::table_staff();
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT staff_id, image_id, image_url, image_hash, sort_order FROM $table WHERE company_id = %d", $company_id), ARRAY_A);
        $index = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $sid = isset($row['staff_id']) ? (int) $row['staff_id'] : 0;
                if ($sid <= 0) {
                    continue;
                }
                $index[$sid] = array(
                    'image_id'   => isset($row['image_id']) ? (int) $row['image_id'] : 0,
                    'image_url'  => isset($row['image_url']) ? (string) $row['image_url'] : '',
                    'image_hash' => isset($row['image_hash']) ? (string) $row['image_hash'] : '',
                    'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
                );
            }
        }
        return $index;
    }

    public static function store_categories(int $company_id, array $categories) : void {
        global $wpdb;
        $table = self::table_categories();
        $now   = self::now();
        $ids   = array();
        foreach ($categories as $category) {
            $cat_id = isset($category['id']) ? (int) $category['id'] : (isset($category['category_id']) ? (int) $category['category_id'] : 0);
            if ($cat_id <= 0) {
                continue;
            }
            $ids[] = $cat_id;
            $data = array(
                'company_id'  => $company_id,
                'category_id' => $cat_id,
                'parent_id'   => isset($category['parent_id']) ? (int) $category['parent_id'] : (isset($category['parentId']) ? (int) $category['parentId'] : 0),
                'title'       => isset($category['title']) ? (string) $category['title'] : (isset($category['name']) ? (string) $category['name'] : ''),
                'raw_data'    => wp_json_encode($category),
                'sort_order'  => isset($category['sort_order']) ? (int) $category['sort_order'] : 0,
                'updated_at'  => $now,
            );
            $wpdb->replace($table, $data, array('%d', '%d', '%d', '%s', '%s', '%d', '%s'));
        }
        if (empty($ids)) {
            $wpdb->delete($table, array('company_id' => $company_id), array('%d'));
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $params = array_merge(array($company_id), $ids);
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE company_id = %d AND category_id NOT IN ($placeholders)", $params));
        }
    }

    public static function store_services(int $company_id, array $services) : array {
        global $wpdb;
        $table = self::table_services();
        $now   = self::now();
        $ids   = array();
        $relations = array();
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }
            $service_id = isset($service['id']) ? (int) $service['id'] : 0;
            if ($service_id <= 0) {
                continue;
            }
            $ids[] = $service_id;
            $category_id = 0;
            $category_name = '';
            if (isset($service['category_id'])) {
                $category_id = (int) $service['category_id'];
            } elseif (isset($service['categoryId'])) {
                $category_id = (int) $service['categoryId'];
            } elseif (isset($service['category']['id'])) {
                $category_id = (int) $service['category']['id'];
            }
            if (isset($service['category']['title'])) {
                $category_name = (string) $service['category']['title'];
            } elseif (isset($service['category']['name'])) {
                $category_name = (string) $service['category']['name'];
            } elseif (isset($service['category_name'])) {
                $category_name = (string) $service['category_name'];
            }
            $price_min = isset($service['price_min']) ? (float) $service['price_min'] : (isset($service['cost_min']) ? (float) $service['cost_min'] : 0.0);
            $price_max = isset($service['price_max']) ? (float) $service['price_max'] : (isset($service['cost_max']) ? (float) $service['cost_max'] : $price_min);
            $duration  = isset($service['duration']) ? (int) $service['duration'] : (isset($service['length']) ? (int) $service['length'] : 0);
            $title     = isset($service['title']) ? (string) $service['title'] : (isset($service['name']) ? (string) $service['name'] : '');
            $description = isset($service['comment']) ? (string) $service['comment'] : (isset($service['description']) ? (string) $service['description'] : '');
            $is_active = isset($service['active']) ? (int) !!$service['active'] : 1;
            $sort      = isset($service['weight']) ? (int) $service['weight'] : (isset($service['sort_order']) ? (int) $service['sort_order'] : 0);

            $wpdb->replace(
                $table,
                array(
                    'company_id'    => $company_id,
                    'service_id'    => $service_id,
                    'category_id'   => $category_id,
                    'category_name' => $category_name,
                    'title'         => $title,
                    'description'   => $description,
                    'price_min'     => $price_min,
                    'price_max'     => $price_max,
                    'duration'      => $duration,
                    'raw_data'      => wp_json_encode($service),
                    'is_active'     => $is_active,
                    'sort_order'    => $sort,
                    'updated_at'    => $now,
                ),
                array('%d','%d','%d','%s','%s','%f','%f','%d','%s','%d','%d','%s')
            );

            if (!empty($service['staff']) && is_array($service['staff'])) {
                foreach ($service['staff'] as $staff) {
                    $sid = 0;
                    if (is_array($staff)) {
                        $sid = isset($staff['id']) ? (int) $staff['id'] : 0;
                    } elseif (is_numeric($staff)) {
                        $sid = (int) $staff;
                    }
                    if ($sid > 0) {
                        if (!isset($relations[$service_id])) {
                            $relations[$service_id] = array();
                        }
                        $relations[$service_id][$sid] = true;
                    }
                }
            }
        }

        if (empty($ids)) {
            $wpdb->delete($table, array('company_id' => $company_id), array('%d'));
            self::store_service_relations($company_id, array());
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $params = array_merge(array($company_id), $ids);
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE company_id = %d AND service_id NOT IN ($placeholders)", $params));
            self::store_service_relations($company_id, $relations);
        }

        $relation_count = 0;
        foreach ($relations as $staff_map) {
            if (is_array($staff_map)) {
                $relation_count += count($staff_map);
            }
        }

        return array(
            'total'      => count($ids),
            'relations'  => $relation_count,
        );
    }

    protected static function store_service_relations(int $company_id, array $map) : void {
        global $wpdb;
        $table = self::table_service_staff();
        $wpdb->delete($table, array('company_id' => $company_id), array('%d'));
        foreach ($map as $service_id => $staff_map) {
            if (!is_array($staff_map)) {
                continue;
            }
            foreach (array_keys($staff_map) as $staff_id) {
                $sid = (int) $staff_id;
                if ($sid <= 0) {
                    continue;
                }
                $wpdb->insert($table, array(
                    'company_id' => $company_id,
                    'service_id' => (int) $service_id,
                    'staff_id'   => $sid,
                ), array('%d','%d','%d'));
            }
        }
    }

    public static function store_staff(int $company_id, array $staff, array $image_map = array()) : array {
        global $wpdb;
        $table = self::table_staff();
        $now   = self::now();
        $ids   = array();
        foreach ($staff as $row) {
            if (!is_array($row)) {
                continue;
            }
            $staff_id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($staff_id <= 0) {
                continue;
            }
            $ids[] = $staff_id;
            $sort_order = null;
            if (isset($row['sort_order']) && $row['sort_order'] !== null && $row['sort_order'] !== '') {
                $sort_order = (int) $row['sort_order'];
            } elseif (isset($row['weight']) && $row['weight'] !== null && $row['weight'] !== '') {
                $sort_order = (int) $row['weight'];
            }
            if ($sort_order === null) {
                $sort_order = 500;
            }
            $data = array(
                'company_id' => $company_id,
                'staff_id'   => $staff_id,
                'name'       => isset($row['name']) ? (string) $row['name'] : '',
                'position'   => isset($row['position']) ? yc_sanitize_position($row['position']) : '',
                'about'      => isset($row['about']) ? (string) $row['about'] : (isset($row['comment']) ? (string) $row['comment'] : ''),
                'image_id'   => 0,
                'image_url'  => '',
                'image_hash' => '',
                'raw_data'   => wp_json_encode($row),
                'is_active'  => isset($row['active']) ? (int) !!$row['active'] : 1,
                'sort_order' => $sort_order,
                'updated_at' => $now,
            );
            if (isset($image_map[$staff_id])) {
                $img = $image_map[$staff_id];
                $data['image_id']   = isset($img['id']) ? (int) $img['id'] : 0;
                $data['image_url']  = isset($img['url']) ? (string) $img['url'] : '';
                $data['image_hash'] = isset($img['hash']) ? (string) $img['hash'] : '';
            }
            $wpdb->replace($table, $data, array('%d','%d','%s','%s','%s','%d','%s','%s','%s','%d','%d','%s'));
        }
        if (empty($ids)) {
            $wpdb->delete($table, array('company_id' => $company_id), array('%d'));
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $params = array_merge(array($company_id), $ids);
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE company_id = %d AND staff_id NOT IN ($placeholders)", $params));
        }
        return array('total' => count($ids));
    }

    public static function get_staff_batch(int $company_id, int $offset, int $limit) : array {
        global $wpdb;
        $table = self::table_staff();
        $offset = max(0, (int) $offset);
        $limit  = max(1, (int) $limit);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE company_id = %d ORDER BY staff_id ASC LIMIT %d OFFSET %d",
                $company_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE company_id = %d",
                $company_id
            )
        );

        return array(
            'rows'  => is_array($rows) ? $rows : array(),
            'total' => $total,
        );
    }

    public static function update_staff_images(int $company_id, array $image_map) : void {
        if (empty($image_map)) {
            return;
        }

        global $wpdb;
        $table = self::table_staff();
        $now   = self::now();

        foreach ($image_map as $staff_id => $image) {
            $sid = (int) $staff_id;
            if ($sid <= 0 || !is_array($image)) {
                continue;
            }
            $data = array(
                'image_id'   => isset($image['id']) ? (int) $image['id'] : 0,
                'image_url'  => isset($image['url']) ? (string) $image['url'] : '',
                'image_hash' => isset($image['hash']) ? (string) $image['hash'] : '',
                'updated_at' => $now,
            );
            $where = array(
                'company_id' => $company_id,
                'staff_id'   => $sid,
            );
            $wpdb->update(
                $table,
                $data,
                $where,
                array('%d','%s','%s','%s'),
                array('%d','%d')
            );
        }
    }

    protected static function service_sync_option(int $company_id) : string {
        return self::SERVICE_SYNC_OPTION_PREFIX . $company_id;
    }

    public static function begin_service_sync(int $company_id) : void {
        $option = array(
            'ids'       => array(),
            'processed' => 0,
            'total'     => null,
        );
        update_option(self::service_sync_option($company_id), $option, false);
    }

    public static function append_service_sync_ids(int $company_id, array $service_ids) : array {
        $option = get_option(self::service_sync_option($company_id), array());
        if (!isset($option['ids']) || !is_array($option['ids'])) {
            $option['ids'] = array();
        }

        $before = count($option['ids']);

        foreach ($service_ids as $id) {
            $sid = (int) $id;
            if ($sid <= 0) {
                continue;
            }
            $option['ids'][$sid] = true;
        }

        $after = count($option['ids']);
        $delta = max(0, $after - $before);

        $option['processed'] = $after;
        update_option(self::service_sync_option($company_id), $option, false);

        return array(
            'processed'        => $option['processed'],
            'processed_delta'  => $delta,
            'total'            => isset($option['total']) ? $option['total'] : null,
        );
    }

    public static function set_service_sync_total(int $company_id, ?int $total) : void {
        $option = get_option(self::service_sync_option($company_id), array());
        if (!is_array($option)) {
            $option = array();
        }
        if (!isset($option['ids']) || !is_array($option['ids'])) {
            $option['ids'] = array();
        }
        $option['processed'] = isset($option['processed']) ? (int) $option['processed'] : count($option['ids']);
        $option['total'] = is_null($total) ? null : (int) $total;
        update_option(self::service_sync_option($company_id), $option, false);
    }

    public static function get_service_sync_state(int $company_id) : array {
        $option = get_option(self::service_sync_option($company_id), array());
        if (!is_array($option)) {
            $option = array();
        }
        if (!isset($option['ids']) || !is_array($option['ids'])) {
            $option['ids'] = array();
        }
        if (!isset($option['processed']) || !is_numeric($option['processed'])) {
            $option['processed'] = count($option['ids']);
        }
        if (!array_key_exists('total', $option)) {
            $option['total'] = null;
        }
        return $option;
    }

    public static function complete_service_sync(int $company_id) : void {
        $state = self::get_service_sync_state($company_id);
        $ids = array();
        foreach ($state['ids'] as $id => $flag) {
            $sid = (int) $id;
            if ($sid > 0) {
                $ids[] = $sid;
            }
        }
        self::cleanup_services($company_id, $ids);
        delete_option(self::service_sync_option($company_id));
    }

    public static function store_services_partial(int $company_id, array $services, bool $reset_relations = false) : array {
        global $wpdb;
        $table = self::table_services();
        $now   = self::now();
        $ids   = array();
        $relations = array();

        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }
            $service_id = isset($service['id']) ? (int) $service['id'] : 0;
            if ($service_id <= 0) {
                continue;
            }
            $ids[] = $service_id;

            $category_id = 0;
            $category_name = '';
            if (isset($service['category_id'])) {
                $category_id = (int) $service['category_id'];
            } elseif (isset($service['categoryId'])) {
                $category_id = (int) $service['categoryId'];
            } elseif (isset($service['category']['id'])) {
                $category_id = (int) $service['category']['id'];
            }
            if (isset($service['category']['title'])) {
                $category_name = (string) $service['category']['title'];
            } elseif (isset($service['category']['name'])) {
                $category_name = (string) $service['category']['name'];
            } elseif (isset($service['category_name'])) {
                $category_name = (string) $service['category_name'];
            }

            $price_min = isset($service['price_min']) ? (float) $service['price_min'] : (isset($service['cost_min']) ? (float) $service['cost_min'] : 0.0);
            $price_max = isset($service['price_max']) ? (float) $service['price_max'] : (isset($service['cost_max']) ? (float) $service['cost_max'] : $price_min);
            $duration  = isset($service['duration']) ? (int) $service['duration'] : (isset($service['length']) ? (int) $service['length'] : 0);
            $title     = isset($service['title']) ? (string) $service['title'] : (isset($service['name']) ? (string) $service['name'] : '');
            $description = isset($service['comment']) ? (string) $service['comment'] : (isset($service['description']) ? (string) $service['description'] : '');
            $is_active = isset($service['active']) ? (int) !!$service['active'] : 1;
            $sort      = isset($service['weight']) ? (int) $service['weight'] : (isset($service['sort_order']) ? (int) $service['sort_order'] : 0);

            $wpdb->replace(
                $table,
                array(
                    'company_id'    => $company_id,
                    'service_id'    => $service_id,
                    'category_id'   => $category_id,
                    'category_name' => $category_name,
                    'title'         => $title,
                    'description'   => $description,
                    'price_min'     => $price_min,
                    'price_max'     => $price_max,
                    'duration'      => $duration,
                    'raw_data'      => wp_json_encode($service),
                    'is_active'     => $is_active,
                    'sort_order'    => $sort,
                    'updated_at'    => $now,
                ),
                array('%d','%d','%d','%s','%s','%f','%f','%d','%s','%d','%d','%s')
            );

            if (!empty($service['staff']) && is_array($service['staff'])) {
                foreach ($service['staff'] as $staff) {
                    $sid = 0;
                    if (is_array($staff)) {
                        $sid = isset($staff['id']) ? (int) $staff['id'] : 0;
                    } elseif (is_numeric($staff)) {
                        $sid = (int) $staff;
                    }
                    if ($sid > 0) {
                        if (!isset($relations[$service_id])) {
                            $relations[$service_id] = array();
                        }
                        $relations[$service_id][$sid] = true;
                    }
                }
            }
        }

        self::store_service_relations_partial($company_id, $relations, $reset_relations);

        $state = self::append_service_sync_ids($company_id, $ids);
        $processed_delta = isset($state['processed_delta']) ? (int) $state['processed_delta'] : count($ids);

        $relation_count = 0;
        foreach ($relations as $staff_map) {
            if (is_array($staff_map)) {
                $relation_count += count($staff_map);
            }
        }

        return array(
            'processed' => $processed_delta,
            'relations' => $relation_count,
            'state'     => $state,
        );
    }

    protected static function store_service_relations_partial(int $company_id, array $map, bool $reset = false) : void {
        global $wpdb;
        $table = self::table_service_staff();
        if ($reset) {
            $wpdb->delete($table, array('company_id' => $company_id), array('%d'));
        }
        foreach ($map as $service_id => $staff_map) {
            if (!is_array($staff_map)) {
                continue;
            }
            foreach (array_keys($staff_map) as $staff_id) {
                $sid = (int) $staff_id;
                if ($sid <= 0) {
                    continue;
                }
                $wpdb->replace(
                    $table,
                    array(
                        'company_id' => $company_id,
                        'service_id' => (int) $service_id,
                        'staff_id'   => $sid,
                    ),
                    array('%d','%d','%d')
                );
            }
        }
    }

    public static function cleanup_services(int $company_id, array $keep_ids) : void {
        global $wpdb;
        $services = self::table_services();
        $pivot    = self::table_service_staff();

        if (empty($keep_ids)) {
            $wpdb->delete($services, array('company_id' => $company_id), array('%d'));
            $wpdb->delete($pivot, array('company_id' => $company_id), array('%d'));
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keep_ids), '%d'));
        $params = array_merge(array($company_id), $keep_ids);

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $services WHERE company_id = %d AND service_id NOT IN ($placeholders)",
                $params
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $pivot WHERE company_id = %d AND service_id NOT IN ($placeholders)",
                $params
            )
        );
    }

    public static function get_categories(int $company_id) : array {
        global $wpdb;
        $table = self::table_categories();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE company_id = %d ORDER BY sort_order ASC, title ASC", $company_id), ARRAY_A);
        $map = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $cid = isset($row['category_id']) ? (int) $row['category_id'] : 0;
                if ($cid <= 0) {
                    continue;
                }
                $title = isset($row['title']) ? (string) $row['title'] : '';
                if ($title === '' && !empty($row['raw_data'])) {
                    $raw = json_decode($row['raw_data'], true);
                    if (isset($raw['title'])) {
                        $title = (string) $raw['title'];
                    } elseif (isset($raw['name'])) {
                        $title = (string) $raw['name'];
                    }
                }
                $map[$cid] = $title;
            }
        }
        return $map;
    }

    public static function get_staff(int $company_id) : array {
        global $wpdb;
        $table = self::table_staff();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE company_id = %d ORDER BY sort_order ASC, name ASC", $company_id), ARRAY_A);
        $list = array();
        if (!is_array($rows)) {
            return $list;
        }
        foreach ($rows as $row) {
            $sid = isset($row['staff_id']) ? (int) $row['staff_id'] : 0;
            if ($sid <= 0) {
                continue;
            }
            $data = array(
                'id'        => $sid,
                'name'      => isset($row['name']) ? $row['name'] : '',
                'position'  => isset($row['position']) ? $row['position'] : '',
                'about'     => isset($row['about']) ? $row['about'] : '',
                'image_id'  => isset($row['image_id']) ? (int) $row['image_id'] : 0,
                'image_url' => isset($row['image_url']) ? $row['image_url'] : '',
                'sort_order'=> isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
                'company_id'=> $company_id,
            );
            if (!empty($row['raw_data'])) {
                $raw = json_decode($row['raw_data'], true);
                if (is_array($raw)) {
                    $data = array_merge($raw, $data);
                }
            }
            if (!empty($data['image_id'])) {
                $attachment_url = wp_get_attachment_url((int) $data['image_id']);
                if ($attachment_url) {
                    $data['image_url'] = $attachment_url;
                }
            }
            if (empty($data['image_url']) && !empty($row['image_url'])) {
                $data['image_url'] = $row['image_url'];
            }
            $list[$sid] = $data;
        }
        return $list;
    }

    public static function set_staff_sort_order(int $company_id, array $orders) : void {
        if ($company_id <= 0 || empty($orders)) {
            return;
        }

        global $wpdb;
        $table = self::table_staff();
        $now   = self::now();

        foreach ($orders as $staff_id => $weight) {
            $sid = (int) $staff_id;
            if ($sid <= 0) {
                continue;
            }
            $order = is_numeric($weight) ? (int) $weight : 0;
            $wpdb->update(
                $table,
                array(
                    'sort_order' => $order,
                    'updated_at' => $now,
                ),
                array(
                    'company_id' => $company_id,
                    'staff_id'   => $sid,
                ),
                array('%d', '%s'),
                array('%d', '%d')
            );
        }
    }

    public static function get_services(int $company_id, $category_id = null) : array {
        global $wpdb;
        $table = self::table_services();
        $sql = "SELECT * FROM $table WHERE company_id = %d";
        $args = array($company_id);
        if ($category_id !== null && $category_id !== '' && is_numeric($category_id)) {
            $sql .= ' AND category_id = %d';
            $args[] = (int) $category_id;
        }
        $sql .= ' ORDER BY sort_order ASC, title ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }
        $staff_map = self::get_staff($company_id);
        $rel_table = self::table_service_staff();
        $relations_raw = $wpdb->get_results($wpdb->prepare("SELECT service_id, staff_id FROM $rel_table WHERE company_id = %d", $company_id), ARRAY_A);
        $relations = array();
        if (is_array($relations_raw)) {
            foreach ($relations_raw as $rel) {
                $sid = isset($rel['service_id']) ? (int) $rel['service_id'] : 0;
                $staff_id = isset($rel['staff_id']) ? (int) $rel['staff_id'] : 0;
                if ($sid <= 0 || $staff_id <= 0) {
                    continue;
                }
                if (!isset($relations[$sid])) {
                    $relations[$sid] = array();
                }
                $relations[$sid][] = $staff_id;
            }
        }
        $services = array();
        foreach ($rows as $row) {
            $sid = isset($row['service_id']) ? (int) $row['service_id'] : 0;
            if ($sid <= 0) {
                continue;
            }
            $data = array(
                'id'          => $sid,
                'title'       => isset($row['title']) ? $row['title'] : '',
                'description' => isset($row['description']) ? $row['description'] : '',
                'price_min'   => isset($row['price_min']) ? (float) $row['price_min'] : 0,
                'price_max'   => isset($row['price_max']) ? (float) $row['price_max'] : 0,
                'duration'    => isset($row['duration']) ? (int) $row['duration'] : 0,
                'category_id' => isset($row['category_id']) ? (int) $row['category_id'] : 0,
                'category'    => array(
                    'id'    => isset($row['category_id']) ? (int) $row['category_id'] : 0,
                    'title' => isset($row['category_name']) ? $row['category_name'] : '',
                ),
                'company_id'  => $company_id,
            );
            if (!empty($row['raw_data'])) {
                $raw = json_decode($row['raw_data'], true);
                if (is_array($raw)) {
                    $data = array_merge($raw, $data);
                }
            }
            $staff_list = array();
            if (isset($relations[$sid])) {
                foreach ($relations[$sid] as $staff_id) {
                    if (isset($staff_map[$staff_id])) {
                        $staff_list[] = $staff_map[$staff_id];
                    }
                }
            }
            $data['staff'] = $staff_list;
            $services[] = $data;
        }
        return $services;
    }
}
