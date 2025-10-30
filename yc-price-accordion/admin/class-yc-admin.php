<?php
if (!defined('ABSPATH')) exit;

class YC_Admin {

    public static function boot(){
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'settings'));
    }

    public static function menu(){
        add_options_page(
            'YClients Прайс (Кеш)',
            'YClients Прайс (Кеш)',
            'manage_options',
            'yc-price-settings',
            array(__CLASS__, 'render_page')
        );
    }

    public static function settings(){
        // Приоритеты по ID
        register_setting('yc_price_group', 'yc_staff_priority_id_map', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_textarea'),
            'default' => ''
        ));
        // TTL кеша
        register_setting('yc_price_group', 'yc_cache_ttl', array(
            'type' => 'integer',
            'sanitize_callback' => array(__CLASS__, 'sanitize_ttl'),
            'default' => 30
        ));
        // Флаг: рендер только из кеша
        register_setting('yc_price_group', 'yc_render_from_cache_only', array(
            'type' => 'boolean',
            'sanitize_callback' => array(__CLASS__, 'sanitize_bool'),
            'default' => 1
        ));
        // Ручной JSON сотрудников
        register_setting('yc_price_group', 'yc_manual_staff_json', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_textarea'),
            'default' => ''
        ));
    }

    public static function sanitize_textarea($v){
        return is_string($v) ? $v : '';
    }
    public static function sanitize_ttl($v){
        $v = intval($v);
        if ($v < 1) $v = 1;
        if ($v > 1440) $v = 1440;
        return $v;
    }
    public static function sanitize_bool($v){
        return $v ? 1 : 0;
    }

    public static function handle_actions(){
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['yc_seed_cache'])){
            check_admin_referer('yc_price_group-options');
            $json = isset($_POST['yc_manual_staff_json']) ? (string)$_POST['yc_manual_staff_json'] : '';
            $ok = class_exists('YC_API') ? YC_API::seed_cache_from_json($json) : false;
            add_settings_error('yc-price-settings', 'yc_seed', $ok ? 'Кеш сохранён из JSON.' : 'Неверный JSON.', $ok ? 'updated' : 'error');
        }
        if (isset($_POST['yc_refresh_cache'])){
            check_admin_referer('yc_price_group-options');
            $ok = class_exists('YC_API') ? YC_API::refresh_cache(5) : false;
            add_settings_error('yc-price-settings', 'yc_refresh', $ok ? 'Кеш обновлён из источника.' : 'Не удалось обновить кеш из источника.', $ok ? 'updated' : 'error');
        }
    }

    public static function render_page(){
        if (!current_user_can('manage_options')) return;
        self::handle_actions();
        settings_errors('yc-price-settings');

        $priority_map = get_option('yc_staff_priority_id_map', '');
        $ttl = intval(get_option('yc_cache_ttl', 30));
        $cache_only = intval(get_option('yc_render_from_cache_only', 1));
        $manual_json = get_option('yc_manual_staff_json', '');
        $last = get_option('yc_cache_last_updated', '—');
        ?>
        <div class="wrap yc-admin-card">
            <h2>YClients Прайс — кеш и сортировка</h2>
            <form method="post" action="options.php">
                <?php settings_fields('yc_price_group'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">Приоритеты сотрудников по ID</th>
                            <td>
                                <textarea name="yc_staff_priority_id_map" class="large-text code" rows="6" placeholder="12345=1&#10;67890=2"><?php echo esc_textarea($priority_map); ?></textarea>
                                <p class="description">Формат: <code>staff_id=порядок</code> построчно или JSON-объект. Меньше — выше.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">TTL кеша (минуты)</th>
                            <td>
                                <input type="number" class="small-text" min="1" max="1440" name="yc_cache_ttl" value="<?php echo esc_attr($ttl); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Рендер только из кеша</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="yc_render_from_cache_only" value="1" <?php checked($cache_only, 1); ?> />
                                    Включить (страницы не ходят в API, вывод только из кеша)
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Ручной ввод сотрудников (JSON)</th>
                            <td>
                                <textarea name="yc_manual_staff_json" class="large-text code" rows="10" placeholder='[{"id":98771,"name":"ФИО","position":"Должность","photo":"https://...","order":0}]'><?php echo esc_textarea($manual_json); ?></textarea>
                                <p class="description">Можно вставить массив сотрудников или объект с ключом <code>staff</code>. Кнопкой ниже можно сохранить кеш напрямую из этого JSON.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
                <h3>Управление кешем</h3>
                <p>Последнее обновление кеша: <strong><?php echo esc_html($last); ?></strong></p>
                <p>
                    <?php wp_nonce_field('yc_price_group-options'); ?>
                    <button name="yc_refresh_cache" value="1" class="button button-primary">Обновить кеш из источника</button>
                    <button name="yc_seed_cache" value="1" class="button">Сохранить кеш из JSON</button>
                </p>
            </form>
        </div>
        <?php
    }
}
