<?php
if (!defined('ABSPATH')) {
    exit;
}

class YC_Media {
    protected static function ensure_attachment_metadata(int $attachment_id, string $hash, int $company_id, int $staff_id, string $source_url) : void {
        if ($attachment_id <= 0) {
            return;
        }

        update_post_meta($attachment_id, '_yc_pa_staff_hash', $hash);
        update_post_meta($attachment_id, '_yc_pa_staff_source', esc_url_raw($source_url));
        update_post_meta($attachment_id, '_yc_pa_staff_company', (int) $company_id);
        update_post_meta($attachment_id, '_yc_pa_staff_id', (int) $staff_id);
    }

    protected static function prepare_attachment_response(int $attachment_id, string $hash, int $company_id, int $staff_id, string $source_url) : ?array {
        if ($attachment_id <= 0) {
            return null;
        }

        $status = get_post_status($attachment_id);
        if (!$status || $status === 'trash') {
            return null;
        }

        $path = get_attached_file($attachment_id);
        if ($path && !file_exists($path)) {
            return null;
        }

        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return null;
        }

        self::ensure_attachment_metadata($attachment_id, $hash, $company_id, $staff_id, $source_url);

        return array('id' => (int) $attachment_id, 'url' => $url, 'hash' => $hash);
    }

    protected static function find_existing_staff_attachment(string $hash) : int {
        if ($hash === '') {
            return 0;
        }

        $posts = get_posts(array(
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'posts_per_page'   => 1,
            'orderby'          => 'ID',
            'order'            => 'DESC',
            'fields'           => 'ids',
            'suppress_filters' => true,
            'meta_query'       => array(
                array(
                    'key'   => '_yc_pa_staff_hash',
                    'value' => $hash,
                ),
            ),
        ));

        if (!empty($posts)) {
            return (int) $posts[0];
        }

        return 0;
    }

    public static function remember_staff_image(int $attachment_id, string $hash, int $company_id, int $staff_id, string $source_url) : void {
        if ($attachment_id <= 0) {
            return;
        }

        if ($hash === '' && $source_url !== '') {
            $hash = md5($source_url);
        }

        if ($hash === '') {
            return;
        }

        self::ensure_attachment_metadata($attachment_id, $hash, $company_id, $staff_id, $source_url);
    }

    public static function ensure_staff_image(int $company_id, int $staff_id, string $image_url, array $existing = array()) : array {
        $image_url = trim($image_url);
        if ($image_url === '') {
            return array('id' => 0, 'url' => '', 'hash' => '');
        }

        $hash = md5($image_url);

        if (!empty($existing) && isset($existing['image_hash']) && $existing['image_hash'] === $hash && !empty($existing['image_id'])) {
            $reuse = self::prepare_attachment_response((int) $existing['image_id'], $hash, $company_id, $staff_id, $image_url);
            if ($reuse !== null) {
                return $reuse;
            }
        }

        $stored_id = self::find_existing_staff_attachment($hash);
        if ($stored_id > 0) {
            $reuse = self::prepare_attachment_response($stored_id, $hash, $company_id, $staff_id, $image_url);
            if ($reuse !== null) {
                return $reuse;
            }
        }

        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $temp = download_url($image_url);
        if (is_wp_error($temp)) {
            return array('id' => 0, 'url' => $image_url, 'hash' => $hash);
        }

        $file_array = array(
            'name'     => basename(parse_url($image_url, PHP_URL_PATH)),
            'tmp_name' => $temp,
        );

        if (empty($file_array['name'])) {
            $file_array['name'] = 'staff-' . $company_id . '-' . $staff_id . '.jpg';
        }

        if (!function_exists('sanitize_file_name')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $file_array['name'] = sanitize_file_name($file_array['name']);

        $attachment_id = media_handle_sideload($file_array, 0);
        if (is_wp_error($attachment_id)) {
            @unlink($temp);
            return array('id' => 0, 'url' => $image_url, 'hash' => $hash);
        }

        $response = self::prepare_attachment_response((int) $attachment_id, $hash, $company_id, $staff_id, $image_url);
        if ($response !== null) {
            return $response;
        }

        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            $url = $image_url;
        }

        return array('id' => (int) $attachment_id, 'url' => $url, 'hash' => $hash);
    }
}
