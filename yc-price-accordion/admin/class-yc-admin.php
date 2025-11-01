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
                'syncing'          => __('Синхронизация…', 'yc-price-accordion'),
                'done'             => __('Синхронизация завершена', 'yc-price-accordion'),
                'error'            => __('Ошибка синхронизации', 'yc-price-accordion'),
                'buttonAll'        => __('Синхронизировать все', 'yc-price-accordion'),
                'buttonStaff'      => __('Синхронизировать специалистов', 'yc-price-accordion'),
                'buttonServices'   => __('Синхронизировать услуги', 'yc-price-accordion'),
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
                    <div class="yc-sync-actions">
                        <button type="button" class="button button-primary" data-sync-mode="all" data-label="<?php esc_attr_e('Синхронизировать все', 'yc-price-accordion'); ?>"><?php esc_html_e('Синхронизировать все', 'yc-price-accordion'); ?></button>
                        <button type="button" class="button button-secondary" data-sync-mode="staff" data-label="<?php esc_attr_e('Синхронизировать специалистов', 'yc-price-accordion'); ?>"><?php esc_html_e('Синхронизировать специалистов', 'yc-price-accordion'); ?></button>
                        <button type="button" class="button button-secondary" data-sync-mode="services" data-label="<?php esc_attr_e('Синхронизировать услуги', 'yc-price-accordion'); ?>"><?php esc_html_e('Синхронизировать услуги', 'yc-price-accordion'); ?></button>
                    </div>
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
                        <button type="button" class="yc-tab-button" data-tab="services"><?php esc_html_e('Услуги', 'yc-price-accordion'); ?></button>
                        <button type="button" class="yc-tab-button" data-tab="advanced"><?php esc_html_e('Дополнительно', 'yc-price-accordion'); ?></button>
                    </nav>
                    <section class="yc-tab-panel active" data-tab="general">
                        <?php self::render_general_tab($branches_option); ?>
                    </section>
                    <section class="yc-tab-panel" data-tab="display">
                        <?php self::render_display_tab($branches_option); ?>
                    </section>
                    <section class="yc-tab-panel" data-tab="services">
                        <?php self::render_services_tab($branches_option); ?>
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

    protected static function render_services_tab(array $branches_option) : void {
        if (!class_exists('YC_API')) {
            echo '<p>' . esc_html__('Модуль API не загружен. Проверьте установку плагина.', 'yc-price-accordion') . '</p>';
            return;
        }

        $branches = array();
        if (is_array($branches_option)) {
            foreach ($branches_option as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $cid = isset($branch['id']) ? (int) $branch['id'] : 0;
                if ($cid <= 0) {
                    continue;
                }
                $branches[] = array(
                    'id'    => $cid,
                    'title' => isset($branch['title']) && $branch['title'] !== '' ? $branch['title'] : sprintf(esc_html__('Филиал %d', 'yc-price-accordion'), $cid),
                );
            }
        }

        echo '<div class="yc-section">';
        echo '<h2>' . esc_html__('Список услуг', 'yc-price-accordion') . '</h2>';
        echo '<p class="description">' . esc_html__('Посмотрите выгруженные услуги по каждому филиалу. Обновите синхронизацию, если данные устарели.', 'yc-price-accordion') . '</p>';

        if (empty($branches)) {
            echo '<p>' . esc_html__('Добавьте хотя бы один филиал, чтобы увидеть услуги.', 'yc-price-accordion') . '</p>';
            echo '</div>';
            return;
        }

        echo '<div class="yc-services-list">';

        foreach ($branches as $branch) {
            $services = YC_API::get_services($branch['id']);
            $service_count = is_array($services) ? count($services) : 0;
            $active_count = 0;
            $grouped = array();
            $last_updated = self::get_services_last_updated(is_array($services) ? $services : array());
            $category_map = YC_API::get_categories($branch['id']);
            if (!is_array($category_map)) {
                $category_map = array();
            }

            if (!empty($services) && is_array($services)) {
                foreach ($services as $service) {
                    if (!is_array($service)) {
                        continue;
                    }
                    list($group_id, $group_name) = self::resolve_service_category_group($service, $category_map);
                    $normalized_title = $group_name;
                    if ($normalized_title !== '') {
                        if (function_exists('mb_strtolower')) {
                            $normalized_title = mb_strtolower($normalized_title, 'UTF-8');
                        } else {
                            $normalized_title = strtolower($normalized_title);
                        }
                    }
                    $group_key = $group_id . '|' . $normalized_title;
                    if (!isset($grouped[$group_key])) {
                        $grouped[$group_key] = array(
                            'id'       => $group_id,
                            'title'    => $group_name,
                            'services' => array(),
                        );
                    }
                    if (!empty($service['is_active'])) {
                        $active_count++;
                    }
                    $grouped[$group_key]['services'][] = $service;
                }
            }

            uasort($grouped, static function($a, $b) {
                $aTitle = isset($a['title']) ? (string) $a['title'] : '';
                $bTitle = isset($b['title']) ? (string) $b['title'] : '';
                return strcasecmp($aTitle, $bTitle);
            });

            echo '<div class="yc-service-branch">';
            echo '<div class="yc-service-branch-header">';
            echo '<h3>' . esc_html($branch['title']) . ' (ID ' . (int) $branch['id'] . ')</h3>';
            echo '<div class="yc-service-meta">';
            echo '<span>' . sprintf(esc_html__('Всего услуг: %d', 'yc-price-accordion'), (int) $service_count) . '</span>';
            echo '<span>' . sprintf(esc_html__('Активных: %d', 'yc-price-accordion'), (int) $active_count) . '</span>';
            if ($last_updated !== '') {
                echo '<span>' . sprintf(esc_html__('Обновлено: %s', 'yc-price-accordion'), esc_html($last_updated)) . '</span>';
            }
            echo '</div>';
            echo '</div>';

            if (empty($grouped)) {
                echo '<p class="description">' . esc_html__('Нет сохранённых услуг. Выполните синхронизацию.', 'yc-price-accordion') . '</p>';
                echo '</div>';
                continue;
            }

            foreach ($grouped as $category) {
                $category_id = isset($category['id']) ? (int) $category['id'] : 0;
                $category_title = isset($category['title']) ? $category['title'] : '';
                $items = isset($category['services']) && is_array($category['services']) ? $category['services'] : array();
                if (empty($items)) {
                    continue;
                }

                usort($items, static function($a, $b) {
                    $orderA = self::get_service_sort_order($a);
                    $orderB = self::get_service_sort_order($b);
                    if ($orderA !== $orderB) {
                        return $orderA <=> $orderB;
                    }
                    $titleA = isset($a['title']) ? (string) $a['title'] : '';
                    $titleB = isset($b['title']) ? (string) $b['title'] : '';
                    return strcasecmp($titleA, $titleB);
                });

                $category_name = $category_title !== '' ? $category_title : __('Без категории', 'yc-price-accordion');
                $category_label = esc_html($category_name);
                if ($category_id > 0) {
                    $category_label .= ' (ID ' . $category_id . ')';
                }
                $category_label .= ' (' . (int) count($items) . ')';

                echo '<div class="yc-service-category">';
                echo '<h4>' . $category_label . '</h4>';
                echo '<table class="widefat striped yc-services-table">';
                echo '<thead><tr>';
                echo '<th>' . esc_html__('Услуга', 'yc-price-accordion') . '</th>';
                echo '<th>' . esc_html__('Стоимость', 'yc-price-accordion') . '</th>';
                echo '<th>' . esc_html__('Длительность', 'yc-price-accordion') . '</th>';
                echo '<th>' . esc_html__('Статус', 'yc-price-accordion') . '</th>';
                echo '<th>' . esc_html__('Специалисты', 'yc-price-accordion') . '</th>';
                echo '</tr></thead><tbody>';

                foreach ($items as $service) {
                    $title = isset($service['title']) ? $service['title'] : '';
                    $description = isset($service['description']) ? $service['description'] : '';
                    if (function_exists('yc_pa_normalize_service_description')) {
                        $description = yc_pa_normalize_service_description($description);
                    }
                    $price = self::format_service_price($service);
                    $duration = self::format_service_duration($service);
                    $is_active = !empty($service['is_active']);
                    $status_class = $is_active ? 'is-active' : 'is-inactive';
                    $status_label = $is_active ? esc_html__('Активна', 'yc-price-accordion') : esc_html__('Выключена', 'yc-price-accordion');
                    $staff = self::format_service_staff_list($service);

                    echo '<tr>';
                    echo '<td>';
                    echo '<strong>' . ($title !== '' ? esc_html($title) : '&#8212;') . '</strong>';
                    if ($description !== '') {
                        echo '<div class="yc-service-description">' . wp_kses_post(wpautop($description)) . '</div>';
                    }
                    echo '</td>';
                    echo '<td>' . esc_html($price) . '</td>';
                    echo '<td>' . esc_html($duration) . '</td>';
                    echo '<td><span class="yc-service-status ' . esc_attr($status_class) . '">' . $status_label . '</span></td>';
                    echo '<td>' . $staff . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
                echo '</div>';
            }

            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
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

        $groups = array();
        $missing = array();
        foreach ($branches as $branch) {
            $cid = isset($branch['id']) ? (int) $branch['id'] : 0;
            if ($cid <= 0) {
                continue;
            }
            $title = isset($branch['title']) ? $branch['title'] : ('Company ' . $cid);
            $staffs = YC_API::get_staff($cid);
            if (empty($staffs)) {
                $missing[] = $title . ' (ID ' . $cid . ')';
                continue;
            }
            foreach ($staffs as $staff) {
                yc_pa_group_staff_member($groups, $staff, array('id' => $cid, 'title' => $title));
            }
        }

        if (empty($groups)) {
            echo '<p>' . esc_html__('Нет сохранённых специалистов. Выполните синхронизацию.', 'yc-price-accordion') . '</p>';
            if (!empty($missing)) {
                foreach ($missing as $label) {
                    echo '<p><strong>' . esc_html($label) . '</strong>: ' . esc_html__('Нет сохранённых специалистов. Выполните синхронизацию.', 'yc-price-accordion') . '</p>';
                }
            }
            return;
        }

        $groups = yc_pa_finalize_staff_groups($groups);
        $list = array_values($groups);
        usort($list, static function($a, $b) {
            $ma = isset($a['manual_order']) ? (int) $a['manual_order'] : PHP_INT_MAX;
            $mb = isset($b['manual_order']) ? (int) $b['manual_order'] : PHP_INT_MAX;
            if ($ma !== $mb) {
                return $ma <=> $mb;
            }
            $na = isset($a['name']) ? (string) $a['name'] : '';
            $nb = isset($b['name']) ? (string) $b['name'] : '';
            $cmp = strcasecmp($na, $nb);
            if ($cmp !== 0) {
                return $cmp;
            }
            $ba = isset($a['primary_branch_title']) ? (string) $a['primary_branch_title'] : '';
            $bb = isset($b['primary_branch_title']) ? (string) $b['primary_branch_title'] : '';
            return strcasecmp($ba, $bb);
        });

        echo '<div class="yc-staff-links-block">';
        echo '<h3>' . esc_html__('Специалисты', 'yc-price-accordion') . '</h3>';
        echo '<table class="widefat striped yc-admin-table yc-staff-table">';
        echo '<thead><tr><th>' . esc_html__('Имя', 'yc-price-accordion') . '</th><th>' . esc_html__('Должность', 'yc-price-accordion') . '</th><th>' . esc_html__('Филиалы', 'yc-price-accordion') . '</th></tr></thead><tbody>';
        foreach ($list as $entry) {
            $name = isset($entry['name']) ? $entry['name'] : '';
            $position = isset($entry['position']) ? $entry['position'] : '';
            $branch_entries = isset($entry['branch_entries']) && is_array($entry['branch_entries']) ? $entry['branch_entries'] : array();
            echo '<tr>';
            echo '<td>' . ($name !== '' ? esc_html($name) : '&#8212;') . '</td>';
            echo '<td>' . ($position !== '' ? esc_html($position) : '&#8212;') . '</td>';
            echo '<td>';
            echo '<ul class="yc-staff-branch-list">';
            foreach ($branch_entries as $branch_id => $staff_rows) {
                if (!is_array($staff_rows)) {
                    continue;
                }
                foreach ($staff_rows as $staff_id => $staff_row) {
                    $bid = (int) $branch_id;
                    $sid = (int) $staff_id;
                    if ($bid <= 0 || $sid <= 0) {
                        continue;
                    }
                    $branch_title = isset($staff_row['branch_title']) && $staff_row['branch_title'] !== '' ? $staff_row['branch_title'] : ('ID ' . $bid);
                    $order = isset($staff_row['order']) ? (int) $staff_row['order'] : 500;
                    $link_val = isset($map[$bid][$sid]) ? $map[$bid][$sid] : '';
                    echo '<li>';
                    echo '<span class="yc-staff-branch-label">' . esc_html($branch_title) . ' (ID ' . $bid . ', #' . $sid . ')</span>';
                    echo '<div class="yc-staff-branch-controls">';
                    echo '<label>' . esc_html__('Порядок', 'yc-price-accordion') . ' <input type="number" class="small-text" name="yc_staff_order[' . $bid . '][' . $sid . ']" value="' . esc_attr($order) . '" /></label>';
                    echo '<label>' . esc_html__('Ссылка', 'yc-price-accordion') . ' <input type="text" class="regular-text" name="' . esc_attr(self::OPTION_STAFF_LINKS) . '[' . $bid . '][' . $sid . ']" value="' . esc_attr($link_val) . '" placeholder="https://example.com/staff" /></label>';
                    echo '</div>';
                    echo '</li>';
                }
            }
            echo '</ul>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        if (!empty($missing)) {
            foreach ($missing as $label) {
                echo '<p><strong>' . esc_html($label) . '</strong>: ' . esc_html__('Нет сохранённых специалистов. Выполните синхронизацию.', 'yc-price-accordion') . '</p>';
            }
        }
        echo '</div>';
    }

    protected static function get_service_sort_order($service) : int {
        if (!is_array($service)) {
            return PHP_INT_MAX;
        }
        if (isset($service['sort_order']) && is_numeric($service['sort_order'])) {
            return (int) $service['sort_order'];
        }
        if (isset($service['weight']) && is_numeric($service['weight'])) {
            return (int) $service['weight'];
        }
        if (isset($service['order']) && is_numeric($service['order'])) {
            return (int) $service['order'];
        }
        return PHP_INT_MAX;
    }

    protected static function format_service_price(array $service) : string {
        $min = isset($service['price_min']) ? (float) $service['price_min'] : 0.0;
        $max = isset($service['price_max']) ? (float) $service['price_max'] : $min;

        if ($min <= 0 && $max <= 0) {
            return '—';
        }

        $format_number = static function($value) {
            $decimals = abs($value - round($value)) > 0.001 ? 2 : 0;
            return number_format_i18n($value, $decimals);
        };

        if ($min <= 0 && $max > 0) {
            return $format_number($max);
        }

        if ($min > 0 && $max <= 0) {
            $max = $min;
        }

        if (abs($min - $max) < 0.001) {
            return $format_number($min);
        }

        return $format_number($min) . ' – ' . $format_number($max);
    }

    protected static function format_service_duration(array $service) : string {
        $minutes = isset($service['duration']) ? (int) $service['duration'] : 0;
        if ($minutes <= 0) {
            return '—';
        }
        $hours = intdiv($minutes, 60);
        $remain = $minutes % 60;
        $parts = array();
        if ($hours > 0) {
            $parts[] = sprintf(_n('%d час', '%d часа', $hours, 'yc-price-accordion'), $hours);
        }
        if ($remain > 0) {
            $parts[] = sprintf(_n('%d минута', '%d минуты', $remain, 'yc-price-accordion'), $remain);
        }
        return implode(' ', $parts);
    }

    protected static function format_service_staff_list(array $service) : string {
        if (empty($service['staff']) || !is_array($service['staff'])) {
            return '&#8212;';
        }

        $names = array();
        foreach ($service['staff'] as $member) {
            if (!is_array($member)) {
                continue;
            }
            $name = isset($member['name']) ? trim((string) $member['name']) : '';
            if ($name === '') {
                continue;
            }
            $names[] = $name;
        }

        if (empty($names)) {
            return '&#8212;';
        }

        $names = array_values(array_unique($names));
        $display = array_slice($names, 0, 3);
        $more = count($names) - count($display);
        $html = esc_html(implode(', ', $display));
        if ($more > 0) {
            $html .= '<br /><span class="yc-service-staff-more">' . sprintf(esc_html__('и ещё %d', 'yc-price-accordion'), $more) . '</span>';
        }
        return $html;
    }

    protected static function resolve_service_category_group(array $service, array $category_map) : array {
        $category_id = 0;
        if (isset($service['category_id'])) {
            $category_id = (int) $service['category_id'];
        } elseif (isset($service['categoryId'])) {
            $category_id = (int) $service['categoryId'];
        } elseif (!empty($service['category']) && is_array($service['category']) && isset($service['category']['id'])) {
            $category_id = (int) $service['category']['id'];
        }

        $name = self::extract_service_category_label($service);
        if ($name === '' && $category_id > 0 && isset($category_map[$category_id]) && $category_map[$category_id] !== '') {
            $name = (string) $category_map[$category_id];
        }

        if ($name === '') {
            if ($category_id > 0) {
                $name = sprintf(esc_html__('Категория #%d', 'yc-price-accordion'), $category_id);
            } else {
                $name = esc_html__('Без категории', 'yc-price-accordion');
            }
        }

        return array($category_id, $name);
    }

    protected static function extract_service_category_label(array $service) : string {
        $candidates = array('category_label', 'category_title', 'category_name', 'categoryName', 'categoryTitle', 'group', 'group_name', 'group_title');
        foreach ($candidates as $candidate) {
            if (!empty($service[$candidate]) && is_string($service[$candidate])) {
                return trim(wp_strip_all_tags((string) $service[$candidate]));
            }
        }

        if (!empty($service['category']) && is_array($service['category'])) {
            foreach ($candidates as $candidate) {
                if (!empty($service['category'][$candidate]) && is_string($service['category'][$candidate])) {
                    return trim(wp_strip_all_tags((string) $service['category'][$candidate]));
                }
            }
            if (!empty($service['category']['title'])) {
                return trim(wp_strip_all_tags((string) $service['category']['title']));
            }
            if (!empty($service['category']['name'])) {
                return trim(wp_strip_all_tags((string) $service['category']['name']));
            }
        }

        if (!empty($service['category_name']) && is_string($service['category_name'])) {
            return trim(wp_strip_all_tags((string) $service['category_name']));
        }

        return '';
    }

    protected static function get_services_last_updated(array $services) : string {
        $latest = '';
        foreach ($services as $service) {
            if (!is_array($service) || empty($service['updated_at'])) {
                continue;
            }
            $value = (string) $service['updated_at'];
            if ($latest === '' || strcmp($value, $latest) > 0) {
                $latest = $value;
            }
        }
        if ($latest === '') {
            return '';
        }
        $format = get_option('date_format') . ' ' . get_option('time_format');
        return get_date_from_gmt($latest, $format);
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
        if (!is_array($input)) {
            return array();
        }
        foreach ($input as $company_id => $staff_map) {
            $company_id = (int) $company_id;
            if ($company_id <= 0 || !is_array($staff_map)) {
                continue;
            }
            $orders = array();
            foreach ($staff_map as $staff_id => $weight) {
                $sid = (int) $staff_id;
                if ($sid <= 0) {
                    continue;
                }
                if ($weight === '' || $weight === null) {
                    continue;
                }
                $orders[$sid] = (int) $weight;
            }
            if (!empty($orders)) {
                YC_Repository::set_staff_sort_order($company_id, $orders);
            }
        }
        return yc_pa_get_manual_staff_order();
    }
}
