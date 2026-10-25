<?php
/**
 * Plugin Name: MIRV Projects
 * Description: Управляемый каталог публичных проектов, страницы проектов и блок на главной.
 * Version: 1.0.4
 * Author: Mirivlad
 * Requires PHP: 8.0
 * Requires at least: 5.8
 * Update URI: https://github.com/mirivlad/mirv_wp_plugins
 * Text Domain: mirv-projects
 */
defined('ABSPATH') || exit;

define('MIRVP_DIR', plugin_dir_path(__FILE__));
define('MIRVP_URL', plugin_dir_url(__FILE__));
define('MIRVP_VERSION', '1.0.4');

require_once MIRVP_DIR . 'includes/render.php';
require_once MIRVP_DIR . 'includes/admin.php';
/* Purge old page-cache snapshots when project data or ordering changes.
 * The REST home-card endpoint is intentionally never page-cached. */
function mirvp_purge_cache($legacy_page_id = 0) {
    if (!class_exists('WpFastestCache')) return;
    $paths = [home_url('/'), mirvp_catalog_url()];
    if ($legacy_page_id && get_post_status($legacy_page_id) === 'publish')
        $paths[] = get_permalink($legacy_page_id);
    try {
        $cache = new WpFastestCache();
        foreach (array_unique($paths) as $url) $cache->clear_cache_by_url($url);
    } catch (Throwable $error) {
        error_log('MIRV Projects cache invalidation: '.$error->getMessage());
    }
}
add_action('save_post_mirv_project', function($post_id) {
    if (!wp_is_post_revision($post_id)) {
        mirvp_purge_cache(absint(mirvp_meta($post_id,'legacy_page_id',0)));
    }
}, 99);
add_action('before_delete_post', function($post_id) {
    if (get_post_type($post_id) === 'mirv_project')
        mirvp_purge_cache(absint(mirvp_meta($post_id,'legacy_page_id',0)));
});
add_action('trashed_post', function($post_id) {
    if (get_post_type($post_id) === 'mirv_project')
        mirvp_purge_cache(absint(mirvp_meta($post_id,'legacy_page_id',0)));
});
add_action('created_mirvp_group', function() {mirvp_purge_cache();});
add_action('edited_mirvp_group', function() {mirvp_purge_cache();});



add_action('init', 'mirvp_register');
function mirvp_register() {
    $base = sanitize_title((string)get_option('mirvp_project_base', 'public_projects'));
    if (!$base) $base = 'public_projects';
    register_post_type('mirv_project', [
        'labels' => [
            'name' => 'Публичные проекты', 'singular_name' => 'Проект',
            'add_new_item' => 'Добавить проект', 'edit_item' => 'Редактировать проект',
            'all_items' => 'Все проекты', 'menu_name' => 'Публичные проекты',
        ],
        'public' => true,
        'show_in_rest' => true,
        'has_archive' => false,
        'menu_icon' => 'dashicons-portfolio',
        'supports' => ['title','editor','excerpt','thumbnail','revisions'],
        'rewrite' => ['slug'=>$base,'with_front'=>false],
    ]);
    register_taxonomy('mirvp_group', 'mirv_project', [
        'labels'=>['name'=>'Разделы каталога', 'singular_name'=>'Раздел', 'menu_name'=>'Разделы'],
        'public'=>true,
        'hierarchical'=>true,
        'show_in_rest'=>true,
        'show_admin_column'=>true,
        'rewrite'=>false,
    ]);
}
register_activation_hook(__FILE__, function() {
    mirvp_register();
    // An existing published catalogue is left untouched.
    if (!get_option('mirvp_catalog_page_id')) {
        $page = get_page_by_path('public_projects', OBJECT, 'page');
        if (!$page) {
            $id = wp_insert_post([
                'post_type' => 'page', 'post_status' => 'publish',
                'post_title' => 'Проекты', 'post_name' => 'public_projects',
                'post_content' => '[mirv_projects_catalog]',
            ], true);
            if (!is_wp_error($id)) update_option('mirvp_catalog_page_id', (int)$id);
        } else {
            update_option('mirvp_catalog_page_id', (int)$page->ID);
        }
    }
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

/*
 * WordPress custom post type rewrite rules are matched before legacy
 * hierarchical page routes. Existing URLs /public_projects/<slug>/ must
 * resolve to the original published WP pages, while new projects receive
 * their ordinary CPT permalinks.
 */
add_filter('request', function($query) {
    $slug = '';
    if (!empty($query['mirv_project'])) {
        $slug = sanitize_title((string)$query['mirv_project']);
    } elseif (($query['post_type'] ?? '') === 'mirv_project' && !empty($query['name'])) {
        $slug = sanitize_title((string)$query['name']);
    }
    if (!$slug || str_starts_with($slug, 'record-')) return $query;
    $original = get_page_by_path('public_projects/'.$slug, OBJECT, 'page');
    if (!$original || $original->post_status !== 'publish') return $query;
    $map = get_option('mirvp_legacy_map', []);
    if (!is_array($map) || !isset($map[$original->ID])) return $query;
    return ['pagename'=>'public_projects/'.$slug];
}, 5);


function mirvp_catalog_url() {
    $id = absint(get_option('mirvp_catalog_page_id',0));
    if ($id && get_post_status($id) === 'publish') return get_permalink($id);
    return home_url('/public_projects/');
}
function mirvp_meta($id, $key, $default = '') {
    $v = get_post_meta($id, '_mirvp_' . $key, true);
    return ($v === '' || $v === null) ? $default : $v;
}
function mirvp_term_order($a, $b) {
    $n = ((int)get_term_meta($a->term_id, '_mirvp_order', true)) <=> ((int)get_term_meta($b->term_id, '_mirvp_order', true));
    return $n ?: strcmp($a->name, $b->name);
}
function mirvp_groups() {
    $terms = get_terms(['taxonomy'=>'mirvp_group','hide_empty'=>false]);
    if (is_wp_error($terms)) return [];
    usort($terms, 'mirvp_term_order');
    return $terms;
}
function mirvp_project_sort($a, $b) {
    $x = (int) mirvp_meta($a->ID, 'order', 999);
    $y = (int) mirvp_meta($b->ID, 'order', 999);
    return ($x <=> $y) ?: strcmp($a->post_title, $b->post_title);
}
function mirvp_projects() {
    $list = get_posts(['post_type'=>'mirv_project','post_status'=>'publish','numberposts'=>-1,'orderby'=>'title','order'=>'ASC','suppress_filters'=>true]);
    return array_values(array_filter($list, function($p) {
        return (string) mirvp_meta($p->ID, 'catalog', '1') === '1';
    }));
}
function mirvp_project_url($id) {
    $legacy_id = absint(mirvp_meta($id,'legacy_page_id',0));
    if ($legacy_id && get_post_status($legacy_id) === 'publish') {
        return get_permalink($legacy_id);
    }
    return get_permalink($id);
}
function mirvp_project_image($id) {
    if (has_post_thumbnail($id)) return get_the_post_thumbnail_url($id,'large');
    return esc_url_raw((string)mirvp_meta($id,'image'));
}
function mirvp_project_group($id) {
    $terms = get_the_terms($id, 'mirvp_group');
    if (!$terms || is_wp_error($terms)) return null;
    usort($terms, 'mirvp_term_order');
    return $terms[0];
}

/* Preserve the old page addresses, but render the CPT content as the source of truth.
 * The old WP pages remain intact and can be restored without reversing migration. */
add_filter('the_content', function($content) {
    if (is_admin() || !is_singular('page') || !in_the_loop() || !is_main_query()) return $content;
    $map = get_option('mirvp_legacy_map', []);
    if (!is_array($map)) return $content;
    $id = (int)get_the_ID();
    if (empty($map[$id])) return $content;
    $project = get_post((int)$map[$id]);
    if (!$project || $project->post_type !== 'mirv_project' || $project->post_status !== 'publish') return $content;
    return '<div class="mirvp-project-details">' . mirvp_links_html($project->ID) . '</div>' . $project->post_content;
}, 7);

/* Redirect the technical CPT URLs of migrated entries to the canonical
 * original pages. Newly created projects keep their normal CPT permalinks. */
add_action('template_redirect', function() {
    if (!is_singular('mirv_project') || is_preview()) return;
    $post_id = get_queried_object_id();
    $legacy_id = absint(mirvp_meta($post_id, 'legacy_page_id', 0));
    if ($legacy_id && get_post_status($legacy_id) === 'publish') {
        wp_safe_redirect(get_permalink($legacy_id), 301);
        exit;
    }
}, 1);

/* Prevent confusion when editing a legacy WP page that is now backed by a project. */
add_action('admin_notices', function() {
    if (!is_admin() || empty($_GET['post'])) return;
    $legacy_id = absint($_GET['post']);
    $map = get_option('mirvp_legacy_map', []);
    if (!is_array($map) || empty($map[$legacy_id])) return;
    $new_id = absint($map[$legacy_id]);
    if (!current_user_can('edit_post', $new_id)) return;
    echo '<div class="notice notice-info"><p>Описание этого проекта управляется плагином MIRV Projects. ';
    echo '<a href="'.esc_url(get_edit_post_link($new_id)).'">Редактировать проект</a>. ';
    echo 'Адрес этой страницы сохранён для существующих ссылок.</p></div>';
});

/* Migrated projects remain editable in MIRV Projects. Their original page
 * title is dynamically sourced from the project record (without altering
 * the WordPress page in the database). */
add_filter('the_title', function($title, $post_id) {
    if (is_admin() || !is_int($post_id) || get_post_type($post_id) !== 'page') return $title;
    $map = get_option('mirvp_legacy_map', []);
    if (!is_array($map) || empty($map[$post_id])) return $title;
    $project = get_post((int)$map[$post_id]);
    return $project && $project->post_status === 'publish' ? $project->post_title : $title;
}, 10, 2);

/* Unpublishing or trashing the project must also hide its old canonical page.
 * Otherwise the old static content would accidentally become public again. */
add_action('template_redirect', function() {
    if (!is_page()) return;
    $legacy_id = get_queried_object_id();
    $map = get_option('mirvp_legacy_map', []);
    if (!is_array($map) || empty($map[$legacy_id])) return;
    $project = get_post((int)$map[$legacy_id]);
    if ($project && $project->post_status === 'publish') return;
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    nocache_headers();
    include get_404_template();
    exit;
}, 2);

add_shortcode('mirv_projects_catalog', 'mirvp_render_catalog');
add_shortcode('mirv_projects_now', 'mirvp_render_now');
add_shortcode('mirv_project_detail', function($atts) {
    $atts = shortcode_atts(['id'=>0], $atts);
    $p = get_post(absint($atts['id']));
    return $p && $p->post_type === 'mirv_project' && $p->post_status === 'publish'
        ? wp_kses_post(do_shortcode($p->post_content)) : '';
});

add_action('wp_enqueue_scripts', function() {
    if (!(is_front_page() || is_page() || is_singular('mirv_project'))) return;
    wp_enqueue_style('mirvp-public', MIRVP_URL . 'assets/projects.css', [], MIRVP_VERSION);
    if (is_front_page()) {
        wp_enqueue_script('mirvp-now', MIRVP_URL . 'assets/now.js', [], MIRVP_VERSION, true);
        wp_add_inline_script('mirvp-now','window.MIRVP_NOW_URL = '.wp_json_encode(esc_url_raw(rest_url('mirv-projects/v1/now'))).';','before');
    }
});

add_action('rest_api_init', function() {
    register_rest_route('mirv-projects/v1', '/now', [
        'methods'=>'GET',
        'permission_callback'=>'__return_true',
        'callback'=>function() {
            $response = new WP_REST_Response(['html'=>mirvp_render_now_cards()]);
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate');
            return $response;
        },
    ]);
});
