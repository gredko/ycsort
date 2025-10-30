<?php
if (!defined('ABSPATH')) exit;

class YC_API {
  const API_ROOT = 'https://api.yclients.com/api/v1';
  public static $debug_log = array();

  protected static function headers(){
    $partner = defined('PARTNER_TOKEN') ? trim((string)PARTNER_TOKEN) : '';
    $user    = defined('USER_TOKEN')    ? trim((string)USER_TOKEN)    : '';
    if ($partner==='') $partner = (string)get_option('yc_partner_token','');
    if ($user==='')    $user    = (string)get_option('yc_user_token','');
    $auth = 'Bearer '.$partner.', User '.$user;
    return array(
      'Accept'       => 'application/vnd.yclients.v2+json',
      'Content-Type' => 'application/json',
      'Authorization'=> $auth,
    );
  }

  protected static function request($path, $params=array()){
    $url = rtrim(self::API_ROOT,'/').'/'.ltrim($path,'/');
    if (!empty($params)) $url = add_query_arg($params, $url);
    $args = array('headers'=>self::headers(),'timeout'=>20);
    $t0 = microtime(true);
    $resp = wp_remote_get($url, $args);
    $elapsed = round((microtime(true)-$t0)*1000);

    if (is_wp_error($resp)){
      if (yc_pa_debug_enabled()){
        self::$debug_log[] = array('url'=>$url,'code'=>null,'elapsed_ms'=>$elapsed,'error'=>$resp->get_error_message(),'body'=>null,'count'=>null);
      }
      return array('error'=>$resp->get_error_message());
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);

    if (yc_pa_debug_enabled()){
      self::$debug_log[] = array(
        'url'=>$url,'code'=>$code,'elapsed_ms'=>$elapsed,'error'=>null,
        'count'=>is_array(isset($json['data'])?$json['data']:null)?count($json['data']):null,
        'body'=>is_string($body)?(strlen($body)>1500?substr($body,0,1500).'…':$body):null
      );
    }
    if ($code>=200 && $code<300 && is_array($json)) return $json;
    return array('error'=>'HTTP '.$code,'raw'=>$body);
  }

  protected static function is_fresh($company_id, $section, $cached){
    if ($cached === null) {
      return false;
    }
    $ttl = yc_pa_cache_ttl();
    if ($ttl <= 0) {
      return true;
    }
    $updated = YC_Storage::get_section_timestamp($company_id, $section);
    if ($updated <= 0) {
      return false;
    }
    return (time() - $updated) < $ttl;
  }

  protected static function fetch_categories($company_id){
    $json = self::request('/company/'.intval($company_id).'/service_categories');
    if (isset($json['error'])) {
      return $json;
    }
    $data = is_array(isset($json['data'])?$json['data']:null)?$json['data']:array();
    $map = array();
    foreach($data as $row){
      $cid = intval(isset($row['id'])?$row['id']:0);
      $name = trim(isset($row['title'])?$row['title']:(isset($row['name'])?$row['name']:''));
      if ($cid>0 && $name!=='') $map[$cid]=$name;
    }
    return $map;
  }

  protected static function fetch_services($company_id){
    $json = self::request('/company/'.intval($company_id).'/services');
    if (isset($json['error'])) {
      return $json;
    }
    return is_array(isset($json['data'])?$json['data']:null)?$json['data']:array();
  }

  protected static function fetch_staff($company_id){
    $resp = self::request('/company/'.intval($company_id).'/staff');
    if (isset($resp['error'])) {
      return $resp;
    }
    $staffs = array();
    if (!empty($resp['data']) && is_array($resp['data'])){
      foreach($resp['data'] as $s){
        $sid = isset($s['id'])?intval($s['id']):0;
        if ($sid<=0) continue;
        $pos = '';
        if (isset($s['position'])) {
          $pos = yc_sanitize_position($s['position']);
        } elseif (isset($s['specialization'])) {
          $pos = yc_sanitize_position($s['specialization']);
        }
        $staffs[$sid] = array(
          'id'=>$sid,
          'name'=> isset($s['name'])?$s['name']:'',
          'image_url'=> isset($s['avatar_big'])?$s['avatar_big']:(isset($s['avatar'])?$s['avatar']:''),
          'position'=> $pos,
        );
      }
    }
    if (empty($staffs)){
      $services = self::get_services($company_id,null,true);
      foreach($services as $svc){
        if (!empty($svc['staff']) && is_array($svc['staff'])){
          foreach($svc['staff'] as $st){
            if (!is_array($st)) continue;
            $sid = isset($st['id'])?intval($st['id']):0;
            if ($sid<=0) continue;
            if (!isset($staffs[$sid])) $staffs[$sid] = array(
              'id'=>$sid,
              'name'=> isset($st['name'])?$st['name']:'',
              'image_url'=> isset($st['image_url'])?$st['image_url']:'',
              'position'=> '',
            );
            else {
              if (empty($staffs[$sid]['image_url']) && !empty($st['image_url'])) $staffs[$sid]['image_url'] = $st['image_url'];
              if (empty($staffs[$sid]['name']) && !empty($st['name'])) $staffs[$sid]['name'] = $st['name'];
            }
          }
        }
      }
    }
    return $staffs;
  }

  public static function get_categories($company_id, $force = false){
    $cid = intval($company_id);
    $cached = YC_Storage::get_section($cid, 'categories');
    if (!$force && !yc_pa_debug_enabled() && self::is_fresh($cid, 'categories', $cached)){
      return $cached;
    }
    $map = self::fetch_categories($cid);
    if (!isset($map['error'])) {
      YC_Storage::set_section($cid, 'categories', $map);
      return $map;
    }
    return is_array($cached) ? $cached : array();
  }

  protected static function service_matches_category($service, $category_id){
    $target = intval($category_id);
    if ($target <= 0) {
      return false;
    }
    if (isset($service['category_id']) && intval($service['category_id']) === $target) {
      return true;
    }
    if (isset($service['categoryId']) && intval($service['categoryId']) === $target) {
      return true;
    }
    if (isset($service['category']) && is_array($service['category'])) {
      $cid = 0;
      if (isset($service['category']['id'])) {
        $cid = intval($service['category']['id']);
      } elseif (isset($service['category'][0])) {
        $cid = intval($service['category'][0]);
      }
      if ($cid === $target) {
        return true;
      }
    }
    if (isset($service['categories']) && is_array($service['categories'])) {
      foreach ($service['categories'] as $cat) {
        if (is_array($cat)) {
          if (isset($cat['id']) && intval($cat['id']) === $target) {
            return true;
          }
          if (isset($cat[0]) && intval($cat[0]) === $target) {
            return true;
          }
        } elseif (intval($cat) === $target) {
          return true;
        }
      }
    }
    return false;
  }

  public static function get_services($company_id, $category_id=null, $force=false){
    $cid = intval($company_id);
    $cached = YC_Storage::get_section($cid, 'services_all');
    if (!$force && !yc_pa_debug_enabled() && self::is_fresh($cid, 'services_all', $cached)){
      $services = is_array($cached) ? $cached : array();
    } else {
      $services = self::fetch_services($cid);
      if (!isset($services['error'])) {
        YC_Storage::set_section($cid, 'services_all', $services);
      } elseif (is_array($cached)) {
        $services = $cached;
      } else {
        $services = array();
      }
    }

    if ($category_id === null || $category_id === '' || !is_numeric($category_id)){
      return is_array($services) ? $services : array();
    }

    $category_id = intval($category_id);
    if ($category_id <= 0) {
      return is_array($services) ? $services : array();
    }

    $filtered = array();
    foreach ($services as $service){
      if (self::service_matches_category($service, $category_id)){
        $filtered[] = $service;
      }
    }
    return $filtered;
  }

  public static function get_staff($company_id, $force = false){
    $cid = intval($company_id);
    $cached = YC_Storage::get_section($cid, 'staff');
    if (!$force && !yc_pa_debug_enabled() && self::is_fresh($cid, 'staff', $cached)){
      return is_array($cached) ? $cached : array();
    }
    $staffs = self::fetch_staff($cid);
    if (!isset($staffs['error'])) {
      YC_Storage::set_section($cid, 'staff', $staffs);
      return $staffs;
    }
    return is_array($cached) ? $cached : array();
  }

  public static function sync_company($company_id, $force = false){
    $cid = intval($company_id);
    if ($cid <= 0) {
      return array('categories'=>false,'services'=>false,'staff'=>false,'errors'=>array('invalid company'));
    }
    $result = array('categories'=>false,'services'=>false,'staff'=>false,'errors'=>array());

    $cats_cached = YC_Storage::get_section($cid, 'categories');
    if ($force || yc_pa_debug_enabled() || !self::is_fresh($cid, 'categories', $cats_cached)) {
      $cats = self::fetch_categories($cid);
      if (isset($cats['error'])) {
        $result['errors'][] = 'categories: ' . $cats['error'];
      } else {
        YC_Storage::set_section($cid, 'categories', $cats);
        $result['categories'] = true;
      }
    }

    $svc_cached = YC_Storage::get_section($cid, 'services_all');
    if ($force || yc_pa_debug_enabled() || !self::is_fresh($cid, 'services_all', $svc_cached)) {
      $services = self::fetch_services($cid);
      if (isset($services['error'])) {
        $result['errors'][] = 'services: ' . $services['error'];
      } else {
        YC_Storage::set_section($cid, 'services_all', $services);
        $result['services'] = true;
      }
    }

    $staff_cached = YC_Storage::get_section($cid, 'staff');
    if ($force || yc_pa_debug_enabled() || !self::is_fresh($cid, 'staff', $staff_cached)) {
      $staff = self::fetch_staff($cid);
      if (isset($staff['error'])) {
        $result['errors'][] = 'staff: ' . $staff['error'];
      } else {
        YC_Storage::set_section($cid, 'staff', $staff);
        $result['staff'] = true;
      }
    }

    return $result;
  }
}
