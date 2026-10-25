<?php
defined('ABSPATH') || exit;

function mirvp_img_markup($post_id) {
    $url = mirvp_project_image($post_id);
    if (!$url) return '<span class="mirvp-card__placeholder" aria-hidden="true">MIRV</span>';
    return '<img src="'.esc_url($url).'" alt="'.esc_attr(get_the_title($post_id)).'" loading="lazy" decoding="async">';
}
function mirvp_render_card($p, $layout = 'cards') {
    $id = $p->ID;
    $summary = (string)mirvp_meta($id,'summary',$p->post_excerpt);
    if (!$summary) $summary = wp_trim_words(wp_strip_all_tags($p->post_content),36,'…');
    $tech = (string)mirvp_meta($id,'tech');
    $status = (string)mirvp_meta($id,'phase','active');
    $labels = ['active'=>'В разработке','supported'=>'Поддерживается','released'=>'Выпущен','paused'=>'Приостановлен','archived'=>'Архив'];
    $status_text = $labels[$status] ?? $status;
    ob_start(); ?>
    <article class="mirvp-card mirvp-card--<?php echo esc_attr($layout); ?>">
        <a class="mirvp-card__image" href="<?php echo esc_url(mirvp_project_url($id)); ?>"><?php echo mirvp_img_markup($id); // escaped in helper ?></a>
        <div class="mirvp-card__content">
            <div class="mirvp-card__topline"><span><?php echo esc_html($status_text); ?></span></div>
            <h3><a href="<?php echo esc_url(mirvp_project_url($id)); ?>"><?php echo esc_html(get_the_title($id)); ?></a></h3>
            <p class="mirvp-card__summary"><?php echo esc_html($summary); ?></p>
            <?php if ($tech): ?><p class="mirvp-card__tech"><?php echo esc_html($tech); ?></p><?php endif; ?>
            <a class="mirvp-card__more" href="<?php echo esc_url(mirvp_project_url($id)); ?>">Подробнее →</a>
        </div>
    </article>
    <?php return (string)ob_get_clean();
}
function mirvp_render_catalog() {
    $projects = mirvp_projects();
    $groups = mirvp_groups();
    $featured = array_values(array_filter($projects, fn($p)=>(string)mirvp_meta($p->ID,'featured','0')==='1'));
    usort($featured, 'mirvp_project_sort');
    $featured_ids = array_map(fn($p)=>$p->ID, $featured);
    ob_start(); ?>
    <section class="mirvp-catalog" aria-label="Публичные проекты">
        <h1>Публичные проекты</h1>
        <div class="mirvp-catalog__intro"><?php echo wp_kses_post((string)get_option("mirvp_catalog_intro","Программы, инструменты, игры и эксперименты.")); ?></div>
        <nav class="mirvp-catalog__nav" aria-label="Разделы каталога">
            <?php if ($featured): ?><a href="#mirvp-featured">Избранное</a><?php endif; ?>
            <?php foreach ($groups as $g):
                if ((string)get_term_meta($g->term_id,'_mirvp_visible',true)==='0') continue;
                if (!array_filter($projects, fn($p)=>has_term($g->term_id,'mirvp_group',$p))) continue; ?>
                <a href="#<?php echo esc_attr($g->slug); ?>"><?php echo esc_html($g->name); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($featured):
            $featured_columns = (int)get_option('mirvp_featured_columns', 3);
            if (!in_array($featured_columns, [1, 2, 3, 4], true)) $featured_columns = 3; ?>
            <section id="mirvp-featured" class="mirvp-section">
                <h2>Избранное</h2>
                <div class="mirvp-grid mirvp-grid--cards mirvp-grid--featured<?php echo $featured_columns === 1 ? ' mirvp-grid--single' : ''; ?>" style="--mirvp-columns:<?php echo esc_attr($featured_columns); ?>">
                    <?php foreach ($featured as $p) echo mirvp_render_card($p); ?>
                </div>
            </section>
        <?php endif; ?>
        <?php foreach ($groups as $g):
            if ((string)get_term_meta($g->term_id,'_mirvp_visible',true)==='0') continue;
            $in_group = array_values(array_filter($projects, fn($p)=>!in_array($p->ID,$featured_ids,true) && has_term($g->term_id,'mirvp_group',$p)));
            if (!$in_group) continue;
            usort($in_group,'mirvp_project_sort');
            $layout = (string)get_term_meta($g->term_id,'_mirvp_layout',true);
            if (!in_array($layout,['cards','compact','detailed'],true)) $layout='cards';
            $columns = (int)get_term_meta($g->term_id,'_mirvp_columns',true);
            if (!in_array($columns,[1,2,3,4],true)) $columns=3; ?>
            <section class="mirvp-section" id="<?php echo esc_attr($g->slug); ?>">
                <h2><?php echo esc_html($g->name); ?></h2>
                <div class="mirvp-grid mirvp-grid--<?php echo esc_attr($layout); ?>" style="--mirvp-columns:<?php echo esc_attr($columns); ?>">
                    <?php foreach ($in_group as $p) echo mirvp_render_card($p,$layout); ?>
                </div>
            </section>
        <?php endforeach; ?>
        <?php
        // Uncategorised projects must remain discoverable, not silently disappear.
        $orphan = array_values(array_filter($projects,function($p)use($groups,$featured_ids){
            if (in_array($p->ID,$featured_ids,true)) return false;
            foreach ($groups as $g) {
                if ((string)get_term_meta($g->term_id,'_mirvp_visible',true)!=='0' && has_term($g->term_id,'mirvp_group',$p)) return false;
            }
            return !get_the_terms($p->ID,'mirvp_group');
        }));
        if ($orphan): ?>
            <section class="mirvp-section"><h2>Другие проекты</h2><div class="mirvp-grid mirvp-grid--cards">
                <?php foreach ($orphan as $p) echo mirvp_render_card($p); ?>
            </div></section>
        <?php endif; ?>
        <div class="mirvp-catalog__footer"><?php echo wp_kses_post((string)get_option('mirvp_catalog_footer','')); ?></div>
    </section>
    <?php return (string)ob_get_clean();
}
function mirvp_now_selection() {
    $projects = array_values(array_filter(mirvp_projects(),fn($p)=>(string)mirvp_meta($p->ID,'on_home','1')==='1'));
    $day = gmdate('Y-m-d');
    usort($projects, function($a,$b) use ($day) {
        $x = sprintf('%u',crc32($day.'|'.$a->ID));
        $y = sprintf('%u',crc32($day.'|'.$b->ID));
        return (int)$x <=> (int)$y;
    });
    return array_slice($projects,0,3);
}
function mirvp_render_now_cards() {
    ob_start();
    foreach (mirvp_now_selection() as $p):
        $g = mirvp_project_group($p->ID);
        $summary = (string)mirvp_meta($p->ID,'summary',$p->post_excerpt); ?>
        <a class="mirv-home-now-card" href="<?php echo esc_url(mirvp_project_url($p->ID)); ?>" style="text-decoration:none">
            <span class="mirv-home-now-card__kind"><?php echo esc_html($g ? $g->name : 'Проект'); ?></span>
            <h3><?php echo esc_html($p->post_title); ?></h3>
            <p><?php echo esc_html(wp_trim_words($summary,22,'…')); ?></p>
        </a>
    <?php endforeach;
    return (string)ob_get_clean();
}
function mirvp_render_now() {
    return '<section class="mirv-home-now mirvp-now" aria-labelledby="mirv-home-now-title"><div class="mirv-home-section-head"><h2 id="mirv-home-now-title">Сейчас в работе</h2><a href="'.esc_url(mirvp_catalog_url()).'">Все проекты →</a></div><div class="mirv-home-now-grid" id="mirvp-now-cards">'.mirvp_render_now_cards().'</div></section>';
}

// For brand-new CPT projects render the rich project content using the theme's single template.
add_filter('the_content', function($content) {
    if (!is_singular('mirv_project') || !in_the_loop() || !is_main_query()) return $content;
    $id = get_the_ID();
    if (mirvp_meta($id,'legacy_page_id')) return $content;
    $tech = mirvp_meta($id,'tech');
    $links = mirvp_links_html($id);
    $hero = mirvp_project_image($id);
    $gallery = mirvp_gallery_html($id);
    return '<div class="mirvp-detail"><p><a href="'.esc_url(mirvp_catalog_url()).'">← Все публичные проекты</a></p>'
        .($hero?'<figure class="mirvp-detail__hero"><img src="'.esc_url($hero).'" alt="'.esc_attr(get_the_title($id)).'"></figure>':'')
        .($tech?'<p class="mirvp-card__tech">'.esc_html($tech).'</p>':'')
        .$links.$content.$gallery.'</div>';
}, 8);
function mirvp_gallery_html($id) {
    $urls = array_filter(array_map('trim', preg_split('/\\R+/', (string)mirvp_meta($id,'gallery'))));
    if (!$urls) return '';
    $html='<div class="mirvp-detail__gallery">';
    foreach ($urls as $url) {
        $url = esc_url_raw($url);
        if ($url) $html.='<a href="'.esc_url($url).'" target="_blank" rel="noopener"><img src="'.esc_url($url).'" loading="lazy" decoding="async" alt=""></a>';
    }
    return $html.'</div>';
}
function mirvp_links_html($id) {
    $items = [];
    foreach (['github'=>'GitHub','demo'=>'Сайт / демо','docs'=>'Документация','download'=>'Скачать','store'=>'Магазин','issues'=>'Сообщить об ошибке'] as $key=>$label) {
        $url = esc_url_raw((string)mirvp_meta($id,'link_'.$key));
        if ($url) $items[$label] = $url;
    }
    foreach (explode("\n",(string)mirvp_meta($id,'other_links')) as $line) {
        $parts = array_map('trim',explode('|',$line,2));
        if (count($parts)===2 && esc_url_raw($parts[1])) $items[$parts[0]]=$parts[1];
    }
    if (!$items) return '';
    $html='<div class="mirvp-detail__links">';
    foreach ($items as $label=>$url) $html.='<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">'.esc_html($label).' →</a>';
    return $html.'</div>';
}
