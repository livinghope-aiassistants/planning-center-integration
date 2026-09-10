<?php
/**
 * Planning Center Groups Handler
 * Part of Planning Center Integration Plugin v1.8.0
 * Minimal implementation - will be expanded in future versions
 * 
 * v1.8.0: GLOBAL TAG FILTER - Added toggle to use tag selection as global filter for all groups
 * v1.7.9: SHORTCODE GENERATOR - Added copy buttons and live shortcode builder for easy tag filtering
 * v1.7.8: ENHANCED - Individual tag selection within tag groups (granular control!)
 * v1.7.7: Added Tag Group filtering - admins can select which tag groups to include
 * v1.7.4: Added support for tags (filtering now works!)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PC_Groups_Handler {
    
    private $groups_api_url = 'https://api.planningcenteronline.com/groups/v2';
    private $table_name;
    // v1.9.22: Cache computed auth header so base64_encode isn't called on every request
    private $auth_header = null;

    /**
     * Return the Basic auth header string, computing it only once per request.
     */
    private function get_auth_header($app_id, $secret) {
        if ($this->auth_header === null) {
            $this->auth_header = 'Basic ' . base64_encode($app_id . ':' . $secret);
        }
        return $this->auth_header;
    }
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'pc_group_descriptions';
        
        // Register shortcode
        add_shortcode('planning_center_groups', array($this, 'display_groups_shortcode'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add cache clearing actions
        add_action('admin_post_clear_pc_groups_cache', array($this, 'clear_groups_cache'));
        
        // v1.0.3: Add debug API response action
        add_action('admin_post_pc_groups_debug_api_response', array($this, 'debug_api_response'));
    }
    
    /**
     * Activation function - creates database table
     */
    public function activate() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            group_id varchar(50) NOT NULL,
            group_name varchar(255) NOT NULL,
            original_description text,
            ai_description longtext,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            approved_at datetime,
            approved_by bigint(20),
            PRIMARY KEY  (id),
            KEY group_id (group_id),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Set default options
        add_option('pc_groups_app_id', '');
        add_option('pc_groups_secret', '');
        add_option('pc_groups_cache_duration', 3600);
        add_option('pc_groups_cards_per_row', 3);
        add_option('pc_groups_button_text', 'Learn More');
        add_option('pc_groups_button_color', '#007acc');
        add_option('pc_groups_default_image', '');
        add_option('pc_groups_ai_enabled', 0);
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('pc_groups_settings', 'pc_groups_app_id');
        register_setting('pc_groups_settings', 'pc_groups_secret');
        register_setting('pc_groups_settings', 'pc_groups_cache_duration', array('default' => 3600));
        register_setting('pc_groups_settings', 'pc_groups_display_mode', array('default' => 'grid'));
        register_setting('pc_groups_settings', 'pc_groups_cards_per_row', array('default' => 3));
        register_setting('pc_groups_settings', 'pc_groups_button_text', array('default' => 'Learn More'));
        register_setting('pc_groups_settings', 'pc_groups_button_color', array('default' => '#007acc'));
        register_setting('pc_groups_settings', 'pc_groups_button_text_color', array('default' => '#ffffff')); // v1.8.5
        register_setting('pc_groups_settings', 'pc_groups_open_in_new_tab', array('default' => 1)); // v1.8.6
        register_setting('pc_groups_settings', 'pc_groups_default_image');
        register_setting('pc_groups_settings', 'pc_groups_ai_enabled', array('default' => 0));
        register_setting('pc_groups_settings', 'pc_groups_ai_service', array('default' => 'claude'));
        register_setting('pc_groups_settings', 'pc_groups_ai_api_key');
        register_setting('pc_groups_settings', 'pc_groups_debug_mode', array('default' => 0));
        register_setting('pc_groups_settings', 'pc_groups_excluded_groups', array('default' => ''));
        register_setting('pc_groups_settings', 'pc_groups_see_all_enabled', array('default' => 0));
        register_setting('pc_groups_settings', 'pc_groups_see_all_position', array('default' => 'last'));
        register_setting('pc_groups_settings', 'pc_groups_see_all_text', array('default' => 'See All Groups'));
        register_setting('pc_groups_settings', 'pc_groups_see_all_link', array('default' => ''));
        register_setting('pc_groups_settings', 'pc_groups_see_all_image', array('default' => ''));
        register_setting('pc_groups_settings', 'pc_groups_selected_tags', array('default' => array()));
        register_setting('pc_groups_settings', 'pc_groups_enable_global_tag_filter', array('default' => 0));
        
        // v1.0.3: Color customization
        register_setting('pc_groups_settings', 'pc_groups_text_color', array('default' => '#2c3e50'));
        register_setting('pc_groups_settings', 'pc_groups_card_bg_color', array('default' => '#ffffff'));
        register_setting('pc_groups_settings', 'pc_groups_container_bg_color', array('default' => 'transparent'));
        register_setting('pc_groups_settings', 'pc_groups_icon_color', array('default' => '#667eea'));
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Enqueue media uploader
        wp_enqueue_media();
        
        // Get status info
        $api_connected = !empty(get_option('pc_groups_app_id')) && !empty(get_option('pc_groups_secret'));
        $groups_cached = get_transient('pc_groups_data') !== false;
        
        ?>
        <div class="wrap pc-admin-wrap">
            <div class="pc-admin-header">
                <h1>Planning Center Groups Settings <span class="version">v1.6.0</span></h1>
                <p>Configure your Groups API settings and display options</p>
            </div>
            
            <!-- Status Cards -->
            <div class="pc-status-cards">
                <div class="pc-status-card">
                    <h3>🔌 API Status</h3>
                    <div class="value" style="color: <?php echo $api_connected ? '#46b450' : '#dc3232'; ?>">
                        <?php echo $api_connected ? 'Connected' : 'Not Connected'; ?>
                    </div>
                    <span class="status <?php echo $api_connected ? 'status-success' : 'status-error'; ?>">
                        <?php echo $api_connected ? '✓ Active' : '✗ Setup Required'; ?>
                    </span>
                </div>
                
                <div class="pc-status-card">
                    <h3>📊 Groups Cache</h3>
                    <div class="value" style="color: <?php echo $groups_cached ? '#46b450' : '#666'; ?>">
                        <?php echo $groups_cached ? 'Cached' : 'Empty'; ?>
                    </div>
                    <span class="status <?php echo $groups_cached ? 'status-success' : 'status-warning'; ?>">
                        <?php echo $groups_cached ? 'Active' : 'Will load on next visit'; ?>
                    </span>
                </div>
            </div>
            
            <form action="options.php" method="post">
                <?php settings_fields('pc_groups_settings'); ?>
                
                <div class="pc-settings-card">
                    <div class="pc-settings-card-header">
                        <h3>🔌 Planning Center API Connection</h3>
                        <p>Enter your Planning Center API credentials to fetch groups data.</p>
                    </div>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_app_id">Application ID</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="pc_groups_app_id" 
                                       name="pc_groups_app_id" 
                                       value="<?php echo esc_attr(get_option('pc_groups_app_id')); ?>" 
                                       class="regular-text" />
                                <p class="description">Your Planning Center API Application ID</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_secret">Secret</label>
                            </th>
                            <td>
                                <?php 
                                $groups_secret = get_option('pc_groups_secret');
                                $masked_groups_secret = !empty($groups_secret) ? str_repeat('•', 32) : '';
                                ?>
                                <input type="password" 
                                       id="pc_groups_secret" 
                                       name="pc_groups_secret" 
                                       value="<?php echo esc_attr($groups_secret); ?>" 
                                       data-original="<?php echo esc_attr($groups_secret); ?>"
                                       data-masked="<?php echo esc_attr($masked_groups_secret); ?>"
                                       class="regular-text pc-secret-field" 
                                       readonly />
                                <button type="button" class="button pc-toggle-secret" data-target="pc_groups_secret">
                                    <span class="dashicons dashicons-visibility"></span> Show
                                </button>
                                <button type="button" class="button pc-edit-secret" data-target="pc_groups_secret">
                                    <span class="dashicons dashicons-edit"></span> Edit
                                </button>
                                <p class="description">Your Planning Center API Secret (hidden for security)</p>
                                
                                <script>
                                jQuery(document).ready(function($) {
                                    // Toggle secret visibility
                                    $('.pc-toggle-secret').on('click', function() {
                                        var target = $(this).data('target');
                                        var $field = $('#' + target);
                                        var $icon = $(this).find('.dashicons');
                                        var $text = $(this).contents().filter(function() { return this.nodeType === 3; });
                                        
                                        if ($field.attr('type') === 'password') {
                                            $field.attr('type', 'text');
                                            $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                                            $text[0].textContent = ' Hide';
                                        } else {
                                            $field.attr('type', 'password');
                                            $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                                            $text[0].textContent = ' Show';
                                        }
                                    });
                                    
                                    // Enable editing
                                    $('.pc-edit-secret').on('click', function() {
                                        var target = $(this).data('target');
                                        var $field = $('#' + target);
                                        
                                        if ($field.attr('readonly')) {
                                            $field.removeAttr('readonly').focus();
                                            $(this).html('<span class="dashicons dashicons-saved"></span> Done');
                                        } else {
                                            $field.attr('readonly', 'readonly');
                                            $(this).html('<span class="dashicons dashicons-edit"></span> Edit');
                                        }
                                    });
                                });
                                </script>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_cache_duration">Cache Duration (seconds)</label>
                            </th>
                            <td>
                                <input type="number" 
                                       id="pc_groups_cache_duration" 
                                       name="pc_groups_cache_duration" 
                                       value="<?php echo esc_attr(get_option('pc_groups_cache_duration', 3600)); ?>" 
                                       class="small-text" />
                                <p class="description">How long to cache group data (default: 3600 = 1 hour)</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="pc-settings-card">
                    <div class="pc-settings-card-header">
                        <h3>🏷️ Tag Group Filtering</h3>
                        <p>Select which Tag Groups to include. Only tags from selected groups will be available for filtering.</p>
                    </div>
                    
                    <table class="form-table">
                        <tr style="border-bottom: 2px solid #ddd;">
                            <th scope="row">
                                <label for="pc_groups_enable_global_tag_filter">🌐 Global Tag Filter</label>
                            </th>
                            <td>
                                <label style="display: inline-flex; align-items: center; padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; color: white; font-weight: 600; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                    <input type="checkbox" 
                                           id="pc_groups_enable_global_tag_filter" 
                                           name="pc_groups_enable_global_tag_filter" 
                                           value="1" 
                                           <?php checked(get_option('pc_groups_enable_global_tag_filter', 0), 1); ?>
                                           style="width: 20px; height: 20px; margin-right: 10px; cursor: pointer;" />
                                    <span style="font-size: 14px;">Enable Global Tag Filtering</span>
                                </label>
                                <p class="description" style="margin-top: 10px;">
                                    <strong>When ENABLED:</strong> Only groups with the tags you select below will be displayed anywhere on your site (unless overridden by shortcode).<br>
                                    <strong>When DISABLED:</strong> All groups are displayed, but you can still filter by tag using shortcodes.
                                </p>
                                <div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffb900; border-radius: 4px;">
                                    <strong>💡 Use Case Examples:</strong>
                                    <ul style="margin: 5px 0 0 20px; font-size: 13px;">
                                        <li><strong>Enabled:</strong> Only show "Open Enrollment" groups site-wide (hide closed groups globally)</li>
                                        <li><strong>Disabled:</strong> Show all groups by default, use shortcodes for page-specific filtering</li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label>Available Tag Groups</label>
                            </th>
                            <td>
                                <?php
                                // Fetch available tag groups with their tags
                                $available_tag_groups = $this->fetch_tag_groups();
                                $selected_tags = get_option('pc_groups_selected_tags', array());
                                
                                // Show debug output if debug mode is enabled
                                $debug_mode = get_option('pc_groups_debug_mode', 0);
                                if ($debug_mode && current_user_can('manage_options')) {
                                    echo '<div style="background: #fff3cd; border-left: 4px solid #ffb900; padding: 15px; margin-bottom: 20px;">';
                                    echo '<h4 style="margin-top: 0;">🔍 Tag Groups API Debug Output</h4>';
                                    
                                    // Make API calls to get debug info
                                    $app_id = get_option('pc_groups_app_id');
                                    $secret = get_option('pc_groups_secret');
                                    
                                    if (!empty($app_id) && !empty($secret)) {
                                        // Fetch tags
                                        $tags_url = $this->groups_api_url . '/tags?per_page=100&include=tag_group';
                                        
                                        $tags_response = wp_remote_get($tags_url, array(
                                            'headers' => array(
                                                'Authorization' => $this->get_auth_header($app_id, $secret),
                                            ),
                                            'timeout' => 15,
                                        ));
                                        
                                        if (!is_wp_error($tags_response)) {
                                            $tags_body = wp_remote_retrieve_body($tags_response);
                                            $tags_data = json_decode($tags_body, true);
                                            $tags_response_code = wp_remote_retrieve_response_code($tags_response);
                                            
                                            echo '<p><strong>Step 1: Fetch Tags</strong></p>';
                                            echo '<p><strong>API URL:</strong> <code style="background: white; padding: 2px 6px; border: 1px solid #ddd; font-size: 11px; display: block; margin-top: 5px; word-break: break-all;">' . esc_html($tags_url) . '</code></p>';
                                            echo '<p><strong>Response Code:</strong> <span style="color: ' . ($tags_response_code == 200 ? '#46b450' : '#dc3232') . '; font-weight: bold;">' . esc_html($tags_response_code) . '</span></p>';
                                            
                                            if (isset($tags_data['data'])) {
                                                $tag_count = count($tags_data['data']);
                                                echo '<p><strong>✅ Tags Found:</strong> ' . $tag_count . '</p>';
                                                
                                                // Show first few tags
                                                if ($tag_count > 0) {
                                                    echo '<details style="margin-top: 10px;">';
                                                    echo '<summary style="cursor: pointer; font-weight: bold; padding: 5px; background: white; border: 1px solid #ddd;">🏷️ Click to view first 3 tags</summary>';
                                                    echo '<pre style="background: white; padding: 10px; border: 1px solid #ddd; overflow-x: auto; margin-top: 5px; font-size: 11px;">';
                                                    for ($i = 0; $i < min(3, $tag_count); $i++) {
                                                        echo esc_html(print_r($tags_data['data'][$i], true));
                                                        if ($i < 2 && $i < $tag_count - 1) echo "\n---\n\n";
                                                    }
                                                    echo '</pre>';
                                                    echo '</details>';
                                                }
                                            } else {
                                                echo '<p style="color: #dc3232;"><strong>⚠️ No tags returned from API</strong></p>';
                                            }
                                        } else {
                                            echo '<p style="color: #dc3232;"><strong>Tags API Error:</strong> ' . esc_html($tags_response->get_error_message()) . '</p>';
                                        }
                                        
                                        echo '<hr style="margin: 15px 0; border: none; border-top: 1px solid #ddd;">';
                                        
                                        // Fetch tag groups
                                        $tag_groups_url = $this->groups_api_url . '/tag_groups?per_page=100';
                                        
                                        $tag_groups_response = wp_remote_get($tag_groups_url, array(
                                            'headers' => array(
                                                'Authorization' => $this->get_auth_header($app_id, $secret),
                                            ),
                                            'timeout' => 15,
                                        ));
                                        
                                        if (!is_wp_error($tag_groups_response)) {
                                            $tag_groups_response_code = wp_remote_retrieve_response_code($tag_groups_response);
                                            
                                            echo '<p><strong>Step 2: Fetch Tag Groups</strong></p>';
                                            echo '<p><strong>Response Code:</strong> <span style="color: ' . ($tag_groups_response_code == 200 ? '#46b450' : '#dc3232') . '; font-weight: bold;">' . esc_html($tag_groups_response_code) . '</span></p>';
                                            echo '<p><strong>✅ Tag Groups Found:</strong> ' . count($available_tag_groups) . '</p>';
                                        }
                                        
                                        echo '<hr style="margin: 15px 0; border: none; border-top: 1px solid #ddd;">';
                                        
                                        // Show what we parsed
                                        echo '<div style="margin-top: 10px; padding: 10px; background: white; border: 1px solid #ddd;">';
                                        echo '<strong>Step 3: Parsed Results (Tags Grouped by Tag Group)</strong>';
                                        echo '<ul style="margin: 5px 0 0 20px;">';
                                        foreach ($available_tag_groups as $tg) {
                                            $tag_names = array_map(function($t) { return $t['name']; }, $tg['tags']);
                                            echo '<li><strong>' . esc_html($tg['name']) . ':</strong> ' . count($tg['tags']) . ' tags';
                                            if (!empty($tg['tags'])) {
                                                echo '<br><span style="color: #666; font-size: 12px; margin-left: 20px;">(' . implode(', ', $tag_names) . ')</span>';
                                            }
                                            echo '</li>';
                                        }
                                        echo '</ul>';
                                        echo '</div>';
                                        
                                        // CRITICAL: Show selected tag IDs vs available tag IDs
                                        $selected_tag_ids = get_option('pc_groups_selected_tags', array());
                                        if (!empty($selected_tag_ids) || $debug_mode) {
                                            echo '<hr style="margin: 15px 0; border: none; border-top: 1px solid #ddd;">';
                                            echo '<div style="padding: 15px; background: #fff3cd; border: 1px solid #ffb900; border-radius: 4px;">';
                                            echo '<strong style="color: #856404;">🔍 Tag ID Verification</strong>';
                                            echo '<p style="margin: 10px 0 5px 0; font-size: 13px;">Checking if your selected tag IDs match the tags from API...</p>';
                                            
                                            // Get all available tag IDs from API
                                            $all_available_tag_ids = array();
                                            $tag_id_to_name = array();
                                            foreach ($available_tag_groups as $tg) {
                                                foreach ($tg['tags'] as $tag) {
                                                    $all_available_tag_ids[] = $tag['id'];
                                                    $tag_id_to_name[$tag['id']] = $tag['name'];
                                                }
                                            }
                                            
                                            $matching_ids = array_intersect($selected_tag_ids, $all_available_tag_ids);
                                            $non_matching_ids = array_diff($selected_tag_ids, $all_available_tag_ids);
                                            
                                            echo '<div style="margin-top: 10px; font-family: monospace; font-size: 12px; background: white; padding: 15px; border-radius: 4px;">';
                                            
                                            if (!empty($selected_tag_ids)) {
                                                echo '<p style="margin: 0 0 10px 0;"><strong style="color: #333;">📌 YOUR Selected Tag IDs:</strong> ' . count($selected_tag_ids) . ' total</p>';
                                                echo '<div style="margin-left: 20px; padding: 10px; background: #f9f9f9; border-left: 3px solid #667eea; overflow-x: auto;">';
                                                echo '<code style="font-size: 11px;">' . implode(', ', $selected_tag_ids) . '</code>';
                                                echo '</div>';
                                            }
                                            
                                            echo '<p style="margin: 15px 0 10px 0;"><strong style="color: #333;">🌐 Planning Center API Tag IDs:</strong> ' . count($all_available_tag_ids) . ' total</p>';
                                            echo '<div style="margin-left: 20px; padding: 10px; background: #f9f9f9; border-left: 3px solid #46b450; overflow-x: auto; max-height: 150px;">';
                                            echo '<code style="font-size: 11px;">';
                                            foreach ($tag_id_to_name as $id => $name) {
                                                echo $id . ' = "' . esc_html($name) . '"<br>';
                                            }
                                            echo '</code>';
                                            echo '</div>';
                                            
                                            if (!empty($selected_tag_ids)) {
                                                if (empty($matching_ids)) {
                                                    echo '<div style="margin-top: 15px; padding: 15px; background: #f8d7da; border: 2px solid #dc3232; border-radius: 4px;">';
                                                    echo '<p style="color: #721c24; font-weight: bold; margin: 0 0 10px 0; font-size: 14px;">❌ ZERO MATCHING TAG IDs!</p>';
                                                    echo '<p style="color: #721c24; margin: 0 0 10px 0;">Your selected tag IDs don\'t match ANY tags from Planning Center.</p>';
                                                    echo '<p style="margin: 0; padding: 10px; background: white; border-left: 3px solid #dc3232; font-family: sans-serif; font-size: 13px;">';
                                                    echo '<strong>Quick Fix:</strong><br>';
                                                    echo '1. Click "Select None" for all tag groups below<br>';
                                                    echo '2. Click Save Changes<br>';
                                                    echo '3. Refresh this page<br>';
                                                    echo '4. Re-check the tags you want<br>';
                                                    echo '5. Click Save Changes<br>';
                                                    echo '6. Clear Groups Cache<br>';
                                                    echo 'This will save the correct tag IDs from the API.';
                                                    echo '</p>';
                                                    echo '</div>';
                                                } else {
                                                    echo '<div style="margin-top: 15px; padding: 10px; background: #d4edda; border: 2px solid #46b450; border-radius: 4px;">';
                                                    echo '<p style="color: #155724; font-weight: bold; margin: 0 0 5px 0;">✅ ' . count($matching_ids) . ' tag(s) match!</p>';
                                                    echo '<p style="color: #155724; margin: 0; font-size: 12px;">Matching tag IDs: ' . implode(', ', $matching_ids) . '</p>';
                                                    if (!empty($non_matching_ids)) {
                                                        echo '<p style="color: #856404; margin: 10px 0 0 0; padding: 8px; background: #fff3cd; border-left: 3px solid #ffb900;">⚠️ ' . count($non_matching_ids) . ' stale tag ID(s): ' . implode(', ', $non_matching_ids) . '<br><span style="font-size: 11px;">These are old IDs that no longer exist. Uncheck and re-check tags to update.</span></p>';
                                                    }
                                                    echo '</div>';
                                                }
                                            }
                                            
                                            echo '</div>';
                                            echo '</div>';
                                        }
                                        
                                    } else {
                                        echo '<p style="color: #dc3232;">⚠️ API credentials not configured</p>';
                                    }
                                    
                                    echo '</div>';
                                }
                                
                                if (empty($available_tag_groups)) {
                                    echo '<p style="color: #dc3232;">⚠️ No tag groups found. Make sure API credentials are configured and you have tag groups in Planning Center.</p>';
                                } else {
                                    // Check if ANY tag group has tags
                                    $has_any_tags = false;
                                    foreach ($available_tag_groups as $tg) {
                                        if (!empty($tg['tags'])) {
                                            $has_any_tags = true;
                                            break;
                                        }
                                    }
                                    
                                    if (!$has_any_tags) {
                                        echo '<div style="background: #fff3cd; border-left: 4px solid #ffb900; padding: 15px; margin-bottom: 15px;">';
                                        echo '<p style="margin: 0 0 10px 0;"><strong>⚠️ No Tags Found in Your Tag Groups</strong></p>';
                                        echo '<p style="margin: 0 0 10px 0;">Your Tag Groups exist but don\'t have any tags assigned to them in Planning Center.</p>';
                                        echo '<p style="margin: 0;"><strong>To fix this:</strong></p>';
                                        echo '<ol style="margin: 5px 0 0 20px;">';
                                        echo '<li>Go to <a href="https://groups.planningcenteronline.com/tags" target="_blank">Planning Center Groups → Tags</a></li>';
                                        echo '<li>Create tags and assign them to your Tag Groups (Stage of Life, Neighborhood, etc.)</li>';
                                        echo '<li>Come back here and refresh this page</li>';
                                        echo '<li>Enable Debug Mode below and check your error log if tags still don\'t appear</li>';
                                        echo '</ol>';
                                        echo '</div>';
                                    }
                                    
                                    echo '<div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; background: #f9f9f9;">';
                                    echo '<p style="margin-top: 0;"><strong>Select Tags to Include:</strong> <span style="font-size: 12px; color: #666;">(Uncheck to exclude from filtering)</span></p>';
                                    
                                    // If nothing selected, default to "all selected"
                                    $all_tag_ids = array();
                                    foreach ($available_tag_groups as $tag_group) {
                                        foreach ($tag_group['tags'] as $tag) {
                                            $all_tag_ids[] = $tag['id'];
                                        }
                                    }
                                    
                                    if (empty($selected_tags)) {
                                        $selected_tags = $all_tag_ids;
                                    }
                                    
                                    foreach ($available_tag_groups as $tag_group) {
                                        $group_id = $tag_group['id'];
                                        $group_name = $tag_group['name'];
                                        $group_tags = $tag_group['tags'];
                                        
                                        // Count how many tags in this group are selected
                                        $selected_in_group = 0;
                                        foreach ($group_tags as $tag) {
                                            if (in_array($tag['id'], $selected_tags)) {
                                                $selected_in_group++;
                                            }
                                        }
                                        
                                        echo '<div style="margin-bottom: 20px; padding: 10px; background: white; border-left: 3px solid #667eea;">';
                                        
                                        // Tag Group Header with Select All/None
                                        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
                                        echo '<strong style="font-size: 14px; color: #333;">📂 ' . esc_html($group_name) . '</strong>';
                                        echo '<span style="font-size: 11px; color: #666;">(' . $selected_in_group . '/' . count($group_tags) . ' selected)</span>';
                                        echo '</div>';
                                        
                                        // Individual tags
                                        if (empty($group_tags)) {
                                            echo '<div style="padding: 5px 0; color: #999; font-style: italic;">No tags in this group</div>';
                                        } else {
                                            echo '<div style="padding-left: 20px;">';
                                            echo '<table style="width: 100%; border-collapse: collapse;">';
                                            
                                            foreach ($group_tags as $tag) {
                                                $tag_id = $tag['id'];
                                                $tag_name = $tag['name'];
                                                $checked = in_array($tag_id, $selected_tags) ? 'checked' : '';
                                                
                                                echo '<tr style="border-bottom: 1px solid #f0f0f0;">';
                                                echo '<td style="padding: 8px 0; width: 70%;">';
                                                echo '<label style="display: flex; align-items: center; cursor: pointer;">';
                                                echo '<input type="checkbox" name="pc_groups_selected_tags[]" value="' . esc_attr($tag_id) . '" ' . $checked . ' style="margin-right: 8px;" data-tag-name="' . esc_attr($tag_name) . '"> ';
                                                echo '<span style="font-size: 13px;">' . esc_html($tag_name) . '</span>';
                                                echo '</label>';
                                                echo '</td>';
                                                echo '<td style="padding: 8px 0; text-align: right; width: 30%;">';
                                                echo '<button type="button" class="button button-small pc-copy-single-tag" data-tag="' . esc_attr($tag_name) . '" style="font-size: 11px;">📋 Copy Shortcode</button>';
                                                echo '</td>';
                                                echo '</tr>';
                                            }
                                            
                                            echo '</table>';
                                            echo '</div>';
                                        }
                                        
                                        echo '</div>';
                                    }
                                    
                                    echo '</div>';
                                    echo '<p class="description">Uncheck individual tags to exclude them from being pulled. If no tags are selected, all tags will be included.</p>';
                                    echo '<p class="description"><strong>Tip:</strong> Use Ctrl+F to search for specific tags within the list above.</p>';
                                    
                                    // Shortcode Generator Section
                                    echo '<div style="margin-top: 25px; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">';
                                    echo '<h4 style="margin: 0 0 10px 0; color: white; font-size: 16px;">🔧 Shortcode Generator</h4>';
                                    echo '<p style="margin: 0 0 15px 0; color: rgba(255,255,255,0.9); font-size: 13px;">Build custom shortcodes to display groups by tag on different pages</p>';
                                    
                                    echo '<div style="background: white; padding: 15px; border-radius: 6px;">';
                                    echo '<p style="margin: 0 0 10px 0; font-weight: 600; font-size: 13px; color: #333;">Select tags to include in shortcode:</p>';
                                    
                                    // Create checkboxes for all selected tags
                                    echo '<div id="pc-shortcode-builder-tags" style="max-height: 200px; overflow-y: auto; margin-bottom: 15px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">';
                                    
                                    if (!empty($available_tag_groups)) {
                                        foreach ($available_tag_groups as $tag_group) {
                                            foreach ($tag_group['tags'] as $tag) {
                                                $tag_id = $tag['id'];
                                                $tag_name = $tag['name'];
                                                
                                                // Only show tags that are selected in main section
                                                if (in_array($tag_id, $selected_tags)) {
                                                    echo '<label style="display: inline-block; margin: 5px 10px 5px 0; padding: 5px 10px; background: white; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; transition: all 0.2s;">';
                                                    echo '<input type="checkbox" class="pc-shortcode-builder-checkbox" data-tag="' . esc_attr($tag_name) . '" style="margin-right: 5px;"> ';
                                                    echo '<span style="font-size: 12px;">' . esc_html($tag_name) . '</span>';
                                                    echo '</label>';
                                                }
                                            }
                                        }
                                    }
                                    
                                    echo '</div>';
                                    
                                    // Generated shortcode display
                                    echo '<div style="margin-bottom: 10px;">';
                                    echo '<label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 12px; color: #666;">Generated Shortcode:</label>';
                                    echo '<div style="display: flex; gap: 10px;">';
                                    echo '<input type="text" id="pc-generated-shortcode" value=\'[planning_center_groups]\' readonly style="flex: 1; padding: 10px; font-family: monospace; font-size: 12px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;">';
                                    echo '<button type="button" class="button button-primary" id="pc-copy-generated-shortcode" style="white-space: nowrap;">📋 Copy to Clipboard</button>';
                                    echo '</div>';
                                    echo '</div>';
                                    
                                    echo '<p style="margin: 0; font-size: 11px; color: #666;">💡 <strong>Tip:</strong> Paste this shortcode into any page or post to display filtered groups.</p>';
                                    
                                    echo '</div>'; // Close white inner box
                                    echo '</div>'; // Close generator section
                                }
                                ?>
                                
                                <script>
                                jQuery(document).ready(function($) {
                                    // Add Select All / Select None buttons
                                    $('.pc-settings-card').find('strong:contains("📂")').each(function() {
                                        var $header = $(this).parent();
                                        var $container = $(this).closest('div[style*="background: white"]');
                                        var $checkboxes = $container.find('input[type="checkbox"][name="pc_groups_selected_tags[]"]');
                                        
                                        if ($checkboxes.length > 0) {
                                            var $controls = $('<div style="margin-left: 10px;"></div>');
                                            $controls.append('<button type="button" class="button button-small select-all-tags" style="margin-right: 5px;">Select All</button>');
                                            $controls.append('<button type="button" class="button button-small select-none-tags">Select None</button>');
                                            $header.append($controls);
                                            
                                            $controls.find('.select-all-tags').on('click', function() {
                                                $checkboxes.prop('checked', true);
                                                updateCount();
                                            });
                                            
                                            $controls.find('.select-none-tags').on('click', function() {
                                                $checkboxes.prop('checked', false);
                                                updateCount();
                                            });
                                            
                                            function updateCount() {
                                                var selected = $checkboxes.filter(':checked').length;
                                                var total = $checkboxes.length;
                                                $header.find('span').first().text('(' + selected + '/' + total + ' selected)');
                                            }
                                            
                                            // Update count when checkboxes change
                                            $checkboxes.on('change', updateCount);
                                        }
                                    });
                                    
                                    // Copy single tag shortcode
                                    $(document).on('click', '.pc-copy-single-tag', function(e) {
                                        e.preventDefault();
                                        var $btn = $(this);
                                        var tag = $btn.data('tag');
                                        var shortcode = '[planning_center_groups tag="' + tag + '"]';
                                        
                                        // Copy to clipboard
                                        if (navigator.clipboard && navigator.clipboard.writeText) {
                                            navigator.clipboard.writeText(shortcode).then(function() {
                                                var originalText = $btn.html();
                                                $btn.html('✓ Copied!').css({'background': '#46b450', 'color': 'white', 'border-color': '#46b450'});
                                                setTimeout(function() {
                                                    $btn.html(originalText).css({'background': '', 'color': '', 'border-color': ''});
                                                }, 2000);
                                            }).catch(function(err) {
                                                alert('Failed to copy shortcode. Please copy manually: ' + shortcode);
                                            });
                                        } else {
                                            // Fallback for older browsers
                                            var $temp = $('<textarea>');
                                            $('body').append($temp);
                                            $temp.val(shortcode).select();
                                            try {
                                                document.execCommand('copy');
                                                var originalText = $btn.html();
                                                $btn.html('✓ Copied!').css({'background': '#46b450', 'color': 'white', 'border-color': '#46b450'});
                                                setTimeout(function() {
                                                    $btn.html(originalText).css({'background': '', 'color': '', 'border-color': ''});
                                                }, 2000);
                                            } catch (err) {
                                                alert('Failed to copy shortcode. Please copy manually: ' + shortcode);
                                            }
                                            $temp.remove();
                                        }
                                    });
                                    
                                    // Live preview: Update generated shortcode when checkboxes change
                                    $(document).on('change', '.pc-shortcode-builder-checkbox', function() {
                                        updateGeneratedShortcode();
                                    });
                                    
                                    function updateGeneratedShortcode() {
                                        var selectedTags = [];
                                        $('.pc-shortcode-builder-checkbox:checked').each(function() {
                                            selectedTags.push($(this).data('tag'));
                                        });
                                        
                                        var shortcode = '[planning_center_groups';
                                        if (selectedTags.length > 0) {
                                            shortcode += ' tag="' + selectedTags.join(', ') + '"';
                                        }
                                        shortcode += ']';
                                        
                                        $('#pc-generated-shortcode').val(shortcode);
                                    }
                                    
                                    // Copy generated shortcode to clipboard
                                    $(document).on('click', '#pc-copy-generated-shortcode', function(e) {
                                        e.preventDefault();
                                        var $btn = $(this);
                                        var shortcode = $('#pc-generated-shortcode').val();
                                        
                                        if (navigator.clipboard && navigator.clipboard.writeText) {
                                            navigator.clipboard.writeText(shortcode).then(function() {
                                                var originalText = $btn.text();
                                                $btn.text('✓ Copied!');
                                                setTimeout(function() {
                                                    $btn.text(originalText);
                                                }, 2000);
                                            }).catch(function(err) {
                                                alert('Failed to copy shortcode. Please copy manually: ' + shortcode);
                                            });
                                        } else {
                                            // Fallback for older browsers
                                            $('#pc-generated-shortcode').select();
                                            try {
                                                document.execCommand('copy');
                                                var originalText = $btn.text();
                                                $btn.text('✓ Copied!');
                                                setTimeout(function() {
                                                    $btn.text(originalText);
                                                }, 2000);
                                            } catch (err) {
                                                alert('Failed to copy shortcode. Please copy manually: ' + shortcode);
                                            }
                                        }
                                    });
                                    
                                    // Add hover effect for shortcode builder checkboxes
                                    $(document).on('mouseenter', '#pc-shortcode-builder-tags label', function() {
                                        $(this).css({'background': '#f0f0f0', 'border-color': '#667eea'});
                                    }).on('mouseleave', '#pc-shortcode-builder-tags label', function() {
                                        $(this).css({'background': 'white', 'border-color': '#ddd'});
                                    });
                                });
                                </script>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="pc-settings-card">
                    <div class="pc-settings-card-header">
                        <h3>🎨 Display Settings</h3>
                        <p>Customize how groups are displayed on your website.</p>
                    </div>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_display_mode">Display Mode</label>
                            </th>
                            <td>
                                <select id="pc_groups_display_mode" name="pc_groups_display_mode">
                                    <option value="grid" <?php selected(get_option('pc_groups_display_mode', 'grid'), 'grid'); ?>>Grid (Static)</option>
                                </select>
                                <p class="description">Currently set to Grid layout for group display.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_cards_per_row">Cards Per Row</label>
                            </th>
                            <td>
                                <select id="pc_groups_cards_per_row" name="pc_groups_cards_per_row">
                                    <option value="1" <?php selected(get_option('pc_groups_cards_per_row', 3), '1'); ?>>1 Card (Full Width)</option>
                                    <option value="2" <?php selected(get_option('pc_groups_cards_per_row', 3), '2'); ?>>2 Cards</option>
                                    <option value="3" <?php selected(get_option('pc_groups_cards_per_row', 3), '3'); ?>>3 Cards</option>
                                </select>
                                <p class="description">In carousel mode, this determines how many cards are visible at once.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_button_text">Button Text</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="pc_groups_button_text" 
                                       name="pc_groups_button_text" 
                                       value="<?php echo esc_attr(get_option('pc_groups_button_text', 'Learn More')); ?>" 
                                       class="regular-text" />
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_button_color">Button Color</label>
                            </th>
                            <td>
                                <input type="color" 
                                       id="pc_groups_button_color" 
                                       name="pc_groups_button_color" 
                                       value="<?php echo esc_attr(get_option('pc_groups_button_color', '#007acc')); ?>" />
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_open_in_new_tab">Open Links in New Tab</label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="pc_groups_open_in_new_tab" 
                                           name="pc_groups_open_in_new_tab" 
                                           value="1" 
                                           <?php checked(get_option('pc_groups_open_in_new_tab', 1), 1); ?> />
                                    Open group links in a new browser tab
                                </label>
                                <p class="description">
                                    <strong>Recommended:</strong> Keep checked to keep visitors on your website.<br>
                                    When enabled, clicking a group opens Planning Center in a new tab.
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_text_color">Text Color</label>
                            </th>
                            <td>
                                <input type="color" 
                                       id="pc_groups_text_color" 
                                       name="pc_groups_text_color" 
                                       value="<?php echo esc_attr(get_option('pc_groups_text_color', '#2c3e50')); ?>" />
                                <p class="description">Color for group titles and text</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_card_bg_color">Card Background Color</label>
                            </th>
                            <td>
                                <input type="color" 
                                       id="pc_groups_card_bg_color" 
                                       name="pc_groups_card_bg_color" 
                                       value="<?php echo esc_attr(get_option('pc_groups_card_bg_color', '#ffffff')); ?>" />
                                <p class="description">Background color for individual group cards</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_container_bg_color">Container Background Color</label>
                            </th>
                            <td>
                                <input type="color" 
                                       id="pc_groups_container_bg_color" 
                                       name="pc_groups_container_bg_color" 
                                       value="<?php echo esc_attr(get_option('pc_groups_container_bg_color', '#ffffff')); ?>" />
                                <p class="description">Background color for the entire groups section (use #ffffff for transparent white, or match your page background)</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_icon_color">Icon Color</label>
                            </th>
                            <td>
                                <input type="color" 
                                       id="pc_groups_icon_color" 
                                       name="pc_groups_icon_color" 
                                       value="<?php echo esc_attr(get_option('pc_groups_icon_color', '#667eea')); ?>" />
                                <p class="description">Color for location and schedule icons</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_default_image">Default Group Image</label>
                            </th>
                            <td>
                                <?php 
                                $default_image = get_option('pc_groups_default_image', '');
                                ?>
                                <input type="hidden" 
                                       id="pc_groups_default_image" 
                                       name="pc_groups_default_image" 
                                       value="<?php echo esc_attr($default_image); ?>" />
                                
                                <div class="pc-image-preview" style="margin-bottom: 10px;">
                                    <?php if ($default_image): ?>
                                        <img src="<?php echo esc_url($default_image); ?>" 
                                             style="max-width: 300px; height: auto; display: block; border: 1px solid #ddd; padding: 5px;" 
                                             id="pc_default_image_preview" />
                                    <?php else: ?>
                                        <div id="pc_default_image_preview" style="width: 300px; height: 169px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border: 1px solid #ddd;">
                                            <span style="color: #999;">No image selected</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <button type="button" class="button" id="pc_upload_default_image">
                                    <?php echo $default_image ? 'Change Image' : 'Upload Image'; ?>
                                </button>
                                
                                <?php if ($default_image): ?>
                                    <button type="button" class="button" id="pc_remove_default_image">Remove Image</button>
                                <?php endif; ?>
                                
                                <p class="description">This image will be used when a group doesn't have a header image. Recommended size: 1600x900 (16:9 ratio)</p>
                                
                                <script>
                                jQuery(document).ready(function($) {
                                    // Media uploader
                                    $('#pc_upload_default_image').on('click', function(e) {
                                        e.preventDefault();
                                        
                                        var image_frame;
                                        if (image_frame) {
                                            image_frame.open();
                                            return;
                                        }
                                        
                                        image_frame = wp.media({
                                            title: 'Select Default Group Image',
                                            multiple: false,
                                            library: {
                                                type: 'image'
                                            }
                                        });
                                        
                                        image_frame.on('select', function() {
                                            var attachment = image_frame.state().get('selection').first().toJSON();
                                            $('#pc_groups_default_image').val(attachment.url);
                                            $('#pc_default_image_preview').html('<img src="' + attachment.url + '" style="max-width: 300px; height: auto; display: block; border: 1px solid #ddd; padding: 5px;" />');
                                            $('#pc_upload_default_image').text('Change Image');
                                            if ($('#pc_remove_default_image').length === 0) {
                                                $('#pc_upload_default_image').after(' <button type="button" class="button" id="pc_remove_default_image">Remove Image</button>');
                                            }
                                        });
                                        
                                        image_frame.open();
                                    });
                                    
                                    // Remove image
                                    $(document).on('click', '#pc_remove_default_image', function(e) {
                                        e.preventDefault();
                                        $('#pc_groups_default_image').val('');
                                        $('#pc_default_image_preview').html('<div style="width: 300px; height: 169px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border: 1px solid #ddd;"><span style="color: #999;">No image selected</span></div>');
                                        $('#pc_upload_default_image').text('Upload Image');
                                        $(this).remove();
                                    });
                                });
                                </script>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_debug_mode">Debug Mode</label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="pc_groups_debug_mode" 
                                           name="pc_groups_debug_mode" 
                                           value="1" 
                                           <?php checked(get_option('pc_groups_debug_mode', 0), 1); ?> />
                                    Show debug information to administrators
                                </label>
                                <p class="description">Displays detailed diagnostic info on the frontend (admin-only) and logs to server error log. Enable when troubleshooting missing images or buttons.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Exclusion List -->
                <div class="pc-settings-card">
                    <div class="pc-settings-card-header">
                        <h3>🚫 Group Exclusions</h3>
                        <p>Exclude specific groups from displaying by entering their exact names (one per line).</p>
                    </div>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_excluded_groups">Excluded Groups</label>
                            </th>
                            <td>
                                <textarea id="pc_groups_excluded_groups" 
                                          name="pc_groups_excluded_groups" 
                                          rows="5" 
                                          class="large-text code"
                                          placeholder="Enter group names, one per line"><?php 
                                    echo esc_textarea(get_option('pc_groups_excluded_groups')); 
                                ?></textarea>
                                <p class="description">Enter the exact group name (one per line) to hide it from display</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- See All Groups Card -->
                <div class="pc-settings-card">
                    <div class="pc-settings-card-header">
                        <h3>🔗 "See All Groups" Card</h3>
                        <p>Add an optional card that links to a page showing all groups.</p>
                    </div>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_see_all_enabled">Enable Card</label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="pc_groups_see_all_enabled" 
                                           name="pc_groups_see_all_enabled" 
                                           value="1" 
                                           <?php checked(get_option('pc_groups_see_all_enabled', 0), 1); ?> />
                                    Show "See All Groups" card
                                </label>
                                <p class="description">Display a special card that links to your groups page</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_see_all_position">Card Position</label>
                            </th>
                            <td>
                                <select id="pc_groups_see_all_position" name="pc_groups_see_all_position">
                                    <option value="first" <?php selected(get_option('pc_groups_see_all_position', 'last'), 'first'); ?>>First (Before all groups)</option>
                                    <option value="last" <?php selected(get_option('pc_groups_see_all_position', 'last'), 'last'); ?>>Last (After all groups)</option>
                                </select>
                                <p class="description">Choose where the "See All Groups" card appears</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_see_all_text">Card Text</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="pc_groups_see_all_text" 
                                       name="pc_groups_see_all_text" 
                                       value="<?php echo esc_attr(get_option('pc_groups_see_all_text', 'See All Groups')); ?>" 
                                       class="regular-text" 
                                       placeholder="See All Groups" />
                                <p class="description">Text to display on the card</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_see_all_link">Link URL</label>
                            </th>
                            <td>
                                <input type="url" 
                                       id="pc_groups_see_all_link" 
                                       name="pc_groups_see_all_link" 
                                       value="<?php echo esc_attr(get_option('pc_groups_see_all_link')); ?>" 
                                       class="regular-text" 
                                       placeholder="https://yoursite.com/groups" />
                                <p class="description">URL where users can see all groups</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_see_all_image">Card Image</label>
                            </th>
                            <td>
                                <?php $see_all_image = get_option('pc_groups_see_all_image', ''); ?>
                                <input type="hidden" 
                                       id="pc_groups_see_all_image" 
                                       name="pc_groups_see_all_image" 
                                       value="<?php echo esc_attr($see_all_image); ?>" />
                                <div class="pc-image-preview-wrapper">
                                    <?php if ($see_all_image): ?>
                                        <img src="<?php echo esc_url($see_all_image); ?>" 
                                             style="max-width: 300px; height: auto; display: block; margin-bottom: 10px; border: 1px solid #ddd;" 
                                             id="pc_groups_see_all_image_preview" />
                                    <?php else: ?>
                                        <div id="pc_groups_see_all_image_preview" style="width: 300px; height: 169px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border: 1px solid #ddd;">
                                            <span style="color: #999;">No image selected</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="button" id="pc_groups_see_all_upload_button">
                                    <?php echo $see_all_image ? 'Change Image' : 'Upload Image'; ?>
                                </button>
                                <?php if ($see_all_image): ?>
                                    <button type="button" class="button" id="pc_groups_see_all_remove_button">Remove Image</button>
                                <?php endif; ?>
                                <p class="description">Custom image for the "See All Groups" card. Recommended size: 1200x675 (16:9 ratio)</p>
                                
                                <script>
                                jQuery(document).ready(function($) {
                                    // Media uploader for See All Groups image
                                    var mediaUploader;
                                    
                                    $('#pc_groups_see_all_upload_button').on('click', function(e) {
                                        e.preventDefault();
                                        
                                        if (mediaUploader) {
                                            mediaUploader.open();
                                            return;
                                        }
                                        
                                        mediaUploader = wp.media({
                                            title: 'Select See All Groups Image',
                                            button: { text: 'Use this image' },
                                            multiple: false
                                        });
                                        
                                        mediaUploader.on('select', function() {
                                            var attachment = mediaUploader.state().get('selection').first().toJSON();
                                            $('#pc_groups_see_all_image').val(attachment.url);
                                            $('#pc_groups_see_all_image_preview').html('<img src="' + attachment.url + '" style="max-width: 300px; height: auto; display: block; border: 1px solid #ddd;" />');
                                            $('#pc_groups_see_all_upload_button').text('Change Image');
                                            if ($('#pc_groups_see_all_remove_button').length === 0) {
                                                $('#pc_groups_see_all_upload_button').after('<button type="button" class="button" id="pc_groups_see_all_remove_button">Remove Image</button>');
                                            }
                                        });
                                        
                                        mediaUploader.open();
                                    });
                                    
                                    // Remove image
                                    $(document).on('click', '#pc_groups_see_all_remove_button', function(e) {
                                        e.preventDefault();
                                        $('#pc_groups_see_all_image').val('');
                                        $('#pc_groups_see_all_image_preview').html('<div style="width: 300px; height: 169px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border: 1px solid #ddd;"><span style="color: #999;">No image selected</span></div>');
                                        $('#pc_groups_see_all_upload_button').text('Upload Image');
                                        $(this).remove();
                                    });
                                });
                                </script>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="pc-settings-card">
                    <div class="pc-settings-card-header">
                        <h3>🤖 AI Enhancement Settings (Optional)</h3>
                        <p>Automatically enhance group descriptions using AI to make them more engaging and consistent.</p>
                    </div>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="pc_groups_ai_enabled">Enable AI Enhancement</label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="pc_groups_ai_enabled" 
                                           name="pc_groups_ai_enabled" 
                                           value="1" 
                                           <?php checked(get_option('pc_groups_ai_enabled'), 1); ?> />
                                    Enhance group descriptions with AI
                                </label>
                                <p class="description">When enabled, AI will rewrite group descriptions to be more engaging</p>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- Collapsible AI Settings Section -->
                    <div id="pc-groups-ai-settings-section" style="<?php echo get_option('pc_groups_ai_enabled') ? '' : 'display: none;'; ?>">
                        <div style="background: #f9f9f9; border-left: 4px solid #007acc; padding: 20px; margin: 20px 0;">
                            <h4 style="margin-top: 0; color: #23282d;">🤖 AI Configuration</h4>
                            <p style="color: #666; margin: 0;">AI-powered description enhancement for Groups is coming in a future update. This section will include:</p>
                            <ul style="color: #666; margin: 10px 0 0 20px;">
                                <li>AI service selection (Claude, ChatGPT)</li>
                                <li>Custom enhancement instructions</li>
                                <li>Church information for accurate descriptions</li>
                                <li>Approval workflow options</li>
                            </ul>
                        </div>
                    </div>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        // Toggle AI settings visibility for Groups
                        $('#pc_groups_ai_enabled').on('change', function() {
                            if ($(this).is(':checked')) {
                                $('#pc-groups-ai-settings-section').slideDown(300);
                            } else {
                                $('#pc-groups-ai-settings-section').slideUp(300);
                            }
                        });
                    });
                    </script>
                </div>
                
                <?php submit_button('Save Settings'); ?>
            </form>
            
            <!-- Cache Management -->
            <div class="pc-settings-card">
                <div class="pc-settings-card-header">
                    <h3>🗄️ Cache Management</h3>
                    <p>Clear cached data if you need to force a refresh from Planning Center.</p>
                </div>
                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=clear_pc_groups_cache'), 'clear_cache'); ?>" 
                   class="button button-secondary">Clear Groups Cache</a>
            </div>
            
            <?php if (get_option('pc_groups_debug_mode')): ?>
            <div class="pc-settings-card" style="background: #fff3cd; border-left: 4px solid #ffb900;">
                <h3>🔍 Debug Tools</h3>
                <p>View raw API responses to diagnose image and button issues:</p>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block;">
                    <input type="hidden" name="action" value="pc_groups_debug_api_response">
                    <?php wp_nonce_field('pc_groups_debug_api_response_nonce'); ?>
                    <?php submit_button('View Raw API Response', 'secondary', 'submit', false); ?>
                </form>
                <p class="description" style="margin-top: 10px;">
                    This will show you exactly what data Planning Center is returning for groups, including all available fields.
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * AI Descriptions page (placeholder for v1.0.0)
     */
    public function ai_descriptions_page() {
        ?>
        <div class="wrap pc-admin-wrap">
            <h1>AI Descriptions for Groups</h1>
            <div class="pc-menu-card">
                <h2>Coming Soon</h2>
                <p>AI-enhanced group descriptions with approval workflow will be available in a future version.</p>
                <p>This feature will allow you to:</p>
                <ul>
                    <li>Automatically generate engaging group descriptions</li>
                    <li>Review and approve AI suggestions before publishing</li>
                    <li>Track description performance and approval history</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * Display groups shortcode
     */
    public function display_groups_shortcode($atts) {
        // v1.0.3: Basic test to verify shortcode is running
        $test_output = '<!-- Planning Center Groups Shortcode Running -->';
        
        $atts = shortcode_atts(array(
            'limit' => -1,  // Changed from 10 to -1 to show all groups by default
            'type' => '',
            'tag' => '',  // New: filter by tag(s) - comma-separated
            'cards' => 0,
            'mode' => '',  // New: override display_mode ('grid' or 'carousel')
            'name_filter' => '',  // New: filter by name keywords
            'enrollment_filter' => 'all',  // New: 'all', 'open', 'closed'
            'button_color' => '', // v1.8.5: override button color
            'btncolor' => '', // v1.9.7: Alternative parameter name
            'btn_bg' => '', // v1.9.8: Parameter without 'color' word
            'button_text_color' => '', // v1.8.5: override button text color
            'card_bg_color' => '', // v1.8.5: override card background color
        ), $atts);
        
        $groups = $this->fetch_groups();
        
        if (is_wp_error($groups)) {
            if (current_user_can('manage_options')) {
                return '<div class="pc-error">Error fetching groups: ' . esc_html($groups->get_error_message()) . '</div>';
            }
            return '<div class="pc-error">Unable to load groups at this time.</div>';
        }
        
        // v1.0.3: Show debug info for admins (BEFORE empty check so it always displays)
        $output = ''; // Initialize output buffer
        $debug_output = '';
        $debug_mode = get_option('pc_groups_debug_mode', 0);
        if (current_user_can('manage_options') && $debug_mode) {
            $debug_output = '<div class="pc-groups-debug" style="background: #fff3cd; padding: 20px; margin: 20px 0; border-left: 4px solid #ffb900; font-family: monospace; white-space: pre-wrap; font-size: 12px;">';
            $debug_output .= '<strong style="font-size: 14px; display: block; margin-bottom: 10px;">🔍 GROUPS DEBUG INFO (Only visible to admins)</strong>';
            $debug_output .= "Shortcode executed successfully!\n";
            $debug_output .= "Debug mode is: " . ($debug_mode ? 'ENABLED' : 'DISABLED') . "\n";
            $debug_output .= "User is admin: " . (current_user_can('manage_options') ? 'YES' : 'NO') . "\n";
            
            // Show global filter status
            $global_filter = get_option('pc_groups_enable_global_tag_filter', 0);
            $selected_tag_count = count(get_option('pc_groups_selected_tags', array()));
            $debug_output .= "Global tag filter: " . ($global_filter ? '🟢 ENABLED' : '🔴 DISABLED') . "\n";
            if ($global_filter) {
                $debug_output .= "  → Only showing groups with selected tags (" . $selected_tag_count . " tags selected)\n";
            }
            $debug_output .= "\n";
            
            // Check if data came from cache
            $cache_key = 'pc_groups_data';
            $cache_exists = get_transient($cache_key);
            $debug_output .= "Cache status: " . ($cache_exists !== false ? 'HIT (using cached data)' : 'MISS (fetched from API)') . "\n";
            $debug_output .= "Groups fetched from API...\n\n";
            
            $debug_output .= "\n=== GROUPS DATA RECEIVED IN SHORTCODE ===\n";
            $debug_output .= "Total groups: " . count($groups) . "\n";
            
            if (count($groups) === 0) {
                $debug_output .= "\n⚠️ WARNING: Zero groups returned!\n";
                $debug_output .= "Possible causes:\n";
                $debug_output .= "  1. Old empty cache still active → Clear Groups Cache\n";
                $debug_output .= "  2. API credentials incorrect\n";
                $debug_output .= "  3. All groups are archived\n";
                $debug_output .= "\nTry: Clear Groups Cache button in settings\n";
            }
            $debug_output .= "\n";
            
            foreach ($groups as $idx => $group) {
                $debug_output .= "Group #" . ($idx + 1) . ": " . esc_html($group['name']) . "\n";
                $debug_output .= "  - Type: " . esc_html($group['type']) . "\n";
                
                // CRITICAL: Show tag details
                $debug_output .= "  - Tags array: " . (isset($group['tags']) ? 'EXISTS' : 'MISSING') . "\n";
                if (isset($group['tags'])) {
                    $debug_output .= "  - Tags count: " . count($group['tags']) . "\n";
                    if (!empty($group['tags'])) {
                        $debug_output .= "  - Tags: " . implode(', ', array_map('esc_html', $group['tags'])) . "\n";
                    } else {
                        $debug_output .= "  - Tags: EMPTY ARRAY\n";
                    }
                }
                
                if (!empty($group['enrollment_strategy'])) {
                    $debug_output .= "  - Enrollment: " . esc_html($group['enrollment_strategy']) . "\n";
                }
                $debug_output .= "  - Has image_url: " . (!empty($group['image_url']) ? 'YES' : 'NO') . "\n";
                if (!empty($group['image_url'])) {
                    $debug_output .= "  - Image URL: " . esc_html($group['image_url']) . "\n";
                }
                $debug_output .= "  - Has group_url: " . (!empty($group['group_url']) ? 'YES' : 'NO') . "\n";
                if (!empty($group['group_url'])) {
                    $debug_output .= "  - Group URL: " . esc_html($group['group_url']) . "\n";
                }
                $debug_output .= "  - All keys: " . implode(', ', array_keys($group)) . "\n\n";
            }
            
            $debug_output .= '</div>';
        }
        
        if (empty($groups)) {
            return $debug_output . '<div class="pc-no-groups">No groups available at this time.</div>';
        }
        
        // Apply filters
        $initial_count = count($groups);
        
        // v1.0.3: Filter by name keywords
        if (!empty($atts['name_filter'])) {
            $name_keywords = array_map('trim', explode(',', $atts['name_filter']));
            $groups = array_filter($groups, function($group) use ($name_keywords) {
                foreach ($name_keywords as $keyword) {
                    if (stripos($group['name'], $keyword) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }
        
        // v1.0.3: Filter by enrollment status
        if ($atts['enrollment_filter'] !== 'all') {
            $groups = array_filter($groups, function($group) use ($atts) {
                if (!isset($group['enrollment_strategy'])) {
                    return true; // If no enrollment data, include it
                }
                
                $strategy = $group['enrollment_strategy'];
                
                if ($atts['enrollment_filter'] === 'open') {
                    return in_array($strategy, ['open_signup', 'request_to_join']);
                } elseif ($atts['enrollment_filter'] === 'closed') {
                    return !in_array($strategy, ['open_signup', 'request_to_join']);
                }
                
                return true;
            });
        }
        
        // v1.0.2: Filter by type
        if (!empty($atts['type'])) {
            $groups = array_filter($groups, function($group) use ($atts) {
                return stripos($group['type'], $atts['type']) !== false;
            });
        }
        
        // v1.7.4: Filter by tag(s) - case-insensitive, OR logic (like events)
        if (!empty($atts['tag'])) {
            $requested_tags = array_map('trim', explode(',', $atts['tag']));
            
            $groups = array_filter($groups, function($group) use ($requested_tags) {
                if (empty($group['tags'])) {
                    return false;
                }
                // Check if group has ANY of the requested tags (OR logic)
                // Case-insensitive comparison
                foreach ($requested_tags as $requested_tag) {
                    foreach ($group['tags'] as $group_tag) {
                        if (strcasecmp($requested_tag, $group_tag) === 0) {
                            return true;
                        }
                    }
                }
                return false;
            });
        }
        
        // v1.5.0: Filter by exclusion list
        $excluded_groups_raw = get_option('pc_groups_excluded_groups', '');
        $excluded_groups = array_filter(array_map('trim', explode("\n", $excluded_groups_raw)));
        
        if (!empty($excluded_groups)) {
            $groups = array_filter($groups, function($group) use ($excluded_groups) {
                return !$this->is_group_excluded($group['name'], $excluded_groups);
            });
        }
        
        // Add filter debug info after filtering
        $debug_mode = get_option('pc_groups_debug_mode', 0);
        if (current_user_can('manage_options') && $debug_mode) {
            $filter_debug = '<div class="pc-groups-debug" style="background: #d4edda; padding: 20px; margin: 20px 0; border-left: 4px solid #28a745; font-family: monospace; white-space: pre-wrap; font-size: 12px;">';
            $filter_debug .= '<strong style="font-size: 14px; display: block; margin-bottom: 10px;">🔍 FILTER RESULTS</strong>';
            $filter_debug .= "Total groups before filtering: " . $initial_count . "\n";
            $filter_debug .= "Total groups after filtering: " . count($groups) . "\n\n";
            
            if (!empty($atts['name_filter'])) {
                $filter_debug .= "Name filter: '" . esc_html($atts['name_filter']) . "'\n";
            }
            if ($atts['enrollment_filter'] !== 'all') {
                $filter_debug .= "Enrollment filter: '" . esc_html($atts['enrollment_filter']) . "'\n";
            }
            if (!empty($atts['type'])) {
                $filter_debug .= "Type filter: '" . esc_html($atts['type']) . "'\n";
            }
            if (!empty($atts['tag'])) {
                $filter_debug .= "Tag filter: '" . esc_html($atts['tag']) . "'\n";
            }
            
            $filter_debug .= "\nGroups that matched filters:\n";
            foreach ($groups as $group) {
                $filter_debug .= "  - " . esc_html($group['name']);
                if (isset($group['enrollment_strategy'])) {
                    $filter_debug .= " (enrollment: " . esc_html($group['enrollment_strategy']) . ")";
                }
                $filter_debug .= "\n";
            }
            
            $filter_debug .= '</div>';
            $debug_output .= $filter_debug;
        }
        
        // Apply limit
        if ($atts['limit'] > 0) {
            $groups = array_slice($groups, 0, $atts['limit']);
        }
        
        // Get display settings
        $display_mode = !empty($atts['mode']) ? $atts['mode'] : get_option('pc_groups_display_mode', 'grid');
        $cards_per_row = $atts['cards'] > 0 ? $atts['cards'] : get_option('pc_groups_cards_per_row', 3);
        $button_text = get_option('pc_groups_button_text', 'Learn More');
        
        // v1.9.9: Check if events shortcode has run on this page (inherit colors from events)
        global $pc_events_button_color, $pc_events_button_text_color;
        
        // v1.8.5: Use shortcode colors if provided, otherwise inherit from events, otherwise use global settings
        if (!empty($atts['button_color'])) {
            $button_color = $atts['button_color'];
        } elseif (!empty($atts['btncolor'])) {
            $button_color = $atts['btncolor'];
        } elseif (!empty($atts['btn_bg'])) {
            $button_color = $atts['btn_bg'];
        } elseif (!empty($pc_events_button_color)) {
            // v1.9.9: Inherit from events shortcode on same page
            $button_color = $pc_events_button_color;
        } else {
            $button_color = get_option('pc_groups_button_color', '#007acc');
        }
        
        if (!empty($atts['button_text_color'])) {
            $button_text_color = $atts['button_text_color'];
        } elseif (!empty($pc_events_button_text_color)) {
            // v1.9.9: Inherit from events shortcode on same page
            $button_text_color = $pc_events_button_text_color;
        } else {
            $button_text_color = get_option('pc_groups_button_text_color', '#ffffff');
        }
        
        $card_bg_color = !empty($atts['card_bg_color']) ? $atts['card_bg_color'] : get_option('pc_groups_card_bg_color', '#ffffff');
        
        // v1.9.6: Add # to colors if missing (WordPress strips # from shortcode attributes)
        if (!empty($button_color) && substr($button_color, 0, 1) !== '#') {
            $button_color = '#' . $button_color;
        }
        if (!empty($button_text_color) && substr($button_text_color, 0, 1) !== '#') {
            $button_text_color = '#' . $button_text_color;
        }
        if (!empty($card_bg_color) && substr($card_bg_color, 0, 1) !== '#') {
            $card_bg_color = '#' . $card_bg_color;
        }
        
        // v1.9.5: DEBUG - Echo immediately like events does
        if (current_user_can('manage_options')) {
            $color_source = !empty($atts['button_color']) ? 'shortcode' : 
                           (!empty($atts['btncolor']) ? 'btncolor' : 
                           (!empty($atts['btn_bg']) ? 'btn_bg' : 
                           (!empty($pc_events_button_color) ? 'inherited-from-events' : 'global-setting')));
            echo '<!-- GROUPS DEBUG v1.9.9: source=' . $color_source . ' | events_color="' . esc_attr($pc_events_button_color) . '" | final="' . esc_attr($button_color) . '" -->';
        }
        
        // v1.0.3: Get other color settings (always use global)
        $text_color = get_option('pc_groups_text_color', '#2c3e50');
        $container_bg_color = get_option('pc_groups_container_bg_color', 'transparent');
        $icon_color = get_option('pc_groups_icon_color', '#667eea');
        
        // v1.8.6: Get link behavior setting
        $open_in_new_tab = get_option('pc_groups_open_in_new_tab', 1);
        $target_attr = $open_in_new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
        
        // v1.5.0: Get See All Groups card settings
        $see_all_enabled = get_option('pc_groups_see_all_enabled', 0);
        $see_all_position = get_option('pc_groups_see_all_position', 'last');
        $see_all_text = get_option('pc_groups_see_all_text', 'See All Groups');
        $see_all_link = get_option('pc_groups_see_all_link', '');
        $see_all_image = get_option('pc_groups_see_all_image', '');
        
        // Generate a stable (not random-per-request) ID for this shortcode instance so
        // CSS optimizers that cache "used CSS" per page (e.g. WP Rocket's Remove Unused
        // CSS) keep matching it on every load instead of going stale immediately.
        static $pc_groups_instance_counter = 0;
        $pc_groups_instance_counter++;
        $instance_id = 'pc-groups-' . (get_the_ID() ?: 0) . '-' . $pc_groups_instance_counter;
        
        // v1.9.0: Generate instance-specific CSS for colors
        $custom_css = "
            <style>
                #{$instance_id} .pc-group-button {
                    background-color: {$button_color} !important;
                    color: {$button_text_color} !important;
                }
                #{$instance_id} .pc-group-button:hover {
                    background-color: " . $this->darken_color($button_color, 15) . " !important;
                    color: {$button_text_color} !important;
                }
                #{$instance_id} .pc-group-card {
                    background-color: {$card_bg_color} !important;
                }
                #{$instance_id} .pc-group-schedule .dashicons {
                    color: {$button_color} !important;
                    margin-top: 2px !important;
                }
                #{$instance_id} .pc-group-title {
                    font-size: 1.2em !important;
                }
                #{$instance_id} .pc-group-schedule {
                    align-items: flex-start !important;
                }
            </style>
        ";
        
        // Build output
        $output .= $debug_output; // v1.0.3: Add debug panel (append, don't overwrite!)
        $output .= $custom_css; // v1.9.0: Add instance-specific CSS
        $output .= '<div id="' . esc_attr($instance_id) . '">'; // v1.9.0: Wrapper for instance-specific styles
        
        // Apply container background if set
        $container_style = '';
        if ($container_bg_color !== 'transparent') {
            $container_style = ' style="background-color: ' . esc_attr($container_bg_color) . '; padding: 40px 20px; border-radius: 12px;"';
        }
        
        if ($display_mode === 'carousel') {
            // DEBUG OUTPUT
            $debug_mode = get_option('pc_groups_debug_mode', 0);
            if ($debug_mode) {
                $output .= '<div style="background: #fff3cd; border: 2px solid #856404; padding: 15px; margin: 20px 0; font-family: monospace; font-size: 12px;">';
                $output .= '<strong style="color: #856404;">🔍 GROUPS CAROUSEL DEBUG v1.2.1</strong><br><br>';
                $output .= '<strong>Total Groups Found:</strong> ' . count($groups) . '<br>';
                $output .= '<strong>Display Mode:</strong> ' . esc_html($display_mode) . '<br>';
                $output .= '<strong>Cards Per Row:</strong> ' . esc_attr($cards_per_row) . '<br><br>';
                
                $carousel_count = 0;
                foreach ($groups as $idx => $group) {
                    $has_image = !empty($group['image_url']);
                    $has_url = !empty($group['group_url']);
                    $will_render = $has_image && $has_url;
                    
                    $output .= '<div style="margin: 10px 0; padding: 10px; background: ' . ($will_render ? '#d4edda' : '#f8d7da') . ';">';
                    $output .= '<strong>Group #' . ($idx + 1) . ':</strong> ' . esc_html($group['name']) . '<br>';
                    $output .= '&nbsp;&nbsp;Has Image: ' . ($has_image ? '✅ YES' : '❌ NO') . '<br>';
                    if ($has_image) {
                        $output .= '&nbsp;&nbsp;Image URL: ' . esc_html(substr($group['image_url'], 0, 80)) . '...<br>';
                    }
                    $output .= '&nbsp;&nbsp;Has Group URL: ' . ($has_url ? '✅ YES' : '❌ NO') . '<br>';
                    if ($has_url) {
                        $output .= '&nbsp;&nbsp;Group URL: ' . esc_html($group['group_url']) . '<br>';
                    }
                    $output .= '&nbsp;&nbsp;<strong>Will Render in Carousel: ' . ($will_render ? '✅ YES' : '❌ NO') . '</strong><br>';
                    $output .= '</div>';
                    
                    if ($will_render) {
                        $carousel_count++;
                    }
                }
                
                $output .= '<br><strong>Cards That Will Render:</strong> ' . $carousel_count . ' / ' . count($groups) . '<br>';
                $output .= '</div>';
            }
            
            $output .= '<div class="pc-groups-carousel-wrapper">';
            $output .= '<div class="pc-groups-container pc-carousel pc-carousel-' . esc_attr($cards_per_row) . '">'; // NO inline styles for carousel!
            $output .= '<div class="pc-groups-carousel-track">';
            
            $carousel_image_index = 0; // v1.9.16: Track image index for lazy loading
            foreach ($groups as $group) {
                // Carousel mode: Image-only clickable cards
                if (!empty($group['image_url']) && !empty($group['group_url'])) {
                    $output .= '<a href="' . esc_url($group['group_url']) . '" 
                                  class="pc-group-card-link"' . $target_attr . '
                                  title="' . esc_attr($group['name']) . '">';
                    $output .= '<div class="pc-group-card">';
                    // v1.9.16: Use img tag for lazy loading (eager for first image)
                    $loading_attr = ($carousel_image_index === 0) ? 'eager' : 'lazy';
                    $output .= '<div class="pc-group-image">';
                    $output .= '<img src="' . esc_url($group['image_url']) . '" alt="' . esc_attr($group['name']) . '" width="800" height="450" loading="' . $loading_attr . '" />';
                    $output .= '</div>';
                    $output .= '</div>';
                    $output .= '</a>';
                    $carousel_image_index++;
                }
            }
            
            $output .= '</div>'; // Close track
            $output .= '</div>'; // Close container
            $output .= '<button class="pc-carousel-nav pc-carousel-prev" aria-label="Previous">‹</button>';
            $output .= '<button class="pc-carousel-nav pc-carousel-next" aria-label="Next">›</button>';
            $output .= '<div class="pc-carousel-indicators"></div>';
            $output .= '</div>'; // Close wrapper
        } else {
            $output .= '<div class="pc-groups-container cards-' . esc_attr($cards_per_row) . '"' . $container_style . '>';
            
            // v1.5.0: Render See All Groups card if enabled and position is first
            if ($see_all_enabled && $see_all_position === 'first' && !empty($see_all_link)) {
                $output .= '<a href="' . esc_url($see_all_link) . '" class="pc-group-card pc-see-all-card"' . $target_attr . '>';
                
                if (!empty($see_all_image)) {
                    $output .= '<div class="pc-group-image" style="background-image: url(' . esc_url($see_all_image) . '); background-size: cover; background-position: center;"></div>';
                } else {
                    $output .= '<div class="pc-group-image pc-group-image-placeholder"></div>';
                }
                
                $output .= '<div class="pc-group-content pc-see-all-content">';
                $output .= '<h3 class="pc-group-title pc-see-all-title" style="color: ' . esc_attr($text_color) . ';">' . esc_html($see_all_text) . '</h3>';
                $output .= '</div></a>';
            }
            
            foreach ($groups as $group) {
                $output .= $this->render_group_card($group, $button_text, $button_color, $button_text_color, $text_color, $card_bg_color, $icon_color, $target_attr, $open_in_new_tab);
            }
            
            // v1.5.0: Render See All Groups card if enabled and position is last
            if ($see_all_enabled && $see_all_position === 'last' && !empty($see_all_link)) {
                $output .= '<a href="' . esc_url($see_all_link) . '" class="pc-group-card pc-see-all-card"' . $target_attr . '>';
                
                if (!empty($see_all_image)) {
                    $output .= '<div class="pc-group-image" style="background-image: url(' . esc_url($see_all_image) . '); background-size: cover; background-position: center;"></div>';
                } else {
                    $output .= '<div class="pc-group-image pc-group-image-placeholder"></div>';
                }
                
                $output .= '<div class="pc-group-content pc-see-all-content">';
                $output .= '<h3 class="pc-group-title pc-see-all-title" style="color: ' . esc_attr($text_color) . ';">' . esc_html($see_all_text) . '</h3>';
                $output .= '</div></a>';
            }
            
            $output .= '</div>';
        }
        
        $output .= '</div>'; // v1.9.0: Close instance-specific wrapper
        
        return $output;
    }
    
    /**
     * v1.9.21: Public method for cache warming (called by cron)
     * Replaces need for Reflection API
     */
    public function warm_groups_cache() {
        return $this->fetch_groups();
    }
    
    /**
     * Fetch groups from Planning Center API
     */
    private function fetch_groups() {
        // Check cache first
        $cache_key = 'pc_groups_data';
        $cached = get_transient($cache_key);
        
        // v1.0.3: Debug cache status
        if (get_option('pc_groups_debug_mode', 0)) {
            error_log('PCI Groups Debug: Checking cache for key: ' . $cache_key);
            error_log('PCI Groups Debug: Cache hit: ' . ($cached !== false ? 'YES (returning cached data)' : 'NO (fetching from API)'));
            if ($cached !== false) {
                error_log('PCI Groups Debug: Cached groups count: ' . count($cached));
            }
        }
        
        if ($cached !== false) {
            return $cached;
        }
        
        $app_id = get_option('pc_groups_app_id');
        $secret = get_option('pc_groups_secret');
        
        if (empty($app_id) || empty($secret)) {
            return new WP_Error('no_credentials', 'API credentials not configured');
        }
        
        // Fetch all groups from API first
        $url = $this->groups_api_url . '/groups?per_page=100&include=enrollment,group_type';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header($app_id, $secret),
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!isset($data['data'])) {
            return new WP_Error('invalid_response', 'Invalid API response');
        }
        
        // v1.8.1: Filter to ONLY publicly visible groups
        // A group is visible on Church Center if it has a public_church_center_web_url
        $all_groups_count = count($data['data']);
        $visible_groups = array();
        
        foreach ($data['data'] as $group_item) {
            $attrs = $group_item['attributes'];
            
            // Check if group has public Church Center URL (indicates visibility)
            if (!empty($attrs['public_church_center_web_url'])) {
                $visible_groups[] = $group_item;
            }
        }
        
        // Replace data with only visible groups
        $data['data'] = $visible_groups;
        
        // Debug: Log filtering results
        if (get_option('pc_groups_debug_mode', 0)) {
            error_log('PCI Groups Debug: Total groups fetched: ' . $all_groups_count);
            error_log('PCI Groups Debug: Publicly visible groups (with public_church_center_web_url): ' . count($visible_groups));
            error_log('PCI Groups Debug: Filtered out ' . ($all_groups_count - count($visible_groups)) . ' private groups');
        }
        
        // Fetch all tags (needed for admin interface tag display)
        $tags_url = $this->groups_api_url . '/tags?per_page=100';
        
        $tags_response = wp_remote_get($tags_url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header($app_id, $secret),
            ),
            'timeout' => 15,
        ));
        
        // Build a map of tag_id => tag_name for all tags
        $all_tags_lookup = array();
        if (!is_wp_error($tags_response)) {
            $tags_body = wp_remote_retrieve_body($tags_response);
            $tags_data = json_decode($tags_body, true);
            
            if (isset($tags_data['data'])) {
                foreach ($tags_data['data'] as $tag) {
                    $tag_id = $tag['id'];
                    $tag_name = $tag['attributes']['name'] ?? '';
                    if ($tag_id && $tag_name) {
                        $all_tags_lookup[$tag_id] = $tag_name;
                    }
                }
                
                // Debug: Show tag fetching results
                if (get_option('pc_groups_debug_mode', 0)) {
                    error_log('PCI Groups Debug: Fetched ' . count($tags_data['data']) . ' tags from API');
                    error_log('PCI Groups Debug: Built tag lookup with ' . count($all_tags_lookup) . ' tags');
                }
            }
        } else {
            if (get_option('pc_groups_debug_mode', 0)) {
                error_log('PCI Groups Debug: ERROR fetching tags: ' . $tags_response->get_error_message());
            }
        }
        
        // NEW v1.8.1: Fetch tags for each individual group
        // This builds a lookup of group_id => array of tag names
        $group_tags_lookup = array();
        
        if (get_option('pc_groups_debug_mode', 0)) {
            error_log('PCI Groups Debug: Starting to fetch tags for each group...');
        }
        
        foreach ($data['data'] as $group_item) {
            $group_id = $group_item['id'];
            $group_name = $group_item['attributes']['name'] ?? 'Unknown';

            // v1.9.22: Check per-group tag cache before hitting the API.
            // Previously every cache-miss cycle made one API call per group (N+1 problem).
            $tag_cache_key = 'pc_gtags_' . $group_id;
            $cached_tags = get_transient($tag_cache_key);
            if ($cached_tags !== false) {
                $group_tags_lookup[$group_id] = $cached_tags;
                continue;
            }

            // Fetch tags for this specific group
            $group_tags_url = $this->groups_api_url . '/groups/' . $group_id . '/tags';
            
            $group_tags_response = wp_remote_get($group_tags_url, array(
                'headers' => array(
                    'Authorization' => $this->get_auth_header($app_id, $secret),
                ),
                'timeout' => 10,
            ));
            
            $group_tags_array = array();
            
            if (!is_wp_error($group_tags_response)) {
                $group_tags_body = wp_remote_retrieve_body($group_tags_response);
                $group_tags_data = json_decode($group_tags_body, true);
                
                if (isset($group_tags_data['data'])) {
                    foreach ($group_tags_data['data'] as $tag) {
                        $tag_name = $tag['attributes']['name'] ?? '';
                        if ($tag_name) {
                            $group_tags_array[] = $tag_name;
                        }
                    }
                }
                
                // Debug first 3 groups
                if (get_option('pc_groups_debug_mode', 0) && count($group_tags_lookup) < 3) {
                    error_log('PCI Groups Debug: Group "' . $group_name . '" has ' . count($group_tags_array) . ' tags: ' . implode(', ', $group_tags_array));
                }
            } else {
                if (get_option('pc_groups_debug_mode', 0) && count($group_tags_lookup) < 3) {
                    error_log('PCI Groups Debug: ERROR fetching tags for group "' . $group_name . '": ' . $group_tags_response->get_error_message());
                }
            }

            // Cache this group's tags for 1 hour so repeat cache-miss cycles skip the call
            set_transient($tag_cache_key, $group_tags_array, HOUR_IN_SECONDS);
            $group_tags_lookup[$group_id] = $group_tags_array;
        }
        
        if (get_option('pc_groups_debug_mode', 0)) {
            error_log('PCI Groups Debug: Finished fetching tags. Total groups processed: ' . count($group_tags_lookup));
        }
        
        // Process groups - ONLY include active groups
        $groups = array();
        $included_data = $data['included'] ?? array(); // Enrollment and GroupType data will be here
        
        // Get selected tags from settings (individual tag IDs, not group IDs)
        $selected_tags = get_option('pc_groups_selected_tags', array());
        
        // Build tag lookup from all tags, filtering by selected tags
        $tag_lookup = array();
        foreach ($all_tags_lookup as $tag_id => $tag_name) {
            // Only include tag if:
            // 1. No tags selected (include all), OR
            // 2. This specific tag ID is in the selected list
            if (empty($selected_tags) || in_array($tag_id, $selected_tags)) {
                $tag_lookup[$tag_id] = $tag_name;
            }
        }
        
        // Debug: Show filtering results
        if (get_option('pc_groups_debug_mode', 0)) {
            error_log('=== TAG FILTERING DEBUG ===');
            error_log('PCI Groups Debug: Selected tag IDs from settings: ' . (empty($selected_tags) ? 'NONE (all tags allowed)' : count($selected_tags) . ' tags'));
            if (!empty($selected_tags)) {
                error_log('PCI Groups Debug: Selected tag IDs: ' . implode(', ', $selected_tags));
            }
            error_log('PCI Groups Debug: All fetched tag IDs: ' . implode(', ', array_keys($all_tags_lookup)));
            error_log('PCI Groups Debug: After filtering, tag lookup has: ' . count($tag_lookup) . ' tags');
            if (!empty($tag_lookup)) {
                error_log('PCI Groups Debug: Filtered tag IDs: ' . implode(', ', array_keys($tag_lookup)));
                error_log('PCI Groups Debug: Filtered tag names: ' . implode(', ', array_slice($tag_lookup, 0, 5)));
            } else {
                error_log('⚠️ PCI Groups Debug: TAG LOOKUP IS EMPTY! None of your selected tag IDs match the fetched tags!');
            }
            error_log('=== END TAG FILTERING DEBUG ===');
        }
        
        foreach ($data['data'] as $item) {
            $attrs = $item['attributes'];
            $links = $item['links'] ?? array();
            
            // FILTER: Skip archived groups
            if (!empty($attrs['archived_at'])) {
                continue;
            }
            
            // Extract enrollment strategy (store it for shortcode filtering)
            $enrollment_strategy = null;
            if (isset($item['relationships']['enrollment']['data']['id'])) {
                $enrollment_id = $item['relationships']['enrollment']['data']['id'];
                foreach ($included_data as $included_item) {
                    if ($included_item['type'] === 'Enrollment' && $included_item['id'] === $enrollment_id) {
                        $enrollment_strategy = $included_item['attributes']['strategy'] ?? null;
                        break;
                    }
                }
            }
            
            // Extract group_type name from relationships
            $group_type_name = '';
            if (isset($item['relationships']['group_type']['data']['id'])) {
                $group_type_id = $item['relationships']['group_type']['data']['id'];
                foreach ($included_data as $included_item) {
                    if ($included_item['type'] === 'GroupType' && $included_item['id'] === $group_type_id) {
                        $group_type_name = $included_item['attributes']['name'] ?? '';
                        break;
                    }
                }
            }
            
            // Extract header image URLs (prefer medium size for 16:9 cards)
            $header_image = $attrs['header_image'] ?? array();
            $image_url = '';
            if (!empty($header_image['medium'])) {
                $image_url = $header_image['medium'];
            } elseif (!empty($header_image['original'])) {
                $image_url = $header_image['original'];
            } elseif (!empty($header_image['thumbnail'])) {
                $image_url = $header_image['thumbnail'];
            }
            
            // Build schedule string (Day and Time)
            $schedule = $this->format_schedule($attrs);
            
            // Get meeting location
            $location = $attrs['location_type_preference'] ?? '';
            if (empty($location) && !empty($attrs['virtual_location_url'])) {
                $location = 'Online';
            }
            
            // Get group URL - use public Church Center URL for public-facing page
            $group_url = $attrs['public_church_center_web_url'] ?? '';
            if (empty($group_url)) {
                // Fallback to admin URL if public URL not available
                $group_url = $links['html'] ?? '';
            }
            if (empty($group_url)) {
                // Last resort: construct URL from group ID
                $group_url = 'https://groups.planningcenteronline.com/groups/' . $item['id'];
            }
            
            // Extract tags from the group_tags_lookup we built earlier (v1.8.1)
            // v1.8.3: Store ALL tags from Planning Center (no pre-filtering)
            // Let global filter and shortcode do their own filtering at display time
            $group_tags = array();
            if (isset($group_tags_lookup[$item['id']])) {
                $group_tags = $group_tags_lookup[$item['id']];
                
                // Debug first 3 groups
                if (get_option('pc_groups_debug_mode', 0) && count($groups) < 3) {
                    error_log('PCI Groups Debug: Group "' . ($attrs['name'] ?? 'Unknown') . '" has ' . count($group_tags) . ' tags: ' . implode(', ', $group_tags));
                }
            } else {
                // Debug: No tags found
                if (get_option('pc_groups_debug_mode', 0) && count($groups) < 3) {
                    error_log('PCI Groups Debug: Group "' . ($attrs['name'] ?? 'Unknown') . '" has NO tags in lookup');
                }
            }
            
            $groups[] = array(
                'id' => $item['id'],
                'name' => $attrs['name'] ?? '',
                'description' => $attrs['description'] ?? '',
                'type' => $group_type_name, // v1.0.3: From relationships
                'location' => $location,
                'schedule' => $schedule,
                'enrollment_open' => true, // All non-archived groups shown
                'enrollment_strategy' => $enrollment_strategy, // v1.0.3: Store for filtering
                'image_url' => $image_url,
                'group_url' => $group_url,
                'tags' => $group_tags, // v1.7.4: Tags from relationships
            );
            
            // v1.0.3: Debug logging
            $this->debug_log('Processing group: ' . ($attrs['name'] ?? 'Unknown'), array(
                'id' => $item['id'],
                'has_image' => !empty($image_url),
                'image_url' => $image_url,
                'has_group_url' => !empty($group_url),
                'group_url' => $group_url,
                'header_image_keys' => array_keys($header_image),
                'all_attributes' => array_keys($attrs),
                'tags' => $group_tags, // Add tags to debug
            ));
        }
        
        // Apply global tag filter if enabled
        $global_tag_filter_enabled = get_option('pc_groups_enable_global_tag_filter', 0);
        if ($global_tag_filter_enabled && !empty($selected_tags)) {
            // v1.8.3: Filter to only show groups that have at least one selected tag
            $groups = array_filter($groups, function($group) use ($selected_tags, $all_tags_lookup) {
                if (empty($group['tags'])) {
                    return false; // No tags = exclude
                }
                
                // Check if group has ANY of the selected tags
                foreach ($group['tags'] as $tag_name) {
                    // Find tag ID by name
                    $tag_id = array_search($tag_name, $all_tags_lookup);
                    if ($tag_id !== false && in_array($tag_id, $selected_tags)) {
                        return true; // Has a selected tag = include
                    }
                }
                
                return false; // No selected tags = exclude
            });
            
            // Debug: Log global filtering
            if (get_option('pc_groups_debug_mode', 0)) {
                error_log('PCI Groups Debug: Global tag filter ENABLED');
                error_log('PCI Groups Debug: Groups after global tag filter: ' . count($groups));
            }
        } else {
            if (get_option('pc_groups_debug_mode', 0)) {
                error_log('PCI Groups Debug: Global tag filter DISABLED (showing all groups)');
            }
        }
        
        // v1.8.3: Sort groups by schedule (Day → Time → Name)
        // Groups with no schedule go to the end
        usort($groups, function($a, $b) {
            // Helper to extract day of week (Sunday=0, Saturday=6)
            // Searches for day name anywhere in the schedule string
            $getDayNumber = function($schedule) {
                if (empty($schedule)) return 7; // No schedule = end of list
                
                $schedule = strtolower($schedule);
                
                // Search for day names (longest first to avoid "sun" matching "sunday")
                $days = [
                    'sunday' => 0,
                    'monday' => 1,
                    'tuesday' => 2,
                    'wednesday' => 3,
                    'thursday' => 4,
                    'friday' => 5,
                    'saturday' => 6,
                    'sun' => 0,
                    'mon' => 1,
                    'tue' => 2,
                    'tues' => 2,
                    'wed' => 3,
                    'thu' => 4,
                    'thur' => 4,
                    'thurs' => 4,
                    'fri' => 5,
                    'sat' => 6
                ];
                
                // Check full names first, then abbreviations
                foreach (['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'] as $day) {
                    if (strpos($schedule, $day) !== false) {
                        return $days[$day];
                    }
                }
                
                // Then check abbreviations
                foreach (['sun', 'mon', 'tue', 'tues', 'wed', 'thu', 'thur', 'thurs', 'fri', 'sat'] as $abbr) {
                    if (strpos($schedule, $abbr) !== false) {
                        return $days[$abbr];
                    }
                }
                
                return 7; // No recognized day = end of list
            };
            
            // Helper to extract time (convert to 24-hour integer for comparison)
            // Searches for time pattern anywhere in the schedule string
            $getTimeNumber = function($schedule) {
                if (empty($schedule)) return 9999; // No time = end of day
                
                // Match time patterns like "7:00 PM", "7pm", "7:00pm", "19:00"
                // Look for time anywhere in the string
                if (preg_match('/(\d{1,2}):(\d{2})\s*(am|pm)?/i', $schedule, $matches)) {
                    $hour = (int)$matches[1];
                    $minute = (int)$matches[2];
                    $ampm = isset($matches[3]) ? strtolower($matches[3]) : '';
                    
                    // Convert to 24-hour
                    if ($ampm === 'pm' && $hour < 12) {
                        $hour += 12;
                    } elseif ($ampm === 'am' && $hour === 12) {
                        $hour = 0;
                    }
                    
                    return ($hour * 100) + $minute; // e.g., 14:30 = 1430
                } elseif (preg_match('/(\d{1,2})\s*(am|pm)/i', $schedule, $matches)) {
                    // Handle "7pm" without colon
                    $hour = (int)$matches[1];
                    $ampm = strtolower($matches[2]);
                    
                    // Convert to 24-hour
                    if ($ampm === 'pm' && $hour < 12) {
                        $hour += 12;
                    } elseif ($ampm === 'am' && $hour === 12) {
                        $hour = 0;
                    }
                    
                    return $hour * 100; // e.g., 14:00 = 1400
                }
                
                return 9999; // No recognized time = end of day
            };
            
            $scheduleA = $a['schedule'] ?? '';
            $scheduleB = $b['schedule'] ?? '';
            
            // Compare by day first
            $dayA = $getDayNumber($scheduleA);
            $dayB = $getDayNumber($scheduleB);
            
            if ($dayA !== $dayB) {
                return $dayA - $dayB;
            }
            
            // Same day, compare by time
            $timeA = $getTimeNumber($scheduleA);
            $timeB = $getTimeNumber($scheduleB);
            
            if ($timeA !== $timeB) {
                return $timeA - $timeB;
            }
            
            // Same day and time, compare alphabetically by name
            return strcasecmp($a['name'], $b['name']);
        });
        
        if (get_option('pc_groups_debug_mode', 0)) {
            error_log('PCI Groups Debug: Groups sorted by schedule (Day → Time → Name)');
        }
        
        // Cache the results
        $cache_duration = get_option('pc_groups_cache_duration', 3600);
        set_transient($cache_key, $groups, $cache_duration);
        
        return $groups;
    }
    
    /**
     * Fetch Tag Groups from Planning Center with all their tags
     * Used in admin to let users select which tag groups and individual tags to include
     */
    public function fetch_tag_groups() {
        $app_id = get_option('pc_groups_app_id');
        $secret = get_option('pc_groups_secret');
        
        if (empty($app_id) || empty($secret)) {
            return array();
        }
        
        // First, fetch all tags (not tag groups)
        $tags_url = $this->groups_api_url . '/tags?per_page=100&include=tag_group';
        
        $tags_response = wp_remote_get($tags_url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header($app_id, $secret),
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($tags_response)) {
            return array();
        }
        
        $tags_body = wp_remote_retrieve_body($tags_response);
        $tags_data = json_decode($tags_body, true);
        
        // Debug: Log the tags API response
        if (get_option('pc_groups_debug_mode', 0)) {
            error_log('=== TAGS API DEBUG ===');
            error_log('Tags API URL: ' . $tags_url);
            error_log('Response Code: ' . wp_remote_retrieve_response_code($tags_response));
            error_log('Tags Found: ' . (isset($tags_data['data']) ? count($tags_data['data']) : 0));
            if (!empty($tags_data['data'][0])) {
                error_log('First Tag Structure:');
                error_log(print_r($tags_data['data'][0], true));
            }
            error_log('=== END TAGS API DEBUG ===');
        }
        
        if (!isset($tags_data['data'])) {
            return array();
        }
        
        // Now fetch tag groups to get their names
        $tag_groups_url = $this->groups_api_url . '/tag_groups?per_page=100';
        
        $tag_groups_response = wp_remote_get($tag_groups_url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header($app_id, $secret),
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($tag_groups_response)) {
            return array();
        }
        
        $tag_groups_body = wp_remote_retrieve_body($tag_groups_response);
        $tag_groups_data = json_decode($tag_groups_body, true);
        
        if (!isset($tag_groups_data['data'])) {
            return array();
        }
        
        // Build a lookup of tag group ID to tag group info
        $tag_group_lookup = array();
        foreach ($tag_groups_data['data'] as $tg) {
            $tag_group_lookup[$tg['id']] = array(
                'id' => $tg['id'],
                'name' => $tg['attributes']['name'] ?? 'Unnamed Tag Group',
                'tags' => array(), // Will populate this
            );
        }
        
        // Group tags by their tag_group_id
        foreach ($tags_data['data'] as $tag) {
            $tag_id = $tag['id'];
            $tag_name = $tag['attributes']['name'] ?? 'Unnamed Tag';
            
            // Get the tag group this tag belongs to
            $tag_group_id = null;
            if (isset($tag['relationships']['tag_group']['data']['id'])) {
                $tag_group_id = $tag['relationships']['tag_group']['data']['id'];
            }
            
            // Add this tag to the appropriate tag group
            if ($tag_group_id && isset($tag_group_lookup[$tag_group_id])) {
                $tag_group_lookup[$tag_group_id]['tags'][] = array(
                    'id' => $tag_id,
                    'name' => $tag_name,
                );
            }
        }
        
        // Convert lookup to array
        $tag_groups = array_values($tag_group_lookup);
        
        // Debug: Final result
        if (get_option('pc_groups_debug_mode', 0)) {
            error_log('Final Tag Groups Built: ' . count($tag_groups));
            foreach ($tag_groups as $tg) {
                error_log('  - ' . $tg['name'] . ': ' . count($tg['tags']) . ' tags');
            }
        }
        
        return $tag_groups;
    }
    
    /**
     * Format schedule string from Planning Center data
     */
    private function format_schedule($attrs) {
        $schedule = '';
        
        // Try to get schedule from 'schedule' field
        if (!empty($attrs['schedule'])) {
            $schedule = $attrs['schedule'];
        }
        
        // Or build from day/time if available
        elseif (!empty($attrs['meets_at'])) {
            $schedule = $attrs['meets_at'];
        }
        
        // Or use day_of_week and time fields if available
        else {
            $parts = array();
            if (!empty($attrs['day_of_week'])) {
                $parts[] = $attrs['day_of_week'];
            }
            if (!empty($attrs['time_of_day'])) {
                $parts[] = $attrs['time_of_day'];
            }
            if (!empty($parts)) {
                $schedule = implode(' at ', $parts);
            }
        }
        
        return $schedule;
    }
    
    /**
     * Check if a group should be excluded based on exclusion list
     */
    private function is_group_excluded($group_name, $excluded_list) {
        if (empty($excluded_list) || empty($group_name)) {
            return false;
        }
        
        foreach ($excluded_list as $excluded_name) {
            if (trim($excluded_name) === trim($group_name)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Render a single group card
     */
    private function render_group_card($group, $button_text, $button_color, $button_text_color = '#ffffff', $text_color = '#2c3e50', $card_bg_color = '#ffffff', $icon_color = '#667eea', $target_attr = '', $open_in_new_tab = false) {
        // Get default placeholder image
        $default_image = get_option('pc_groups_default_image', '');
        $image_url = !empty($group['image_url']) ? $group['image_url'] : $default_image;
        
        $output = '<div class="pc-group-card" style="background-color: ' . esc_attr($card_bg_color) . ';">';
        
        // Header Image (16:9 ratio) - v1.9.16: Converted to img tag for lazy loading
        if (!empty($image_url)) {
            $output .= '<div class="pc-group-image">';
            $output .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($group['name']) . '" width="800" height="450" loading="lazy" />';
            $output .= '</div>';
        } else {
            $output .= '<div class="pc-group-image pc-group-image-placeholder"></div>';
        }
        
        // Card Content
        $output .= '<div class="pc-group-content">';
        
        // Group Name
        $output .= '<h3 class="pc-group-title" style="color: ' . esc_attr($text_color) . ';">' . esc_html($group['name']) . '</h3>';
        
        // Schedule/Time
        if (!empty($group['schedule'])) {
            $output .= '<p class="pc-group-schedule" style="color: ' . esc_attr($text_color) . ';"><span class="dashicons dashicons-clock" style="color: ' . esc_attr($icon_color) . ';"></span> ' . esc_html($group['schedule']) . '</p>';
        } elseif (!empty($group['meets_at'])) {
            $output .= '<p class="pc-group-schedule" style="color: ' . esc_attr($text_color) . ';"><span class="dashicons dashicons-clock" style="color: ' . esc_attr($icon_color) . ';"></span> ' . esc_html($group['meets_at']) . '</p>';
        }
        
        $output .= '</div>'; // End content
        
        // Footer with Button
        $output .= '<div class="pc-group-footer">';
        
        if (!empty($group['group_url'])) {
            $aria = $button_text . ' about ' . $group['name'] . ' on Planning Center';
            if ($open_in_new_tab) {
                $aria .= ', opens in a new tab';
            }
            $output .= '<a href="' . esc_url($group['group_url']) . '"
                          class="pc-group-button"
                          aria-label="' . esc_attr($aria) . '"
                          style="background-color: ' . esc_attr($button_color) . ' !important; color: ' . esc_attr($button_text_color) . ' !important;"' .
                          $target_attr . '>' .
                          esc_html($button_text) .
                       '</a>';
        }
        
        $output .= '</div>'; // End footer
        $output .= '</div>'; // End card
        
        return $output;
    }
    
    /**
     * Clear groups cache
     */
    public function clear_groups_cache() {
        // Security: Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        check_admin_referer('clear_cache');
        
        delete_transient('pc_groups_data');
        
        wp_redirect(admin_url('admin.php?page=planning-center-groups-settings&cache_cleared=1'));
        exit;
    }
    
    /**
     * v1.0.3: Debug helper - Log message if debug mode is enabled
     */
    private function debug_log($message, $data = null) {
        if (get_option('pc_groups_debug_mode', false)) {
            $log_message = 'PCI Groups Debug: ' . $message;
            if ($data !== null) {
                $log_message .= ' | Data: ' . print_r($data, true);
            }
            error_log($log_message);
        }
    }
    
    /**
     * v1.0.3: Admin action to view raw API response
     */
    public function debug_api_response() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        check_admin_referer('pc_groups_debug_api_response_nonce');
        
        $app_id = get_option('pc_groups_app_id');
        $secret = get_option('pc_groups_secret');
        
        if (empty($app_id) || empty($secret)) {
            wp_die('Planning Center Groups credentials not configured');
        }
        
        $url = $this->groups_api_url . '/groups?per_page=5';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header($app_id, $secret)
            ),
            'timeout' => 30
        ));
        
        echo '<html><head><title>Planning Center Groups API Debug</title>';
        echo '<style>
            body { font-family: monospace; padding: 20px; background: #f0f0f0; }
            .debug-container { background: white; padding: 20px; border-radius: 5px; }
            pre { background: #f5f5f5; padding: 15px; overflow-x: auto; border: 1px solid #ddd; }
            h2 { color: #333; border-bottom: 2px solid #007acc; padding-bottom: 10px; }
            .back-button { display: inline-block; padding: 10px 20px; background: #0073aa; 
                          color: white; text-decoration: none; border-radius: 3px; margin-bottom: 20px; }
            .back-button:hover { background: #005177; }
        </style></head><body>';
        
        echo '<div class="debug-container">';
        echo '<a href="' . admin_url('admin.php?page=planning-center-groups-settings') . '" class="back-button">← Back to Settings</a>';
        echo '<h2>Planning Center Groups API Raw Response</h2>';
        
        if (is_wp_error($response)) {
            echo '<h3 style="color: red;">Error:</h3>';
            echo '<pre>' . esc_html($response->get_error_message()) . '</pre>';
        } else {
            echo '<h3>Response Code: ' . wp_remote_retrieve_response_code($response) . '</h3>';
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if ($data) {
                echo '<h3>Formatted JSON:</h3>';
                echo '<pre>' . esc_html(json_encode($data, JSON_PRETTY_PRINT)) . '</pre>';
                
                // Show specific data points we're looking for
                echo '<h3>Key Data Points:</h3>';
                if (isset($data['data']) && is_array($data['data'])) {
                    foreach ($data['data'] as $index => $group) {
                        echo '<h4>Group ' . ($index + 1) . ': ' . esc_html($group['attributes']['name'] ?? 'Unknown') . '</h4>';
                        echo '<ul>';
                        echo '<li><strong>ID:</strong> ' . esc_html($group['id'] ?? 'N/A') . '</li>';
                        
                        $header_image = $group['attributes']['header_image'] ?? array();
                        echo '<li><strong>Has header_image:</strong> ' . (!empty($header_image) ? 'YES' : 'NO');
                        if (!empty($header_image)) {
                            echo ' (Available sizes: ' . esc_html(implode(', ', array_keys($header_image))) . ')';
                            if (!empty($header_image['medium'])) {
                                echo '<br>Medium: ' . esc_html($header_image['medium']);
                            }
                        }
                        echo '</li>';
                        
                        $links = $group['links'] ?? array();
                        echo '<li><strong>Has html link:</strong> ' . (isset($links['html']) ? 'YES' : 'NO');
                        if (isset($links['html'])) {
                            echo ' (' . esc_html($links['html']) . ')';
                        }
                        echo '</li>';
                        
                        echo '<li><strong>All attributes:</strong> ' . esc_html(implode(', ', array_keys($group['attributes'] ?? []))) . '</li>';
                        echo '</ul>';
                    }
                }
            } else {
                echo '<h3>Raw Response Body:</h3>';
                echo '<pre>' . esc_html($body) . '</pre>';
            }
        }
        
        echo '</div></body></html>';
        exit;
    }
    
    /**
     * Darken a hex color by a percentage
     * v1.9.0: Added for hover effects in instance-specific CSS
     */
    private function darken_color($hex, $percent) {
        $hex = str_replace('#', '', $hex);
        
        if (strlen($hex) == 3) {
            $hex = str_repeat(substr($hex, 0, 1), 2) . 
                   str_repeat(substr($hex, 1, 1), 2) . 
                   str_repeat(substr($hex, 2, 1), 2);
        }
        
        $rgb = array(
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        );
        
        for ($i = 0; $i < 3; $i++) {
            $rgb[$i] = max(0, min(255, $rgb[$i] - ($rgb[$i] * $percent / 100)));
        }
        
        return sprintf("#%02x%02x%02x", $rgb[0], $rgb[1], $rgb[2]);
    }
}
