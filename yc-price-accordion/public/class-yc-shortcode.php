<?php

if (!defined('ABSPATH')) exit;

class YC_Shortcode {
  public static function init(){
    add_shortcode('yclients_price', [__CLASS__,'render']);
    add_action('wp_enqueue_scripts', [__CLASS__,'assets']);
  }
  public static function assets(){
    wp_register_style('yc-accordion', YC_PA_URL . 'public/css/yc-accordion.css', array(), YC_PA_VER);
    wp_register_script('yc-accordion', YC_PA_URL . 'public/js/yc-accordion.js', array(), YC_PA_VER, true);
    // staff grid assets
    wp_register_style('yc-staff-grid', YC_PA_URL . 'public/yc-staff-grid.css', array(), YC_PA_VER);
    wp_register_style('yc-price-public', YC_PA_URL . 'public/yc-price-public.css', array(), YC_PA_VER);
    wp_register_script('yc-staff-sort', YC_PA_URL . 'public/yc-staff-sort.js', array('yc-accordion'), YC_PA_VER, true);
    // enqueue on front
    if(!is_admin()){
      wp_enqueue_style('yc-accordion');
      wp_enqueue_style('yc-staff-grid');
      wp_enqueue_style('yc-price-public');
      wp_enqueue_script('yc-accordion');
      wp_enqueue_script('yc-staff-sort');
    }
  }

  private static function format_service_price_text(array $service){
    $pmin = isset($service['price_min']) ? (float) $service['price_min'] : (isset($service['price']) ? (float) $service['price'] : 0.0);
    $pmax = isset($service['price_max']) ? (float) $service['price_max'] : $pmin;

    if ($pmin <= 0 && $pmax <= 0) {
      return '—';
    }

    $format = static function($value) {
      $decimals = abs($value - round($value)) > 0.001 ? 2 : 0;
      return number_format($value, $decimals, ',', ' ');
    };

    if ($pmin > 0 && $pmax > 0 && abs($pmax - $pmin) >= 0.01) {
      return $format($pmin) . '–' . $format($pmax) . ' ₽';
    }

    $single = $pmax > 0 ? $pmax : $pmin;
    return $format($single) . ' ₽';
  }

  private static function extract_service_category_name(array $service){
    $candidates = array('category_label', 'category_title', 'category_name', 'categoryName', 'categoryTitle', 'group', 'group_name', 'group_title');
    foreach ($candidates as $key) {
      if (!empty($service[$key]) && is_string($service[$key])) {
        return wp_strip_all_tags($service[$key]);
      }
    }
    if (!empty($service['category']) && is_array($service['category'])) {
      foreach ($candidates as $key) {
        if (!empty($service['category'][$key]) && is_string($service['category'][$key])) {
          return wp_strip_all_tags($service['category'][$key]);
        }
      }
      if (!empty($service['category']['title'])) {
        return wp_strip_all_tags($service['category']['title']);
      }
      if (!empty($service['category']['name'])) {
        return wp_strip_all_tags($service['category']['name']);
      }
    }
    return '';
  }

  private static function resolve_category_name($category_id, array $items, array $map){
    $cat_id = (int) $category_id;
    if ($cat_id === 0) {
      return __('Без категории', 'yc-price-accordion');
    }
    if (isset($map[$cat_id]) && $map[$cat_id] !== '') {
      return $map[$cat_id];
    }
    foreach ($items as $svc) {
      if (!is_array($svc)) {
        continue;
      }
      $name = self::extract_service_category_name($svc);
      if ($name !== '') {
        return $name;
      }
    }
    return sprintf(__('Категория #%d', 'yc-price-accordion'), $cat_id);
  }

  private static function render_staff_grid($branches, $filter_branch, $filter_single, $filter_multi){
    $groups = array();
    $use_filter = ($filter_single || !empty($filter_multi));

    $link_branches = $branches;
    if ($filter_branch) {
      $link_branches = array_filter($branches, static function($branch) use ($filter_branch) {
        return isset($branch['id']) && (int) $branch['id'] === $filter_branch;
      });
      if (empty($link_branches)) {
        $link_branches = $branches;
      }
    }

    foreach ($branches as $branch) {
      $cid = isset($branch['id']) ? (int) $branch['id'] : 0;
      if ($cid <= 0) {
        continue;
      }
      if ($filter_branch && $cid !== $filter_branch) {
        continue;
      }

      $staffIds = array();
      if ($use_filter) {
        $servicesMerged = array();
        if ($filter_single) {
          $servicesMerged = array_merge($servicesMerged, YC_API::get_services($cid, $filter_single));
        }
        if (!empty($filter_multi)) {
          foreach ($filter_multi as $catId) {
            $servicesMerged = array_merge($servicesMerged, YC_API::get_services($cid, $catId));
          }
        }
        $seenSvc = array();
        foreach ($servicesMerged as $svc) {
          $sid = isset($svc['id']) ? (int) $svc['id'] : 0;
          if ($sid > 0) {
            $seenSvc[$sid] = $svc;
          }
        }
        foreach ($seenSvc as $svc) {
          if (!empty($svc['staff']) && is_array($svc['staff'])) {
            foreach ($svc['staff'] as $st) {
              if (!is_array($st)) {
                continue;
              }
              $sid = isset($st['id']) ? (int) $st['id'] : 0;
              if ($sid > 0) {
                $staffIds[$sid] = true;
              }
            }
          }
        }
      } else {
        $allStaff = YC_API::get_staff($cid);
        foreach ($allStaff as $sid => $st) {
          $sid = (int) $sid;
          if ($sid > 0) {
            $staffIds[$sid] = true;
          }
        }
      }

      if (empty($staffIds)) {
        continue;
      }

      $staffMap = YC_API::get_staff($cid);
      if (empty($staffMap)) {
        $servicesAll = YC_API::get_services($cid, null);
        foreach ($servicesAll as $svc) {
          if (empty($svc['staff']) || !is_array($svc['staff'])) {
            continue;
          }
          foreach ($svc['staff'] as $st) {
            if (!is_array($st)) {
              continue;
            }
            $sid = isset($st['id']) ? (int) $st['id'] : 0;
            if ($sid <= 0) {
              continue;
            }
            if (!isset($staffMap[$sid])) {
              $staffMap[$sid] = array(
                'id'        => $sid,
                'name'      => isset($st['name']) ? $st['name'] : '',
                'image_url' => isset($st['image_url']) ? $st['image_url'] : '',
                'position'  => '',
                'sort_order'=> 0,
              );
            }
          }
        }
      }

      foreach ($staffMap as $sid => $st) {
        $sid = (int) $sid;
        if ($sid <= 0) {
          continue;
        }
        if ($use_filter && !isset($staffIds[$sid])) {
          continue;
        }
        if (!isset($st['name'])) {
          $st['name'] = '';
        }
        if (!isset($st['image_url'])) {
          $st['image_url'] = '';
        }
        $pos = isset($st['position']) ? $st['position'] : '';
        if (is_array($pos)) {
          $pos = implode(', ', array_filter($pos));
        }
        $pos = yc_sanitize_position($pos);

        $manual_weight = null;
        if (isset($st['sort_order']) && $st['sort_order'] !== null && $st['sort_order'] !== '') {
          $manual_weight = (int) $st['sort_order'];
        }

        $payload = array(
          'id'           => $sid,
          'name'         => $st['name'],
          'image_url'    => $st['image_url'],
          'position'     => $pos,
          'manual_order' => $manual_weight,
          'sort_order'   => $manual_weight,
        );

        $branch_meta = array(
          'id'    => $cid,
          'title' => isset($branch['title']) ? $branch['title'] : '',
        );

        yc_pa_group_staff_member($groups, $payload, $branch_meta);
      }
    }

    if (empty($groups)) {
      return '<div class="yc-price-empty">Нет специалистов по выбранным категориям.</div>';
    }

    $groups = yc_pa_finalize_staff_groups($groups);
    $list = array();
    foreach ($groups as $entry) {
      $list[] = array(
        'id'           => isset($entry['primary_staff_id']) ? (int) $entry['primary_staff_id'] : 0,
        'name'         => isset($entry['name']) ? $entry['name'] : '',
        'image_url'    => isset($entry['image_url']) ? $entry['image_url'] : '',
        'position'     => isset($entry['position']) ? $entry['position'] : '',
        'manual_order' => isset($entry['manual_order']) ? (int) $entry['manual_order'] : 500,
        'branch_id'    => isset($entry['primary_branch_id']) ? (int) $entry['primary_branch_id'] : 0,
        'branch_title' => isset($entry['primary_branch_title']) ? $entry['primary_branch_title'] : '',
        'branch_map'   => isset($entry['branch_map']) && is_array($entry['branch_map']) ? $entry['branch_map'] : array(),
        'branch_titles'=> isset($entry['branch_titles']) && is_array($entry['branch_titles']) ? $entry['branch_titles'] : array(),
      );
    }

    if (empty($list)) {
      return '<div class="yc-price-empty">Нет специалистов по выбранным категориям.</div>';
    }

    usort($list, static function($a, $b) {
      $ma = isset($a['manual_order']) && $a['manual_order'] !== null ? (int) $a['manual_order'] : PHP_INT_MAX;
      $mb = isset($b['manual_order']) && $b['manual_order'] !== null ? (int) $b['manual_order'] : PHP_INT_MAX;
      if ($ma !== $mb) {
        return $ma <=> $mb;
      }

      $na = isset($a['name']) ? (string) $a['name'] : '';
      $nb = isset($b['name']) ? (string) $b['name'] : '';
      $cmp = strcasecmp($na, $nb);
      if ($cmp !== 0) {
        return $cmp;
      }

      $ba = isset($a['branch_title']) ? (string) $a['branch_title'] : '';
      $bb = isset($b['branch_title']) ? (string) $b['branch_title'] : '';
      return strcasecmp($ba, $bb);
    });

    $title = esc_html(get_option('yc_title_staff', 'Специалисты'));
    $version_attr = esc_attr(YC_PA_VER);

    $render_link = static function($branches, $staff) {
      $ordered_branches = array();
      if (isset($staff['branch_map']) && is_array($staff['branch_map']) && !empty($staff['branch_map'])) {
        $primary_branch = isset($staff['branch_id']) ? (int) $staff['branch_id'] : 0;
        if ($primary_branch > 0 && isset($staff['branch_map'][$primary_branch])) {
          $ordered_branches[$primary_branch] = (int) $staff['branch_map'][$primary_branch];
        }
        foreach ($staff['branch_map'] as $branch_id => $staff_id) {
          $bid = (int) $branch_id;
          $sid = (int) $staff_id;
          if ($bid <= 0 || $sid <= 0) {
            continue;
          }
          if (!isset($ordered_branches[$bid])) {
            $ordered_branches[$bid] = $sid;
          }
        }
        foreach ($ordered_branches as $branch_id => $staff_id) {
          $href = yc_get_staff_link($branch_id, $staff_id);
          if ($href) {
            return $href;
          }
        }
      }
      $staff_id = isset($staff['id']) ? (int) $staff['id'] : 0;
      if ($staff_id <= 0) {
        return '';
      }
      foreach ($branches as $branch) {
        $branch_id = isset($branch['id']) ? (int) $branch['id'] : 0;
        if ($branch_id <= 0) {
          continue;
        }
        $href = yc_get_staff_link($branch_id, $staff_id);
        if ($href) {
          return $href;
        }
      }
      return '';
    };

    ob_start();
    echo '<div class="yc-staff-grid-wrap">';
    echo '<div class="yc-block-title">' . $title . '</div>';
    echo '<div class="yc-staff-grid" data-ycpa-version="' . $version_attr . '">';

    foreach ($list as $staff) {
      $name = isset($staff['name']) ? $staff['name'] : '';
      $image = isset($staff['image_url']) ? $staff['image_url'] : '';
      $position = isset($staff['position']) ? $staff['position'] : '';
      $id = isset($staff['id']) ? (int) $staff['id'] : 0;
      $href = $render_link($link_branches, $staff);
      $order_attr = isset($staff['manual_order']) && $staff['manual_order'] !== null ? (int) $staff['manual_order'] : 9999;

      echo '<div class="yc-staff-card" data-order="' . esc_attr($order_attr) . '">';
      if ($href) {
        echo '<a class="yc-staff-photo" href="' . esc_url($href) . '" target="_blank" rel="noopener nofollow">';
      } else {
        echo '<span class="yc-staff-photo">';
      }

      if ($image) {
        echo '<img src="' . esc_url($image) . '" alt="" loading="lazy" />';
      } else {
        echo '<span class="yc-staff-ph" aria-hidden="true"></span>';
      }

      if ($href) {
        echo '</a>';
      } else {
        echo '</span>';
      }

      echo '<div class="yc-staff-meta">';
      $name_html = esc_html($name);
      if ($href) {
        $name_html = '<a href="' . esc_url($href) . '" target="_blank" rel="noopener nofollow">' . $name_html . '</a>';
      }
      echo '<div class="yc-staff-name">' . $name_html . '</div>';
      if ($position) {
        echo '<div class="yc-staff-pos">' . esc_html($position) . '</div>';
      }
      echo '</div>';
      echo '</div>';
    }

    echo '</div></div>';
    return ob_get_clean();
  }

  public static function render($atts=array()){
    $atts = shortcode_atts(array('branch_id'=>'','category_id'=>'','category_ids'=>''), $atts, 'yclients_price');

    $branches = yc_get_branches();
    if (empty($branches)) return '<div class="yc-price-empty">Не настроены филиалы.</div>';

    $filter_branch = $atts['branch_id']!=='' ? intval($atts['branch_id']) : null;

    $filter_cat = null;
    $filter_ids = array();

    $raw_category = trim((string) $atts['category_id']);
    if ($raw_category !== '') {
      $cat_parts = array_values(array_filter(array_map('trim', explode(',', $raw_category)), 'strlen'));
      if (count($cat_parts) > 1) {
        foreach ($cat_parts as $part) {
          $value = intval($part);
          if ($value > 0) {
            $filter_ids[] = $value;
          }
        }
      } elseif (count($cat_parts) === 1) {
        $filter_cat = intval($cat_parts[0]);
      }
    }

    $raw_category_ids = trim((string) $atts['category_ids']);
    if ($raw_category_ids !== '') {
      $multi_parts = array_values(array_filter(array_map('trim', explode(',', $raw_category_ids)), 'strlen'));
      foreach ($multi_parts as $part) {
        $value = intval($part);
        if ($value > 0) {
          $filter_ids[] = $value;
        }
      }
    }

    if (!empty($filter_ids)) {
      $filter_ids = array_values(array_unique($filter_ids));
      $filter_cat = null; // multi-selection overrides single category filter
    }

    // Build dataset for price list
    $dataset = array();
    foreach($branches as $b){
      $cid = intval($b['id']);
      if ($filter_branch && $cid !== $filter_branch) continue;
      $cats = YC_API::get_categories($cid);
      $services = YC_API::get_services($cid, $filter_cat);
      $grouped = array();
      foreach($services as $svc){
        $cat_id = intval(isset($svc['category_id'])?$svc['category_id']:0);
        if ($filter_cat && $cat_id!==$filter_cat) continue;
        if (!empty($filter_ids) && !in_array($cat_id,$filter_ids,true)) continue;
        // Skip services without staff
        if (empty($svc['staff']) || !is_array($svc['staff'])) continue;
        $hasStaff=false; foreach($svc['staff'] as $st){ if(!empty($st['id'])){ $hasStaff=true; break; } }
        if(!$hasStaff) continue;
        if (!isset($grouped[$cat_id])) $grouped[$cat_id] = array('category_id'=>$cat_id,'items'=>array());
        $svc_id = isset($svc['id']) ? (int) $svc['id'] : 0;
        $svc['company_id'] = $cid; $svc['company_title'] = $b['title']; $svc['branch'] = $b;
        $svc['display_price'] = self::format_service_price_text($svc);
        $svc['booking_url'] = $svc_id > 0 ? yc_pa_build_booking_url($b, $cid, $svc_id) : '';
        $grouped[$cat_id]['items'][] = $svc;
      }
      $categories=array();
      foreach($grouped as $cat_id=>$row){
        $cat_name = self::resolve_category_name($cat_id, isset($row['items']) ? $row['items'] : array(), $cats);
        $cat_label = $cat_name;
        $categories[] = array(
          'category_id'    => $cat_id,
          'category_name'  => $cat_name,
          'category_label' => $cat_label,
          'items'          => $row['items']
        );
      }
      usort($categories,function($a,$b){ return strcasecmp($a['category_name'],$b['category_name']); });
      if (!empty($categories)) {
        $dataset[] = array('branch'=>$b,'categories'=>$categories);
      }
    }

    wp_enqueue_style('yc-accordion');
    wp_enqueue_script('yc-accordion');
    $page = yc_pa_vlist_page();

    ob_start();
    if (yc_pa_debug_enabled() && current_user_can('manage_options')){
      echo '<div class="yc-pa-debug" style="margin:12px 0;padding:12px;border:1px dashed #c2410c;background:#fff7ed;">';
      echo '<strong>DEBUG:</strong> Кэш выключен. Проверка API.';
      if (!empty(YC_API::$debug_log)){
        echo '<details style="margin-top:6px;"><summary>API логи ('.count(YC_API::$debug_log).')</summary><pre style="white-space:pre-wrap;">'.esc_html(json_encode(YC_API::$debug_log, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details>';
      }
      echo '</div>';
    }

    // Price block with top margin for separation
    echo '<div class="yc-accordion-section">';
    $yc_price_t = esc_html( get_option('yc_title_price','Прайс - лист') );
    echo '<div class="yc-block-title">'.$yc_price_t.'</div>';
    echo '<div class="yc-accordion" role="tablist" aria-multiselectable="true" data-page="'.esc_attr($page).'">';
    foreach($dataset as $row){
      $branch=$row['branch']; $panel_id='yc-acc-'.esc_attr($branch['id']);
      echo '<div class="yc-acc-item" data-branch="'.esc_attr($branch['id']).'">';
      echo '<button class="yc-acc-header" aria-expanded="false" aria-controls="'.$panel_id.'"><span class="yc-acc-title">'.esc_html(!empty($branch['title']) ? $branch['title'] : 'Филиал').'</span><span class="yc-acc-icon" aria-hidden="true">+</span></button>';
      echo '<div id="'.$panel_id.'" class="yc-acc-content" role="region" aria-hidden="true">';
      if (empty($row['categories'])){
        echo '<div class="yc-acc-empty">Нет доступных услуг.</div>';
      } else {
        foreach($row['categories'] as $cat){
          $items = $cat['items'];
          $total = count($items); $initial = array_slice($items,0,$page); $rest = array_slice($items,$page);
          echo '<div class="yc-cat" data-category="'.esc_attr($cat['category_id']).'">';
          $cat_label = isset($cat['category_label']) ? $cat['category_label'] : (isset($cat['category_name']) ? $cat['category_name'] : 'Категория');
          echo '<div class="yc-cat-title">'.esc_html($cat_label).($total>$page?' · <span class="yc-cat-count">'.intval($total).'</span>':'').'</div>';
          $rest_attr = '';
          if (!empty($rest)) {
            $rest_attr = ' data-rest="' . esc_attr(wp_json_encode($rest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
          }
          echo '<ul class="yc-services"'.$rest_attr.'>';
          foreach($initial as $svc){
            $name = isset($svc['title'])?$svc['title']:(isset($svc['name'])?$svc['name']:'');
            $sid  = intval(isset($svc['id'])?$svc['id']:0);
            $price_txt = isset($svc['display_price']) ? $svc['display_price'] : self::format_service_price_text($svc);
            $book_url = '';
            if (!empty($svc['booking_url'])) {
              $book_url = $svc['booking_url'];
            } else {
              $branch_arr = isset($svc['branch'])?$svc['branch']:array('id'=>intval($svc['company_id']));
              $book_url = yc_pa_build_booking_url($branch_arr, intval($svc['company_id']), $sid);
            }
            echo '<li class="yc-service"><div class="yc-service-row"><div class="yc-service-name">'.esc_html($name).'</div><div class="yc-service-right"><div class="yc-service-price">'.esc_html($price_txt).'</div>';
            if ($book_url) echo '<a class="yc-book-btn" href="'.esc_url($book_url).'" target="_blank" rel="noopener nofollow">Записаться</a>';
            echo '</div></div></li>';
          }
          echo '</ul>';
          if (!empty($rest)) echo '<button class="yc-load-more" type="button">Показать ещё</button>';
          echo '</div>';
        }
      }
      echo '</div></div>';
    }
    echo '</div></div>'; // accordion and section

    // Specialists block (optional, displayed after price)
    if (yc_pa_show_staff()){
      echo '<div class="yc-staff-section">';
      echo self::render_staff_grid($branches, $filter_branch, $filter_cat, $filter_ids);
      echo '</div>';
    }

    return ob_get_clean();
  }
}
