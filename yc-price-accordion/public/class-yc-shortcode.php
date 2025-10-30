<?php
if (! defined('ABSPATH')) {
    exit;
}

class YC_Shortcode
{
    public static function boot(): void
    {
        add_shortcode('yclients_price', [__CLASS__, 'render']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
    }

    public static function init(): void
    {
        self::boot();
    }

    public static function register_assets(): void
    {
        wp_register_style('yc-accordion', YC_PA_URL . 'public/css/yc-accordion.css', [], YC_PA_VERSION);
        wp_register_style('yc-price-public', YC_PA_URL . 'public/yc-price-public.css', [], YC_PA_VERSION);
        wp_register_style('yc-staff-grid', YC_PA_URL . 'public/yc-staff-grid.css', [], YC_PA_VERSION);

        wp_register_script('yc-accordion', YC_PA_URL . 'public/js/yc-accordion.js', [], YC_PA_VERSION, true);
        wp_register_script('yc-staff-sort', YC_PA_URL . 'public/yc-staff-sort.js', ['yc-accordion'], YC_PA_VERSION, true);

        if (! is_admin()) {
            wp_enqueue_style('yc-accordion');
            wp_enqueue_style('yc-price-public');
            wp_enqueue_style('yc-staff-grid');
            wp_enqueue_script('yc-accordion');
            wp_enqueue_script('yc-staff-sort');

            $order_map = get_option('yc_staff_order_map', '');
            $localize  = [
                'ajaxUrl' => admin_url('admin-ajax.php'),
            ];
            if (! empty($order_map)) {
                $localize['adminOrder'] = $order_map;
            }

            wp_localize_script('yc-staff-sort', 'YCStaffOrder', $localize);
        }
    }

    public static function render(array $atts = []): string
    {
        $atts = shortcode_atts([
            'branch_id'    => '',
            'category_id'  => '',
            'category_ids' => '',
        ], $atts, 'yclients_price');

        $branches = yc_get_branches();
        if (empty($branches)) {
            return '<div class="yc-price-empty">' . esc_html__('Не настроены филиалы.', 'yc-price-accordion') . '</div>';
        }

        $filter_branch = $atts['branch_id'] !== '' ? (int) $atts['branch_id'] : null;
        $filter_cat    = $atts['category_id'] !== '' ? (int) $atts['category_id'] : null;
        $filter_ids    = [];

        if (yc_pa_multi_cats_enabled() && ! empty($atts['category_ids'])) {
            foreach (explode(',', $atts['category_ids']) as $id) {
                $id = (int) trim($id);
                if ($id > 0) {
                    $filter_ids[] = $id;
                }
            }
        }

        $dataset = self::build_dataset($branches, $filter_branch, $filter_cat, $filter_ids);
        if (empty($dataset)) {
            return '<div class="yc-price-empty">' . esc_html__('Нет данных для отображения.', 'yc-price-accordion') . '</div>';
        }

        $page_size = yc_pa_vlist_page();

        ob_start();

        if (yc_pa_debug_enabled() && current_user_can('manage_options') && ! empty(YC_API::$debug_log)) {
            printf(
                '<div class="yc-pa-debug"><strong>DEBUG:</strong> %s<pre>%s</pre></div>',
                esc_html__('Кеш отключен, используются live данные.', 'yc-price-accordion'),
                esc_html(wp_json_encode(YC_API::$debug_log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
            );
        }

        if (yc_pa_show_staff()) {
            echo '<div class="yc-staff-section">' . self::render_staff_grid($branches, $filter_branch, $filter_cat, $filter_ids) . '</div>';
        }

        echo '<div class="yc-accordion-section">';
        $price_title = get_option('yc_title_price', 'Прайс-лист');
        echo '<div class="yc-block-title">' . esc_html($price_title) . '</div>';
        echo '<div class="yc-accordion" role="tablist" aria-multiselectable="true" data-page="' . esc_attr($page_size) . '">';

        foreach ($dataset as $branch_row) {
            $branch    = $branch_row['branch'];
            $panel_id  = 'yc-acc-' . (int) $branch['id'];
            $branch_id = (int) $branch['id'];

            echo '<div class="yc-acc-item" data-branch="' . esc_attr($branch_id) . '">';
            echo '<button class="yc-acc-header" aria-expanded="false" aria-controls="' . esc_attr($panel_id) . '">';
            echo '<span class="yc-acc-title">' . esc_html($branch['title']) . '</span>';
            echo '<span class="yc-acc-icon" aria-hidden="true">+</span>';
            echo '</button>';
            echo '<div id="' . esc_attr($panel_id) . '" class="yc-acc-content" role="region" aria-hidden="true">';

            if (empty($branch_row['categories'])) {
                echo '<div class="yc-acc-empty">' . esc_html__('Нет доступных услуг.', 'yc-price-accordion') . '</div>';
            } else {
                foreach ($branch_row['categories'] as $category) {
                    $services = $category['items'];
                    $total    = count($services);
                    $initial  = array_slice($services, 0, $page_size);
                    $rest     = array_slice($services, $page_size);

                    echo '<div class="yc-cat" data-category="' . esc_attr($category['category_id']) . '">';
                    echo '<div class="yc-cat-title">' . esc_html($category['category_name']);
                    if ($total > $page_size) {
                        echo ' · <span class="yc-cat-count">' . esc_html($total) . '</span>';
                    }
                    echo '</div>';

                    $rest_payload = ! empty($rest) ? esc_attr(wp_json_encode($rest)) : '';
                    echo '<ul class="yc-services"' . ($rest_payload ? ' data-rest="' . $rest_payload . '"' : '') . '>';

                    foreach ($initial as $service) {
                        echo self::render_service_row($service, $branch);
                    }

                    echo '</ul>';

                    if (! empty($rest)) {
                        echo '<button class="yc-load-more" type="button">' . esc_html__('Показать ещё', 'yc-price-accordion') . '</button>';
                    }

                    echo '</div>';
                }
            }

            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';

        return ob_get_clean();
    }

    private static function build_dataset(array $branches, ?int $filter_branch, ?int $filter_cat, array $filter_ids): array
    {
        $dataset = [];

        foreach ($branches as $branch) {
            $branch_id = isset($branch['id']) ? (int) $branch['id'] : 0;
            if ($branch_id <= 0) {
                continue;
            }
            if (null !== $filter_branch && $branch_id !== $filter_branch) {
                continue;
            }

            $categories_map = YC_API::get_categories($branch_id);
            $services       = self::filter_services(YC_API::get_services($branch_id, $filter_cat), $filter_cat, $filter_ids);

            if (empty($services)) {
                continue;
            }

            $grouped = [];
            foreach ($services as $service) {
                $category_id = (int) ($service['category_id'] ?? 0);
                if ($category_id === 0) {
                    $category_id = -1;
                }

                if (! isset($grouped[$category_id])) {
                    $title = $categories_map[$category_id] ?? ($category_id === -1 ? __('Без категории', 'yc-price-accordion') : __('Категория', 'yc-price-accordion'));
                    $grouped[$category_id] = [
                        'category_id'   => $category_id,
                        'category_name' => $title,
                        'items'         => [],
                    ];
                }

                $service['branch'] = $branch;
                $grouped[$category_id]['items'][] = $service;
            }

            if (empty($grouped)) {
                continue;
            }

            usort($grouped, function ($a, $b) {
                return strcasecmp($a['category_name'], $b['category_name']);
            });

            $dataset[] = [
                'branch'     => $branch,
                'categories' => $grouped,
            ];
        }

        return $dataset;
    }

    private static function filter_services(array $services, ?int $filter_cat, array $filter_ids): array
    {
        if (empty($services)) {
            return [];
        }

        $multi_filter = ! empty($filter_ids);
        $filtered     = [];

        foreach ($services as $service) {
            $category_id = (int) ($service['category_id'] ?? 0);

            if (null !== $filter_cat && $category_id !== $filter_cat) {
                continue;
            }

            if ($multi_filter && ! in_array($category_id, $filter_ids, true)) {
                continue;
            }

            if (empty($service['staff']) || ! is_array($service['staff'])) {
                continue;
            }

            $has_staff = false;
            foreach ($service['staff'] as $member) {
                if (! empty($member['id'])) {
                    $has_staff = true;
                    break;
                }
            }

            if (! $has_staff) {
                continue;
            }

            $filtered[] = $service;
        }

        return $filtered;
    }

    private static function render_service_row(array $service, array $branch): string
    {
        $name = $service['title'] ?? '';
        $sid  = (int) ($service['id'] ?? 0);

        $min = (float) ($service['price_min'] ?? 0);
        $max = (float) ($service['price_max'] ?? 0);

        if ($max > 0 && abs($max - $min) > 0.001) {
            $price = number_format_i18n($min, 0) . '–' . number_format_i18n($max, 0) . ' ₽';
        } else {
            $price = number_format_i18n($min, 0) . ' ₽';
        }

        $company_id = (int) ($service['company_id'] ?? ($branch['id'] ?? 0));
        $url        = $sid > 0 ? yc_pa_build_booking_url($branch, $company_id, $sid) : '';

        $html  = '<li class="yc-service">';
        $html .= '<div class="yc-service-row">';
        $html .= '<div class="yc-service-name">' . esc_html($name) . '</div>';
        $html .= '<div class="yc-service-right">';
        $html .= '<div class="yc-service-price">' . esc_html($price) . '</div>';

        if ($url) {
            $html .= '<a class="yc-book-btn" href="' . esc_url($url) . '" target="_blank" rel="noopener nofollow">' . esc_html__('Записаться', 'yc-price-accordion') . '</a>';
        }

        $html .= '</div>';
        $html .= '</div>';
        $html .= '</li>';

        return $html;
    }

    private static function render_staff_grid(array $branches, ?int $filter_branch, ?int $filter_cat, array $filter_ids): string
    {
        $staff = self::collect_staff($branches, $filter_branch, $filter_cat, $filter_ids);
        if (empty($staff)) {
            return '<div class="yc-price-empty">' . esc_html__('Нет специалистов по выбранным фильтрам.', 'yc-price-accordion') . '</div>';
        }

        $title = get_option('yc_title_staff', 'Специалисты');

        ob_start();
        echo '<div class="yc-staff-grid-wrap">';
        echo '<div class="yc-block-title">' . esc_html($title) . '</div>';
        echo '<div class="yc-staff-grid" data-ycpa-version="' . esc_attr(YC_PA_VERSION) . '">';
        foreach ($staff as $member) {
            echo self::render_staff_card($member);
        }
        echo '</div>';
        echo '</div>';

        return ob_get_clean();
    }

    private static function collect_staff(array $branches, ?int $filter_branch, ?int $filter_cat, array $filter_ids): array
    {
        $by_name       = [];
        $priority_name = self::parse_name_priority_map();
        $priority_id   = self::parse_id_priority_map();
        $use_filters   = null !== $filter_cat || ! empty($filter_ids);

        foreach ($branches as $branch) {
            $branch_id = isset($branch['id']) ? (int) $branch['id'] : 0;
            if ($branch_id <= 0) {
                continue;
            }
            if (null !== $filter_branch && $branch_id !== $filter_branch) {
                continue;
            }

            $allowed_staff = null;
            if ($use_filters) {
                $allowed_staff = self::collect_staff_ids_from_services($branch_id, $filter_cat, $filter_ids);
                if (empty($allowed_staff)) {
                    continue;
                }
            }

            $staff_map = YC_API::get_staff($branch_id);
            if (empty($staff_map)) {
                continue;
            }

            foreach ($staff_map as $member) {
                $staff_id = (int) ($member['id'] ?? 0);
                if ($staff_id <= 0) {
                    continue;
                }
                if (null !== $allowed_staff && ! isset($allowed_staff[$staff_id])) {
                    continue;
                }

                $name = trim((string) ($member['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $norm = yc_normalize_name($name);

                $weight = isset($member['order']) ? (float) $member['order'] : 9999;
                if (isset($priority_id[$staff_id])) {
                    $weight = min($weight, (float) $priority_id[$staff_id]);
                }
                if (isset($priority_name[$norm])) {
                    $weight = min($weight, (float) $priority_name[$norm]);
                }

                $entry = [
                    'id'        => $staff_id,
                    'name'      => $name,
                    'position'  => yc_sanitize_position($member['position'] ?? ''),
                    'image_url' => $member['image_url'] ?? '',
                    'branch_id' => $branch_id,
                    'weight'    => $weight,
                    'link'      => yc_get_staff_link($branch_id, $staff_id),
                ];

                if (isset($by_name[$norm])) {
                    $existing = $by_name[$norm];
                    if ($entry['weight'] < $existing['weight']) {
                        $by_name[$norm] = $entry;
                        continue;
                    }

                    if ($entry['image_url'] && ! $existing['image_url']) {
                        $existing['image_url'] = $entry['image_url'];
                    }
                    if ($entry['position'] && ! $existing['position']) {
                        $existing['position'] = $entry['position'];
                    }
                    $by_name[$norm] = $existing;
                } else {
                    $by_name[$norm] = $entry;
                }
            }
        }

        if (empty($by_name)) {
            return [];
        }

        $list = array_values($by_name);
        usort($list, function ($a, $b) {
            $cmp = $a['weight'] <=> $b['weight'];
            if (0 !== $cmp) {
                return $cmp;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $list;
    }

    private static function collect_staff_ids_from_services(int $branch_id, ?int $filter_cat, array $filter_ids): array
    {
        $services = self::filter_services(YC_API::get_services($branch_id, $filter_cat), $filter_cat, $filter_ids);
        $allowed  = [];

        foreach ($services as $service) {
            if (empty($service['staff']) || ! is_array($service['staff'])) {
                continue;
            }
            foreach ($service['staff'] as $member) {
                $sid = (int) ($member['id'] ?? 0);
                if ($sid > 0) {
                    $allowed[$sid] = true;
                }
            }
        }

        return $allowed;
    }

    private static function render_staff_card(array $member): string
    {
        $html  = '<div class="yc-staff-card" data-order="' . esc_attr($member['weight']) . '">';
        $img   = $member['image_url'];
        $link  = $member['link'];
        $name  = $member['name'];
        $pos   = $member['position'];

        $html .= '<a class="yc-staff-photo"' . ($link ? ' href="' . esc_url($link) . '" target="_blank" rel="noopener nofollow"' : '') . '>';
        if ($img) {
            $html .= '<img src="' . esc_url($img) . '" alt="" loading="lazy" />';
        } else {
            $html .= '<span class="yc-staff-ph" aria-hidden="true"></span>';
        }
        $html .= '</a>';

        $html .= '<div class="yc-staff-meta">';
        if ($link) {
            $html .= '<div class="yc-staff-name"><a href="' . esc_url($link) . '" target="_blank" rel="noopener nofollow">' . esc_html($name) . '</a></div>';
        } else {
            $html .= '<div class="yc-staff-name">' . esc_html($name) . '</div>';
        }

        if ($pos) {
            $html .= '<div class="yc-staff-pos">' . esc_html($pos) . '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private static function parse_id_priority_map(): array
    {
        $raw = get_option('yc_staff_priority_id_map', '');
        $map = [];

        if (is_array($raw)) {
            foreach ($raw as $id => $weight) {
                $map[(int) $id] = (float) $weight;
            }
            return $map;
        }

        $raw = trim((string) $raw);
        if ($raw === '') {
            return $map;
        }

        if ($raw[0] === '{') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $id => $weight) {
                    $map[(int) $id] = (float) $weight;
                }
            }

            return $map;
        }

        foreach (preg_split('/\r?\n|,/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$id, $weight] = array_pad(explode('=', $line, 2), 2, '');
            $id = (int) trim($id);
            if ($id <= 0) {
                continue;
            }

            $map[$id] = (float) trim($weight);
        }

        return $map;
    }

    private static function parse_name_priority_map(): array
    {
        $raw = get_option('yc_staff_admin_order_map', '');
        $map = [];

        $lines = preg_split('/\r?\n/', (string) $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$name, $weight] = array_pad(explode('=', $line, 2), 2, '');
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $map[yc_normalize_name($name)] = (float) trim($weight);
        }

        return $map;
    }
}
