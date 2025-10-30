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

  public static function get_categories($company_id){
    $ckey='cats_'.intval($company_id);
    if (!yc_pa_debug_enabled()){
      $c=yc_pa_cache_get($ckey);
      if ($c!==false && $c!==null) return $c;
    }
    $json = self::request('/company/'.intval($company_id).'/service_categories');
    $data = is_array(isset($json['data'])?$json['data']:null)?$json['data']:array();
    $map = array();
    foreach($data as $row){
      $cid = intval(isset($row['id'])?$row['id']:0);
      $name = trim(isset($row['title'])?$row['title']:(isset($row['name'])?$row['name']:''));
      if ($cid>0 && $name!=='') $map[$cid]=$name;
    }
    if (!yc_pa_debug_enabled()) yc_pa_cache_set($ckey,$map);
    return $map;
  }

  public static function get_services($company_id,$category_id=null){
    $params=array(); if (!empty($category_id)) $params['category_id']=$category_id;
    $ckey='svc_'.intval($company_id).'_'.md5(json_encode($params));
    if (!yc_pa_debug_enabled()){
      $c=yc_pa_cache_get($ckey); if ($c!==false && $c!==null) return $c;
    }
    $json = self::request('/company/'.intval($company_id).'/services',$params);
    $data = is_array(isset($json['data'])?$json['data']:null)?$json['data']:array();
    if (!yc_pa_debug_enabled()) yc_pa_cache_set($ckey,$data);
    return $data;
  }

  public static function get_staff($company_id){
    $ckey='staff_'.intval($company_id);
    if (!yc_pa_debug_enabled()){
      $c=yc_pa_cache_get($ckey); if ($c!==false && $c!==null) return $c;
    }
    $resp = self::request('/company/'.intval($company_id).'/staff');
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
      $services = self::get_services($company_id,null);
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
    if (!yc_pa_debug_enabled()) yc_pa_cache_set($ckey,$staffs);
    return $staffs;
  }
}
