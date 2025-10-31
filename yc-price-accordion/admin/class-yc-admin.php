<?php
if (!defined('ABSPATH')) {
    exit;
}

class YC_Admin {
    const OPTION_BRANCHES        = 'yc_branches';
    const OPTION_CACHE_TTL       = 'yc_cache_ttl';
    const OPTION_DEBUG           = 'yc_debug';
    const OPTION_PARTNER         = 'yc_partner_token';
    const OPTION_USER            = 'yc_user_token';
    const OPTION_MULTI_CATEGORIES= 'yc_multi_categories';
    const OPTION_BOOK_URL_TPL    = 'yc_book_url_tpl';
    const OPTION_BOOK_STEP       = 'yc_book_step';
    const OPTION_UTM_SOURCE      = 'yc_utm_source';
    const OPTION_UTM_MEDIUM      = 'yc_utm_medium';
    const OPTION_UTM_CAMPAIGN    = 'yc_utm_campaign';
    const OPTION_VLIST_PAGE      = 'yc_vlist_page';
    const OPTION_STAFF_LINKS     = 'yc_staff_links';
    const OPTION_SHOW_STAFF      = 'yc_show_staff';
    const OPTION_TITLE_STAFF     = 'yc_title_staff';
    const OPTION_TITLE_PRICE     = 'yc_title_price';
    const OPTION_LAST_SYNC       = 'yc_pa_last_sync';

    public static function init() : void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function menu() : void {
        add_options_page('YClients Прайс', 'YClients Прайс', 'manage_options', 'yc-price-settings', [__CLASS__, 'render_page']);
    }

    public static function settings() : void {
        register_setting(
            'yc_price_group',
            'yc_staff_order',
            array(
                'type'              => 'array',
                'sanitize_callback' => array(__CLASS__, 'sanitize_staff_order'),
                'default'           => array(),
            )
        );

        register_setting('yc_price_group', self::OPTION_BRANCHES, array('type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize_branches'], 'default' => array()));
        register_setting('yc_price_group', self::OPTION_CACHE_TTL, array('type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_int_nonneg'], 'default' => 15));
        register_setting('yc_price_group', self::OPTION_DEBUG, array('type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_bool'], 'default' => 0));
        register_setting('yc_price_group', self::OPTION_PARTNER, array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        register_setting('yc_price_group', self::OPTION_USER, array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        register_setting('yc_price_group', self::OPTION_MULTI_CATEGORIES, array('type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_bool'], 'default' => 0));
        register_setting('yc_price_group', self::OPTION_SHOW_STAFF, array('type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_bool'], 'default' => 1));
        register_setting('yc_price_group', self::OPTION_BOOK_URL_TPL, array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''));
        register_setting('yc_price_group', self::OPTION_BOOK_STEP, array('type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_book_step'], 'default' => 'select-master'));
        register_setting('yc_price_group', self::OPTION_UTM_SOURCE, array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'site'));
        register_setting('yc_price_group', self::OPTION_UTM_MEDIUM, array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'price'));
        register_setting('yc_price_group', self::OPTION_UTM_CAMPAIGN, array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'booking'));
        register_setting('yc_price_group', self::OPTION_VLIST_PAGE, array('type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_int_nonneg'], 'default' => 15));
        register_setting('yc_price_group', self::OPTION_STAFF_LINKS, array('type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize_staff_links'], 'default' => array()));
        register_setting('yc_price_group', self::OPTION_TITLE_STAFF, array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Специалисты'));
        register_setting('yc_price_group', self::OPTION_TITLE_PRICE, array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Прайс - лист'));
    }

    public static function assets(string $hook) : void {
        if ($hook !== 'settings_page_yc-price-settings') {
            return;
        }
        wp_enqueue_style('yc-admin', YC_PA_URL . 'admin/yc-admin.css', array(), YC_PA_VER);
        wp_enqueue_script('yc-admin', YC_PA_URL . 'admin/yc-admin.js', array('jquery'), YC_PA_VER, true);

        $status = array(
            'restUrl'   => esc_url_raw(rest_url(YC_Sync_Controller::ROUTE_NAMESPACE . '/sync')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'lastSync'  => (int) get_option(self::OPTION_LAST_SYNC, 0),
            'i18n'      => array(
                'syncing'    => __('Синхронизация…', 'yc-price-accordion'),
                'done'       => __('Синхронизация завершена', 'yc-price-accordion'),
                'error'      => __('Ошибка синхронизации', 'yc-price-accordion'),
                'buttonStart'=> __('Синхронизировать', 'yc-price-accordion'),
            ),
        );
        wp_localize_script('yc-admin', 'ycPaAdmin', $status);
    }

    public static function render_page() : void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $last_sync = (int) get_option(self::OPTION_LAST_SYNC, 0);
        $last_sync_text = $last_sync > 0 ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_sync) : __('ещё не выполнялась', 'yc-price-accordion');
        $branches_option = get_option(self::OPTION_BRANCHES, array());
        if (!is_array($branches_option)) {
            $branches_option = array();
        }
        ?>
        <div class="wrap yc-admin-page">
            <h1><?php esc_html_e('Настройки YClients Price Accordion', 'yc-price-accordion'); ?></h1>

            <div class="yc-admin-card yc-sync-card">
                <div class="yc-sync-header">
                    <div>
                        <h2><?php esc_html_e('Синхронизация данных', 'yc-price-accordion'); ?></h2>
                        <p class="description"><?php esc_html_e('Выгрузите услуги и специалистов из YClients в локальную базу одним кликом. Во время синхронизации прогресс отображается ниже.', 'yc-price-accordion'); ?></p>
                        <p class="yc-sync-status" data-label="<?php esc_attr_e('Последняя синхронизация', 'yc-price-accordion'); ?>"><strong><?php esc_html_e('Последняя синхронизация:', 'yc-price-accordion'); ?></strong> <?php echo esc_html($last_sync_text); ?></p>
                    </div>
                    <button type="button" class="button button-primary" id="yc-sync-start"><?php esc_html_e('Синхронизировать', 'yc-price-accordion'); ?></button>
                </div>
                <div class="yc-sync-progress" id="yc-sync-progress" hidden>
                    <div class="yc-progress-bar"><span style="width:0%;"></span></div>
                    <p class="yc-sync-message"></p>
                </div>
                <div class="yc-sync-log" id="yc-sync-log"></div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>" id="yc-settings-form">
                <?php settings_fields('yc_price_group'); ?>
                <div class="yc-tabs" id="yc-settings-tabs">
                    <nav class="yc-tabs-nav">
                        <button type="button" class="yc-tab-button active" data-tab="general"><?php esc_html_e('Филиалы и доступ', 'yc-price-accordion'); ?></button>
                        <button type="button" class="yc-tab-button" data-tab="display"><?php esc_html_e('Отображение', 'yc-price-accordion'); ?></button>
                        <button type="button" class="yc-tab-button" data-tab="advanced"><?php esc_html_e('Дополнительно', 'yc-price-accordion'); ?></button>
                    </nav>
                    <section class="yc-tab-panel active" data-tab="general">
                        <?php self::render_general_tab($branches_option); ?>
                    </section>
                    <section class="yc-tab-panel" data-tab="display">
                        <?php self::render_display_tab($branches_option); ?>
                    </section>
                    <section class="yc-tab-panel" data-tab="advanced">
                        <?php self::render_advanced_tab(); ?>
                    </section>
                </div>
                <?php submit_button(__('Сохранить настройки', 'yc-price-accordion')); ?>
            </form>
        </div>
        <?php
    }

    protected static function render_general_tab(array $branches_option) : void {
        $partner = esc_attr(get_option(self::OPTION_PARTNER, ''));
        $user    = esc_attr(get_option(self::OPTION_USER, ''));
        ?>
        <div class="yc-section">
            <h2><?php esc_html_e('Доступ к API', 'yc-price-accordion'); ?></h2>
            <p class="description"><?php esc_html_e('Укажите партнёрский и пользовательский токены из кабинета YClients.', 'yc-price-accordion'); ?></p>
            <div class="yc-field-grid">
                <label>
                    <span><?php esc_html_e('Partner token', 'yc-price-accordion'); ?></span>
                    <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_PARTNER); ?>" value="<?php echo $partner; ?>" autocomplete="off" />
                </label>
                <label>
                    <span><?php esc_html_e('User token', 'yc-price-accordion'); ?></span>
                    <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_USER); ?>" value="<?php echo $user; ?>" autocomplete="off" />
                </label>
            </div>
        </div>
        <div class="yc-section">
            <h2><?php esc_html_e('Филиалы', 'yc-price-accordion'); ?></h2>
            <p class="description"><?php esc_html_e('Добавьте филиалы, которые нужно синхронизировать. URL может быть как домен, так и готовая ссылка на запись.', 'yc-price-accordion'); ?></p>
            <?php self::render_branch_table($branches_option); ?>
        </div>
        <?php
    }

    protected static function render_display_tab(array $branches_option) : void {
        $show_staff = (int) get_option(self::OPTION_SHOW_STAFF, 1);
        $multi = (int) get_option(self::OPTION_MULTI_CATEGORIES, 0);
        $title_staff = esc_attr(get_option(self::OPTION_TITLE_STAFF, 'Специалисты'));
        $title_price = esc_attr(get_option(self::OPTION_TITLE_PRICE, 'Прайс-лист'));
        $tpl = esc_attr(get_option(self::OPTION_BOOK_URL_TPL, ''));
        $step = get_option(self::OPTION_BOOK_STEP, 'select-master');
        $utm_source = esc_attr(get_option(self::OPTION_UTM_SOURCE, 'site'));
        $utm_medium = esc_attr(get_option(self::OPTION_UTM_MEDIUM, 'price'));
        $utm_campaign = esc_attr(get_option(self::OPTION_UTM_CAMPAIGN, 'booking'));
        $lazy_page = (int) get_option(self::OPTION_VLIST_PAGE, 15);
        ?>
        <div class="yc-section">
            <h2><?php esc_html_e('Отображение шорткода', 'yc-price-accordion'); ?></h2>
            <label class="yc-toggle">
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_SHOW_STAFF); ?>" value="1" <?php checked(1, $show_staff); ?> />
                <span><?php esc_html_e('Показывать блок «Специалисты»', 'yc-price-accordion'); ?></span>
            </label>
            <label class="yc-toggle">
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_MULTI_CATEGORIES); ?>" value="1" <?php checked(1, $multi); ?> />
                <span><?php esc_html_e('Разрешить фильтр по нескольким категориям в шорткоде', 'yc-price-accordion'); ?></span>
            </label>
            <div class="yc-field-grid">
                <label>
                    <span><?php esc_html_e('Заголовок блока специалистов', 'yc-price-accordion'); ?></span>
                    <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_TITLE_STAFF); ?>" value="<?php echo $title_staff; ?>" />
                </label>
                <label>
                    <span><?php esc_html_e('Заголовок прайс-листа', 'yc-price-accordion'); ?></span>
                    <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_TITLE_PRICE); ?>" value="<?php echo $title_price; ?>" />
                </label>
            </div>
            <div class="yc-field-grid">
                <label>
                    <span><?php esc_html_e('Шаблон URL онлайн-записи', 'yc-price-accordion'); ?></span>
                    <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_BOOK_URL_TPL); ?>" value="<?php echo $tpl; ?>" placeholder="https://example.yclients.com/" />
                </label>
                <label>
                    <span><?php esc_html_e('Шаг в YClients', 'yc-price-accordion'); ?></span>
                    <select name="<?php echo esc_attr(self::OPTION_BOOK_STEP); ?>">
                        <option value="select-master" <?php selected('select-master', $step); ?>><?php esc_html_e('Сразу выбор мастера', 'yc-price-accordion'); ?></option>
                        <option value="select-services" <?php selected('select-services', $step); ?>><?php esc_html_e('Сначала выбор услуги', 'yc-price-accordion'); ?></option>
                    </select>
                </label>
            </div>
            <div class="yc-field-grid">
                <label>
                    <span><?php esc_html_e('UTM Source', 'yc-price-accordion'); ?></span>
                    <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_UTM_SOURCE); ?>" value="<?php echo $utm_source; ?>" />
                </label>
                <label>
                    <span><?php esc_html_e('UTM Medium', 'yc-price-accordion'); ?></span>
                    <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_UTM_MEDIUM); ?>" value="<?php echo $utm_medium; ?>" />
                </label>
                <label>
                    <span><?php esc_html_e('UTM Campaign', 'yc-price-accordion'); ?></span>
                    <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_UTM_CAMPAIGN); ?>" value="<?php echo $utm_campaign; ?>" />
                </label>
                <label>
                    <span><?php esc_html_e('Ленивая подгрузка — порция, шт.', 'yc-price-accordion'); ?></span>
                    <input type="number" min="5" max="100" name="<?php echo esc_attr(self::OPTION_VLIST_PAGE); ?>" value="<?php echo esc_attr($lazy_page); ?>" />
                </label>
            </div>
        </div>
        <div class="yc-section">
            <h2><?php esc_html_e('Ссылки на страницы специалистов', 'yc-price-accordion'); ?></h2>
            <p class="description"><?php esc_html_e('После синхронизации выберите для каждого специалиста ссылку на его страницу на сайте.', 'yc-price-accordion'); ?></p>
            <?php self::render_staff_links_section($branches_option); ?>
        </div>
        <?php
    }

    protected static function render_advanced_tab() : void {
        $cache = (int) get_option(self::OPTION_CACHE_TTL, 15);
        $debug = (int) get_option(self::OPTION_DEBUG, 0);
        ?>
        <div class="yc-section">
            <h2><?php esc_html_e('Дополнительные параметры', 'yc-price-accordion'); ?></h2>
            <label>
                <span><?php esc_html_e('Время хранения старого кэша (минут)', 'yc-price-accordion'); ?></span>
                <input type="number" min="0" name="<?php echo esc_attr(self::OPTION_CACHE_TTL); ?>" value="<?php echo esc_attr($cache); ?>" />
            </label>
            <label class="yc-toggle">
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_DEBUG); ?>" value="1" <?php checked(1, $debug); ?> />
                <span><?php esc_html_e('Включить отладочную информацию на витрине (видно только администраторам)', 'yc-price-accordion'); ?></span>
            </label>
        </div>
        <div class="yc-section">
            <h2><?php esc_html_e('Ручной порядок специалистов', 'yc-price-accordion'); ?></h2>
            <p class="description"><?php esc_html_e('Используйте веса вида id=порядок, через запятую. Меньшее значение — выше в списке.', 'yc-price-accordion'); ?></p>
            <?php self::render_manual_order_section(); ?>
        </div>
        <?php
    }

    protected static function render_branch_table(array $branches) : void {
        ?>
        <table class="widefat striped yc-admin-table" id="yc-branches-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Company ID', 'yc-price-accordion'); ?></th>
                    <th><?php esc_html_e('Название', 'yc-price-accordion'); ?></th>
                    <th><?php esc_html_e('URL онлайн-записи', 'yc-price-accordion'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="yc-branches-body">
                <?php
                if (empty($branches)) {
                    $branches = array(array('id' => '', 'title' => '', 'url' => ''));
                }
                foreach ($branches as $index => $branch) {
                    $id    = isset($branch['id']) ? (int) $branch['id'] : '';
                    $title = isset($branch['title']) ? $branch['title'] : '';
                    $url   = isset($branch['url']) ? $branch['url'] : '';
                    ?>
                    <tr>
                        <td><input type="number" min="1" name="<?php echo esc_attr(self::OPTION_BRANCHES); ?>[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($id); ?>" required /></td>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_BRANCHES); ?>[<?php echo esc_attr($index); ?>][title]" value="<?php echo esc_attr($title); ?>" required /></td>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_BRANCHES); ?>[<?php echo esc_attr($index); ?>][url]" value="<?php echo esc_attr($url); ?>" placeholder="https://example.yclients.com/" /></td>
                        <td><button type="button" class="button button-secondary yc-remove-row">&times;</button></td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
        <p><button type="button" class="button" id="yc-add-branch"><?php esc_html_e('Добавить филиал', 'yc-price-accordion'); ?></button></p>
        <?php
    }

    protected static function render_staff_links_section(array $branches) : void {
        $map = get_option(self::OPTION_STAFF_LINKS, array());
        if (!is_array($branches) || empty($branches)) {
            echo '<p class="description">' . esc_html__('Добавьте филиалы и выполните синхронизацию, чтобы настроить ссылки.', 'yc-price-accordion') . '</p>';
            return;
        }
        if (!is_array($map)) {
            $map = array();
        }
        foreach ($branches as $branch) {
            $cid = isset($branch['id']) ? (int) $branch['id'] : 0;
            if ($cid <= 0) {
                continue;
            }
            $title = isset($branch['title']) ? $branch['title'] : ('Company ' . $cid);
            echo '<div class="yc-staff-links-block">';
            echo '<h3>' . esc_html($title) . ' (ID ' . $cid . ')</h3>';
            $staffs = YC_API::get_staff($cid);
            if (empty($staffs)) {
                echo '<p>' . esc_html__('Нет сохранённых специалистов. Выполните синхронизацию.', 'yc-price-accordion') . '</p>';
                echo '</div>';
                continue;
            }
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('Имя', 'yc-price-accordion') . '</th><th>' . esc_html__('Должность', 'yc-price-accordion') . '</th><th>' . esc_html__('Ссылка', 'yc-price-accordion') . '</th></tr></thead><tbody>';
            foreach ($staffs as $staff) {
                $sid = isset($staff['id']) ? (int) $staff['id'] : 0;
                if ($sid <= 0) {
                    continue;
                }
                $name = isset($staff['name']) ? $staff['name'] : '';
                $position = '';
                if (isset($staff['position'])) {
                    $position = is_array($staff['position']) ? implode(', ', array_filter($staff['position'])) : $staff['position'];
                }
                $val = isset($map[$cid][$sid]) ? $map[$cid][$sid] : '';
                echo '<tr>';
                echo '<td>' . $sid . '</td>';
                echo '<td>' . esc_html($name) . '</td>';
                echo '<td>' . esc_html($position) . '</td>';
                echo '<td><input type="text" class="regular-text" name="' . esc_attr(self::OPTION_STAFF_LINKS) . '[' . $cid . '][' . $sid . ']" value="' . esc_attr($val) . '" placeholder="https://example.com/staff" /></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }
    }

    protected static function render_manual_order_section() : void {
        $branches = get_option(self::OPTION_BRANCHES, array());
        if (!is_array($branches)) {
            $branches = array();
        }
        $order_map = yc_pa_get_manual_staff_order();
        foreach ($branches as $branch) {
            $cid = isset($branch['id']) ? (int) $branch['id'] : 0;
            if ($cid <= 0) {
                continue;
            }
            $label = isset($branch['title']) ? $branch['title'] : ('Company ' . $cid);
            $key = (string) $cid;
            $preset = '';
            if (isset($order_map[$key]) && is_array($order_map[$key])) {
                $pairs = $order_map[$key];
                asort($pairs, SORT_NUMERIC);
                $buf = array();
                foreach ($pairs as $sid => $weight) {
                    $buf[] = (int) $sid . '=' . (int) $weight;
                }
                $preset = implode(', ', $buf);
            }
            echo '<label class="yc-manual-order">';
            echo '<span>' . esc_html($label) . ' (ID ' . $cid . ')</span>';
            echo '<textarea name="yc_staff_order[' . $cid . ']" rows="3" placeholder="123=1, 456=5">' . esc_textarea($preset) . '</textarea>';
            echo '</label>';
        }
    }

    public static function sanitize_book_step($value) {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('select-services', 'select-master'), true) ? $value : 'select-master';
    }

    public static function sanitize_branches($val) {
        $out = array();
        if (is_array($val)) {
            foreach ($val as $row) {
                $id    = isset($row['id']) ? intval($row['id']) : 0;
                $title = isset($row['title']) ? trim(wp_strip_all_tags($row['title'])) : '';
                $url   = isset($row['url']) ? esc_url_raw($row['url']) : '';
                if ($id > 0 && $title !== '') {
                    $out[] = array('id' => $id, 'title' => $title, 'url' => $url);
                }
            }
        }
        return $out;
    }

    public static function sanitize_int_nonneg($v) {
        $v = intval($v);
        return $v < 0 ? 0 : $v;
    }

    public static function sanitize_bool($v) {
        return $v ? 1 : 0;
    }

    public static function sanitize_staff_links($val) {
        $out = array();
        if (is_array($val)) {
            foreach ($val as $branch_id => $staffs) {
                $bid = intval($branch_id);
                if ($bid <= 0) {
                    continue;
                }
                if (!is_array($staffs)) {
                    continue;
                }
                foreach ($staffs as $sid => $url) {
                    $sid_i = intval($sid);
                    $u = esc_url_raw((string) $url);
                    if ($sid_i > 0 && $u !== '') {
                        if (!isset($out[$bid])) {
                            $out[$bid] = array();
                        }
                        $out[$bid][$sid_i] = $u;
                    }
                }
            }
        }
        return $out;
    }

    public static function sanitize_staff_order($input) {
        $out = array();
        if (is_string($input)) {
            $input = array('all' => $input);
        }
        if (!is_array($input)) {
            return array();
        }
        foreach ($input as $company_id => $line) {
            $company_id = (int) $company_id;
            if ($company_id <= 0) {
                continue;
            }
            if (!is_string($line)) {
                continue;
            }
            $pairs = array();
            $items = preg_split('/[,\n]+/', $line);
            foreach ($items as $item) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }
                if (strpos($item, '=') !== false) {
                    list($sid, $weight) = array_map('trim', explode('=', $item, 2));
                    $sid = (int) $sid;
                    $weight = (int) $weight;
                    if ($sid > 0) {
                        $pairs[$sid] = $weight > 0 ? $weight : 9999;
                    }
                } else {
                    $sid = (int) $item;
                    if ($sid > 0) {
                        $pairs[$sid] = count($pairs) + 1;
                    }
                }
            }
            if (!empty($pairs)) {
                $out[$company_id] = $pairs;
            }
        }
        return $out;
    }
}
