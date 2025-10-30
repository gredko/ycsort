<?php
if (!defined('ABSPATH')) { exit; }

class YC_Storage {
    const OPTION_KEY = 'yc_pa_snapshot';

    /**
     * Runtime cache of stored data.
     *
     * @var array|null
     */
    protected static $snapshot = null;

    protected static function load() {
        if (self::$snapshot === null) {
            $raw = get_option(self::OPTION_KEY, array());
            self::$snapshot = is_array($raw) ? $raw : array();
        }
        return self::$snapshot;
    }

    protected static function persist() {
        if (self::$snapshot === null) {
            self::$snapshot = array();
        }
        update_option(self::OPTION_KEY, self::$snapshot, false);
    }

    public static function get_section($company_id, $section) {
        $data = self::load();
        $cid  = (string) (int) $company_id;
        if ($cid === '0') {
            return null;
        }
        if (!isset($data[$cid][$section]) || !is_array($data[$cid][$section])) {
            return null;
        }
        return isset($data[$cid][$section]['data']) ? $data[$cid][$section]['data'] : null;
    }

    public static function get_section_timestamp($company_id, $section) {
        $data = self::load();
        $cid  = (string) (int) $company_id;
        if ($cid === '0') {
            return 0;
        }
        if (!isset($data[$cid][$section]) || !is_array($data[$cid][$section])) {
            return 0;
        }
        return isset($data[$cid][$section]['updated']) ? (int) $data[$cid][$section]['updated'] : 0;
    }

    public static function set_section($company_id, $section, $value) {
        $cid = (string) (int) $company_id;
        if ($cid === '0') {
            return;
        }
        self::load();
        if (!isset(self::$snapshot[$cid]) || !is_array(self::$snapshot[$cid])) {
            self::$snapshot[$cid] = array();
        }
        self::$snapshot[$cid][$section] = array(
            'data'    => $value,
            'updated' => time(),
        );
        self::persist();
    }

    public static function purge_company($company_id) {
        $cid = (string) (int) $company_id;
        self::load();
        if ($cid === '0') {
            return;
        }
        if (isset(self::$snapshot[$cid])) {
            unset(self::$snapshot[$cid]);
            self::persist();
        }
    }

    public static function purge_all() {
        self::$snapshot = array();
        delete_option(self::OPTION_KEY);
    }
}
