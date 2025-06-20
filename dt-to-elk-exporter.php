<?php
/**
 * Plugin Name: Disciple.Tools to ELK Exporter
 * Description: Exports Disciple.Tools data (contacts, groups, appointments, tasks) to ELK via Bulk API.
 * Version: 1.2
 * Author: Jon Ralls
 */

if (!defined('ABSPATH')) exit;

class DtElkExporter {

    public function __construct() {
        // Register activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Admin initialization hooks
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_manual_export'));

        // Cron job hooks
        add_action('init', array($this, 'setup_cron_export'));
        add_action('dtelk_cron_export', array($this, 'export_to_elk'));
    }

    public function activate() {
        // This method runs only once on plugin activation.
        // It should be kept as simple as possible to avoid activation failures.
        // No Disciple.Tools specific calls here, as DT might not be fully loaded/active yet.
        // Any default settings can be set here if needed, but not dependent on other plugins.
    }

    public function deactivate() {
        $timestamp = wp_next_scheduled('dtelk_cron_export');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'dtelk_cron_export');
        }
    }

    public function register_settings() {
        add_option('dtelk_elk_endpoint', '');
        add_option('dtelk_api_key', '');
        add_option('dtelk_index_name', '');
        add_option('dtelk_selected_post_types', []);
        
        register_setting('dtelk_options_group', 'dtelk_elk_endpoint');
        register_setting('dtelk_options_group', 'dtelk_api_key');
        register_setting('dtelk_options_group', 'dtelk_index_name');
        register_setting('dtelk_options_group', 'dtelk_selected_post_types');
    }

    public function add_admin_menu() {
        add_menu_page(
            'Disciple.Tools to ELK Exporter',
            'D.T. ELK Exporter',
            'manage_options',
            'dtelk-exporter', // This is the slug for your menu page
            array($this, 'exporter_admin_page'), // Callback function for the page content
            'dashicons-database-export',
            81
        );
    }

    public function exporter_admin_page() {
        $all_dt_post_types = [];
        // Check for DT_Posts only when rendering the page, which is after plugins_loaded
        if ( class_exists( 'DT_Posts' ) ) {
            $all_dt_post_types = DT_Posts::get_post_types();
        } else {
            // Keep this error notice as it indicates a critical dependency issue
            echo '<div class="notice notice-error"><p><strong>Error:</strong> Disciple.Tools core classes (DT_Posts) are not available. Please ensure Disciple.Tools is active and fully loaded.</p></div>';
        }
        
        $selected_post_types = get_option('dtelk_selected_post_types', []);
        ?>
        <div class="wrap">
            <h1>Disciple.Tools to ELK Exporter</h1>
            <p>Configure the settings below to connect to your Elasticsearch instance.</p>
            <form method="post" action="options.php">
                <?php settings_fields('dtelk_options_group'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">ELK Endpoint (/_bulk)</th>
                        <td><input type="text" name="dtelk_elk_endpoint" value="<?php echo esc_attr(get_option('dtelk_elk_endpoint')); ?>" size="60"></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">API Key</th>
                        <td><input type="text" name="dtelk_api_key" value="<?php echo esc_attr(get_option('dtelk_api_key')); ?>" size="60"></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Index Name</th>
                        <td><input type="text" name="dtelk_index_name" value="<?php echo esc_attr(get_option('dtelk_index_name')); ?>" size="40"></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Select Post Types to Export</th>
                        <td>
                            <?php
                            if ( ! empty( $all_dt_post_types ) ) {
                                foreach ($all_dt_post_types as $post_type_slug => $post_type_name) {
                                    $checked = in_array($post_type_slug, $selected_post_types) ? 'checked' : '';
                                    echo '<label><input type="checkbox" name="dtelk_selected_post_types[]" value="' . esc_attr($post_type_slug) . '" ' . $checked . '> ' . esc_html($post_type_name) . '</label><br>';
                                }
                            } else {
                                // Keep this warning notice if no DT post types are found
                                echo '<div class="notice notice-warning inline"><p><strong>Warning:</strong> No Disciple.Tools post types were found. This could mean Disciple.Tools is not fully active, or its post types are not yet registered.</p></div>';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr>
            <h2>Manual Export</h2>
            <p>Click the button below to trigger a manual export of all data to ELK.</p>
            <form method="post">
                <?php wp_nonce_field('dtelk_manual_export_nonce', 'dtelk_manual_export_nonce_field'); ?>
                <?php submit_button('Manual Export to ELK', 'primary', 'dtelk_manual_export'); ?>
            </form>
        </div>
        <?php
    }

    public function handle_manual_export() {
        if (isset($_POST['dtelk_manual_export']) && check_admin_referer('dtelk_manual_export_nonce', 'dtelk_manual_export_nonce_field')) {
            $result = $this->export_to_elk();
            if (is_wp_error($result)) {
                $msg = $result->get_error_message();
                $code = $result->get_error_code();
                // Keep the error notice for export failures
                add_action('admin_notices', function() use ($msg, $code) {
                    echo "<div class='notice notice-error'><p><strong>Export failed ($code):</strong> " . esc_html($msg) . "</p></div>";
                });
            } else {
                // Keep the success notice
                add_action('admin_notices', function() {
                    echo "<div class='notice notice-success is-dismissible'><p>Manual export to ELK completed successfully.</p></div>";
                });
            }
        }
    }

    public function export_to_elk() {
        $endpoint = rtrim(get_option('dtelk_elk_endpoint'), '/') . '/_bulk';
        $api_key = get_option('dtelk_api_key');
        $index = get_option('dtelk_index_name');
        $selected_post_types = get_option('dtelk_selected_post_types', []);

        if (!$endpoint || !$api_key || !$index) {
            return new WP_Error('missing_config', 'ELK settings (Endpoint, API Key, Index Name) are missing. Please configure them on the plugin settings page.');
        }

        if (empty($selected_post_types)) {
            return new WP_Error('no_post_types_selected', 'No Disciple.Tools post types have been selected for export. Please go to the plugin settings and select the post types you wish to export.');
        }

        // Critical check: Ensure DT_Posts class is available before doing any DT-specific operations
        if ( ! class_exists( 'DT_Posts' ) ) {
            return new WP_Error('dt_class_missing', 'Disciple.Tools core classes are not available. Cannot export data. Please ensure Disciple.Tools is active and fully functional.');
        }

        $lines = [];
        $total_posts_found = 0;

        foreach ($selected_post_types as $type) {
            $posts_query = get_posts([
                'post_type'   => $type,
                'numberposts' => -1, // Consider batching this for very large datasets
                'post_status' => 'any',
                'fields'      => 'ids',
                'suppress_filters' => true
            ]);

            if (empty($posts_query)) {
                // Removed the admin_notice here, as it's debugging info
                continue; // Move to the next selected post type
            }
            $total_posts_found += count($posts_query);


            foreach ($posts_query as $post_id) {
                $dt_post_data = DT_Posts::get_post($type, $post_id);
                
                if (is_object($dt_post_data) && !is_wp_error($dt_post_data)) {
                    $doc = (array) $dt_post_data;

                    $doc_for_elk = [];
                    foreach ($doc as $key => $value) {
                        if (is_object($value)) {
                            if (method_exists($value, 'to_array')) {
                                $value = $value->to_array();
                            } else {
                                $value = (array) $value;
                            }
                        } elseif (is_array($value)) {
                            $value = $this->recursive_array_to_array($value);
                        }
                        
                        $new_key = str_replace(['-', ' '], '_', $key);
                        $doc_for_elk[$new_key] = $value;
                    }

                    $json_index = json_encode(['index' => ['_index' => $index, '_id' => $post_id]]);
                    $json_doc = json_encode($doc_for_elk);

                    // Add check for json_encode failure (good practice for production)
                    if ( $json_index === false || $json_doc === false ) {
                        // In production, you might log this to WP_DEBUG_LOG if enabled,
                        // or a custom log file, but don't show an admin notice for every single failed post.
                        // For now, we'll just skip this record.
                        continue;
                    }

                    $lines[] = $json_index;
                    $lines[] = $json_doc;

                } else {
                    // Removed the admin_notice here, as it's debugging info.
                    // In production, you might log this to WP_DEBUG_LOG or a custom log.
                    // For example:
                    // error_log("DT_ELK_Exporter: Failed to retrieve data for Post ID: {$post_id} (Type: {$type}). Error: " . (is_wp_error($dt_post_data) ? $dt_post_data->get_error_message() : 'Unknown error'));
                    continue; // Skip to the next post
                }
            }
        }

        if ($total_posts_found === 0) {
            return new WP_Error('no_data_overall', 'No content was found across all selected post types to export.');
        }

        if (empty($lines)) {
            return new WP_Error('no_valid_docs', 'Posts were found, but no valid ELK documents could be generated from them. Check for issues with individual post data or encoding problems.');
        }

        $body = implode("\n", $lines) . "\n";

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/x-ndjson',
                'Authorization' => 'ApiKey ' . $api_key
            ],
            'body' => $body,
            'method' => 'POST',
            'timeout' => 60,
            'sslverify' => false // Consider changing to true for production if your ELK has valid SSL
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            $error_body = wp_remote_retrieve_body($response);
            return new WP_Error('elk_api_error', "ELK API Error (HTTP $code): " . $error_body);
        }

        return true;
    }

    /**
     * Helper function to recursively convert objects in arrays to arrays.
     * Useful for ensuring all nested data is in array format for JSON encoding.
     * @param array $array The array to process.
     * @return array The processed array.
     */
    private function recursive_array_to_array( $array ) {
        foreach ($array as $key => $value) {
            if (is_object($value)) {
                if (method_exists($value, 'to_array')) {
                    $array[$key] = $value->to_array();
                } else {
                    $array[$key] = (array) $value;
                }
            } elseif (is_array($value)) {
                $array[$key] = $this->recursive_array_to_array($value);
            }
        }
        return $array;
    }
}

// Instantiate the plugin class only AFTER all active plugins have been loaded.
// This is crucial for avoiding conflicts and ensuring dependent classes are available.
add_action('plugins_loaded', function() {
    new DtElkExporter();
});
