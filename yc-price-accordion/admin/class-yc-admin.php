<?php
if (!defined('ABSPATH')) exit;

class YC_Admin {
  const OPTION_BRANCHES='yc_branches';
  const OPTION_CACHE_TTL='yc_cache_ttl';
  const OPTION_DEBUG='yc_debug';
  const OPTION_PARTNER='yc_partner_token';
  const OPTION_USER='yc_user_token';
  const OPTION_MULTI_CATEGORIES='yc_multi_categories';
  const OPTION_BOOK_URL_TPL='yc_book_url_tpl';
  const OPTION_BOOK_STEP='yc_book_step';
  const OPTION_UTM_SOURCE='yc_utm_source';
  const OPTION_UTM_MEDIUM='yc_utm_medium';
  const OPTION_UTM_CAMPAIGN='yc_utm_campaign';
  const OPTION_VLIST_PAGE='yc_vlist_page';
  const OPTION_STAFF_LINKS='yc_staff_links';
  const OPTION_SHOW_STAFF='yc_show_staff';
  const OPTION_TITLE_STAFF='yc_title_staff';
  const OPTION_TITLE_PRICE='yc_title_price';
  const OPTION_LAST_SYNC='yc_pa_last_sync';

  public static function init(){
    add_action('admin_menu',[__CLASS__,'menu']);
    add_action('admin_init',[__CLASS__,'settings']);
    add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
    add_action('admin_post_yc_pa_sync',[__CLASS__,'handle_sync']);
    foreach([self::OPTION_BRANCHES,self::OPTION_CACHE_TTL,self::OPTION_PARTNER,self::OPTION_USER] as $opt){
      add_action('update_option_'.$opt,[__CLASS__,'flush_cache'],10,3);
    }
  }

  public static function menu(){
    add_options_page('YClients Прайс','YClients Прайс','manage_options','yc-price-settings',[__CLASS__,'render_page']);
  }

  public static function settings(){
    register_setting(
      'yc_price_group',
      'yc_staff_order',
      array(
        'type'              => 'array',
        'sanitize_callback' => array(__CLASS__, 'sanitize_staff_order'),
        'default'           => array(),
      )
    );

    register_setting('yc_price_group', self::OPTION_BRANCHES, array('type' => 'array',   'sanitize_callback' => [__CLASS__, 'sanitize_branches'],   'default' => array()));
    register_setting('yc_price_group', self::OPTION_CACHE_TTL, array('type' => 'integer','sanitize_callback' => [__CLASS__, 'sanitize_int_nonneg'],'default' => 15));
    register_setting('yc_price_group', self::OPTION_DEBUG,     array('type' => 'boolean','sanitize_callback' => [__CLASS__, 'sanitize_bool'],      'default' => 0));
    register_setting('yc_price_group', self::OPTION_PARTNER,   array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field',            'default' => ''));
    register_setting('yc_price_group', self::OPTION_USER,      array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field',            'default' => ''));

    register_setting('yc_price_group', self::OPTION_MULTI_CATEGORIES, array('type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_bool'], 'default' => 0));
    register_setting('yc_price_group', self::OPTION_SHOW_STAFF,        array('type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_bool'], 'default' => 1));

    register_setting('yc_price_group', self::OPTION_BOOK_URL_TPL, array('type' => 'string', 'sanitize_callback' => 'esc_url_raw',                  'default' => ''));
    register_setting('yc_price_group', self::OPTION_BOOK_STEP,    array('type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_book_step'],'default' => 'select-master'));

    register_setting('yc_price_group', self::OPTION_UTM_SOURCE,   array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field',          'default' => 'site'));
    register_setting('yc_price_group', self::OPTION_UTM_MEDIUM,   array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field',          'default' => 'price'));
    register_setting('yc_price_group', self::OPTION_UTM_CAMPAIGN, array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field',          'default' => 'booking'));

    register_setting('yc_price_group', self::OPTION_VLIST_PAGE, array('type' => 'integer','sanitize_callback' => [__CLASS__, 'sanitize_int_nonneg'],'default' => 15));
    register_setting('yc_price_group', self::OPTION_STAFF_LINKS, array('type' => 'array',  'sanitize_callback' => [__CLASS__, 'sanitize_staff_links'],'default' => array()));
    register_setting('yc_price_group', self::OPTION_TITLE_STAFF, array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field',           'default' => 'Специалисты'));
    register_setting('yc_price_group', self::OPTION_TITLE_PRICE, array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field',           'default' => 'Прайс - лист'));

    add_settings_section('yc_price_section', 'Настройки YClients', '__return_false', 'yc-price-settings');
    add_settings_field(self::OPTION_BRANCHES,        'Филиалы',                      [__CLASS__, 'field_branches'],        'yc-price-settings', 'yc_price_section');
    add_settings_field(self::OPTION_CACHE_TTL,       'Кэш, минут',                   [__CLASS__, 'field_cache_ttl'],       'yc-price-settings', 'yc_price_section');
    add_settings_field(self::OPTION_DEBUG,           'Временный debug',              [__CLASS__, 'field_debug'],           'yc-price-settings', 'yc_price_section');
    add_settings_field(self::OPTION_MULTI_CATEGORIES,'Фильтр по нескольким категориям',[__CLASS__, 'field_multi_categories'],'yc-price-settings', 'yc_price_section');
    add_settings_field(self::OPTION_SHOW_STAFF,      'Показывать блок «Специалисты»',[__CLASS__, 'field_show_staff'],      'yc-price-settings', 'yc_price_section');
    add_settings_field('yc_titles',                  'Заголовки блоков',             [__CLASS__, 'field_titles'],         'yc-price-settings', 'yc_price_section');
    add_settings_field(self::OPTION_BOOK_URL_TPL,    'Шаблон URL записи',            [__CLASS__, 'field_book_url'],       'yc-price-settings', 'yc_price_section');
    add_settings_field(self::OPTION_BOOK_STEP,       'Шаг на YClients',              [__CLASS__, 'field_book_step'],      'yc-price-settings', 'yc_price_section');
    add_settings_field(self::OPTION_UTM_SOURCE,      'UTM source/medium/campaign',   [__CLASS__, 'field_utms'],           'yc-price-settings', 'yc_price_section');
    add_settings_field(self::OPTION_VLIST_PAGE,      'Ленивая подгрузка — порция',   [__CLASS__, 'field_vlist'],          'yc-price-settings', 'yc_price_section');

    add_settings_section('yc_staff_section', 'Ссылки на страницы специалистов', '__return_false', 'yc-price-settings');
    add_settings_field(self::OPTION_STAFF_LINKS, 'Специалисты по филиалам', [__CLASS__, 'field_staff_links'], 'yc-price-settings', 'yc_staff_section');
  }

  public static function sanitize_book_step($v){ $v=strtolower(trim((string)$v)); return in_array($v,array('select-services','select-master'),true)?$v:'select-master'; }
  public static function sanitize_branches($val){
    $out=array(); if(is_array($val)){
      foreach($val as $row){
        $id=isset($row['id'])?intval($row['id']):0;
        $title=isset($row['title'])?trim(wp_strip_all_tags($row['title'])):'';
        $url=isset($row['url'])?esc_url_raw($row['url']):'';
        if($id>0 && $title!=='') $out[]=array('id'=>$id,'title'=>$title,'url'=>$url);
      }
    }
    return $out;
  }
  public static function sanitize_int_nonneg($v){ $v=intval($v); return $v<0?0:$v; }
  public static function sanitize_bool($v){ return $v?1:0; }
  public static function sanitize_staff_links($val){
    $out=array();
    if (is_array($val)){
      foreach($val as $branch_id=>$staffs){
        $bid=intval($branch_id); if($bid<=0) continue;
        if (!is_array($staffs)) continue;
        foreach($staffs as $sid=>$url){
          $sid_i=intval($sid); $u=esc_url_raw((string)$url);
          if ($sid_i>0 && $u!==''){
            if (!isset($out[$bid])) $out[$bid]=array();
            $out[$bid][$sid_i]=$u;
          }
        }
      }
    }
    return $out;
  }

  public static function field_branches(){
    $branches=get_option(self::OPTION_BRANCHES,array());
    if(!is_array($branches)) $branches=array();
    ?>
    <div id="yc-branches-wrap">
      <table class="widefat striped yc-admin-table">
        <thead><tr><th style="width:150px;">Company ID</th><th>Название филиала</th><th>URL онлайн-записи (можно только домен)</th><th style="width:120px;">Действие</th></tr></thead>
        <tbody id="yc-branches-body">
          <?php if(empty($branches)): ?>
          <tr>
            <td><input class="regular-text" type="number" min="1" name="<?php echo esc_attr(self::OPTION_BRANCHES); ?>[0][id]" required></td>
            <td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_BRANCHES); ?>[0][title]" required></td>
            <td><input class="regular-text" type="text" placeholder="https://nXXXX.yclients.com/" name="<?php echo esc_attr(self::OPTION_BRANCHES); ?>[0][url]"></td>
            <td><button type="button" class="button button-secondary yc-remove-row">Удалить</button></td>
          </tr>
          <?php else: foreach($branches as $i=>$b): ?>
          <tr>
            <td><input class="regular-text" type="number" min="1" name="<?php echo esc_attr(self::OPTION_BRANCHES); ?>[<?php echo esc_attr($i); ?>][id]" value="<?php echo esc_attr(isset($b['id'])?$b['id']:''); ?>" required></td>
            <td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_BRANCHES); ?>[<?php echo esc_attr($i); ?>][title]" value="<?php echo esc_attr(isset($b['title'])?$b['title']:''); ?>" required></td>
            <td><input class="regular-text" type="text" placeholder="https://nXXXX.yclients.com/" name="<?php echo esc_attr(self::OPTION_BRANCHES); ?>[<?php echo esc_attr($i); ?>][url]" value="<?php echo esc_attr(isset($b['url'])?$b['url']:''); ?>"></td>
            <td><button type="button" class="button button-secondary yc-remove-row">Удалить</button></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      <p><button type="button" class="button button-primary" id="yc-add-row">Добавить филиал</button></p>
      <p class="description">Можно указать только домен — плагин сам соберёт ссылку: <code>/company/{company_id}/personal/{book_step}?o=s{service_id}</code></p>
    </div>
    <?php
  }

  public static function field_cache_ttl(){
    $val=intval(get_option(self::OPTION_CACHE_TTL,15));
    printf('<input type="number" min="0" style="width:120px;" name="%s" value="%d" />', esc_attr(self::OPTION_CACHE_TTL), $val);
    echo '<p class="description">0 — отключить кэш. Крон раз в 10 минут прогревает кэш по всем филиалам.</p>';
  }

  public static function field_debug(){
    $val=intval(get_option(self::OPTION_DEBUG,0));
    printf('<label><input type="checkbox" name="%s" value="1" %s /> Показать отладку на витрине (только для админов).</label>', esc_attr(self::OPTION_DEBUG), checked(1,$val,false));
  }

  public static function field_multi_categories(){
    $val=intval(get_option(self::OPTION_MULTI_CATEGORIES,0));
    printf('<label><input type="checkbox" name="%s" value="1" %s /> Разрешить атрибут шорткода <code>category_ids</code> (через запятую).</label>', esc_attr(self::OPTION_MULTI_CATEGORIES), checked(1,$val,false));
  }

  public static function field_show_staff(){
    $val=intval(get_option(self::OPTION_SHOW_STAFF,1));
    printf('<label><input type="checkbox" name="%s" value="1" %s /> Показывать блок «Специалисты»</label>', esc_attr(self::OPTION_SHOW_STAFF), checked(1,$val,false));
  }

  public static function field_titles(){
    $staff = get_option(self::OPTION_TITLE_STAFF, 'Специалисты:');
    $price = get_option(self::OPTION_TITLE_PRICE, 'Прайс-лист');
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    echo '<label>«Специалисты» <input type="text" style="width:260px;margin-left:6px" name="'.esc_attr(self::OPTION_TITLE_STAFF).'" value="'.esc_attr($staff).'" /></label>';
    echo '<label>«Прайс-лист» <input type="text" style="width:260px;margin-left:6px" name="'.esc_attr(self::OPTION_TITLE_PRICE).'" value="'.esc_attr($price).'" /></label>';
    echo '</div>';
  }

  public static function field_book_url(){
    $tpl=esc_attr(get_option(self::OPTION_BOOK_URL_TPL,''));
    echo '<input type="text" class="regular-text code" style="width:100%;" name="'.esc_attr(self::OPTION_BOOK_URL_TPL).'" value="'.$tpl.'" />';
    echo '<p class="description">Можно указать только домен (например, https://n1295696.yclients.com/). Плейсхолдеры: {company_id}, {service_id}, {book_step}, {utm_*}.</p>';
  }

  public static function field_book_step(){
    $val = get_option(self::OPTION_BOOK_STEP, 'select-master');
    ?>
    <fieldset>
      <label>
        <input type="radio" name="<?php echo esc_attr(self::OPTION_BOOK_STEP); ?>" value="select-master" <?php checked('select-master', $val); ?> />
        Сразу выбор мастера
      </label><br/>
      <label>
        <input type="radio" name="<?php echo esc_attr(self::OPTION_BOOK_STEP); ?>" value="select-services" <?php checked('select-services', $val); ?> />
        Сначала выбор услуги
      </label>
    </fieldset>
    <?php
  }

  public static function field_utms(){
    $s=esc_attr(get_option(self::OPTION_UTM_SOURCE,'site'));
    $m=esc_attr(get_option(self::OPTION_UTM_MEDIUM,'price'));
    $c=esc_attr(get_option(self::OPTION_UTM_CAMPAIGN,'booking'));
    echo '<input type="text" style="width:180px;margin-right:6px;" name="'.esc_attr(self::OPTION_UTM_SOURCE).'" value="'.$s.'" placeholder="utm_source" />';
    echo '<input type="text" style="width:180px;margin-right:6px;" name="'.esc_attr(self::OPTION_UTM_MEDIUM).'" value="'.$m.'" placeholder="utm_medium" />';
    echo '<input type="text" style="width:220px;" name="'.esc_attr(self::OPTION_UTM_CAMPAIGN).'" value="'.$c.'" placeholder="utm_campaign" />';
  }

  public static function field_vlist(){
    $val=intval(get_option(self::OPTION_VLIST_PAGE,15));
    printf('<input type="number" min="5" max="100" style="width:120px;" name="%s" value="%d" />', esc_attr(self::OPTION_VLIST_PAGE), $val);
    echo '<p class="description">Сколько услуг показывать сразу. Остальные — «Показать ещё».</p>';
  }

  public static function field_staff_links(){
    $branches = get_option(self::OPTION_BRANCHES, array());
    $map      = get_option(self::OPTION_STAFF_LINKS, array());

    if (!is_array($branches) || empty($branches)) {
      echo '<p class="description">Сначала добавьте филиалы выше.</p>';
      return;
    }

    if (!is_array($map)) {
      $map = array();
    }

    $order_map = yc_pa_get_manual_staff_order();

    echo '<div class="yc-admin-card">';
    echo '<p class="description">Укажите ссылки на страницы специалистов на сайте. Список подгружается из YClients (по API).</p>';

    foreach ($branches as $branch) {
      $cid   = isset($branch['id']) ? (int) $branch['id'] : 0;
      $title = isset($branch['title']) ? esc_html($branch['title']) : 'Филиал';
      if ($cid <= 0) {
        continue;
      }
      if (!class_exists('YC_API')) {
        echo '<p>API недоступно.</p>';
        continue;
      }
      $staffs = YC_API::get_staff($cid);
      echo '<h3 style="margin-top:12px;">' . $title . '</h3>';
      if (empty($staffs)) {
        echo '<p>Не удалось получить список специалистов.</p>';
        continue;
      }

      echo '<table class="widefat striped"><thead><tr><th style="width:60px;">ID</th><th>Имя</th><th>Должность</th><th>Ссылка</th></tr></thead><tbody>';
      foreach ($staffs as $staff) {
        $sid  = isset($staff['id']) ? (int) $staff['id'] : 0;
        $name = isset($staff['name']) ? esc_html($staff['name']) : '';
        $pos  = '';
        if (isset($staff['position'])) {
          if (is_array($staff['position'])) {
            $pos = esc_html(implode(', ', array_filter($staff['position'])));
          } else {
            $pos = esc_html($staff['position']);
          }
        }
        $val = isset($map[$cid][$sid]) ? esc_attr($map[$cid][$sid]) : '';
        echo '<tr><td>' . $sid . '</td><td>' . $name . '</td><td>' . $pos . '</td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr(self::OPTION_STAFF_LINKS) . '[' . $cid . '][' . $sid . ']" value="' . $val . '" placeholder="https://example.com/staff/..." /></td></tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div id="yc_manual_order_block" class="yc-admin-card">';
    echo '<h3 style="margin-top:16px;">' . esc_html__('Ручной порядок специалистов', 'yc-price-accordion') . '</h3>';
    echo '<p class="description">Для каждого филиала укажите порядок. Формат: <code>id1,id2,id3</code> (id1 — первый) или с весами: <code>id1=1,id2=5</code>. Неуказанные окажутся в конце.</p>';

    foreach ($branches as $branch) {
      if (empty($branch['id'])) {
        continue;
      }
      $cid   = (int) $branch['id'];
      $label = !empty($branch['title']) ? esc_html($branch['title']) : ('Company ' . $cid);
      $preset = '';
      $key = (string) $cid;
      if (isset($order_map[$key]) && is_array($order_map[$key])) {
        $pairs = $order_map[$key];
        asort($pairs, SORT_NUMERIC);
        $buf = array();
        foreach ($pairs as $sid => $weight) {
          $buf[] = (int) $sid . '=' . (int) $weight;
        }
        $preset = implode(', ', $buf);
      }

      echo '<h4 style="margin:10px 0 6px;">' . $label . ' (ID ' . $cid . ')</h4>';
      echo '<textarea name="yc_staff_order[' . $cid . ']" rows="3" style="width:100%;font-family:monospace;">' . esc_textarea($preset) . '</textarea>';
    }

    echo '</div>';
  }

  public static function render_page(){
    $notice = get_transient('yc_pa_sync_notice');
    if ($notice && is_array($notice)) {
      delete_transient('yc_pa_sync_notice');
      $class = isset($notice['type']) && $notice['type'] === 'error' ? 'notice notice-error' : 'notice notice-success';
      $message = isset($notice['message']) ? esc_html($notice['message']) : '';
      if ($message !== '') {
        printf('<div class="%s"><p>%s</p></div>', $class, $message);
      }
    }

    echo '<div class="wrap"><h1 style="margin-bottom:12px;">Настройки YClients</h1><div class="yc-admin-card"><form method="post" action="options.php">';
    settings_fields('yc_price_group'); do_settings_sections('yc-price-settings'); submit_button();
    echo '</form></div>';

    self::render_sync_card();

    echo '</div>';
  }

  public static function assets($hook){
    if ($hook!=='settings_page_yc-price-settings') return;
    wp_enqueue_style('yc-admin', YC_PA_URL . 'admin/yc-admin.css', array(), YC_PA_VER);
    wp_enqueue_script('yc-admin', YC_PA_URL . 'admin/yc-admin.js', array('jquery'), YC_PA_VER, true);
  }

  public static function flush_cache(){
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_yc_pa_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_yc_pa_%'");
    if (class_exists('YC_Storage')) {
      YC_Storage::purge_all();
    }
    delete_option(self::OPTION_LAST_SYNC);
  }

  protected static function settings_url(){
    return admin_url('options-general.php?page=yc-price-settings');
  }

  protected static function render_sync_card(){
    $last_sync = (int) get_option(self::OPTION_LAST_SYNC, 0);
    $last_sync_text = $last_sync > 0 ? date_i18n(get_option('date_format').' '.get_option('time_format'), $last_sync) : __('ещё не выполнялась', 'yc-price-accordion');

    echo '<div class="yc-admin-card">';
    echo '<h2>' . esc_html__('Синхронизация данных', 'yc-price-accordion') . '</h2>';
    echo '<p class="description">' . esc_html__('Выгружает категории, услуги и специалистов из YClients и сохраняет локально в WordPress. Используйте после изменения данных в личном кабинете.', 'yc-price-accordion') . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('yc_pa_sync_action', 'yc_pa_sync_nonce');
    echo '<input type="hidden" name="action" value="yc_pa_sync" />';
    submit_button(__('Обновить данные YClients', 'yc-price-accordion'), 'secondary', 'submit', false);
    echo '</form>';
    echo '<p class="description">' . esc_html__('Последняя успешная синхронизация:', 'yc-price-accordion') . ' ' . esc_html($last_sync_text) . '</p>';
    echo '</div>';
  }

  public static function handle_sync(){
    if (!current_user_can('manage_options')) {
      wp_die(__('Недостаточно прав.', 'yc-price-accordion'));
    }
    check_admin_referer('yc_pa_sync_action', 'yc_pa_sync_nonce');

    $branches = yc_get_branches();
    $updated  = 0;
    $errors   = array();

    if (is_array($branches)) {
      foreach ($branches as $branch) {
        $cid = isset($branch['id']) ? (int) $branch['id'] : 0;
        if ($cid <= 0) {
          continue;
        }
        $sync = YC_API::sync_company($cid, true);
        if (!empty($sync['errors'])) {
          $errors = array_merge($errors, $sync['errors']);
        } else {
          $updated++;
        }
      }
    }

    if ($updated > 0 && empty($errors)) {
      update_option(self::OPTION_LAST_SYNC, time(), false);
      set_transient('yc_pa_sync_notice', array('type' => 'success', 'message' => sprintf(__('Синхронизация завершена: обновлено филиалов — %d.', 'yc-price-accordion'), $updated)), 30);
    } elseif ($updated > 0) {
      update_option(self::OPTION_LAST_SYNC, time(), false);
      set_transient('yc_pa_sync_notice', array('type' => 'error', 'message' => sprintf(__('Обновлено филиалов — %1$d. Ошибки: %2$s', 'yc-price-accordion'), $updated, implode('; ', $errors))), 30);
    } else {
      $message = empty($errors) ? __('Не удалось выполнить синхронизацию.', 'yc-price-accordion') : implode('; ', $errors);
      set_transient('yc_pa_sync_notice', array('type' => 'error', 'message' => $message), 30);
    }

    wp_safe_redirect(self::settings_url());
    exit;
  }

    public static function sanitize_staff_order( $input ) {
        $out = array();
        if ( is_string( $input ) ) {
            $input = array( 'all' => $input );
        }
        if ( ! is_array( $input ) ) {
            return $out;
        }
        foreach ( $input as $company_key => $raw ) {
            $cid_raw = preg_replace( '/\D+/', '', (string) $company_key );
            $cid = $cid_raw !== '' ? (string) (int) $cid_raw : trim( (string) $company_key );
            if ( $cid === '' ) {
                continue;
            }
            $text = is_array( $raw ) ? '' : trim( (string) $raw );
            if ( $text === '' ) {
                continue;
            }
            $pairs = preg_split( '/[,\r\n]+/', $text );
            $rank  = 1;
            foreach ( $pairs as $token ) {
                $token = trim( $token );
                if ( $token === '' ) {
                    continue;
                }
                if ( strpos( $token, '=' ) !== false ) {
                    list( $sid, $w ) = array_map( 'trim', explode( '=', $token, 2 ) );
                    $sid = preg_replace( '/\D+/', '', $sid );
                    $sid = $sid !== '' ? (int) $sid : 0;
                    $w   = (int) $w;
                    if ( $sid <= 0 ) {
                        continue;
                    }
                    if ( $w <= 0 ) {
                        $w = $rank;
                    }
                    if ( ! isset( $out[ $cid ] ) ) {
                        $out[ $cid ] = array();
                    }
                    $out[ $cid ][ $sid ] = $w;
                    $rank = max( $rank, $w + 1 );
                } else {
                    $sid = preg_replace( '/\D+/', '', $token );
                    $sid = $sid !== '' ? (int) $sid : 0;
                    if ( $sid <= 0 ) {
                        continue;
                    }
                    if ( ! isset( $out[ $cid ] ) ) {
                        $out[ $cid ] = array();
                    }
                    $out[ $cid ][ $sid ] = $rank++;
                }
            }
        }
        return $out;
    }

}


// Guarantee saving of manual order from textarea
add_filter( 'pre_update_option_yc_staff_order', function( $value, $old ) {
    if ( is_array( $value ) ) {
        return $value;
    }
    if ( class_exists( 'YC_Admin' ) && method_exists( 'YC_Admin', 'sanitize_staff_order' ) ) {
        return YC_Admin::sanitize_staff_order( $value );
    }
    return $value;
}, 10, 2 );

