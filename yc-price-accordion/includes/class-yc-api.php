<?php
if (!defined('ABSPATH')) exit;

class YC_API {

    /**
     * Построение ключа кеша с учетом выбранных филиалов (если опция используется).
     */
    public static function cache_key(){
        $branches = get_option('yc_branches', array());
        $hash = md5(json_encode($branches));
        return 'yc_price_cache_' . $hash;
    }

    /**
     * Получить данные из кеша (transient).
     * Формат ожидается:
     * [
     *   'staff'    => [ ['id'=>..., 'name'=>..., 'position'=>..., 'photo'=>..., 'order'=>...], ... ],
     *   'services' => [ ... ]
     * ]
     */
    public static function get_cached_price(){
        $key = self::cache_key();
        $cached = get_transient($key);
        return (is_array($cached) ? $cached : null);
    }

    /**
     * Сохранить данные в кеш на TTL минут из опции yc_cache_ttl (по умолчанию 30).
     */
    public static function set_cached_price($data){
        $key = self::cache_key();
        $ttl = intval(get_option('yc_cache_ttl', 30));
        if ($ttl < 1)   $ttl = 1;
        if ($ttl > 1440) $ttl = 1440;
        set_transient($key, $data, $ttl * MINUTE_IN_SECONDS);
        update_option('yc_cache_last_updated', current_time('mysql'));
    }

    /**
     * Засеять кеш вручную из JSON (из админки).
     * Принимает либо массив сотрудников, либо объект с ключами staff/services.
     */
    public static function seed_cache_from_json($raw){
        $raw = trim((string)$raw);
        if ($raw === '') return false;

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return false;

        if (isset($decoded['staff']) || isset($decoded['services'])){
            $data = array(
                'staff'    => array(),
                'services' => array(),
            );
            if (isset($decoded['staff']) && is_array($decoded['staff'])){
                $data['staff'] = $decoded['staff'];
            }
            if (isset($decoded['services']) && is_array($decoded['services'])){
                $data['services'] = $decoded['services'];
            }
            self::set_cached_price($data);
            return true;
        } else {
            // Если это просто массив сотрудников
            $data = array('staff' => $decoded, 'services' => array());
            self::set_cached_price($data);
            return true;
        }
    }

    /**
     * Ручное обновление кеша из источника.
     * По умолчанию сначала даём шанс внешнему коду через фильтр
     *  - add_filter('ycpa_refresh_cache_data', fn()=>['staff'=>..., 'services'=>...]);
     * Если данных не дали — пробуем вызвать внутренние методы fetch_* (если реализованы).
     */
    public static function refresh_cache($timeout = 5){
        // Попытка получить данные от внешнего кода (темы/плагина)
        $data = apply_filters('ycpa_refresh_cache_data', null);

        if (!is_array($data)){
            // Сборка из внутренних методов, если они есть
            $result = array('staff'=>array(), 'services'=>array());

            // Филиалы могут храниться в yc_branches (если используется)
            $branches = get_option('yc_branches', array());
            if (!is_array($branches) || empty($branches)){
                // Даже если филиалов нет — всё равно попробуем без контекста
                $branches = array(null);
            }

            foreach ($branches as $branch){
                if (method_exists(__CLASS__, 'fetch_staff')){
                    $st = self::fetch_staff($branch, $timeout);
                    if (is_array($st)) $result['staff'] = array_merge($result['staff'], $st);
                }
                if (method_exists(__CLASS__, 'fetch_services')){
                    $sv = self::fetch_services($branch, $timeout);
                    if (is_array($sv)) $result['services'] = array_merge($result['services'], $sv);
                }
            }

            if (!empty($result['staff']) || !empty($result['services'])){
                $data = $result;
            }
        }

        if (is_array($data)){
            if (!isset($data['staff']))    $data['staff'] = array();
            if (!isset($data['services'])) $data['services'] = array();
            self::set_cached_price($data);
            return true;
        }

        return false;
    }

    /**
     * Заглушка: загрузка сотрудников из источника.
     * Замените реальной логикой (HTTP запрос к YClients и маппинг полей),
     * либо оставьте — и используйте seed_cache_from_json() / фильтр ycpa_refresh_cache_data.
     */
    public static function fetch_staff($branch, $timeout = 5){
        // Пример ожидаемого элемента:
        // ['id'=>98771,'name'=>'ФИО','position'=>'Должность','photo'=>'https://...','order'=>0,'branch_id'=>...]
        return array();
    }

    /**
     * Заглушка: загрузка услуг из источника.
     */
    public static function fetch_services($branch, $timeout = 5){
        return array();
    }
}
// конец файла — без закрывающего тега PHP
