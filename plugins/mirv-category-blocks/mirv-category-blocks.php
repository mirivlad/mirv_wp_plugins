<?php
/**
 * Plugin Name: MIRV Category Blocks
 * Description: Выводит красивые блоки записей по выбранным рубрикам через шорткод [mirv_category_blocks].
 * Version: 0.4.10
 * Author: MIRV
 * Update URI: https://github.com/mirivlad/mirv_wp_plugins
 * Text Domain: mirv-category-blocks
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MIRV_Category_Blocks
{
    private const OPTION = 'mcb_options';
    private const VERSION = '0.4.10';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'frontend_assets']);
        add_action('init', [__CLASS__, 'register_image_sizes']);
        add_shortcode('mirv_category_blocks', [__CLASS__, 'shortcode']);
        add_shortcode('mirv_latest_posts', [__CLASS__, 'latest_shortcode']);
    }


    public static function register_image_sizes(): void
    {
        add_image_size('mcb-thumb', 500, 281, true);
    }

    private static function defaults(): array
    {
        return [
            'mode' => 'latest',
            'layout' => 'cards',
            'columns' => 2,
            'show_excerpt' => 1,
            'show_date' => 1,
            'show_category_link' => 1,
            'latest_count' => 4,
            'latest_category' => 1,
            'latest_include_children' => 0,
            'latest_note' => '',
            'sections' => [],
        ];
    }

    private static function get_options(): array
    {
        $options = get_option(self::OPTION, []);
        if (!is_array($options)) {
            $options = [];
        }

        return array_replace_recursive(self::defaults(), $options);
    }

    public static function add_settings_page(): void
    {
        add_options_page(
            'MIRV Category Blocks',
            'MIRV Blocks',
            'manage_options',
            'mirv-category-blocks',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting(
            'mcb_settings_group',
            self::OPTION,
            [
                'type' => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_options'],
                'default' => self::defaults(),
            ]
        );
    }

    public static function sanitize_options($input): array
    {
        $defaults = self::defaults();
        $output = $defaults;

        if (!is_array($input)) {
            return $output;
        }

        $mode = isset($input['mode']) ? sanitize_key((string) $input['mode']) : $defaults['mode'];
        $output['mode'] = in_array($mode, ['latest', 'random'], true) ? $mode : $defaults['mode'];

        $layout = isset($input['layout']) ? sanitize_key((string) $input['layout']) : $defaults['layout'];
        $output['layout'] = in_array($layout, ['cards', 'list'], true) ? $layout : $defaults['layout'];

        $columns = isset($input['columns']) ? absint($input['columns']) : $defaults['columns'];
        $output['columns'] = min(4, max(1, $columns));

        $output['show_excerpt'] = empty($input['show_excerpt']) ? 0 : 1;
        $output['show_date'] = empty($input['show_date']) ? 0 : 1;
        $output['show_category_link'] = empty($input['show_category_link']) ? 0 : 1;

        $latest_count = isset($input['latest_count'])
            ? absint($input['latest_count'])
            : $defaults['latest_count'];
        $output['latest_count'] = min(6, max(2, $latest_count));

        $latest_category = isset($input['latest_category'])
            ? absint($input['latest_category'])
            : $defaults['latest_category'];
        if ($latest_category > 0 && !term_exists($latest_category, 'category')) {
            $latest_category = 0;
        }
        $output['latest_category'] = $latest_category;
        $output['latest_include_children'] = empty($input['latest_include_children']) ? 0 : 1;
        $output['latest_note'] = isset($input['latest_note'])
            ? sanitize_text_field((string) $input['latest_note'])
            : '';

        $sections = [];
        if (!empty($input['sections']) && is_array($input['sections'])) {
            foreach ($input['sections'] as $section) {
                if (!is_array($section)) {
                    continue;
                }

                $category_id = isset($section['category']) ? absint($section['category']) : 0;
                if ($category_id <= 0 || !term_exists($category_id, 'category')) {
                    continue;
                }

                $count = isset($section['count']) ? absint($section['count']) : 2;
                $count = min(12, max(1, $count));

                $title = isset($section['title']) ? sanitize_text_field((string) $section['title']) : '';

                $include_children = array_key_exists('include_children', $section)
                    ? (empty($section['include_children']) ? 0 : 1)
                    : 1;

                $sections[] = [
                    'enabled' => empty($section['enabled']) ? 0 : 1,
                    'title' => $title,
                    'category' => $category_id,
                    'include_children' => $include_children,
                    'show_date' => empty($section['show_date']) ? 0 : 1,
                    'count' => $count,
                ];
            }
        }

        $output['sections'] = $sections;

        return $output;
    }

    public static function admin_assets(string $hook): void
    {
        if ($hook !== 'settings_page_mirv-category-blocks') {
            return;
        }

        wp_enqueue_style(
            'mcb-admin',
            plugins_url('assets/admin.css', __FILE__),
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'mcb-admin',
            plugins_url('assets/admin.js', __FILE__),
            [],
            self::VERSION,
            true
        );
    }


    public static function frontend_assets(): void
    {
        if (is_admin()) {
            return;
        }

        wp_enqueue_style(
            'mcb-frontend',
            plugins_url('assets/frontend.css', __FILE__),
            [],
            self::VERSION
        );
    }

    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = self::get_options();
        $categories = self::get_category_tree();
        ?>
        <div class="wrap mcb-admin-page">
            <h1>MIRV Category Blocks</h1>

            <p>
                Плагин выводит на странице красивые блоки записей по выбранным рубрикам.
                Используй шорткод: <code>[mirv_category_blocks]</code>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('mcb_settings_group'); ?>

                <div class="mcb-card">
                    <h2>Общие настройки</h2>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="mcb-mode">Какие записи показывать</label></th>
                                <td>
                                    <select id="mcb-mode" name="<?php echo esc_attr(self::OPTION); ?>[mode]">
                                        <option value="latest" <?php selected($options['mode'], 'latest'); ?>>Последние</option>
                                        <option value="random" <?php selected($options['mode'], 'random'); ?>>Случайные</option>
                                    </select>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="mcb-layout">Вид вывода</label></th>
                                <td>
                                    <select id="mcb-layout" name="<?php echo esc_attr(self::OPTION); ?>[layout]">
                                        <option value="cards" <?php selected($options['layout'], 'cards'); ?>>Карточки в строку</option>
                                        <option value="list" <?php selected($options['layout'], 'list'); ?>>Список по одной карточке</option>
                                    </select>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="mcb-columns">Колонок в режиме карточек</label></th>
                                <td>
                                    <select id="mcb-columns" name="<?php echo esc_attr(self::OPTION); ?>[columns]">
                                        <?php for ($i = 1; $i <= 4; $i++): ?>
                                            <option value="<?php echo esc_attr((string) $i); ?>" <?php selected((int) $options['columns'], $i); ?>><?php echo esc_html((string) $i); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">Что показывать</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_excerpt]" value="1" <?php checked((int) $options['show_excerpt'], 1); ?>>
                                        Краткое описание записи
                                    </label>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[show_date]" value="<?php echo esc_attr((string) (int) $options['show_date']); ?>">
                                    <br>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_category_link]" value="1" <?php checked((int) $options['show_category_link'], 1); ?>>
                                        Ссылку «Все записи» у каждой рубрики
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mcb-card">
                    <h2>Блок «Новое»</h2>
                    <p>
                        Настройки шорткода <code>[mirv_latest_posts]</code>.
                        По умолчанию блок показывает записи только из рубрики «Блог».
                    </p>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="mcb-latest-count">Сколько записей показывать</label></th>
                                <td>
                                    <input
                                        id="mcb-latest-count"
                                        class="small-text"
                                        type="number"
                                        min="2"
                                        max="6"
                                        name="<?php echo esc_attr(self::OPTION); ?>[latest_count]"
                                        value="<?php echo esc_attr((string) $options['latest_count']); ?>"
                                    >
                                    <p class="description">Первая запись выводится крупно, остальные — компактными карточками.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="mcb-latest-category">Рубрика-источник</label></th>
                                <td>
                                    <select id="mcb-latest-category" name="<?php echo esc_attr(self::OPTION); ?>[latest_category]">
                                        <option value="0" <?php selected((int) $options['latest_category'], 0); ?>>Все рубрики</option>
                                        <?php foreach ($categories as $item): ?>
                                            <?php
                                            $category = $item['term'];
                                            $depth = (int) $item['depth'];
                                            $prefix = $depth > 0 ? str_repeat('— ', $depth) : '';
                                            ?>
                                            <option value="<?php echo esc_attr((string) $category->term_id); ?>" <?php selected((int) $options['latest_category'], (int) $category->term_id); ?>>
                                                <?php echo esc_html($prefix . $category->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <br>
                                    <label class="mcb-category-children-option">
                                        <input
                                            type="checkbox"
                                            name="<?php echo esc_attr(self::OPTION); ?>[latest_include_children]"
                                            value="1"
                                            <?php checked((int) $options['latest_include_children'], 1); ?>
                                        >
                                        Родительская и все вложенные
                                    </label>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="mcb-latest-note">Подпись справа</label></th>
                                <td>
                                    <input
                                        id="mcb-latest-note"
                                        class="regular-text"
                                        type="text"
                                        name="<?php echo esc_attr(self::OPTION); ?>[latest_note]"
                                        value="<?php echo esc_attr((string) $options['latest_note']); ?>"
                                        placeholder="Необязательно"
                                    >
                                    <p class="description">Оставь пустой — подписи рядом с «Новое» не будет.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mcb-card">
                    <h2>Рубрики на главной</h2>
                    <p>Добавь рубрики, которые надо вывести. Для каждой можно указать свой заголовок и количество записей.</p>

                    <table class="widefat striped mcb-sections-table">
                        <thead>
                            <tr>
                                <th>Вкл.</th>
                                <th>Заголовок блока</th>
                                <th>Рубрика</th>
                                <th>Записей</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="mcb-sections-body">
                            <?php
                            $sections = is_array($options['sections']) ? $options['sections'] : [];
                            foreach ($sections as $index => $section) {
                                self::render_section_row(
                                    (int) $index,
                                    $section,
                                    $categories,
                                    !empty($options['show_date'])
                                );
                            }
                            ?>
                        </tbody>
                    </table>

                    <script type="text/template" id="mcb-section-row-template">
                        <?php
                        self::render_section_row('__INDEX__', [
                            'enabled' => 1,
                            'title' => '',
                            'category' => 0,
                            'include_children' => 1,
                            'show_date' => 1,
                            'count' => 2,
                        ], $categories);
                        ?>
                    </script>

                    <p>
                        <button type="button" class="button button-secondary" id="mcb-add-section">Добавить рубрику</button>
                    </p>
                </div>

                <div class="mcb-card">
                    <h2>Как вставить на страницу</h2>
                    <p>В блочном редакторе добавь блок <strong>Шорткод</strong> и вставь:</p>
                    <p><code>[mirv_category_blocks]</code></p>
                    <p><code>[mirv_latest_posts]</code> — блок «Новое» с настройками выше.</p>
                    <p>Можно временно переопределить настройки прямо в шорткоде:</p>
                    <p><code>[mirv_category_blocks mode="random" layout="cards" columns="3"]</code></p>
                </div>

                <?php submit_button('Сохранить настройки'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Return all categories in parent/child display order.
     *
     * Each item is [term => WP_Term, depth => int].
     */
    private static function get_category_tree(): array
    {
        $terms = get_categories([
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (!$terms) {
            return [];
        }

        $children = [];
        foreach ($terms as $term) {
            $parent_id = (int) $term->parent;
            if (!isset($children[$parent_id])) {
                $children[$parent_id] = [];
            }
            $children[$parent_id][] = $term;
        }

        foreach ($children as &$siblings) {
            usort(
                $siblings,
                static function ($a, $b): int {
                    return strnatcasecmp((string) $a->name, (string) $b->name);
                }
            );
        }
        unset($siblings);

        $tree = [];
        $visited = [];

        $walk = static function (int $parent_id, int $depth) use (&$walk, &$tree, &$visited, $children): void {
            if (empty($children[$parent_id])) {
                return;
            }

            foreach ($children[$parent_id] as $term) {
                $term_id = (int) $term->term_id;
                if (isset($visited[$term_id])) {
                    continue;
                }

                $visited[$term_id] = true;
                $tree[] = [
                    'term' => $term,
                    'depth' => $depth,
                ];

                $walk($term_id, $depth + 1);
            }
        };

        $walk(0, 0);

        /*
         * Defensive fallback for malformed/orphaned hierarchies: never make a
         * category disappear from the selector merely because its parent is bad.
         */
        foreach ($terms as $term) {
            $term_id = (int) $term->term_id;
            if (!isset($visited[$term_id])) {
                $tree[] = [
                    'term' => $term,
                    'depth' => 0,
                ];
            }
        }

        return $tree;
    }


    private static function render_section_row($index, array $section, array $categories, bool $default_show_date = true): void
    {
        $base = self::OPTION . '[sections][' . $index . ']';
        $enabled = !empty($section['enabled']);
        $title = isset($section['title']) ? (string) $section['title'] : '';
        $selected_category = isset($section['category']) ? absint($section['category']) : 0;
        $include_children = array_key_exists('include_children', $section)
            ? !empty($section['include_children'])
            : true;
        $show_date = array_key_exists('show_date', $section)
            ? !empty($section['show_date'])
            : $default_show_date;
        $count = isset($section['count']) ? absint($section['count']) : 2;
        ?>
        <tr class="mcb-section-row">
            <td class="mcb-enabled-cell">
                <input type="checkbox" name="<?php echo esc_attr($base); ?>[enabled]" value="1" <?php checked($enabled); ?>>
            </td>
            <td>
                <input class="regular-text" type="text" name="<?php echo esc_attr($base); ?>[title]" value="<?php echo esc_attr($title); ?>" placeholder="Например: Последние рассказы">
            </td>
            <td>
                <select name="<?php echo esc_attr($base); ?>[category]">
                    <option value="0">— выбрать —</option>
                    <?php foreach ($categories as $item): ?>
                        <?php
                        $category = $item['term'];
                        $depth = (int) $item['depth'];
                        $prefix = $depth > 0 ? str_repeat('— ', $depth) : '';
                        ?>
                        <option value="<?php echo esc_attr((string) $category->term_id); ?>" <?php selected($selected_category, (int) $category->term_id); ?>>
                            <?php echo esc_html($prefix . $category->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label class="mcb-category-children-option">
                    <input type="checkbox" name="<?php echo esc_attr($base); ?>[include_children]" value="1" <?php checked($include_children); ?>>
                    Родительская и все вложенные
                </label>
                <label class="mcb-section-option">
                    <input type="checkbox" name="<?php echo esc_attr($base); ?>[show_date]" value="1" <?php checked($show_date); ?>>
                    Показывать дату публикации
                </label>
            </td>
            <td>
                <input class="small-text" type="number" min="1" max="12" name="<?php echo esc_attr($base); ?>[count]" value="<?php echo esc_attr((string) max(1, $count)); ?>">
            </td>
            <td>
                <button type="button" class="button-link-delete mcb-remove-section">Удалить</button>
            </td>
        </tr>
        <?php
    }

    /**
     * Latest posts block for the front page.
     *
     * Usage: [mirv_latest_posts]
     * Optional one-off overrides: count="5" category="1"
     */
    public static function latest_shortcode($atts): string
    {
        $options = self::get_options();

        $atts = shortcode_atts([
            'count' => '',
            'category' => '',
        ], $atts, 'mirv_latest_posts');

        $count = $atts['count'] !== ''
            ? absint($atts['count'])
            : (int) $options['latest_count'];
        $count = min(6, max(2, $count));

        $category_id = $atts['category'] !== ''
            ? absint($atts['category'])
            : (int) $options['latest_category'];

        $include_children = !empty($options['latest_include_children']);
        $note = trim((string) $options['latest_note']);

        $query_args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $count,
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        if ($category_id > 0 && term_exists($category_id, 'category')) {
            $query_args['tax_query'] = [
                [
                    'taxonomy' => 'category',
                    'field' => 'term_id',
                    'terms' => [$category_id],
                    'include_children' => $include_children,
                ],
            ];
        }

        // One published sticky post above the newest articles; never duplicate it.
        $sticky_ids = array_values(array_filter(array_map('absint', (array) get_option('sticky_posts', []))));
        $pinned_query = null;
        if ($sticky_ids) {
            $pinned_args = $query_args;
            $pinned_args['posts_per_page'] = 1;
            $pinned_args['post__in'] = $sticky_ids;
            $pinned_query = new WP_Query($pinned_args);
            if ($pinned_query->have_posts()) {
                $query_args['post__not_in'] = [(int) $pinned_query->posts[0]->ID];
            }
        }

        $query = new WP_Query($query_args);

        if (!$query->have_posts() && (!$pinned_query || !$pinned_query->have_posts())) {
            return '';
        }

        wp_enqueue_style(
            'mcb-frontend',
            plugins_url('assets/frontend.css', __FILE__),
            [],
            self::VERSION
        );

        $index = 0;

        ob_start();
        ?>
        <section class="mirv-latest" style="--mirv-latest-secondary: <?php echo esc_attr((string) max(1, $count - 1)); ?>;" aria-labelledby="mirv-latest-title">
            <div class="mirv-latest__head">
                <div>
                    <span class="mirv-latest__kicker">Блог</span>
                    <h2 id="mirv-latest-title">Новое</h2>
                </div>
                <?php if ($note !== ''): ?>
                    <p><?php echo esc_html($note); ?></p>
                <?php endif; ?>
            </div>

            <?php if ($pinned_query && $pinned_query->have_posts()): ?>
                <div class="mirv-latest__pinned">
                    <?php while ($pinned_query->have_posts()): $pinned_query->the_post(); ?>
                        <?php
                        $pinned_categories = get_the_category();
                        $pinned_category = !empty($pinned_categories) ? $pinned_categories[0] : null;
                        $pinned_img = has_post_thumbnail() ? get_the_post_thumbnail_url(get_the_ID(), 'large') : '';
                        $pinned_style = $pinned_img ? '--mcb-image: url("' . esc_url_raw($pinned_img) . '");' : '';
                        ?>
                        <article class="mirv-latest-card mirv-latest-card--featured mirv-latest-card--pinned">
                            <a class="mirv-latest-card__media" href="<?php the_permalink(); ?>"<?php if ($pinned_style): ?> style="<?php echo esc_attr($pinned_style); ?>"<?php endif; ?>>
                                <?php if (has_post_thumbnail()): ?>
                                    <?php echo get_the_post_thumbnail(get_the_ID(), 'medium_large', ['loading' => 'lazy', 'decoding' => 'async']); ?>
                                <?php else: ?>
                                    <span class="mirv-latest-card__placeholder" aria-hidden="true"><span>Mirv.Top</span></span>
                                <?php endif; ?>
                            </a>
                            <div class="mirv-latest-card__body">
                                <span class="mirv-latest__pin-label">Закреплено</span>
                                <div class="mirv-latest-card__meta">
                                    <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time>
                                    <?php if ($pinned_category): ?>
                                        <span aria-hidden="true">·</span>
                                        <a href="<?php echo esc_url(get_category_link($pinned_category)); ?>"><?php echo esc_html($pinned_category->name); ?></a>
                                    <?php endif; ?>
                                </div>
                                <h3 class="mirv-latest-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                                <p class="mirv-latest-card__excerpt"><?php echo esc_html(wp_trim_words(get_the_excerpt(), 28, '…')); ?></p>
                                <a class="mirv-latest-card__read" href="<?php the_permalink(); ?>">Читать →</a>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
                <?php wp_reset_postdata(); ?>
            <?php endif; ?>

            <div class="mirv-latest__grid">
                <?php while ($query->have_posts()): $query->the_post(); ?>
                    <?php
                    $featured = ($index === 0);
                    $card_img = has_post_thumbnail() ? get_the_post_thumbnail_url(get_the_ID(), 'large') : '';
                    $card_style = $card_img ? '--mcb-image: url("' . esc_url_raw($card_img) . '");' : '';
                    $categories = get_the_category();
                    $category = !empty($categories) ? $categories[0] : null;
                    ?>
                    <article class="mirv-latest-card<?php echo $featured ? ' mirv-latest-card--featured' : ''; ?>">
                        <a class="mirv-latest-card__media" href="<?php the_permalink(); ?>"<?php if ($card_style): ?> style="<?php echo esc_attr($card_style); ?>"<?php endif; ?>>
                            <?php if (has_post_thumbnail()): ?>
                                <?php
                                echo get_the_post_thumbnail(
                                    get_the_ID(),
                                    $featured ? 'medium_large' : 'medium',
                                    [
                                        'loading' => 'lazy',
                                        'decoding' => 'async',
                                    ]
                                );
                                ?>
                            <?php else: ?>
                                <span class="mirv-latest-card__placeholder" aria-hidden="true">
                                    <span>Mirv.Top</span>
                                </span>
                            <?php endif; ?>
                        </a>

                        <div class="mirv-latest-card__body">
                            <div class="mirv-latest-card__meta">
                                <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time>
                                <?php if ($category): ?>
                                    <span aria-hidden="true">·</span>
                                    <a href="<?php echo esc_url(get_category_link($category)); ?>"><?php echo esc_html($category->name); ?></a>
                                <?php endif; ?>
                            </div>

                            <h3 class="mirv-latest-card__title">
                                <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                            </h3>

                            <p class="mirv-latest-card__excerpt">
                                <?php echo esc_html(wp_trim_words(get_the_excerpt(), $featured ? 34 : 18, '…')); ?>
                            </p>

                            <?php if ($featured): ?>
                                <a class="mirv-latest-card__read" href="<?php the_permalink(); ?>">Читать →</a>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php $index++; ?>
                <?php endwhile; ?>
            </div>
        </section>
        <?php

        wp_reset_postdata();
        return (string) ob_get_clean();
    }


    public static function shortcode($atts): string
    {
        $options = self::get_options();

        $atts = shortcode_atts([
            'mode' => '',
            'layout' => '',
            'columns' => '',
        ], $atts, 'mirv_category_blocks');

        $mode = sanitize_key((string) $atts['mode']);
        if (!in_array($mode, ['latest', 'random'], true)) {
            $mode = $options['mode'];
        }

        $layout = sanitize_key((string) $atts['layout']);
        if (!in_array($layout, ['cards', 'list'], true)) {
            $layout = $options['layout'];
        }

        $columns = $atts['columns'] !== '' ? absint($atts['columns']) : (int) $options['columns'];
        $columns = min(4, max(1, $columns));

        $sections = isset($options['sections']) && is_array($options['sections']) ? $options['sections'] : [];
        $sections = array_filter($sections, static function ($section): bool {
            return is_array($section) && !empty($section['enabled']) && !empty($section['category']);
        });

        if (!$sections) {
            if (current_user_can('manage_options')) {
                return '<p class="mirv-catblocks-empty">MIRV Category Blocks: рубрики ещё не настроены.</p>';
            }
            return '';
        }

        wp_enqueue_style(
            'mcb-frontend',
            plugins_url('assets/frontend.css', __FILE__),
            [],
            self::VERSION
        );

        ob_start();
        ?>
        <div class="mirv-catblocks mirv-catblocks--<?php echo esc_attr($layout); ?> mirv-catblocks--columns-<?php echo esc_attr((string) $columns); ?>" style="--mcb-columns: <?php echo esc_attr((string) $columns); ?>;">
            <?php foreach ($sections as $section): ?>
                <?php echo self::render_frontend_section($section, $mode, $layout, $options); ?>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_frontend_section(array $section, string $mode, string $layout, array $options): string
    {
        $category_id = isset($section['category']) ? absint($section['category']) : 0;
        $category = get_category($category_id);
        if (!$category || is_wp_error($category)) {
            return '';
        }

        $count = isset($section['count']) ? absint($section['count']) : 2;
        $count = min(12, max(1, $count));

        $title = isset($section['title']) ? trim((string) $section['title']) : '';
        if ($title === '') {
            $title = $category->name;
        }

        $include_children = array_key_exists('include_children', $section)
            ? !empty($section['include_children'])
            : true;

        $show_date = array_key_exists('show_date', $section)
            ? !empty($section['show_date'])
            : !empty($options['show_date']);

        $query_args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $count,
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
            'orderby' => $mode === 'random' ? 'rand' : 'date',
            'order' => 'DESC',
            'tax_query' => [
                [
                    'taxonomy' => 'category',
                    'field' => 'term_id',
                    'terms' => [$category_id],
                    'include_children' => $include_children,
                ],
            ],
        ];

        $query = new WP_Query($query_args);

        ob_start();
        ?>
        <section class="mirv-catblocks-section">
            <div class="mirv-catblocks-section__head">
                <h2 class="mirv-catblocks-section__title"><?php echo esc_html($title); ?></h2>
                <?php if (!empty($options['show_category_link'])): ?>
                    <a class="mirv-catblocks-section__all" href="<?php echo esc_url(get_category_link($category)); ?>">Все записи →</a>
                <?php endif; ?>
            </div>

            <?php if ($query->have_posts()): ?>
                <div class="mirv-catblocks-items">
                    <?php while ($query->have_posts()): $query->the_post(); ?>
                        <article class="mirv-catblocks-item">
                            <?php if (has_post_thumbnail()): ?>
                                <a class="mirv-catblocks-item__thumb" href="<?php the_permalink(); ?>" aria-label="<?php echo esc_attr(get_the_title()); ?>">
                                    <?php the_post_thumbnail('mcb-thumb'); ?>
                                </a>
                            <?php endif; ?>

                            <div class="mirv-catblocks-item__body">
                                <?php if ($show_date): ?>
                                    <div class="mirv-catblocks-item__meta"><?php echo esc_html(get_the_date()); ?></div>
                                <?php endif; ?>

                                <h3 class="mirv-catblocks-item__title">
                                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                </h3>

                                <?php if (!empty($options['show_excerpt'])): ?>
                                    <p class="mirv-catblocks-item__excerpt">
                                        <?php echo esc_html(wp_trim_words(get_the_excerpt(), 24, '…')); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <p class="mirv-catblocks-empty">В этой рубрике пока нет опубликованных записей.</p>
            <?php endif; ?>
        </section>
        <?php

        wp_reset_postdata();
        return (string) ob_get_clean();
    }
}

MIRV_Category_Blocks::init();
