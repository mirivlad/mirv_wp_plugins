<?php
defined('ABSPATH') || exit;

add_action('add_meta_boxes_mirv_project', function() {
    add_meta_box('mirvp-data','Данные публичного проекта','mirvp_meta_box','mirv_project','normal','high');
});
function mirvp_meta_box($post) {
    wp_nonce_field('mirvp_save_project','mirvp_nonce');
    $fields=['summary'=>'Краткое описание (для карточек)','tech'=>'Технологии','image'=>'Обложка: URL изображения (или миниатюра записи)','gallery'=>'Галерея: URL картинок, по одному в строке'];
    foreach ($fields as $key=>$label):
        $val=(string)mirvp_meta($post->ID,$key);
        echo '<p><label for="mirvp-'.esc_attr($key).'"><strong>'.esc_html($label).'</strong></label><br>';
        if ($key==='summary' || $key==='gallery') {
            echo '<textarea id="mirvp-'.esc_attr($key).'" name="mirvp['.esc_attr($key).']" rows="3" style="width:100%">'.esc_textarea($val).'</textarea>';
        } else echo '<input id="mirvp-'.esc_attr($key).'" name="mirvp['.esc_attr($key).']" value="'.esc_attr($val).'" style="width:100%">';
        echo '</p>';
    endforeach;
    echo '<hr><h4>Ссылки проекта</h4>';
    foreach (['github'=>'GitHub','demo'=>'Сайт / демо','docs'=>'Документация','download'=>'Скачать','store'=>'Магазин','issues'=>'Ошибки / Issues'] as $key=>$label) {
        echo '<p><label>'.esc_html($label).' <input type="url" name="mirvp[link_'.esc_attr($key).']" value="'.esc_attr((string)mirvp_meta($post->ID,'link_'.$key)).'" placeholder="https://" style="width:75%"></label></p>';
    }
    echo '<p><label><strong>Другие ссылки (формат Название | https://...)</strong><br><textarea name="mirvp[other_links]" rows="3" style="width:100%">'.esc_textarea((string)mirvp_meta($post->ID,'other_links')).'</textarea></label></p>';
    $status=(string)mirvp_meta($post->ID,'phase','active');
    echo '<p><label><strong>Состояние проекта</strong> <select name="mirvp[phase]">';
    foreach (['active'=>'В разработке','supported'=>'Поддерживается','released'=>'Выпущен','paused'=>'Приостановлен','archived'=>'Архив'] as $v=>$label) {
        echo '<option value="'.esc_attr($v).'" '.selected($status,$v,false).'>'.esc_html($label).'</option>';
    }
    echo '</select></label></p>';
    foreach (['catalog'=>'Показывать в публичном каталоге','on_home'=>'Участвует в ротации «Сейчас в работе»','featured'=>'Закрепить вверху каталога'] as $key=>$label) {
        $default=$key==='featured'?'0':'1';
        echo '<p><label><input type="checkbox" name="mirvp['.esc_attr($key).']" value="1" '.checked((string)mirvp_meta($post->ID,$key,$default),'1',false).'> '.esc_html($label).'</label></p>';
    }
    $legacy=(int)mirvp_meta($post->ID,'legacy_page_id',0);
    if ($legacy) echo '<p><em>Исходная страница сохранена: <a href="'.esc_url(get_edit_post_link($legacy)).'">ID '.esc_html($legacy).'</a>. Публичный URL не изменился.</em></p>';
}
add_action('save_post_mirv_project', function($id, $post, $update) {
    if (!isset($_POST['mirvp_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mirvp_nonce'])),'mirvp_save_project')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($id) || !current_user_can('edit_post',$id)) return;
    $raw = isset($_POST['mirvp']) && is_array($_POST['mirvp']) ? wp_unslash($_POST['mirvp']) : [];
    foreach (['summary','tech','image','gallery','phase','other_links','link_github','link_demo','link_docs','link_download','link_store','link_issues'] as $key) {
        $value = isset($raw[$key]) && is_string($raw[$key]) ? $raw[$key] : '';
        if ($key==='summary' || $key==='tech') $value=sanitize_textarea_field($value);
        elseif ($key==='gallery') $value=implode("\n",array_filter(array_map('esc_url_raw',preg_split('/\R+/',trim($value)))));
        elseif ($key==='other_links') $value=sanitize_textarea_field($value);
        elseif ($key==='phase') $value=in_array($value,['active','supported','released','paused','archived'],true)?$value:'active';
        else $value=esc_url_raw($value);
        update_post_meta($id,'_mirvp_'.$key,$value);
    }
    foreach (['catalog','on_home','featured'] as $key) update_post_meta($id,'_mirvp_'.$key,!empty($raw[$key])?'1':'0');
}, 10, 3);

add_action('admin_menu', function() {
    add_submenu_page('edit.php?post_type=mirv_project','Порядок и отображение','Порядок и отображение','manage_options','mirvp-catalog','mirvp_admin_catalog');
});
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos((string)$hook,'mirvp-catalog')===false) return;
    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_script('mirvp-admin',MIRVP_URL.'assets/admin.js',['jquery','jquery-ui-sortable'],MIRVP_VERSION,true);
});
function mirvp_admin_catalog() {
    if (!current_user_can('manage_options')) wp_die('Недостаточно прав');
    $groups=mirvp_groups();
    $projects=get_posts(['post_type'=>'mirv_project','post_status'=>['publish','draft','pending','private'],'numberposts'=>-1]);
    usort($projects,'mirvp_project_sort');
    ?>
    <div class="wrap"><h1>Порядок и отображение проектов</h1>
    <p>Перетаскивайте строки за символ ↕. Здесь задаются порядок разделов, порядок проектов внутри разделов, вид каталога и видимость.</p>
    <?php if (!empty($_GET['updated'])): ?><div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div><?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="mirvp_save_catalog">
        <?php wp_nonce_field('mirvp_save_catalog'); ?>
        <h2>Размещение каталога</h2>
        <p><label>Страница каталога
          <?php wp_dropdown_pages(['name'=>'mirvp_catalog_page_id','selected'=>(int)get_option('mirvp_catalog_page_id',0),'show_option_none'=>'— Не выбрана —','option_none_value'=>0]); ?>
        </label></p>
        <p><label>Базовый адрес страниц проектов
          <input type="text" name="mirvp_project_base" value="<?php echo esc_attr((string)get_option('mirvp_project_base','public_projects')); ?>" pattern="[a-z0-9-]+" placeholder="public_projects">
        </label> <small>После изменения WordPress обновит правила ссылок. Для старых проектов mirv.top сохраняются прежние URL.</small></p>
        <p>Поместите шорткод <code>[mirv_projects_catalog]</code> на выбранную страницу.</p>
        <h2>Описание каталога</h2>
        <p><label for="mirvp-intro">Текст перед категориями (разрешён HTML)</label></p>
        <?php wp_editor((string)get_option('mirvp_catalog_intro',''),'mirvp_catalog_intro',['textarea_name'=>'mirvp_catalog_intro','textarea_rows'=>5,'media_buttons'=>false,'teeny'=>true]); ?>
        <p><label for="mirvp-footer">Текст после категорий (разрешён HTML)</label></p>
        <?php wp_editor((string)get_option('mirvp_catalog_footer',''),'mirvp_catalog_footer',['textarea_name'=>'mirvp_catalog_footer','textarea_rows'=>5,'media_buttons'=>false,'teeny'=>true]); ?>
        <h2>Разделы каталога</h2>
        <table class="widefat striped"><thead><tr><th>Порядок</th><th>Раздел</th><th>Показывать</th><th>Режим</th><th>Колонок</th></tr></thead>
        <tbody class="mirvp-sortable">
        <?php foreach ($groups as $g):
            $id=$g->term_id;
            $visible=(string)get_term_meta($id,'_mirvp_visible',true)!=='0';
            $layout=(string)get_term_meta($id,'_mirvp_layout',true);
            $cols=(int)get_term_meta($id,'_mirvp_columns',true);
            if (!in_array($cols,[1,2,3,4],true))$cols=3; ?>
            <tr><td class="mirvp-handle">↕ <input type="hidden" class="mirvp-order" name="terms[<?php echo (int)$id; ?>][order]" value="<?php echo (int)get_term_meta($id,'_mirvp_order',true); ?>"></td>
            <td><strong><?php echo esc_html($g->name); ?></strong> <a href="<?php echo esc_url(get_edit_term_link($id,'mirvp_group')); ?>">Изменить</a></td>
            <td><input type="checkbox" name="terms[<?php echo (int)$id; ?>][visible]" value="1" <?php checked($visible); ?>></td>
            <td><select name="terms[<?php echo (int)$id; ?>][layout]">
                <?php foreach (['cards'=>'Карточки','compact'=>'Компактно','detailed'=>'Подробно'] as $val=>$label) echo '<option value="'.esc_attr($val).'" '.selected($layout?:'cards',$val,false).'>'.esc_html($label).'</option>'; ?>
            </select></td>
            <td><select name="terms[<?php echo (int)$id; ?>][columns]">
                <?php foreach ([1,2,3,4] as $v) echo '<option '.selected($cols,$v,false).'>'.$v.'</option>'; ?>
            </select></td></tr>
        <?php endforeach; ?></tbody></table>
        <p><a class="button" href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=mirvp_group&post_type=mirv_project')); ?>">Добавить / изменить разделы</a></p>
        <h2>Проекты</h2>
        <p>Проект выводится в основном разделе, выбранном в его редакторе. Закреплённые показываются вверху каталога.</p>
        <?php foreach ($groups as $g):
            $items=array_values(array_filter($projects,fn($p)=>has_term($g->term_id,'mirvp_group',$p)));
            if (!$items)continue;
            usort($items,'mirvp_project_sort'); ?>
            <h3><?php echo esc_html($g->name); ?></h3>
            <table class="widefat striped"><thead><tr><th>Порядок</th><th>Проект</th><th>Каталог</th><th>На главной</th><th>Закреплён</th></tr></thead>
            <tbody class="mirvp-sortable">
            <?php foreach ($items as $p): $id=$p->ID; ?>
                <tr><td class="mirvp-handle">↕ <input type="hidden" class="mirvp-order" name="projects[<?php echo (int)$id; ?>][order]" value="<?php echo (int)mirvp_meta($id,'order',999); ?>"></td>
                <td><a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html($p->post_title); ?></a> <small>(<?php echo esc_html($p->post_status); ?>)</small></td>
                <?php foreach (['catalog'=>'1','on_home'=>'1','featured'=>'0'] as $field=>$default): ?>
                    <td><input type="checkbox" name="projects[<?php echo (int)$id; ?>][<?php echo esc_attr($field); ?>]" value="1" <?php checked((string)mirvp_meta($id,$field,$default),'1'); ?>></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?></tbody></table>
        <?php endforeach; ?>
        <p><input type="submit" class="button button-primary" value="Сохранить порядок и отображение"></p>
    </form></div>
    <?php
}
add_action('admin_post_mirvp_save_catalog', function() {
    if (!current_user_can('manage_options')) wp_die('Недостаточно прав');
    check_admin_referer('mirvp_save_catalog');
    if (isset($_POST['mirvp_catalog_page_id'])) {
        $id = absint($_POST['mirvp_catalog_page_id']);
        if (!$id || get_post_type($id)==='page') update_option('mirvp_catalog_page_id',$id);
    }
    $new_base = sanitize_title((string)($_POST['mirvp_project_base'] ?? 'public_projects'));
    if ($new_base && $new_base !== get_option('mirvp_project_base','public_projects')) {
        update_option('mirvp_project_base',$new_base);
        mirvp_register();
        flush_rewrite_rules();
    }
    foreach (['mirvp_catalog_intro','mirvp_catalog_footer'] as $field) {
        if (isset($_POST[$field]) && is_string($_POST[$field]))
            update_option($field, wp_kses_post(wp_unslash($_POST[$field])));
    }
    $term_ids=array_map(fn($g)=>$g->term_id,mirvp_groups());
    $groups=isset($_POST['terms'])&&is_array($_POST['terms'])?wp_unslash($_POST['terms']):[];
    foreach ($term_ids as $id) {
        $v=$groups[$id]??[];
        if (!$v)continue;
        update_term_meta($id,'_mirvp_order',max(0,(int)($v['order']??0)));
        update_term_meta($id,'_mirvp_visible',!empty($v['visible'])?'1':'0');
        $layout=(string)($v['layout']??'cards');
        update_term_meta($id,'_mirvp_layout',in_array($layout,['cards','compact','detailed'],true)?$layout:'cards');
        $cols=(int)($v['columns']??3);
        update_term_meta($id,'_mirvp_columns',in_array($cols,[1,2,3,4],true)?$cols:3);
    }
    $projects=isset($_POST['projects'])&&is_array($_POST['projects'])?wp_unslash($_POST['projects']):[];
    foreach ($projects as $id=>$v) {
        $id=absint($id);
        if (!$id || get_post_type($id)!=='mirv_project' || !current_user_can('edit_post',$id))continue;
        update_post_meta($id,'_mirvp_order',max(0,(int)($v['order']??999)));
        foreach (['catalog','on_home','featured'] as $key) update_post_meta($id,'_mirvp_'.$key,!empty($v[$key])?'1':'0');
    }
    mirvp_purge_cache();
    wp_safe_redirect(add_query_arg(['post_type'=>'mirv_project','page'=>'mirvp-catalog','updated'=>'1'],admin_url('edit.php')));
    exit;
});
