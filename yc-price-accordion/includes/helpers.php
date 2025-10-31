<?php
if (!defined('ABSPATH')) exit;

function yc_get_branches() {
  $rows = get_option('yc_branches', array()); $out = array();
  if (is_array($rows)) {
    foreach ($rows as $r) {
      $id = isset($r['id']) ? intval($r['id']) : 0;
      $title = isset($r['title']) ? trim(wp_strip_all_tags($r['title'])) : '';
      $url = isset($r['url']) ? trim($r['url']) : '';
      if ($id > 0 && $title !== '') { $out[] = array('id'=>$id,'title'=>$title,'url'=>$url); }
    }
  }
  if (empty($out)) {
    if (defined('COMPANY_IDS') && is_array(COMPANY_IDS)) {
      foreach (COMPANY_IDS as $cid) { $out[] = array('id'=>intval($cid),'title'=>'Филиал '.intval($cid),'url'=>''); }
    } elseif (defined('COMPANY_ID')) { $out[] = array('id'=>intval(COMPANY_ID),'title'=>'Филиал '.intval(COMPANY_ID),'url'=>''); }
  }
  return $out;
}

function yc_pa_cache_ttl(){ return max(0, intval(get_option('yc_cache_ttl', 15))) * MINUTE_IN_SECONDS; }
function yc_pa_cache_get($k){ return get_transient('yc_pa_' . $k); }
function yc_pa_cache_set($k,$v){
  $ttl = yc_pa_cache_ttl();
  if ($ttl > 0) {
    set_transient('yc_pa_' . $k, $v, $ttl);
  }
}

function yc_pa_debug_enabled(){ return !!intval(get_option('yc_debug',0)); }
function yc_pa_multi_cats_enabled(){ return !!intval(get_option('yc_multi_categories',0)); }
function yc_pa_show_staff(){ return !!intval(get_option('yc_show_staff',1)); }
function yc_pa_vlist_page(){ $n=intval(get_option('yc_vlist_page',15)); return $n>4 ? $n : 15; }

function yc_pa_book_step(){ $s=get_option('yc_book_step','select-master'); return in_array($s, array('select-services','select-master'), true) ? $s : 'select-master'; }
function yc_pa_branch_url_tpl($branch){
  $tpl = '';
  if (is_array($branch) && !empty($branch['url'])) { $tpl = trim($branch['url']); }
  if ($tpl === '') {
    $tpl = get_option('yc_book_url_tpl','');
    $tpl = is_string($tpl) ? trim($tpl) : '';
  }
  if ($tpl !== '' && strpos($tpl,'{service_id}') === false) {
    if (strpos($tpl, '://') === false) { $tpl = 'https://' . ltrim($tpl, '/'); }
    $p = wp_parse_url($tpl);
    if (is_array($p) && !empty($p['host'])) {
      $origin = (isset($p['scheme']) ? $p['scheme'] : 'https') . '://' . $p['host'];
      if (!empty($p['port'])) $origin .= ':' . $p['port'];
      $tpl = $origin . '/company/{company_id}/personal/{book_step}?o=s{service_id}';
    } else {
      $base = rtrim($tpl, '/');
      $tpl = $base . '/company/{company_id}/personal/{book_step}?o=s{service_id}';
    }
  }
  return $tpl;
}
function yc_pa_build_booking_url($branch, $company_id, $service_id){
  $tpl = yc_pa_branch_url_tpl($branch); if ($tpl==='') return '';
  $rep = array(
    '{utm_source}'   => rawurlencode((string)get_option('yc_utm_source','site')),
    '{utm_medium}'   => rawurlencode((string)get_option('yc_utm_medium','price')),
    '{utm_campaign}' => rawurlencode((string)get_option('yc_utm_campaign','booking')),
    '{company_id}'   => intval($company_id),
    '{service_id}'   => intval($service_id),
    '{book_step}'    => yc_pa_book_step(),
  );
  return esc_url(strtr($tpl, $rep));
}

function yc_get_staff_link($branch_id, $staff_id){
  $map = get_option('yc_staff_links', array());
  if (!is_array($map)) $map = array();
  $branch_id = intval($branch_id);
  $staff_id = intval($staff_id);
  if (isset($map[$branch_id]) && is_array($map[$branch_id]) && !empty($map[$branch_id][$staff_id])){
    return esc_url($map[$branch_id][$staff_id]);
  }
  return '';
}

function yc_pa_get_manual_staff_order(){
  $branches = yc_get_branches();
  if (empty($branches)) {
    return array();
  }

  $out = array();
  foreach ($branches as $branch) {
    $cid = isset($branch['id']) ? (int) $branch['id'] : 0;
    if ($cid <= 0) continue;
    $staff = YC_API::get_staff($cid);
    if (empty($staff) || !is_array($staff)) continue;
    $key = (string) $cid;
    foreach ($staff as $st) {
      if (!is_array($st)) continue;
      $sid = isset($st['id']) ? (int) $st['id'] : (isset($st['staff_id']) ? (int) $st['staff_id'] : 0);
      if ($sid <= 0) continue;
      $weight = isset($st['sort_order']) ? (int) $st['sort_order'] : (isset($st['weight']) ? (int) $st['weight'] : 0);
      if (!isset($out[$key])) $out[$key] = array();
      $out[$key][$sid] = $weight;
    }
  }

  return $out;
}

function yc_pa_get_global_staff_order(){
  $company_map = yc_pa_get_manual_staff_order();
  if (empty($company_map)) {
    return array();
  }
  $global = array();
  foreach ($company_map as $weights) {
    foreach ($weights as $staff_id => $weight) {
      if (!isset($global[$staff_id]) || $weight < $global[$staff_id]) {
        $global[$staff_id] = $weight;
      }
    }
  }
  return $global;
}

function yc_sanitize_position($pos){
  // Accept string or array; remove purely numeric tokens (branch IDs etc), trim, collapse duplicates
  if (is_array($pos)) {
    $parts = array();
    foreach($pos as $p){
      $p = is_array($p) ? '' : trim((string)$p);
      if ($p === '') continue;
      if (preg_match('/^\d+$/', $p)) continue; // drop numeric-only
      $parts[] = $p;
    }
    $parts = array_unique($parts);
    $pos = implode(', ', $parts);
  } else {
    $pos = trim((string)$pos);
    // If it's just digits — drop
    if (preg_match('/^\d+$/', $pos)) $pos = '';
    // If it contains comma-separated values, filter numeric-only tokens
    if (strpos($pos, ',') !== false){
      $tokens = array_map('trim', explode(',', $pos));
      $clean = array();
      foreach($tokens as $t){
        if ($t === '') continue;
        if (preg_match('/^\d+$/', $t)) continue;
        $clean[] = $t;
      }
      $clean = array_unique($clean);
      $pos = implode(', ', $clean);
    }
  }
  return $pos;
}


function yc_normalize_name($name){
  $name = trim((string)$name);
  $name = preg_replace('/\s+/u',' ',$name);
  if (function_exists('mb_strtolower')) {
    $name = mb_strtolower($name, 'UTF-8');
  } else {
    $name = strtolower($name);
  }
  return $name;
}

function yc_pa_get_staff_sort_order($staff){
  if (!is_array($staff)) return 500;
  if (isset($staff['manual_order']) && $staff['manual_order'] !== '' && $staff['manual_order'] !== null) {
    if (is_numeric($staff['manual_order'])) return (int) $staff['manual_order'];
  }
  if (isset($staff['sort_order']) && $staff['sort_order'] !== '' && $staff['sort_order'] !== null) {
    if (is_numeric($staff['sort_order'])) return (int) $staff['sort_order'];
  }
  if (isset($staff['weight']) && $staff['weight'] !== '' && $staff['weight'] !== null) {
    if (is_numeric($staff['weight'])) return (int) $staff['weight'];
  }
  return 500;
}

function yc_pa_group_staff_member(&$groups, $staff, $branch_meta){
  if (!is_array($staff) || !is_array($branch_meta)) return;
  $name = isset($staff['name']) ? (string) $staff['name'] : '';
  $name_key = yc_normalize_name($name);
  if ($name_key === '') return;
  $branch_id = isset($branch_meta['id']) ? (int) $branch_meta['id'] : 0;
  if ($branch_id <= 0) return;
  $branch_title = isset($branch_meta['title']) ? $branch_meta['title'] : '';
  $staff_id = 0;
  if (isset($staff['id'])) $staff_id = (int) $staff['id'];
  if ($staff_id <= 0 && isset($staff['staff_id'])) $staff_id = (int) $staff['staff_id'];
  if ($staff_id <= 0) return;

  $order = yc_pa_get_staff_sort_order($staff);
  $position = isset($staff['position']) ? yc_sanitize_position($staff['position']) : '';
  $image = isset($staff['image_url']) ? (string) $staff['image_url'] : '';

  if (!isset($groups[$name_key])) {
    $groups[$name_key] = array(
      'name_key' => $name_key,
      'name' => $name,
      'position_set' => array(),
      'position' => '',
      'image_url' => $image,
      'manual_order' => $order,
      'primary_staff_id' => $staff_id,
      'primary_branch_id' => $branch_id,
      'primary_branch_title' => $branch_title,
      'branch_map' => array($branch_id => $staff_id),
      'branch_priority' => array($branch_id => $order),
      'branch_titles' => array($branch_id => $branch_title),
      'branch_entries' => array(),
      'ids' => array($staff_id => true),
    );
  } else {
    if ($groups[$name_key]['name'] === '' && $name !== '') {
      $groups[$name_key]['name'] = $name;
    }
    if ($groups[$name_key]['image_url'] === '' && $image !== '') {
      $groups[$name_key]['image_url'] = $image;
    }
    if ($order < $groups[$name_key]['manual_order']) {
      $groups[$name_key]['manual_order'] = $order;
      $groups[$name_key]['primary_staff_id'] = $staff_id;
      $groups[$name_key]['primary_branch_id'] = $branch_id;
      $groups[$name_key]['primary_branch_title'] = $branch_title;
    }
  }

  if (!isset($groups[$name_key]['branch_priority'][$branch_id]) || $order < $groups[$name_key]['branch_priority'][$branch_id]) {
    $groups[$name_key]['branch_priority'][$branch_id] = $order;
    $groups[$name_key]['branch_map'][$branch_id] = $staff_id;
  }
  $groups[$name_key]['branch_titles'][$branch_id] = $branch_title;
  $groups[$name_key]['ids'][$staff_id] = true;

  if ($position !== '') {
    $groups[$name_key]['position_set'][$position] = true;
  }

  if (!isset($groups[$name_key]['branch_entries'][$branch_id])) {
    $groups[$name_key]['branch_entries'][$branch_id] = array();
  }
  $groups[$name_key]['branch_entries'][$branch_id][$staff_id] = array(
    'staff_id' => $staff_id,
    'branch_id' => $branch_id,
    'branch_title' => $branch_title,
    'order' => $order,
    'position' => $position,
    'name' => $name,
    'image_url' => $image,
  );
}

function yc_pa_finalize_staff_groups($groups){
  if (!is_array($groups)) return array();
  foreach ($groups as $key => $entry) {
    $position = '';
    if (!empty($entry['position_set']) && is_array($entry['position_set'])) {
      $position = implode(', ', array_keys($entry['position_set']));
    }
    $entry['position'] = $position;
    $entry['manual_order'] = isset($entry['manual_order']) ? (int) $entry['manual_order'] : 500;
    $groups[$key] = $entry;
  }
  return $groups;
}
