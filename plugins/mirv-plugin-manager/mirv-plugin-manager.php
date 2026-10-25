<?php
/**
 * Plugin Name: MIRV Plugin Manager
 * Description: Install and update MIRV WordPress plugins from verified GitHub monorepo releases.
 * Version: 0.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: MIRV
 * Update URI: https://github.com/mirivlad/mirv_wp_plugins
 * Text Domain: mirv-plugin-manager
 */
defined('ABSPATH') || exit;

final class MIRV_Plugin_Manager {
    private const REPOSITORY = 'mirivlad/mirv_wp_plugins';
    private const API_URL = 'https://api.github.com/repos/mirivlad/mirv_wp_plugins/releases?per_page=100';
    private const TRANSIENT = 'mirvwpm_releases_v1';
    private const PLUGINS = [
        'mirv-projects' => ['name'=>'MIRV Projects','file'=>'mirv-projects/mirv-projects.php','requires_php'=>'8.0',
            'description'=>'Portfolio: projects, categories, ordering and presentation.'],
        'mirv-category-blocks' => ['name'=>'MIRV Category Blocks','file'=>'mirv-category-blocks/mirv-category-blocks.php','requires_php'=>'7.4',
            'description'=>'Responsive post categories and recent-post shortcodes.'],
        'mirv-plugin-manager' => ['name'=>'MIRV Plugin Manager','file'=>'mirv-plugin-manager/mirv-plugin-manager.php','requires_php'=>'7.4',
            'description'=>'Install, verify and update plugins from GitHub Releases.'],
    ];
    private static function repo(): string { return 'https://github.com/'.self::REPOSITORY; }
    public static function init(): void {
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_post_mirvwpm_install',[__CLASS__,'install']);
        add_action('admin_post_mirvwpm_refresh',[__CLASS__,'refresh']);
        add_filter('update_plugins_github.com',[__CLASS__,'update_info'],10,4);
        add_filter('upgrader_pre_download',[__CLASS__,'verified_download'],10,4);
        add_filter('plugins_api',[__CLASS__,'plugin_information'],10,3);
    }
    private static function asset_url_ok(string $url, string $tag, string $file): bool {
        return $url === self::repo().'/releases/download/'.rawurlencode($tag).'/'.rawurlencode($file);
    }
    private static function releases(bool $refresh=false) {
        if (!$refresh) {
            $cache=get_transient(self::TRANSIENT);
            if (is_array($cache)) return $cache;
        }
        $response=wp_remote_get(self::API_URL,[
            'timeout'=>15,'redirection'=>3,
            'headers'=>['Accept'=>'application/vnd.github+json','X-GitHub-Api-Version'=>'2022-11-28'],
        ]);
        if (is_wp_error($response)) return $response;
        $status=wp_remote_retrieve_response_code($response);
        if ($status!==200) return new WP_Error('mirvwpm_github','GitHub releases API HTTP '.$status);
        $items=json_decode(wp_remote_retrieve_body($response),true);
        if (!is_array($items)) return new WP_Error('mirvwpm_json','Invalid GitHub JSON');
        $latest=[];
        foreach($items as $item) {
            if (!empty($item['draft']) || !empty($item['prerelease'])) continue;
            $tag=(string)($item['tag_name']??'');
            if (!preg_match('/^(mirv-projects|mirv-category-blocks|mirv-plugin-manager)-v(\d+\.\d+\.\d+)$/',$tag,$m)) continue;
            [$full,$slug,$version]=$m;
            if (!isset(self::PLUGINS[$slug])) continue;
            $zip=''; $hash='';
            foreach(($item['assets']??[]) as $asset) {
                if (($asset['name']??'')===$slug.'.zip')$zip=(string)($asset['browser_download_url']??'');
                if (($asset['name']??'')===$slug.'.zip.sha256')$hash=(string)($asset['browser_download_url']??'');
            }
            if (!$zip || !$hash || !self::asset_url_ok($zip,$tag,$slug.'.zip') ||
                !self::asset_url_ok($hash,$tag,$slug.'.zip.sha256')) continue;
            if (isset($latest[$slug]) && version_compare($version,$latest[$slug]['version'],'<=')) continue;
            $latest[$slug]=['slug'=>$slug,'version'=>$version,'tag'=>$tag,
                'package'=>$zip,'checksum'=>$hash,'url'=>self::repo()];
        }
        set_transient(self::TRANSIENT,$latest,20*MINUTE_IN_SECONDS);
        return $latest;
    }
    public static function update_info($update,$data,$file,$locales) {
        $slug='';
        foreach(self::PLUGINS as $candidate=>$info) {
            if ($file===$info['file']){$slug=$candidate;break;}
        }
        if (!$slug || ($data['UpdateURI']??'')!==self::repo()) return $update;
        $latest=self::releases();
        if (is_wp_error($latest) || !isset($latest[$slug]))return $update;
        $item=$latest[$slug];
        return ['id'=>self::repo(),'slug'=>$slug,'version'=>$item['version'],
            'url'=>$item['url'],'package'=>$item['package'],
            'requires'=>'5.8','requires_php'=>self::PLUGINS[$slug]['requires_php']];
    }
    public static function plugin_information($result,$action,$args) {
        if ($action!=='plugin_information' || !isset($args->slug,self::PLUGINS[$args->slug]))return $result;
        $slug=$args->slug;
        $releases=self::releases();
        if (is_wp_error($releases)||!isset($releases[$slug]))return $result;
        $info=self::PLUGINS[$slug];$rel=$releases[$slug];
        return (object)['name'=>$info['name'],'slug'=>$slug,'version'=>$rel['version'],
            'author'=>'MIRV','homepage'=>self::repo(),'requires'=>'5.8',
            'requires_php'=>$info['requires_php'],'download_link'=>$rel['package'],
            'sections'=>['description'=>esc_html($info['description']),
                'changelog'=>'<a href="'.esc_url(self::repo().'/releases/tag/'.$rel['tag']).'">GitHub Release</a>']];
    }
    private static function checksum(string $url) {
        $r=wp_remote_get($url,['timeout'=>20,'redirection'=>3]);
        if(is_wp_error($r))return $r;
        if(wp_remote_retrieve_response_code($r)!==200)
            return new WP_Error('mirvwpm_sha_http','Could not retrieve SHA-256');
        $data=trim(wp_remote_retrieve_body($r));
        if(!preg_match('/^([a-f0-9]{64})(?:\s+.*)?$/i',$data,$matches))
            return new WP_Error('mirvwpm_sha_format','Unexpected checksum format');
        return strtolower($matches[1]);
    }
    public static function verified_download($reply,$package,$upgrader,$hook_extra) {
        if($reply!==false)return $reply;
        $prefix=self::repo().'/releases/download/';
        if(strpos((string)$package,$prefix)!==0) return $reply;
        // Never fall back to an unverified download of a MIRV package.
        $releases=self::releases();
        if(is_wp_error($releases))return $releases;
        foreach($releases as $release) {
            if($package!==$release['package'])continue;
            $sha=self::checksum($release['checksum']);
            if(is_wp_error($sha))return $sha;
            if(!function_exists('download_url'))require_once ABSPATH.'wp-admin/includes/file.php';
            $path=download_url($package,60);
            if(is_wp_error($path))return $path;
            $actual=hash_file('sha256',$path);
            if(!is_string($actual)||!hash_equals($sha,strtolower($actual))) {
                @unlink($path);
                return new WP_Error('mirvwpm_sha_mismatch','Plugin archive SHA-256 mismatch. Installation blocked.');
            }
            return $path;
        }
        return new WP_Error('mirvwpm_release_unknown','Release archive is not in the trusted MIRV release catalogue.');
    }
    public static function menu(): void {
        add_plugins_page('MIRV Plugins','MIRV Plugins','install_plugins','mirv-plugins',[__CLASS__,'page']);
    }
    public static function page(): void {
        if(!current_user_can('install_plugins'))wp_die('Insufficient permissions');
        if(!function_exists('get_plugins'))require_once ABSPATH.'wp-admin/includes/plugin.php';
        $installed=get_plugins();$releases=self::releases();
        echo '<div class="wrap"><h1>MIRV Plugins</h1><p>Плагины из <a href="'.esc_url(self::repo()).'">GitHub</a>. Установка и обновления через WordPress с проверкой SHA-256.</p>';
        if(isset($_GET['installed']))echo '<div class="notice notice-success"><p>Плагин установлен. Активируйте его на странице «Плагины».</p></div>';
        if(isset($_GET['error']))echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['error']))).'</p></div>';
        if(is_wp_error($releases))echo '<div class="notice notice-warning"><p>'.esc_html($releases->get_error_message()).'</p></div>';
        echo '<p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=mirvwpm_refresh'),'mirvwpm_refresh')).'">Проверить релизы</a></p>';
        echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Плагин</th><th>Установлен</th><th>Последний релиз</th><th>Действие</th></tr></thead><tbody>';
        foreach(self::PLUGINS as $slug=>$info) {
            $installed_version=$installed[$info['file']]['Version']??'';
            $rel=is_array($releases)?($releases[$slug]??null):null;
            echo '<tr><td><strong>'.esc_html($info['name']).'</strong><br>'.esc_html($info['description']).'</td><td>'.($installed_version?esc_html($installed_version):'—').'</td><td>'.($rel?esc_html($rel['version']):'Нет релиза').'</td><td>';
            if(!$installed_version && $rel) {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mirvwpm_install"><input type="hidden" name="slug" value="'.esc_attr($slug).'">';
                wp_nonce_field('mirvwpm_install_'.$slug);
                submit_button('Установить','primary','submit',false);
                echo '</form>';
            } elseif($installed_version && $rel && version_compare($rel['version'],$installed_version,'>'))
                echo '<a href="'.esc_url(admin_url('plugins.php')).'">Обновить в WordPress</a>';
            elseif($installed_version)echo 'Установлен';
            else echo 'Недоступен';
            echo '</td></tr>';
        }
        echo '</tbody></table><p>Автоматические обновления включаются индивидуально на странице <a href="'.esc_url(admin_url('plugins.php')).'">«Плагины»</a>.</p></div>';
    }
    public static function refresh(): void {
        if(!current_user_can('install_plugins'))wp_die('Insufficient permissions');
        check_admin_referer('mirvwpm_refresh');
        delete_transient(self::TRANSIENT);
        wp_clean_plugins_cache(true);
        wp_safe_redirect(admin_url('plugins.php?page=mirv-plugins'));
        exit;
    }
    public static function install(): void {
        if(!current_user_can('install_plugins'))wp_die('Insufficient permissions');
        $slug=sanitize_key((string)($_POST['slug']??''));
        if(!isset(self::PLUGINS[$slug]))wp_die('Unknown plugin');
        check_admin_referer('mirvwpm_install_'.$slug);
        if(!function_exists('get_plugins'))require_once ABSPATH.'wp-admin/includes/plugin.php';
        $installed=get_plugins();
        if(isset($installed[self::PLUGINS[$slug]['file']]))wp_die('Plugin already installed');
        $releases=self::releases();
        if(is_wp_error($releases)||!isset($releases[$slug]))wp_die('No published GitHub release');
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        $upgrader=new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result=$upgrader->install($releases[$slug]['package']);
        if(!$result||is_wp_error($result)) {
            $message=is_wp_error($result)?$result->get_error_message():'Plugin installation failed';
            wp_safe_redirect(add_query_arg('error',$message,admin_url('plugins.php?page=mirv-plugins')));exit;
        }
        wp_safe_redirect(admin_url('plugins.php?page=mirv-plugins&installed=1'));exit;
    }
}
MIRV_Plugin_Manager::init();
