<?php
if (!defined('ABSPATH')) {
    exit;
}

class YC_Media {
    public static function ensure_staff_image(int $company_id, int $staff_id, string $image_url, array $existing = array()) : array {
        $image_url = trim($image_url);
        if ($image_url === '') {
            return array('id' => 0, 'url' => '', 'hash' => '');
        }
        $hash = md5($image_url);
        if (!empty($existing) && isset($existing['image_hash']) && $existing['image_hash'] === $hash && !empty($existing['image_id'])) {
            $attachment_id = (int) $existing['image_id'];
            $url = wp_get_attachment_url($attachment_id);
            if ($url) {
                return array('id' => $attachment_id, 'url' => $url, 'hash' => $hash);
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
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            $url = $image_url;
        }
        return array('id' => (int) $attachment_id, 'url' => $url, 'hash' => $hash);
    }
}
