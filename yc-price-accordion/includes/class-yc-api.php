<?php
if (! defined('ABSPATH')) {
    exit;
}

class YC_API
{
    /** @var array|null */
    private static $dataset = null;

    /** @var array */
    public static $debug_log = [];

    /**
     * Build transient cache key based on configured branches.
     */
    public static function cache_key(): string
    {
        $branches = yc_get_branches();
        $hash     = md5(wp_json_encode($branches));

        return 'yc_price_cache_' . $hash;
    }

    /**
     * Return cached dataset.
     */
    public static function get_cached_price(): ?array
    {
        self::ensure_dataset_loaded();

        return self::$dataset;
    }

    /**
     * Store dataset in transient cache and mark last update time.
     */
    public static function set_cached_price($data): void
    {
        self::ensure_dataset_loaded();

        $branch_id = self::guess_primary_branch_id();
        self::$dataset = self::normalise_dataset($data, $branch_id);

        self::persist_dataset();
    }

    /**
     * Seed cache from JSON payload pasted in the admin screen.
     */
    public static function seed_cache_from_json(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '') {
            return false;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return false;
        }

        self::set_cached_price($decoded);

        return true;
    }

    /**
     * Force refresh of all configured branches.
     */
    public static function refresh_cache(int $timeout = 5): bool
    {
        self::ensure_dataset_loaded();

        $from_filter = apply_filters('ycpa_refresh_cache_data', null);
        if (is_array($from_filter)) {
            self::$dataset = self::normalise_dataset($from_filter, self::guess_primary_branch_id());
            self::persist_dataset();

            return true;
        }

        $branches      = yc_get_branches();
        $refreshed     = [];
        $has_fetched   = false;
        $allow_network = ! yc_pa_render_from_cache_only();

        foreach ($branches as $branch) {
            $branch_id = isset($branch['id']) ? (int) $branch['id'] : 0;
            if ($branch_id <= 0) {
                continue;
            }

            if (! $allow_network) {
                $cached = self::get_branch_from_dataset($branch_id);
                if ($cached !== null) {
                    $refreshed[$branch_id] = $cached;
                }
                continue;
            }

            $payload = self::fetch_branch($branch_id, $branch, $timeout);
            if (null === $payload) {
                continue;
            }

            $refreshed[$branch_id] = $payload;
            $has_fetched           = true;
        }

        if ($has_fetched) {
            self::$dataset['branches'] = $refreshed + self::$dataset['branches'];
            self::persist_dataset();

            return true;
        }

        return false;
    }

    /**
     * Prewarm cache for a single branch (used by cron).
     */
    public static function prewarm_branch(int $branch_id, ?array $branch_meta = null, int $timeout = 5): void
    {
        if ($branch_id <= 0 || yc_pa_render_from_cache_only()) {
            return;
        }

        $payload = self::fetch_branch($branch_id, $branch_meta, $timeout);
        if (null === $payload) {
            return;
        }

        self::ensure_dataset_loaded();
        self::$dataset['branches'][$branch_id] = $payload;
        self::persist_dataset();
    }

    /**
     * Return list of categories for the branch as [id => title].
     */
    public static function get_categories(int $branch_id): array
    {
        $branch = self::ensure_branch_available($branch_id);
        if (empty($branch)) {
            return [];
        }

        return $branch['categories'];
    }

    /**
     * Return services for the branch; optional category filter.
     */
    public static function get_services(int $branch_id, ?int $category_id = null): array
    {
        $branch = self::ensure_branch_available($branch_id);
        if (empty($branch)) {
            return [];
        }

        $services = $branch['services'];
        if (null === $category_id) {
            return $services;
        }

        $filtered = [];
        foreach ($services as $service) {
            if ((int) ($service['category_id'] ?? 0) === $category_id) {
                $filtered[] = $service;
            }
        }

        return $filtered;
    }

    /**
     * Return staff keyed by staff ID.
     */
    public static function get_staff(int $branch_id): array
    {
        $branch = self::ensure_branch_available($branch_id);
        if (empty($branch)) {
            return [];
        }

        return $branch['staff'];
    }

    // ---------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------

    private static function ensure_dataset_loaded(): void
    {
        if (null !== self::$dataset) {
            return;
        }

        $cached = get_transient(self::cache_key());
        if (is_array($cached)) {
            self::$dataset = self::normalise_dataset($cached, self::guess_primary_branch_id());
            return;
        }

        self::$dataset = [
            'branches'    => [],
            'last_update' => '',
        ];
    }

    private static function persist_dataset(): void
    {
        $ttl = yc_pa_cache_ttl();
        set_transient(self::cache_key(), self::$dataset, $ttl > 0 ? $ttl : 0);
        update_option('yc_cache_last_updated', current_time('mysql'));
    }

    private static function guess_primary_branch_id(): int
    {
        $branches = yc_get_branches();
        if (! empty($branches)) {
            $first = reset($branches);
            if (is_array($first) && ! empty($first['id'])) {
                return (int) $first['id'];
            }
        }

        return 0;
    }

    private static function normalise_dataset($raw, int $fallback_branch_id): array
    {
        $dataset = [
            'branches'    => [],
            'last_update' => '',
        ];

        if (! is_array($raw)) {
            return $dataset;
        }

        if (isset($raw['branches']) && is_array($raw['branches'])) {
            foreach ($raw['branches'] as $branch_id => $branch_payload) {
                $branch_id = (int) $branch_id;
                if ($branch_id <= 0) {
                    continue;
                }

                $normalised = self::normalise_branch_payload($branch_payload);
                if (! empty($normalised)) {
                    $dataset['branches'][$branch_id] = $normalised;
                }
            }

            return $dataset;
        }

        if ($fallback_branch_id <= 0) {
            return $dataset;
        }

        $prepared = self::prepare_branch_payload($raw);
        if (empty($prepared)) {
            return $dataset;
        }

        $normalised = self::normalise_branch_payload($prepared);
        if (! empty($normalised)) {
            $dataset['branches'][$fallback_branch_id] = $normalised;
        }

        return $dataset;
    }

    private static function normalise_branch_payload($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $categories = [];
        if (isset($raw['categories'])) {
            $categories = self::normalise_categories($raw['categories']);
        }

        $services = [];
        if (isset($raw['services'])) {
            $services = self::normalise_services($raw['services']);
        }

        $staff = [];
        if (isset($raw['staff'])) {
            $staff = self::normalise_staff($raw['staff']);
        }

        return [
            'categories' => $categories,
            'services'   => $services,
            'staff'      => $staff,
        ];
    }

    private static function normalise_categories($raw): array
    {
        $categories = [];

        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_array($value)) {
                    $id    = isset($value['id']) ? (int) $value['id'] : 0;
                    $title = isset($value['title']) ? (string) $value['title'] : ($value['name'] ?? '');
                    if ($id > 0 && $title !== '') {
                        $categories[$id] = wp_strip_all_tags($title);
                    }
                    continue;
                }

                $id    = (int) $key;
                $title = (string) $value;
                if ($id > 0 && $title !== '') {
                    $categories[$id] = wp_strip_all_tags($title);
                }
            }
        }

        return $categories;
    }

    private static function normalise_services($raw): array
    {
        $services = [];

        if (! is_array($raw)) {
            return $services;
        }

        foreach ($raw as $service) {
            if (! is_array($service)) {
                continue;
            }

            $id         = isset($service['id']) ? (int) $service['id'] : 0;
            $category   = isset($service['category_id']) ? (int) $service['category_id'] : 0;
            $title      = isset($service['title']) ? (string) $service['title'] : ($service['name'] ?? '');
            $price_min  = isset($service['price_min']) ? (float) $service['price_min'] : (float) ($service['price'] ?? 0);
            $price_max  = isset($service['price_max']) ? (float) $service['price_max'] : 0.0;
            $company_id = isset($service['company_id']) ? (int) $service['company_id'] : 0;

            if ($id <= 0 || $title === '') {
                continue;
            }

            $normalized = [
                'id'          => $id,
                'category_id' => $category,
                'title'       => $title,
                'price_min'   => $price_min,
                'price_max'   => $price_max,
                'company_id'  => $company_id,
                'staff'       => [],
            ];

            if (! empty($service['staff']) && is_array($service['staff'])) {
                foreach ($service['staff'] as $member) {
                    if (! is_array($member)) {
                        continue;
                    }

                    $staff_id = isset($member['id']) ? (int) $member['id'] : 0;
                    if ($staff_id <= 0) {
                        continue;
                    }

                    $normalized['staff'][] = [
                        'id'   => $staff_id,
                        'name' => isset($member['name']) ? (string) $member['name'] : ($member['title'] ?? ''),
                    ];
                }
            }

            if (isset($service['branch'])) {
                $normalized['branch'] = $service['branch'];
            }

            $services[] = $normalized;
        }

        return $services;
    }

    private static function normalise_staff($raw): array
    {
        $staff = [];

        if (! is_array($raw)) {
            return $staff;
        }

        foreach ($raw as $key => $member) {
            if (! is_array($member)) {
                continue;
            }

            $id = isset($member['id']) ? (int) $member['id'] : (is_numeric($key) ? (int) $key : 0);
            if ($id <= 0) {
                continue;
            }

            $name = isset($member['name']) ? (string) $member['name'] : ($member['title'] ?? '');
            $photo = isset($member['photo']) ? (string) $member['photo'] : ($member['image_url'] ?? '');
            $position = isset($member['position']) ? $member['position'] : ($member['profession'] ?? '');
            $order    = isset($member['order']) ? (float) $member['order'] : 0;

            $staff[$id] = [
                'id'        => $id,
                'name'      => $name,
                'image_url' => $photo,
                'position'  => yc_sanitize_position($position),
                'order'     => $order,
            ];
        }

        return $staff;
    }

    private static function prepare_branch_payload(array $raw): array
    {
        $payload = [
            'categories' => [],
            'services'   => [],
            'staff'      => [],
        ];

        if (isset($raw['categories']) || isset($raw['services']) || isset($raw['staff'])) {
            if (isset($raw['categories']) && is_array($raw['categories'])) {
                $payload['categories'] = $raw['categories'];
            }
            if (isset($raw['services']) && is_array($raw['services'])) {
                $payload['services'] = $raw['services'];
            }
            if (isset($raw['staff']) && is_array($raw['staff'])) {
                $payload['staff'] = $raw['staff'];
            }

            return $payload;
        }

        if (self::looks_like_staff_list($raw)) {
            $payload['staff'] = array_values($raw);

            return $payload;
        }

        if (self::looks_like_services_list($raw)) {
            $payload['services'] = array_values($raw);

            return $payload;
        }

        return [];
    }

    private static function looks_like_staff_list($raw): bool
    {
        if (! self::is_list($raw) || empty($raw)) {
            return false;
        }

        $sample = reset($raw);
        if (! is_array($sample)) {
            return false;
        }

        if (! isset($sample['id']) || (int) $sample['id'] <= 0) {
            return false;
        }

        return isset($sample['name']) || isset($sample['title']);
    }

    private static function looks_like_services_list($raw): bool
    {
        if (! self::is_list($raw) || empty($raw)) {
            return false;
        }

        $sample = reset($raw);
        if (! is_array($sample)) {
            return false;
        }

        if (! isset($sample['id']) || (int) $sample['id'] <= 0) {
            return false;
        }

        if (isset($sample['price']) || isset($sample['price_min']) || isset($sample['category_id'])) {
            return true;
        }

        return false;
    }

    private static function is_list($value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function ensure_branch_available(int $branch_id): array
    {
        self::ensure_dataset_loaded();

        if (isset(self::$dataset['branches'][$branch_id])) {
            return self::$dataset['branches'][$branch_id];
        }

        if (yc_pa_render_from_cache_only()) {
            return [];
        }

        $branch_meta = null;
        foreach (yc_get_branches() as $branch) {
            if ((int) ($branch['id'] ?? 0) === $branch_id) {
                $branch_meta = $branch;
                break;
            }
        }

        $payload = self::fetch_branch($branch_id, $branch_meta, 5);
        if (null === $payload) {
            return [];
        }

        self::$dataset['branches'][$branch_id] = $payload;
        self::persist_dataset();

        return $payload;
    }

    private static function get_branch_from_dataset(int $branch_id): ?array
    {
        self::ensure_dataset_loaded();

        return self::$dataset['branches'][$branch_id] ?? null;
    }

    private static function fetch_branch(int $branch_id, ?array $branch_meta = null, int $timeout = 5): ?array
    {
        $payload = apply_filters('ycpa_fetch_branch_data', null, $branch_id, $branch_meta, $timeout);
        if ($payload instanceof WP_Error) {
            self::log_debug('Branch fetch failed: ' . $payload->get_error_message(), [
                'branch' => $branch_id,
            ]);

            return null;
        }

        if (is_array($payload)) {
            return self::normalise_branch_payload($payload);
        }

        $staff      = method_exists(__CLASS__, 'fetch_staff') ? self::fetch_staff($branch_meta, $timeout) : [];
        $services   = method_exists(__CLASS__, 'fetch_services') ? self::fetch_services($branch_meta, $timeout) : [];
        $categories = method_exists(__CLASS__, 'fetch_categories') ? self::fetch_categories($branch_meta, $timeout) : [];

        $normalised = self::normalise_branch_payload([
            'staff'      => $staff,
            'services'   => $services,
            'categories' => $categories,
        ]);

        if (! empty($normalised['staff']) || ! empty($normalised['services'])) {
            return $normalised;
        }

        return null;
    }

    private static function log_debug(string $message, array $context = []): void
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return;
        }

        self::$debug_log[] = [
            'message' => $message,
            'context' => $context,
            'time'    => current_time('mysql'),
        ];
    }

    // ------------------------------------------------------------------
    // Optional fetchers – override with project specific logic.
    // ------------------------------------------------------------------

    public static function fetch_staff($branch, int $timeout = 5): array
    {
        return [];
    }

    public static function fetch_services($branch, int $timeout = 5): array
    {
        return [];
    }

    public static function fetch_categories($branch, int $timeout = 5): array
    {
        return [];
    }
}
