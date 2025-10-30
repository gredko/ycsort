<?php

if ( ! function_exists( 'yc_sort_staff_manual' ) ) {
    function yc_sort_staff_manual( $staff, $company_id ) {
        $manual = get_option( 'yc_staff_order', array() );
        $map = ( isset( $manual[ $company_id ] ) && is_array( $manual[ $company_id ] ) ) ? $manual[ $company_id ] : array();
        if ( empty( $map ) || ! is_array( $staff ) ) return $staff;
        usort( $staff, function( $a, $b ) use ( $map ) {
            $ida = isset($a['id']) ? (int)$a['id'] : ( isset($a->id)? (int)$a->id : 0 );
            $idb = isset($b['id']) ? (int)$b['id'] : ( isset($b->id)? (int)$b->id : 0 );
            $wa = isset($map[$ida]) ? (int)$map[$ida] : 9999;
            $wb = isset($map[$idb]) ? (int)$map[$idb] : 9999;
            if ( $wa === $wb ) {
                $na = isset($a['name']) ? $a['name'] : ( isset($a->name)? $a->name : '' );
                $nb = isset($b['name']) ? $b['name'] : ( isset($b->name)? $b->name : '' );
                return strcasecmp( (string)$na, (string)$nb );
            }
            return ( $wa < $wb ) ? -1 : 1;
        });
        return $staff;
    }
}

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

  private static function render_staff_grid($branches, $filter_branch, $filter_single, $filter_multi){
    // Collect staff per category filters; global dedup by normalized name (handles cross-branch duplicates)
    $list = array(); 
    $by_name = array(); // key => staff array
    $use_filter = ($filter_single || !empty($filter_multi));

    foreach($branches as $b){
      $cid = intval($b['id']);
      if ($filter_branch && $cid !== $filter_branch) continue;

      $staffIds = array();
      if ($use_filter){
        $servicesMerged = array();
        if ($filter_single){ $servicesMerged = array_merge($servicesMerged, YC_API::get_services($cid, $filter_single)); }
        if (!empty($filter_multi)){ foreach($filter_multi as $catId){ $servicesMerged = array_merge($servicesMerged, YC_API::get_services($cid, $catId)); } }
        $seenSvc = array();
        foreach($servicesMerged as $svc){
          $sid = isset($svc['id'])?intval($svc['id']):0;
          if ($sid>0) $seenSvc[$sid]=$svc;
        }
        foreach($seenSvc as $svc){
          if (!empty($svc['staff']) && is_array($svc['staff'])){
            foreach($svc['staff'] as $st){
              if (!is_array($st)) continue;
              $sid = isset($st['id'])?intval($st['id']):0;
              if ($sid>0) $staffIds[$sid]=true;
            }
          }
        }
      } else {
        $allStaff = YC_API::get_staff($cid);
        foreach($allStaff as $sid=>$st){ $staffIds[intval($sid)] = true; }
      }

      if (empty($staffIds)) continue;
      $staffMap = YC_API::get_staff($cid);
      if (empty($staffMap)){
        $servicesAll = YC_API::get_services($cid, null);
        foreach($servicesAll as $svc){
          if (!empty($svc['staff']) && is_array($svc['staff'])){
            foreach($svc['staff'] as $st){
              if (!is_array($st)) continue;
              $sid = isset($st['id'])?intval($st['id']):0;
              if ($sid<=0) continue;
              if (!isset($staffMap[$sid])){
                $staffMap[$sid]=array('id'=>$sid,'name'=>isset($st['name'])?$st['name']:'','image_url'=>isset($st['image_url'])?$st['image_url']:'','position'=>'');
              }
            }
          }
        }
      }

      foreach(array_keys($staffIds) as $sid){
        $st = isset($staffMap[$sid]) ? $staffMap[$sid] : array('id'=>$sid);
        if (!isset($st['name'])) $st['name']='';
        if (!isset($st['image_url'])) $st['image_url']='';
        $pos = isset($st['position']) ? $st['position'] : '';
        if (is_array($pos)) $pos = implode(', ', array_filter($pos));
        $pos = yc_sanitize_position($pos);
        $nameKey = yc_normalize_name($st['name']);
        if ($nameKey==='') continue;

        if (isset($by_name[$nameKey])){
          // Merge: prefer non-empty avatar/position
          if (empty($by_name[$nameKey]['image_url']) && !empty($st['image_url'])) $by_name[$nameKey]['image_url'] = $st['image_url'];
          if (empty($by_name[$nameKey]['position']) && !empty($pos)) $by_name[$nameKey]['position'] = $pos;
        } else {
          $by_name[$nameKey] = array(
            'id'=>$sid,
            'name'=>$st['name'],
            'image_url'=>$st['image_url'],
            'position'=>$pos,
          );
        }
      }
    }

    if (empty($by_name)) return '<div class="yc-price-empty">Нет специалистов по выбранным категориям.</div>';
    // Transform to list and sort
    $list = array_values($by_name);
    if (empty($list)) return '<div class="yc-price-empty">Нет специалистов по выбранным категориям.</div>';
    uasort($list, function($a,$b){ return strcasecmp(isset($a['name'])?$a['name']:'', isset($b['name'])?$b['name']:''); });

    ob_start(); ?>
    <div class="yc-staff-grid-wrap">
      <?php $yc_staff_t = esc_html( get_option('yc_title_staff', 'Специалисты') ); echo '<div class="yc-block-title">'.$yc_staff_t.'</div>'; ?>
      <div class="yc-staff-grid" data-ycpa-version="1.6.20">
        <?php foreach($list as $st):
          $img = isset($st['image_url'])?$st['image_url']:'';
          $name= isset($st['name'])?$st['name']:' ';
          $pos = isset($st['position'])?yc_sanitize_position($st['position']):'';
          // Choose any branch for link lookup; prefer first seen
          $href = '';
          if (!empty($branches)){
            foreach($branches as $b){
              $href = yc_get_staff_link(intval($b['id']), intval($st['id']));
              if ($href) break;
            }
          }
        ?>
        <div class="yc-staff-card" data-order="<?php $___cid = isset($b['id']) ? intval($b['id']) : (isset($cid)?intval($cid):(isset($branches[0]['id'])?intval($branches[0]['id']):0)); $___sid = isset($st['id'])?intval($st['id']):0; echo isset($weights[$___cid][$___sid]) ? intval($weights[$___cid][$___sid]) : (is_numeric($pos)?intval($pos):0); ?>">
          <a class="yc-staff-photo" <?php if($href): ?>href="<?php echo esc_url($href); ?>" target="_blank" rel="noopener nofollow"<?php endif; ?>>
            <?php if($img): ?><img src="<?php echo esc_url($img); ?>" alt="" loading="lazy" /><?php else: ?><span class="yc-staff-ph" aria-hidden="true"></span><?php endif; ?>
          </a>
          <div class="yc-staff-meta">
            <div class="yc-staff-name"><?php if($href){ echo '<a href="'.esc_url($href).'" target="_blank" rel="noopener nofollow">'.esc_html($name).'</a>'; } else { echo esc_html($name); } ?></div>
            <?php if($pos): ?><div class="yc-staff-pos"><?php echo esc_html($pos); ?></div><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  public static function render($atts=array()){
    $atts = shortcode_atts(array('branch_id'=>'','category_id'=>'','category_ids'=>''), $atts, 'yclients_price');

    $branches = yc_get_branches();
    if (empty($branches)) return '<div class="yc-price-empty">Не настроены филиалы.</div>';

    $filter_branch = $atts['branch_id']!=='' ? intval($atts['branch_id']) : null;
    $filter_cat    = $atts['category_id']!=='' ? intval($atts['category_id']) : null;
    $filter_ids = array();
    if (yc_pa_multi_cats_enabled() && !empty($atts['category_ids'])){
      foreach(explode(',', $atts['category_ids']) as $p){
        $v = intval(trim($p)); if($v>0) $filter_ids[]=$v;
      }
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
        $svc['company_id'] = $cid; $svc['company_title'] = $b['title']; $svc['branch'] = $b;
        $grouped[$cat_id]['items'][] = $svc;
      }
      $categories=array();
      foreach($grouped as $cat_id=>$row){
        $cat_name = isset($cats[$cat_id])?$cats[$cat_id]:('Категория #'.$cat_id);
        $categories[] = array('category_id'=>$cat_id,'category_name'=>$cat_name,'items'=>$row['items']);
      }
      usort($categories,function($a,$b){ return strcasecmp($a['category_name'],$b['category_name']); });
      $dataset[] = array('branch'=>$b,'categories'=>$categories);
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

    // Specialists block (optional, before price) — now with extra spacing
    if (yc_pa_show_staff()){
      echo '<div class="yc-staff-section">';
      echo self::render_staff_grid($branches, $filter_branch, $filter_cat, $filter_ids);
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
          echo '<div class="yc-cat-title">'.esc_html($cat['category_name']).($total>$page?' · <span class="yc-cat-count">'.intval($total).'</span>':'').'</div>';
          echo '<ul class="yc-services"'.(!empty($rest)?' data-rest="'.esc_attr(json_encode($rest)).'"':'').'>';
          foreach($initial as $svc){
            $name = isset($svc['title'])?$svc['title']:(isset($svc['name'])?$svc['name']:'');
            $sid  = intval(isset($svc['id'])?$svc['id']:0);
            $pmin = floatval(isset($svc['price_min'])?$svc['price_min']:(isset($svc['price'])?$svc['price']:0));
            $pmax = floatval(isset($svc['price_max'])?$svc['price_max']:0);
            $price_txt = ($pmax && $pmax!=$pmin)?number_format($pmin,0,',',' ').'–'.number_format($pmax,0,',',' ').' ₽':number_format($pmin,0,',',' ').' ₽';
            $branch_arr = isset($svc['branch'])?$svc['branch']:array('id'=>intval($svc['company_id']));
            $book_url = yc_pa_build_booking_url($branch_arr, intval($svc['company_id']), $sid);
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

    return ob_get_clean();
  }
}
