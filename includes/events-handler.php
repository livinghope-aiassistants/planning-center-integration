<?php
/**
 * Planning Center Events Handler
 * Part of Planning Center Integration Plugin v1.7.6
 * Based on Events v7.3.0
 * 
 * v1.7.6: CRITICAL - Fixed timezone conversion (UTC to site timezone)
 * v1.7.5: Fixed event dates - now uses next_signup_time API, shows no date if unavailable (instead of wrong registration dates)
 * v1.7.4: Added support for Registrations categories as tags (filtering now works!)
 * v1.7.3: Shortcode tag parameter now overrides global tag filter
 * v1.7.2: Fixed shortcode tag parameter filtering (case-insensitive)
 * v1.7.1: Fixed tag/category filtering for Registrations API mode
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PC_Events_Handler {
    
    private $registrations_api_url = 'https://api.planningcenteronline.com/registrations/v2';
    private $calendar_api_url = 'https://api.planningcenteronline.com/calendar/v2';
    private $table_name;
    // v1.9.22: Cache the computed auth header so base64_encode isn't called on every API request
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
        $this->table_name = $wpdb->prefix . 'pc_event_descriptions';
        
        // Register shortcode
        add_shortcode('planning_center_events', array($this, 'display_events_shortcode'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add cache clearing actions
        add_action('admin_post_clear_pc_events_cache', array($this, 'clear_events_cache'));
        add_action('admin_post_clear_pc_ai_cache', array($this, 'clear_ai_cache'));
        add_action('admin_post_clear_pc_all_cache', array($this, 'clear_all_cache'));
        
        // Add AJAX handler for testing AI key
        add_action('wp_ajax_pc_test_ai_key', array($this, 'ajax_test_ai_key'));
        
        // Add approval workflow actions
        add_action('admin_post_pc_approve_description', array($this, 'approve_description'));
        add_action('admin_post_pc_reject_description', array($this, 'reject_description'));
        add_action('admin_post_pc_bulk_approve', array($this, 'bulk_approve_descriptions'));
        
        // v7.3.0: Add background AI generation
        add_action('pc_generate_ai_description', array($this, 'process_ai_generation'));
        
        // v7.3.0: Add transient cleanup schedule
        if (!wp_next_scheduled('pc_cleanup_transients')) {
            wp_schedule_event(time(), 'weekly', 'pc_cleanup_transients');
        }
        add_action('pc_cleanup_transients', array($this, 'cleanup_old_transients'));
        
        // v1.0.3: PERFORMANCE - Add daily transient cleanup
        add_action('pc_daily_transient_cleanup', array($this, 'cleanup_old_transients'));
        
        // v1.0.3: Add debug API response action
        add_action('admin_post_pc_debug_api_response', array($this, 'debug_api_response'));
    }
    
    /**
     * v7.3.0: Encrypt API key for storage
     * v1.0.3: SECURITY FIX - Use proper AES-256-CBC encryption
     */
    private function encrypt_api_key($key) {
        if (empty($key)) {
            return '';
        }
        
        // Use WordPress authentication salts for encryption
        if (!defined('AUTH_KEY') || !defined('SECURE_AUTH_KEY')) {
            // Fallback to basic encoding if salts not defined (should never happen)
            return base64_encode($key);
        }
        
        // Create encryption key from WordPress salts
        $encryption_key = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY);
        
        // Generate a random IV (Initialization Vector)
        $iv = openssl_random_pseudo_bytes(16);
        
        // Encrypt the key
        $encrypted = openssl_encrypt(
            $key,
            'AES-256-CBC',
            $encryption_key,
            0,
            $iv
        );
        
        // Combine IV and encrypted data, then base64 encode for storage
        return base64_encode($iv . '::' . $encrypted);
    }
    
    /**
     * v7.3.0: Decrypt API key from storage
     * v1.0.3: SECURITY FIX - Use proper AES-256-CBC decryption
     */
    private function decrypt_api_key($encrypted_key) {
        if (empty($encrypted_key)) {
            return '';
        }
        
        if (!defined('AUTH_KEY') || !defined('SECURE_AUTH_KEY')) {
            // Fallback for old base64 encoded keys
            $decoded = base64_decode($encrypted_key);
            // Check if it looks like our new format
            if (strpos($decoded, '::') === false) {
                return $decoded; // Old format, return as-is
            }
        }
        
        // Create encryption key from WordPress salts
        $encryption_key = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY);
        
        // Decode the stored value
        $decoded = base64_decode($encrypted_key);
        
        // Check if it's in the new format
        if (strpos($decoded, '::') === false) {
            // Old format (plain base64) - return decoded value
            return $decoded;
        }
        
        // Split IV and encrypted data
        list($iv, $encrypted) = explode('::', $decoded, 2);
        
        // Decrypt
        $decrypted = openssl_decrypt(
            $encrypted,
            'AES-256-CBC',
            $encryption_key,
            0,
            $iv
        );
        
        return $decrypted !== false ? $decrypted : '';
    }
    
    /**
     * v7.3.0: Get API key (with decryption)
     */
    private function get_api_key() {
        $encrypted = get_option('pc_ai_api_key');
        return $this->decrypt_api_key($encrypted);
    }
    
    /**
     * v7.3.0: Check rate limit for AI calls
     */
    private function check_ai_rate_limit() {
        $count = get_transient('pc_ai_calls_today');
        if ($count === false) {
            $count = 0;
        }
        
        $limit = apply_filters('pc_ai_rate_limit', 100); // 100 calls per day default
        
        if ($count >= $limit) {
            $this->log_security_event('rate_limit_exceeded', array(
                'count' => $count,
                'limit' => $limit
            ));
            return false;
        }
        
        return true;
    }
    
    /**
     * v7.3.0: Increment rate limit counter
     */
    private function increment_ai_rate_counter() {
        $count = get_transient('pc_ai_calls_today');
        if ($count === false) {
            $count = 0;
        }
        
        set_transient('pc_ai_calls_today', $count + 1, DAY_IN_SECONDS);
    }
    
    /**
     * v7.3.0: Log security events
     */
    private function log_security_event($event, $details = array()) {
        if (!WP_DEBUG_LOG) {
            return;
        }
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'event' => $event,
            'details' => $details,
            'user_id' => get_current_user_id(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        );
        
        error_log('[PC Events Security] ' . json_encode($log_entry));
    }
    
    /**
     * v7.3.0: Get from cache (supports object cache)
     */
    private function get_from_cache($key, $group = 'planning_center') {
        if (wp_using_ext_object_cache()) {
            return wp_cache_get($key, $group);
        }
        return get_transient($key);
    }
    
    /**
     * v7.3.0: Set to cache (supports object cache)
     */
    private function set_to_cache($key, $value, $expiration = 3600, $group = 'planning_center') {
        if (wp_using_ext_object_cache()) {
            return wp_cache_set($key, $value, $group, $expiration);
        }
        return set_transient($key, $value, $expiration);
    }
    
    /**
     * v7.3.0: Delete from cache (supports object cache)
     */
    private function delete_from_cache($key, $group = 'planning_center') {
        if (wp_using_ext_object_cache()) {
            return wp_cache_delete($key, $group);
        }
        return delete_transient($key);
    }
    
    /**
     * v7.3.0: Cleanup old transients (scheduled weekly)
     */
    public function cleanup_old_transients() {
        global $wpdb;
        
        // Delete expired transients
        $wpdb->query("
            DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_pc_%'
            AND option_name NOT IN (
                SELECT CONCAT('_transient_', SUBSTRING(option_name, 20))
                FROM {$wpdb->options}
                WHERE option_name LIKE '_transient_timeout_pc_%'
                AND option_value > UNIX_TIMESTAMP()
            )
        ");
        
        // Delete timeout options without corresponding transients
        $wpdb->query("
            DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_timeout_pc_%'
            AND SUBSTRING(option_name, 20) NOT IN (
                SELECT SUBSTRING(option_name, 12)
                FROM {$wpdb->options}
                WHERE option_name LIKE '_transient_pc_%'
            )
        ");
        
        $this->log_security_event('transient_cleanup', array(
            'deleted_count' => $wpdb->rows_affected
        ));
    }
    
    /**
     * v7.3.0: Queue AI generation for background processing
     */
    private function queue_ai_generation($event_id, $event_name, $original_description) {
        // Store event data for background processing
        set_transient('pc_ai_queue_' . $event_id, array(
            'event_id' => $event_id,
            'event_name' => $event_name,
            'original_description' => $original_description,
            'queued_at' => time()
        ), HOUR_IN_SECONDS);
        
        // Schedule background generation (10 seconds from now)
        wp_schedule_single_event(time() + 10, 'pc_generate_ai_description', array($event_id));
    }
    
    /**
     * v7.3.0: Process queued AI generation (background)
     */
    public function process_ai_generation($event_id) {
        $queue_data = get_transient('pc_ai_queue_' . $event_id);
        
        if (!$queue_data) {
            return; // Already processed or expired
        }
        
        // Check rate limit
        if (!$this->check_ai_rate_limit()) {
            $this->log_security_event('ai_generation_rate_limited', array('event_id' => $event_id));
            return;
        }
        
        // Generate AI description
        $ai_description = $this->enhance_description_with_ai($queue_data['original_description']);
        
        if ($ai_description && $ai_description !== $queue_data['original_description']) {
            // Store in database
            $this->store_description(
                $event_id,
                $queue_data['event_name'],
                $queue_data['original_description'],
                $ai_description
            );
            
            $this->increment_ai_rate_counter();
        }
        
        // Remove from queue
        delete_transient('pc_ai_queue_' . $event_id);
    }
    
    /**
     * v7.3.0: Track performance metrics
     */
    private function track_performance($metric, $value) {
        // v1.9.22: Use a transient instead of update_option. Transients are never
        // autoloaded, so this data no longer adds weight to every WordPress page.
        // Previously get_option + update_option ran on every page load showing events.
        $stats = get_transient('pc_performance_stats');
        if ($stats === false) {
            // Migrate from legacy option on first run so existing data is preserved
            $stats = get_option('pc_performance_stats', array());
        }

        if (!isset($stats[$metric])) {
            $stats[$metric] = array(
                'count' => 0,
                'total' => 0,
                'min' => $value,
                'max' => $value
            );
        }

        $stats[$metric]['count']++;
        $stats[$metric]['total'] += $value;
        $stats[$metric]['min'] = min($stats[$metric]['min'], $value);
        $stats[$metric]['max'] = max($stats[$metric]['max'], $value);
        $stats[$metric]['avg'] = $stats[$metric]['total'] / $stats[$metric]['count'];

        set_transient('pc_performance_stats', $stats, 30 * DAY_IN_SECONDS);
    }
    
    /**
     * Plugin activation - create database table
     */
    /**
     * Activation function - called by main plugin
     */
    public function activate() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(255) NOT NULL UNIQUE,
            event_name VARCHAR(255) NOT NULL,
            original_description TEXT,
            ai_description TEXT,
            status VARCHAR(20) DEFAULT 'approved',
            created_at DATETIME NOT NULL,
            updated_at DATETIME,
            KEY event_id_idx (event_id),
            KEY status_idx (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Store database version
        add_option('pc_db_version', '1.0');
        
        // Migrate existing transient descriptions to database
        $this->migrate_transient_descriptions();
        
        // v7.3.0: Run initial cleanup
        $this->cleanup_old_transients();
        
        // v1.0.3: PERFORMANCE - Schedule daily transient cleanup
        if (!wp_next_scheduled('pc_daily_transient_cleanup')) {
            wp_schedule_event(time(), 'daily', 'pc_daily_transient_cleanup');
        }
    }
    
    /**
     * v7.3.0: Plugin deactivation - cleanup scheduled events
     */
    /**
     * Deactivation function - called by main plugin
     */
    public function deactivate() {
        // Remove scheduled cleanup
        $timestamp = wp_next_scheduled('pc_cleanup_transients');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'pc_cleanup_transients');
        }
        
        // v1.0.3: Remove daily cleanup cron
        $timestamp = wp_next_scheduled('pc_daily_transient_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'pc_daily_transient_cleanup');
        }
        
        $this->log_security_event('plugin_deactivated', array());
    }
    
    /**
     * Migrate existing AI descriptions from transients to database
     */
    private function migrate_transient_descriptions() {
        global $wpdb;
        
        // This will be called during activation to move any cached descriptions
        // to the permanent database storage
        // For now, we'll just ensure the table is ready
        // Future descriptions will be stored in DB automatically
    }
    
    /**
     * Add admin menu page
     */
    /**
     * Add admin menu - HANDLED BY MAIN PLUGIN
     * Keeping here for reference only
     */
    /*
    public function add_admin_menu() {
        add_options_page(
            'Planning Center Events Settings',
            'Planning Center Events',
            'manage_options',
            'planning-center-events',
            array($this, 'settings_page')
        );
        
        // Add AI Approval Queue page (only if AI enabled and approval required)
        if (get_option('pc_ai_enabled') && get_option('pc_ai_require_approval')) {
            add_management_page(
                'AI Description Queue',
                'AI Description Queue',
                'manage_options',
                'pc-ai-queue',
                array($this, 'approval_queue_page')
            );
        }
    }
    */
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('pc_events_settings', 'pc_app_id');
        register_setting('pc_events_settings', 'pc_secret');
        register_setting('pc_events_settings', 'pc_cache_duration', array('default' => 10800)); // 3 hours
        register_setting('pc_events_settings', 'pc_api_module', array('default' => 'registrations'));
        
        // v1.8.4: Church Center base URL for event pages
        register_setting('pc_events_settings', 'pc_church_center_url', array('default' => ''));
        
        // v1.8.6: Open links in new tab setting
        register_setting('pc_events_settings', 'pc_open_in_new_tab', array('default' => 1));
        
        // Event filtering settings
        register_setting('pc_events_settings', 'pc_public_only', array('default' => 1));
        register_setting('pc_events_settings', 'pc_required_category');
        register_setting('pc_events_settings', 'pc_excluded_events');
        
        // Display settings
        register_setting('pc_events_settings', 'pc_display_mode', array('default' => 'grid'));
        register_setting('pc_events_settings', 'pc_cards_per_row', array('default' => 3));
        register_setting('pc_events_settings', 'pc_truncate_descriptions', array('default' => 0));
        register_setting('pc_events_settings', 'pc_truncate_length', array('default' => 266));
        register_setting('pc_events_settings', 'pc_see_all_enabled', array('default' => 0));
        register_setting('pc_events_settings', 'pc_see_all_position', array('default' => 'last'));
        register_setting('pc_events_settings', 'pc_see_all_text', array('default' => 'See All Events'));
        register_setting('pc_events_settings', 'pc_see_all_link', array('default' => ''));
        register_setting('pc_events_settings', 'pc_see_all_image', array('default' => ''));
        register_setting('pc_events_settings', 'pc_button_text', array('default' => 'Register Now'));
        register_setting('pc_events_settings', 'pc_button_color', array('default' => '#007acc'));
        register_setting('pc_events_settings', 'pc_button_text_color', array('default' => '#ffffff'));
        register_setting('pc_events_settings', 'pc_badge_color', array('default' => '#007acc'));
        register_setting('pc_events_settings', 'pc_title_color', array('default' => '#333333'));
        register_setting('pc_events_settings', 'pc_background_color', array('default' => 'transparent'));
        register_setting('pc_events_settings', 'pc_card_background_color', array('default' => '#ffffff'));
        register_setting('pc_events_settings', 'pc_text_color', array('default' => '#666666'));
        register_setting('pc_events_settings', 'pc_debug_mode', array('default' => 0));
        register_setting('pc_events_settings', 'pc_sort_order', array('default' => 'date_asc'));
        
        // AI Enhancement settings
        register_setting('pc_events_settings', 'pc_ai_enabled', array('default' => 0));
        register_setting('pc_events_settings', 'pc_ai_service', array('default' => 'claude'));
        register_setting('pc_events_settings', 'pc_ai_api_key');
        register_setting('pc_events_settings', 'pc_ai_prompt');
        
        // AI Approval Workflow settings
        register_setting('pc_events_settings', 'pc_ai_require_approval', array('default' => 0));
        register_setting('pc_events_settings', 'pc_ai_approval_email');
        register_setting('pc_events_settings', 'pc_ai_send_notifications', array('default' => 1));
        
        // AI Constraint Parameters
        register_setting('pc_events_settings', 'pc_church_name', array('default' => ''));
        register_setting('pc_events_settings', 'pc_church_nickname', array('default' => ''));
        register_setting('pc_events_settings', 'pc_church_address', array('default' => ''));
        register_setting('pc_events_settings', 'pc_church_city', array('default' => ''));
        register_setting('pc_events_settings', 'pc_church_state', array('default' => ''));
        register_setting('pc_events_settings', 'pc_target_audience', array('default' => ''));
        register_setting('pc_events_settings', 'pc_denomination', array('default' => ''));
    }
    
    /**
     * Settings page HTML
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle cache clear
        if (isset($_GET['cache_cleared'])) {
            $type = sanitize_text_field($_GET['cache_cleared']);
            $messages = array(
                'events' => 'Event data cache cleared! AI descriptions preserved.',
                'ai' => 'AI description cache cleared! Descriptions will be regenerated on next page load.',
                'all' => 'All caches cleared successfully!',
                '1' => 'Cache cleared successfully!' // Backwards compatibility
            );
            $message = $messages[$type] ?? 'Cache cleared successfully!';
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
        
        // Get status info
        $api_connected = !empty(get_option('pc_app_id')) && !empty(get_option('pc_secret'));
        $events_cached = get_transient('pc_events_data') !== false;
        $ai_enabled = get_option('pc_ai_enabled');
        
        ?>
        <div class="wrap pc-admin-wrap">
            <!-- Modern Header -->
            <div class="pc-admin-header">
                <h1>Planning Center Events <span class="version">v7.2.1</span></h1>
                <p style="margin: 0; opacity: 0.9;">Manage your event display settings and AI enhancement options</p>
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
                    <h3>📊 Events Cache</h3>
                    <div class="value" style="color: <?php echo $events_cached ? '#46b450' : '#666'; ?>">
                        <?php echo $events_cached ? 'Cached' : 'Empty'; ?>
                    </div>
                    <span class="status <?php echo $events_cached ? 'status-success' : 'status-warning'; ?>">
                        <?php echo $events_cached ? 'Active' : 'Will load on next visit'; ?>
                    </span>
                </div>
                
                <div class="pc-status-card">
                    <h3>🤖 AI Enhancement</h3>
                    <div class="value" style="color: <?php echo $ai_enabled ? '#007acc' : '#666'; ?>">
                        <?php echo $ai_enabled ? 'Enabled' : 'Disabled'; ?>
                    </div>
                    <span class="status <?php echo $ai_enabled ? 'status-success' : 'status-warning'; ?>">
                        <?php 
                        if ($ai_enabled) {
                            $stats = $this->get_description_stats();
                            echo $stats['total'] . ' descriptions stored';
                        } else {
                            echo 'Configure below';
                        }
                        ?>
                    </span>
                </div>
            </div>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('pc_events_settings');
                ?>
                
                <div class="tab-content active">
                    <div class="pc-settings-card">
                        <div class="pc-settings-card-header">
                            <h3>🔌 Planning Center API Connection</h3>
                            <p>Configure which Planning Center module to pull events from.</p>
                        </div>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="pc_api_module">Planning Center Module</label>
                        </th>
                        <td>
                            <select id="pc_api_module" name="pc_api_module">
                                <option value="registrations" <?php selected(get_option('pc_api_module', 'registrations'), 'registrations'); ?>>Registrations (Event Registration)</option>
                                <option value="calendar" <?php selected(get_option('pc_api_module', 'registrations'), 'calendar'); ?>>Calendar (Church Calendar)</option>
                            </select>
                            <p class="description">
                                <strong>Registrations:</strong> For events that require registration/sign-ups<br>
                                <strong>Calendar:</strong> For general church calendar events<br>
                                Choose the module you use in Planning Center for your events.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_app_id">Application ID</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="pc_app_id" 
                                   name="pc_app_id" 
                                   value="<?php echo esc_attr(get_option('pc_app_id')); ?>" 
                                   class="regular-text" />
                            <p class="description">Your Planning Center API Application ID</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_secret">Secret</label>
                        </th>
                        <td>
                            <?php 
                            $secret = get_option('pc_secret');
                            $masked_secret = !empty($secret) ? str_repeat('•', 32) : '';
                            ?>
                            <input type="password" 
                                   id="pc_secret" 
                                   name="pc_secret" 
                                   value="<?php echo esc_attr($secret); ?>" 
                                   data-original="<?php echo esc_attr($secret); ?>"
                                   data-masked="<?php echo esc_attr($masked_secret); ?>"
                                   class="regular-text pc-secret-field" 
                                   readonly />
                            <button type="button" class="button pc-toggle-secret" data-target="pc_secret">
                                <span class="dashicons dashicons-visibility"></span> Show
                            </button>
                            <button type="button" class="button pc-edit-secret" data-target="pc_secret">
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
                            <label for="pc_cache_duration">Cache Duration (seconds)</label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="pc_cache_duration" 
                                   name="pc_cache_duration" 
                                   value="<?php echo esc_attr(get_option('pc_cache_duration', 10800)); ?>" 
                                   class="small-text" />
                            <p class="description">How long to cache event data (default: 3600 = 1 hour)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_church_center_url">Church Center Base URL</label>
                        </th>
                        <td>
                            <input type="url" 
                                   id="pc_church_center_url" 
                                   name="pc_church_center_url" 
                                   value="<?php echo esc_attr(get_option('pc_church_center_url')); ?>" 
                                   class="regular-text" 
                                   placeholder="https://yourchurch.churchcenter.com/" />
                            <p class="description">
                                <strong>Event Page Links:</strong> Enter either:<br>
                                • Full path: <code>https://yourchurch.churchcenter.com/registrations/events/</code><br>
                                • Or just domain: <code>https://yourchurch.churchcenter.com/</code><br>
                                The plugin will construct proper event page URLs automatically.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_open_in_new_tab">Open Links in New Tab</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="pc_open_in_new_tab" 
                                       name="pc_open_in_new_tab" 
                                       value="1" 
                                       <?php checked(get_option('pc_open_in_new_tab', 1), 1); ?> />
                                Open event links in a new browser tab
                            </label>
                            <p class="description">
                                <strong>Recommended:</strong> Keep checked to keep visitors on your website.<br>
                                When enabled, clicking an event opens Planning Center in a new tab.
                            </p>
                        </td>
                    </tr>
                </table>
                    </div><!-- End API Connection Card -->
                
                <div class="pc-settings-card">
                    <div class="pc-settings-card-header">
                        <h3>🎯 Event Filtering</h3>
                        <p>Control which events from Planning Center appear on your website.</p>
                    </div>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="pc_public_only">Show Public Events Only</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="pc_public_only" 
                                       name="pc_public_only" 
                                       value="1" 
                                       <?php checked(get_option('pc_public_only', 1), 1); ?> />
                                Only display events marked as "Approved" or "Public" in Planning Center
                            </label>
                            <p class="description">Recommended: Keep this checked to hide internal/private events</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_required_category">Required Category (Optional)</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="pc_required_category" 
                                   name="pc_required_category" 
                                   value="<?php echo esc_attr(get_option('pc_required_category')); ?>" 
                                   class="regular-text" 
                                   placeholder="e.g., Website, Featured Event" />
                            <p class="description">
                                <strong>Optional:</strong> If set, only events with this exact category will display on your website.<br>
                                Leave blank to show all public events. Category name is case-sensitive.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_excluded_events">Excluded Events</label>
                        </th>
                        <td>
                            <textarea id="pc_excluded_events" 
                                      name="pc_excluded_events" 
                                      rows="5" 
                                      class="large-text"
                                      placeholder="Event Name 1&#10;Event Name 2&#10;Event Name 3"><?php 
                                echo esc_textarea(get_option('pc_excluded_events')); 
                            ?></textarea>
                            <p class="description">
                                Enter event names to hide (one per line). These events will never display on your website.<br>
                                <strong>Example use:</strong> Volunteer registration forms, internal events, etc.<br>
                                Event names must match exactly (case-sensitive).
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Current Filter Logic</th>
                        <td>
                            <p class="description">
                                <strong>An event will display if ALL of these conditions are true:</strong><br>
                                1. Event is in the future ✓<br>
                                2. <?php echo get_option('pc_public_only', 1) ? 'Event is marked as Public/Approved ✓' : 'Any visibility status ✓'; ?><br>
                                3. <?php 
                                    $required_category = get_option('pc_required_category');
                                    echo !empty($required_category) 
                                        ? 'Event has category: "' . esc_html($required_category) . '" ✓' 
                                        : 'No category requirement ✓'; 
                                ?><br>
                                4. Event is NOT in the exclusion list ✓
                            </p>
                        </td>
                    </tr>
                </table>
                    </div><!-- End Event Filtering Card -->
                
                <div class="pc-settings-card">
                    <div class="pc-settings-card-header">
                        <h3>🎨 Display Settings</h3>
                        <p>Customize how events are displayed on your website.</p>
                    </div>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="pc_display_mode">Display Mode</label>
                        </th>
                        <td>
                            <select id="pc_display_mode" name="pc_display_mode">
                                <option value="grid" <?php selected(get_option('pc_display_mode', 'grid'), 'grid'); ?>>Grid (Static)</option>
                            </select>
                            <p class="description">Currently set to Grid layout for event display.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_cards_per_row">Cards Per Row</label>
                        </th>
                        <td>
                            <select id="pc_cards_per_row" name="pc_cards_per_row">
                                <option value="1" <?php selected(get_option('pc_cards_per_row', 3), 1); ?>>1 Card (Full Width with Alternating Layout)</option>
                                <option value="2" <?php selected(get_option('pc_cards_per_row', 3), 2); ?>>2 Cards</option>
                                <option value="3" <?php selected(get_option('pc_cards_per_row', 3), 3); ?>>3 Cards (Default)</option>
                            </select>
                            <p class="description">Choose how many event cards to display per row. In carousel mode, this determines how many cards are visible at once.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_button_text">Button Text</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="pc_button_text" 
                                   name="pc_button_text" 
                                   value="<?php echo esc_attr(get_option('pc_button_text', 'Register Now')); ?>" 
                                   class="regular-text" 
                                   placeholder="Register Now" />
                            <p class="description">Text displayed on the registration button</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_truncate_descriptions">Truncate Descriptions</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="pc_truncate_descriptions" 
                                       name="pc_truncate_descriptions" 
                                       value="1" 
                                       <?php checked(get_option('pc_truncate_descriptions', 0), 1); ?> />
                                Limit descriptions
                            </label>
                            <p class="description">When enabled, descriptions will be truncated with "..." to keep cards uniform in height</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_truncate_length">Character Limit</label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="pc_truncate_length" 
                                   name="pc_truncate_length" 
                                   value="<?php echo esc_attr(get_option('pc_truncate_length', 266)); ?>" 
                                   min="50" 
                                   max="1000" 
                                   step="1" 
                                   style="width: 100px;" />
                            <p class="description">Number of characters to show before truncating (only applies when truncation is enabled)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row" colspan="2">
                            <h3 style="margin: 20px 0 10px 0;">"See All Events" Card</h3>
                            <p style="font-weight: normal; color: #666; margin: 5px 0 15px 0;">Add a custom card that links to your full events page</p>
                        </th>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_see_all_enabled">Enable Card</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="pc_see_all_enabled" 
                                       name="pc_see_all_enabled" 
                                       value="1" 
                                       <?php checked(get_option('pc_see_all_enabled', 0), 1); ?> />
                                Show "See All Events" card
                            </label>
                            <p class="description">Display a card that links to your full events listing (e.g., Planning Center Calendar)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_see_all_position">Card Position</label>
                        </th>
                        <td>
                            <select id="pc_see_all_position" name="pc_see_all_position">
                                <option value="first" <?php selected(get_option('pc_see_all_position', 'last'), 'first'); ?>>First (Before all events)</option>
                                <option value="last" <?php selected(get_option('pc_see_all_position', 'last'), 'last'); ?>>Last (After all events)</option>
                            </select>
                            <p class="description">Choose where the "See All Events" card appears</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_see_all_text">Card Text</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="pc_see_all_text" 
                                   name="pc_see_all_text" 
                                   value="<?php echo esc_attr(get_option('pc_see_all_text', 'See All Events')); ?>" 
                                   class="regular-text" 
                                   placeholder="See All Events" />
                            <p class="description">Text displayed on the card</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_see_all_link">Link URL</label>
                        </th>
                        <td>
                            <input type="url" 
                                   id="pc_see_all_link" 
                                   name="pc_see_all_link" 
                                   value="<?php echo esc_attr(get_option('pc_see_all_link')); ?>" 
                                   class="regular-text" 
                                   placeholder="https://your-planning-center-url.com" />
                            <p class="description">Where should this card link to? (e.g., your Planning Center Calendar page)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_see_all_image">Card Image</label>
                        </th>
                        <td>
                            <?php $see_all_image = get_option('pc_see_all_image', ''); ?>
                            <input type="hidden" 
                                   id="pc_see_all_image" 
                                   name="pc_see_all_image" 
                                   value="<?php echo esc_attr($see_all_image); ?>" />
                            
                            <div class="pc-image-preview" style="margin-bottom: 10px;">
                                <?php if ($see_all_image): ?>
                                    <img src="<?php echo esc_url($see_all_image); ?>" 
                                         style="max-width: 300px; height: auto; display: block; border: 1px solid #ddd; padding: 5px;" 
                                         id="pc_see_all_image_preview" />
                                <?php else: ?>
                                    <div id="pc_see_all_image_preview" style="width: 300px; height: 169px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border: 1px solid #ddd;">
                                        <span style="color: #999;">No image selected</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <button type="button" class="button" id="pc_upload_see_all_image">
                                <?php echo $see_all_image ? 'Change Image' : 'Upload Image'; ?>
                            </button>
                            
                            <?php if ($see_all_image): ?>
                                <button type="button" class="button" id="pc_remove_see_all_image">Remove Image</button>
                            <?php endif; ?>
                            
                            <p class="description">Custom image for the "See All Events" card. Recommended size: 1200x675 (16:9 ratio)</p>
                            
                            <script>
                            jQuery(document).ready(function($) {
                                // Media uploader for See All Events image
                                $('#pc_upload_see_all_image').on('click', function(e) {
                                    e.preventDefault();
                                    
                                    var image_frame;
                                    if (image_frame) {
                                        image_frame.open();
                                        return;
                                    }
                                    
                                    image_frame = wp.media({
                                        title: 'Select See All Events Image',
                                        multiple: false,
                                        library: {
                                            type: 'image'
                                        }
                                    });
                                    
                                    image_frame.on('select', function() {
                                        var attachment = image_frame.state().get('selection').first().toJSON();
                                        $('#pc_see_all_image').val(attachment.url);
                                        $('#pc_see_all_image_preview').html('<img src="' + attachment.url + '" style="max-width: 300px; height: auto; display: block; border: 1px solid #ddd; padding: 5px;" />');
                                        $('#pc_upload_see_all_image').text('Change Image');
                                        if ($('#pc_remove_see_all_image').length === 0) {
                                            $('#pc_upload_see_all_image').after(' <button type="button" class="button" id="pc_remove_see_all_image">Remove Image</button>');
                                        }
                                    });
                                    
                                    image_frame.open();
                                });
                                
                                // Remove image
                                $(document).on('click', '#pc_remove_see_all_image', function(e) {
                                    e.preventDefault();
                                    $('#pc_see_all_image').val('');
                                    $('#pc_see_all_image_preview').html('<div style="width: 300px; height: 169px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border: 1px solid #ddd;"><span style="color: #999;">No image selected</span></div>');
                                    $('#pc_upload_see_all_image').text('Upload Image');
                                    $(this).remove();
                                });
                            });
                            </script>
                        </td>
                    </tr>
                    
                    <!-- Color Settings in Compact Grid -->
                    <tr>
                        <th scope="row" colspan="2">
                            <h3 style="margin: 20px 0 10px 0;">Color Customization</h3>
                        </th>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; background: #f9f9f9; padding: 20px; border-radius: 4px;">
                                <!-- Column 1: Button Colors -->
                                <div>
                                    <h4 style="margin-top: 0; border-bottom: 2px solid #ddd; padding-bottom: 8px;">Button</h4>
                                    <div style="margin-bottom: 15px;">
                                        <label for="pc_button_color" style="display: block; margin-bottom: 5px; font-weight: 600;">Background</label>
                                        <input type="color" 
                                               id="pc_button_color" 
                                               name="pc_button_color" 
                                               value="<?php echo esc_attr(get_option('pc_button_color', '#007acc')); ?>" 
                                               style="width: 100%; height: 40px;" />
                                    </div>
                                    <div style="margin-bottom: 15px;">
                                        <label for="pc_button_text_color" style="display: block; margin-bottom: 5px; font-weight: 600;">Text</label>
                                        <input type="color" 
                                               id="pc_button_text_color" 
                                               name="pc_button_text_color" 
                                               value="<?php echo esc_attr(get_option('pc_button_text_color', '#ffffff')); ?>" 
                                               style="width: 100%; height: 40px;" />
                                    </div>
                                    <div>
                                        <label for="pc_badge_color" style="display: block; margin-bottom: 5px; font-weight: 600;">Badge</label>
                                        <input type="color" 
                                               id="pc_badge_color" 
                                               name="pc_badge_color" 
                                               value="<?php echo esc_attr(get_option('pc_badge_color', '#007acc')); ?>" 
                                               style="width: 100%; height: 40px;" />
                                    </div>
                                </div>
                                
                                <!-- Column 2: Card Colors -->
                                <div>
                                    <h4 style="margin-top: 0; border-bottom: 2px solid #ddd; padding-bottom: 8px;">Cards</h4>
                                    <div style="margin-bottom: 15px;">
                                        <label for="pc_card_background_color" style="display: block; margin-bottom: 5px; font-weight: 600;">Card Background</label>
                                        <input type="color" 
                                               id="pc_card_background_color" 
                                               name="pc_card_background_color" 
                                               value="<?php echo esc_attr(get_option('pc_card_background_color', '#ffffff')); ?>" 
                                               style="width: 100%; height: 40px;" />
                                    </div>
                                    <div style="margin-bottom: 15px;">
                                        <label for="pc_background_color" style="display: block; margin-bottom: 5px; font-weight: 600;">Container Background</label>
                                        <input type="color" 
                                               id="pc_background_color" 
                                               name="pc_background_color" 
                                               value="<?php echo esc_attr(get_option('pc_background_color', '#f5f5f5')); ?>" 
                                               style="width: 100%; height: 40px;" />
                                    </div>
                                </div>
                                
                                <!-- Column 3: Text Colors -->
                                <div>
                                    <h4 style="margin-top: 0; border-bottom: 2px solid #ddd; padding-bottom: 8px;">Text</h4>
                                    <div style="margin-bottom: 15px;">
                                        <label for="pc_title_color" style="display: block; margin-bottom: 5px; font-weight: 600;">Titles</label>
                                        <input type="color" 
                                               id="pc_title_color" 
                                               name="pc_title_color" 
                                               value="<?php echo esc_attr(get_option('pc_title_color', '#333333')); ?>" 
                                               style="width: 100%; height: 40px;" />
                                    </div>
                                    <div style="margin-bottom: 15px;">
                                        <label for="pc_text_color" style="display: block; margin-bottom: 5px; font-weight: 600;">Descriptions</label>
                                        <input type="color" 
                                               id="pc_text_color" 
                                               name="pc_text_color" 
                                               value="<?php echo esc_attr(get_option('pc_text_color', '#666666')); ?>" 
                                               style="width: 100%; height: 40px;" />
                                    </div>
                                </div>
                            </div>
                            <p class="description" style="margin-top: 10px;">All color settings update instantly when you save</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_sort_order">Sort Events By</label>
                        </th>
                        <td>
                            <select id="pc_sort_order" name="pc_sort_order">
                                <option value="date_asc" <?php selected(get_option('pc_sort_order', 'date_asc'), 'date_asc'); ?>>Date (Earliest First)</option>
                                <option value="date_desc" <?php selected(get_option('pc_sort_order', 'date_asc'), 'date_desc'); ?>>Date (Latest First)</option>
                                <option value="name_asc" <?php selected(get_option('pc_sort_order', 'date_asc'), 'name_asc'); ?>>Name (A-Z)</option>
                                <option value="name_desc" <?php selected(get_option('pc_sort_order', 'date_asc'), 'name_desc'); ?>>Name (Z-A)</option>
                            </select>
                            <p class="description">Choose how to sort events on the page</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_debug_mode">Debug Mode</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="pc_debug_mode" 
                                       name="pc_debug_mode" 
                                       value="1" 
                                       <?php checked(get_option('pc_debug_mode', 0), 1); ?> />
                                Show debug information (admin only)
                            </label>
                            <p class="description">Display technical debugging info above events when logged in as admin</p>
                        </td>
                    </tr>
                </table>
                    </div><!-- End Display Settings Card -->
                
                <div class="pc-settings-card">
                    <div class="pc-settings-card-header">
                        <h3>🤖 AI Enhancement Settings (Optional)</h3>
                        <p>Automatically enhance event descriptions using AI to make them more engaging, consistent, and SEO-optimized.</p>
                    </div>
                    
                    <div class="pc-info-box">
                        <h4>💡 How It Works</h4>
                        <p>AI reads your Planning Center descriptions and rewrites them to be more engaging and action-oriented. Descriptions are stored permanently to save API costs (~90% reduction).</p>
                    </div>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="pc_ai_enabled">Enable AI Enhancement</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="pc_ai_enabled" 
                                       name="pc_ai_enabled" 
                                       value="1" 
                                       <?php checked(get_option('pc_ai_enabled'), 1); ?> />
                                Enhance event descriptions with AI
                            </label>
                            <p class="description">When enabled, AI will rewrite event descriptions to be more engaging and SEO-friendly</p>
                        </td>
                    </tr>
                </table>
                
                <!-- Collapsible AI Settings Section -->
                <div id="pc-ai-settings-section" style="<?php echo get_option('pc_ai_enabled') ? '' : 'display: none;'; ?>">
                    <div style="background: #f9f9f9; border-left: 4px solid #007acc; padding: 20px; margin: 20px 0;">
                        <h4 style="margin-top: 0; color: #23282d;">🤖 AI Configuration</h4>
                        <p style="color: #666; margin-bottom: 0;">Configure your AI service and customize how descriptions are enhanced.</p>
                    </div>
                    
                <table class="form-table">
                        <th scope="row">
                            <label for="pc_ai_service">AI Service</label>
                        </th>
                        <td>
                            <select id="pc_ai_service" name="pc_ai_service" style="min-width: 200px;">
                                <option value="claude" <?php selected(get_option('pc_ai_service', 'claude'), 'claude'); ?>>Claude (Anthropic)</option>
                                <option value="openai" <?php selected(get_option('pc_ai_service'), 'openai'); ?>>ChatGPT (OpenAI)</option>
                            </select>
                            <p class="description">Choose which AI service to use for enhancement</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_ai_api_key">AI API Key</label>
                        </th>
                        <td>
                            <?php 
                            $ai_key = get_option('pc_ai_api_key');
                            $masked_ai_key = !empty($ai_key) ? str_repeat('•', 32) : '';
                            ?>
                            <input type="password" 
                                   id="pc_ai_api_key" 
                                   name="pc_ai_api_key" 
                                   value="<?php echo esc_attr($ai_key); ?>" 
                                   data-original="<?php echo esc_attr($ai_key); ?>"
                                   data-masked="<?php echo esc_attr($masked_ai_key); ?>"
                                   class="regular-text pc-secret-field" 
                                   readonly />
                            <button type="button" class="button pc-toggle-secret" data-target="pc_ai_api_key">
                                <span class="dashicons dashicons-visibility"></span> Show
                            </button>
                            <button type="button" class="button pc-edit-secret" data-target="pc_ai_api_key">
                                <span class="dashicons dashicons-edit"></span> Edit
                            </button>
                            <button type="button" id="pc_test_ai_key" class="button" style="margin-left: 10px;">
                                Test API Key
                            </button>
                            <div id="pc_ai_test_result" style="margin-top: 10px;"></div>
                            <p class="description">
                                Get your API key from: 
                                <a href="https://console.anthropic.com/" target="_blank">Anthropic Console</a> (New accounts get $5 free!) or 
                                <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>
                            </p>
                            <script>
                            jQuery(document).ready(function($) {
                                $('#pc_test_ai_key').on('click', function() {
                                    var btn = $(this);
                                    var apiKey = $('#pc_ai_api_key').val();
                                    var service = $('#pc_ai_service').val();
                                    var resultDiv = $('#pc_ai_test_result');
                                    
                                    if (!apiKey) {
                                        resultDiv.html('<div class="notice notice-error inline"><p>Please enter an API key first.</p></div>');
                                        return;
                                    }
                                    
                                    btn.prop('disabled', true).text('Testing...');
                                    resultDiv.html('<div class="notice notice-info inline"><p>Testing API key...</p></div>');
                                    
                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'POST',
                                        data: {
                                            action: 'pc_test_ai_key',
                                            api_key: apiKey,
                                            service: service,
                                            nonce: '<?php echo wp_create_nonce('pc_test_ai_key'); ?>'
                                        },
                                        success: function(response) {
                                            if (response.success) {
                                                resultDiv.html('<div class="notice notice-success inline"><p><strong>✅ Success!</strong> ' + response.data.message + '</p></div>');
                                            } else {
                                                resultDiv.html('<div class="notice notice-error inline"><p><strong>❌ Error:</strong> ' + response.data.message + '</p></div>');
                                            }
                                        },
                                        error: function() {
                                            resultDiv.html('<div class="notice notice-error inline"><p>Connection error. Please try again.</p></div>');
                                        },
                                        complete: function() {
                                            btn.prop('disabled', false).text('Test API Key');
                                        }
                                    });
                                });
                            });
                            </script>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="pc_ai_prompt">Enhancement Instructions (Optional)</label>
                        </th>
                        <td>
                            <textarea id="pc_ai_prompt" 
                                      name="pc_ai_prompt" 
                                      rows="8" 
                                      class="large-text"
                                      placeholder="Leave blank to use default instructions..."><?php 
                                echo esc_textarea(get_option('pc_ai_prompt')); 
                            ?></textarea>
                            <p class="description">
                                Custom instructions for how AI should enhance your descriptions.<br>
                                Leave blank to use our optimized default prompt for church events.
                            </p>
                        </td>
                    </tr>
                </table>
                
                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin: 30px 0;">
                    <h3 style="margin-top: 0; color: #856404;">⚠️ Important: AI Constraint Parameters</h3>
                    <p style="margin-bottom: 20px; color: #856404;">
                        <strong>Fill these out to prevent AI from making up information!</strong><br>
                        These parameters ensure the AI uses YOUR church's actual information instead of inventing details.
                    </p>
                    
                    <table class="form-table" style="background: white; padding: 15px; border-radius: 4px;">
                        <tr>
                            <th scope="row">
                                <label for="pc_church_name">Official Church Name</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="pc_church_name" 
                                       name="pc_church_name" 
                                       value="<?php echo esc_attr(get_option('pc_church_name')); ?>" 
                                       class="regular-text"
                                       placeholder="e.g., Living Hope Church" />
                                <p class="description">Your church's full, official name</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_church_nickname">Nickname / Short Name</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="pc_church_nickname" 
                                       name="pc_church_nickname" 
                                       value="<?php echo esc_attr(get_option('pc_church_nickname')); ?>" 
                                       class="regular-text"
                                       placeholder="e.g., Church LH, LH" />
                                <p class="description">Common abbreviation or nickname people use</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_church_address">Street Address</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="pc_church_address" 
                                       name="pc_church_address" 
                                       value="<?php echo esc_attr(get_option('pc_church_address')); ?>" 
                                       class="regular-text"
                                       placeholder="e.g., 123 Main St" />
                                <p class="description">Your church's street address</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_church_city">City</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="pc_church_city" 
                                       name="pc_church_city" 
                                       value="<?php echo esc_attr(get_option('pc_church_city')); ?>" 
                                       class="regular-text"
                                       placeholder="e.g., Lehighton" />
                                <p class="description">City where your church is located</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_church_state">State</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="pc_church_state" 
                                       name="pc_church_state" 
                                       value="<?php echo esc_attr(get_option('pc_church_state')); ?>" 
                                       class="medium-text"
                                       placeholder="e.g., PA or Pennsylvania" />
                                <p class="description">State abbreviation or full name</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_target_audience">Target Audience</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="pc_target_audience" 
                                       name="pc_target_audience" 
                                       value="<?php echo esc_attr(get_option('pc_target_audience')); ?>" 
                                       class="regular-text"
                                       placeholder="e.g., families, young adults, local community" />
                                <p class="description">Who does your church primarily serve?</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_denomination">Denomination (Optional)</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="pc_denomination" 
                                       name="pc_denomination" 
                                       value="<?php echo esc_attr(get_option('pc_denomination')); ?>" 
                                       class="regular-text"
                                       placeholder="e.g., Non-denominational, Baptist, Methodist" />
                                <p class="description">Your church's denomination or affiliation</p>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- AI Approval Workflow -->
                    <h3 style="margin-top: 30px;">AI Approval Workflow</h3>
                    <p>Control how AI-generated descriptions are reviewed before appearing on your website.</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Require Admin Approval</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           name="pc_ai_require_approval" 
                                           value="1" 
                                           <?php checked(get_option('pc_ai_require_approval'), 1); ?> />
                                    Require admin approval for AI-generated descriptions
                                </label>
                                <p class="description">
                                    When enabled, AI descriptions will be marked as "pending" and require your approval before appearing on the website.<br>
                                    <strong>Recommended:</strong> Enable this for quality control and review.
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="pc_ai_approval_email">Notification Email</label>
                            </th>
                            <td>
                                <input type="email" 
                                       id="pc_ai_approval_email" 
                                       name="pc_ai_approval_email" 
                                       value="<?php echo esc_attr(get_option('pc_ai_approval_email', get_option('admin_email'))); ?>" 
                                       class="regular-text" />
                                <p class="description">
                                    Email address to receive notifications when new descriptions need approval.<br>
                                    Leave blank to use WordPress admin email: <code><?php echo get_option('admin_email'); ?></code>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">Email Notifications</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           name="pc_ai_send_notifications" 
                                           value="1" 
                                           <?php checked(get_option('pc_ai_send_notifications', 1), 1); ?> />
                                    Send email when new descriptions are pending approval
                                </label>
                                <p class="description">
                                    You'll receive an email with the event name and both descriptions for easy review.
                                </p>
                            </td>
                        </tr>
                        
                        <?php if (get_option('pc_ai_require_approval') && get_option('pc_ai_enabled')): ?>
                        <tr>
                            <td colspan="2">
                                <div style="background: #fff3cd; border-left: 4px solid #ffb900; padding: 15px; margin: 10px 0;">
                                    <h4 style="margin-top: 0;">⏳ Approval Queue Active</h4>
                                    <p style="margin-bottom: 10px;">
                                        AI approval workflow is enabled. New descriptions will require your review.
                                    </p>
                                    <?php
                                    $stats = $this->get_description_stats();
                                    if ($stats['pending'] > 0):
                                    ?>
                                    <p style="margin: 10px 0;">
                                        <strong><?php echo $stats['pending']; ?> descriptions</strong> are currently pending approval.
                                    </p>
                                    <a href="<?php echo admin_url('tools.php?page=pc-ai-queue'); ?>" class="button button-primary">
                                        View Approval Queue →
                                    </a>
                                    <?php else: ?>
                                    <p style="margin: 0;">
                                        ✅ No descriptions pending approval. <a href="<?php echo admin_url('tools.php?page=pc-ai-queue'); ?>">View Queue</a>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
                <!-- End Collapsible AI Settings Section -->
                </div>
                
                <script>
                jQuery(document).ready(function($) {
                    // Toggle AI settings visibility
                    $('#pc_ai_enabled').on('change', function() {
                        if ($(this).is(':checked')) {
                            $('#pc-ai-settings-section').slideDown(300);
                        } else {
                            $('#pc-ai-settings-section').slideUp(300);
                        }
                    });
                });
                </script>
                    </div><!-- End AI Enhancement Card -->
                
                <?php submit_button('Save Settings'); ?>
            </form>
            
            <!-- Cache Management Card -->
            <div class="pc-settings-card">
                <div class="pc-settings-card-header">
                    <h3>🗄️ Cache Management</h3>
                    <p>Choose what to refresh from Planning Center:</p>
                </div>
            
            <?php 
            // Show AI description statistics
            if (get_option('pc_ai_enabled')) {
                $stats = $this->get_description_stats();
                ?>
                <div style="background: #f0f7ff; border-left: 4px solid #007acc; padding: 15px; margin: 20px 0;">
                    <h3 style="margin-top: 0;">💾 AI Description Storage</h3>
                    <p style="margin-bottom: 10px;">
                        <strong><?php echo $stats['total']; ?> descriptions</strong> stored permanently in database<br>
                        <strong><?php echo $stats['approved']; ?> approved</strong> and visible on website
                        <?php if ($stats['pending'] > 0): ?>
                            <br><strong style="color: #ffb900;"><?php echo $stats['pending']; ?> pending approval</strong>
                        <?php endif; ?>
                        <br>
                        <span class="description">Descriptions are stored once and reused, saving API costs!</span>
                    </p>
                    <?php if ($stats['total'] > 0): ?>
                        <p class="description" style="margin: 10px 0 0 0;">
                            ✅ <strong>Benefit:</strong> ~90% reduction in AI API calls compared to temporary caching
                        </p>
                    <?php endif; ?>
                    <?php if (get_option('pc_ai_require_approval') && $stats['pending'] > 0): ?>
                        <p style="margin: 10px 0 0 0;">
                            <a href="<?php echo admin_url('tools.php?page=pc-ai-queue'); ?>" class="button button-primary">
                                Review Pending Descriptions (<?php echo $stats['pending']; ?>) →
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            <?php } ?>
            
            <table style="margin: 20px 0;">
                <tr>
                    <td style="padding-right: 20px; vertical-align: top;">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block;">
                            <input type="hidden" name="action" value="clear_pc_events_cache">
                            <?php wp_nonce_field('clear_pc_events_cache_nonce'); ?>
                            <?php submit_button('Clear Event Data Only', 'secondary', 'submit', false); ?>
                        </form>
                        <p class="description" style="margin-top: 5px; max-width: 250px;">
                            Refreshes event info (titles, dates, images, locations) but <strong>keeps AI descriptions</strong>.
                        </p>
                    </td>
                    <td style="padding-right: 20px; vertical-align: top;">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block;">
                            <input type="hidden" name="action" value="clear_pc_ai_cache">
                            <?php wp_nonce_field('clear_pc_ai_cache_nonce'); ?>
                            <?php submit_button('Regenerate AI Descriptions', 'secondary', 'submit', false); ?>
                        </form>
                        <p class="description" style="margin-top: 5px; max-width: 250px;">
                            Deletes stored descriptions and regenerates all. ⚠️ Uses API credits.
                        </p>
                    </td>
                    <td style="vertical-align: top;">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block;">
                            <input type="hidden" name="action" value="clear_pc_all_cache">
                            <?php wp_nonce_field('clear_pc_all_cache_nonce'); ?>
                            <?php submit_button('Clear Everything', 'delete', 'submit', false); ?>
                        </form>
                        <p class="description" style="margin-top: 5px; max-width: 250px;">
                            Clears all data including stored AI descriptions.
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php if (get_option('pc_debug_mode')): ?>
            <div style="background: #fff3cd; border-left: 4px solid #ffb900; padding: 15px; margin: 20px 0;">
                <h3 style="margin-top: 0;">🔍 Debug Tools</h3>
                <p>View raw API responses to diagnose image and button issues:</p>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block;">
                    <input type="hidden" name="action" value="pc_debug_api_response">
                    <?php wp_nonce_field('pc_debug_api_response_nonce'); ?>
                    <?php submit_button('View Raw API Response', 'secondary', 'submit', false); ?>
                </form>
                <p class="description" style="margin-top: 10px;">
                    This will show you exactly what data Planning Center is returning, including all available fields.
                </p>
            </div>
            <?php endif; ?>
                </div><!-- End Cache Management Card -->
            
            <?php if (get_option('pc_ai_enabled')): ?>
            
            <div class="pc-settings-card">
                <div class="pc-settings-card-header">
                    <h3>⚡ Performance Dashboard</h3>
                    <p>Monitor your plugin's performance and resource usage.</p>
                </div>
            
            <?php
            // Get performance stats (v1.9.22: now stored as transient, not option)
            $stats = get_transient('pc_performance_stats');
            if ($stats === false) {
                $stats = get_option('pc_performance_stats', array());
            }
            $ai_calls_today = get_transient('pc_ai_calls_today') ?: 0;
            $ai_limit = apply_filters('pc_ai_rate_limit', 100);
            $using_object_cache = wp_using_ext_object_cache();
            ?>
            
            <div class="pc-cache-cards" style="margin: 20px 0;">
                <div class="pc-cache-card">
                    <h4>📊 Cache Performance</h4>
                    <?php if (isset($stats['cache_hit'])): ?>
                        <?php
                        $total = $stats['cache_hit']['count'] + ($stats['cache_miss']['count'] ?? 0);
                        $hit_rate = $total > 0 ? round(($stats['cache_hit']['count'] / $total) * 100) : 0;
                        ?>
                        <p style="font-size: 24px; margin: 10px 0; font-weight: 600; color: <?php echo $hit_rate > 80 ? '#46b450' : '#ffb900'; ?>;">
                            <?php echo $hit_rate; ?>%
                        </p>
                        <p style="margin: 0; color: #666;">Cache Hit Rate</p>
                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #999;">
                            <?php echo $stats['cache_hit']['count']; ?> hits / <?php echo $total; ?> requests
                        </p>
                    <?php else: ?>
                        <p style="color: #999;">No data yet</p>
                    <?php endif; ?>
                </div>
                
                <div class="pc-cache-card">
                    <h4>🤖 AI Rate Limiting</h4>
                    <p style="font-size: 24px; margin: 10px 0; font-weight: 600; color: <?php echo $ai_calls_today < $ai_limit * 0.8 ? '#46b450' : '#ffb900'; ?>;">
                        <?php echo $ai_calls_today; ?> / <?php echo $ai_limit; ?>
                    </p>
                    <p style="margin: 0; color: #666;">Calls Today</p>
                    <p style="margin: 5px 0 0 0; font-size: 12px; color: #999;">
                        <?php echo $ai_limit - $ai_calls_today; ?> remaining
                    </p>
                </div>
                
                <div class="pc-cache-card">
                    <h4>⚡ API Response Time</h4>
                    <?php if (isset($stats['api_response_time'])): ?>
                        <?php
                        $avg_time = $stats['api_response_time']['avg'];
                        $color = $avg_time < 1 ? '#46b450' : ($avg_time < 3 ? '#ffb900' : '#dc3232');
                        ?>
                        <p style="font-size: 24px; margin: 10px 0; font-weight: 600; color: <?php echo $color; ?>;">
                            <?php echo number_format($avg_time, 2); ?>s
                        </p>
                        <p style="margin: 0; color: #666;">Average Response</p>
                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #999;">
                            Min: <?php echo number_format($stats['api_response_time']['min'], 2); ?>s / 
                            Max: <?php echo number_format($stats['api_response_time']['max'], 2); ?>s
                        </p>
                    <?php else: ?>
                        <p style="color: #999;">No data yet</p>
                    <?php endif; ?>
                </div>
                
                <div class="pc-cache-card">
                    <h4>💾 Cache Type</h4>
                    <p style="font-size: 18px; margin: 10px 0; font-weight: 600; color: <?php echo $using_object_cache ? '#007acc' : '#666'; ?>;">
                        <?php echo $using_object_cache ? 'Object Cache' : 'Transients'; ?>
                    </p>
                    <p style="margin: 0; color: #666;">
                        <?php echo $using_object_cache ? 'Redis/Memcached Active' : 'Database Caching'; ?>
                    </p>
                    <p style="margin: 5px 0 0 0; font-size: 12px; color: #999;">
                        <?php echo $using_object_cache ? '⚡ High Performance' : 'Standard Performance'; ?>
                    </p>
                </div>
            </div>
            
            <div style="background: #f0f7ff; border-left: 4px solid #007acc; padding: 15px; margin: 20px 0;">
                <h4 style="margin: 0 0 10px 0; color: #007acc;">💡 Performance Tips</h4>
                <ul style="margin: 0; padding-left: 20px;">
                    <?php if (!$using_object_cache): ?>
                    <li>Consider using Redis or Memcached for better cache performance</li>
                    <?php endif; ?>
                    <?php if (isset($stats['cache_hit']) && $hit_rate < 80): ?>
                    <li>Cache hit rate is low - consider increasing cache duration</li>
                    <?php endif; ?>
                    <?php if ($ai_calls_today > $ai_limit * 0.8): ?>
                    <li style="color: #856404;">⚠️ Approaching AI call limit - descriptions will use originals after limit</li>
                    <?php endif; ?>
                    <?php if (isset($stats['api_response_time']) && $avg_time > 3): ?>
                    <li style="color: #856404;">⚠️ Slow API responses detected - check network or PC status</li>
                    <?php endif; ?>
                    <?php if ((!isset($stats['cache_hit']) || $hit_rate >= 80) && $ai_calls_today < $ai_limit * 0.8 && (!isset($stats['api_response_time']) || $avg_time <= 3)): ?>
                    <li style="color: #155724;">✅ Everything looks good! Your plugin is performing well.</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
                </div><!-- End Performance Dashboard Card -->
            
            <div class="pc-settings-card">
                <div class="pc-settings-card-header">
                    <h3>📖 Usage & Shortcode Examples</h3>
                    <p>Add this shortcode to any page or post to display your events:</p>
                </div>
            
            <h3>Basic Usage</h3>
            <code>[planning_center_events]</code>
            <p class="description">Shows all events</p>
            
            <h3>Filter by Ministry/Tag</h3>
            <code>[planning_center_events tag="Men"]</code>
            <p class="description">Shows only events tagged with "Men"</p>
            
            <code>[planning_center_events tag="Youth,Students"]</code>
            <p class="description">Shows events tagged with "Youth" OR "Students"</p>
            
            <h3>Limit Number of Events</h3>
            <code>[planning_center_events limit="6"]</code>
            <p class="description">Shows maximum of 6 events</p>
            
            <h3>Override Layout</h3>
            <code>[planning_center_events cards="2"]</code>
            <p class="description">Display 2 cards per row (overrides global setting)</p>
            
            <h3>Combine Parameters</h3>
            <code>[planning_center_events tag="Women" limit="6" cards="3"]</code>
            <p class="description">Women's events, max 6, in 3-column layout</p>
            
            <hr style="margin: 20px 0;">
            
            <h3>💡 Use Case: Ministry-Specific Pages</h3>
            <p>Create separate pages for different ministries using tag filtering:</p>
            <ul style="margin-left: 20px;">
                <li><strong>Men's Ministry Page:</strong> <code>[planning_center_events tag="Men"]</code></li>
                <li><strong>Women's Ministry Page:</strong> <code>[planning_center_events tag="Women"]</code></li>
                <li><strong>Youth Page:</strong> <code>[planning_center_events tag="Youth,Students"]</code></li>
                <li><strong>All Events Page:</strong> <code>[planning_center_events]</code></li>
            </ul>
            <p class="description">Tag your events in Planning Center Calendar to make this work!</p>
                </div><!-- End Usage Card -->
        </div><!-- End wrap -->
        <?php
    }
    
    /**
     * AI Descriptions page (alias for approval_queue_page for unified menu)
     */
    public function ai_descriptions_page() {
        return $this->approval_queue_page();
    }
    
    /**
     * AI Approval Queue page
     */
    public function approval_queue_page() {
        global $wpdb;
        
        // Handle status filter
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'pending';
        
        // Get descriptions by status
        // v1.9.21: Security fix - use prepared statement for entire query
        if ($status_filter !== 'all') {
            $query = $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE status = %s ORDER BY created_at DESC",
                $status_filter
            );
        } else {
            $query = "SELECT * FROM {$this->table_name} ORDER BY created_at DESC";
        }
        
        $descriptions = $wpdb->get_results($query);
        
        // Get stats
        $stats = $this->get_description_stats();
        
        ?>
        <div class="wrap">
            <h1>AI Description Queue</h1>
            
            <?php
            // Success messages
            if (isset($_GET['approved'])) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Description approved!</strong> It will now appear on your website.</p></div>';
            }
            if (isset($_GET['rejected'])) {
                echo '<div class="notice notice-warning is-dismissible"><p><strong>Description rejected.</strong> The original Planning Center description will be used.</p></div>';
            }
            if (isset($_GET['bulk_approved'])) {
                // v1.0.3: SECURITY - use absint() for IDs
                $count = absint($_GET['bulk_approved'] ?? 0);
                echo '<div class="notice notice-success is-dismissible"><p><strong>' . $count . ' descriptions approved!</strong></p></div>';
            }
            ?>
            
            <!-- Statistics -->
            <div style="background: #f0f7ff; border-left: 4px solid #007acc; padding: 15px; margin: 20px 0;">
                <h3 style="margin-top: 0;">📊 Queue Statistics</h3>
                <p style="margin: 5px 0;"><strong><?php echo $stats['pending']; ?></strong> pending approval</p>
                <p style="margin: 5px 0;"><strong><?php echo $stats['approved']; ?></strong> approved</p>
                <p style="margin: 5px 0;"><strong><?php echo $stats['total']; ?></strong> total descriptions</p>
            </div>
            
            <!-- Status Filter Tabs -->
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo admin_url('tools.php?page=pc-ai-queue&status=pending'); ?>" 
                   class="nav-tab <?php echo $status_filter === 'pending' ? 'nav-tab-active' : ''; ?>">
                    Pending (<?php echo $stats['pending']; ?>)
                </a>
                <a href="<?php echo admin_url('tools.php?page=pc-ai-queue&status=approved'); ?>" 
                   class="nav-tab <?php echo $status_filter === 'approved' ? 'nav-tab-active' : ''; ?>">
                    Approved (<?php echo $stats['approved']; ?>)
                </a>
                <a href="<?php echo admin_url('tools.php?page=pc-ai-queue&status=all'); ?>" 
                   class="nav-tab <?php echo $status_filter === 'all' ? 'nav-tab-active' : ''; ?>">
                    All (<?php echo $stats['total']; ?>)
                </a>
            </h2>
            
            <?php if (empty($descriptions)): ?>
                <div style="padding: 40px; text-align: center; background: #f9f9f9; margin: 20px 0; border: 1px solid #ddd;">
                    <p style="font-size: 18px; color: #666;">
                        <?php if ($status_filter === 'pending'): ?>
                            🎉 No descriptions pending approval!
                        <?php else: ?>
                            No descriptions found.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                
                <!-- Bulk Actions Form (only for pending) -->
                <?php if ($status_filter === 'pending' && !empty($descriptions)): ?>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin: 20px 0;">
                    <input type="hidden" name="action" value="pc_bulk_approve">
                    <?php wp_nonce_field('pc_bulk_approve'); ?>
                    
                    <div class="tablenav top">
                        <div class="alignleft actions">
                            <button type="submit" class="button action" onclick="return confirm('Approve all selected descriptions?');">
                                Bulk Approve Selected
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Descriptions List -->
                <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <?php if ($status_filter === 'pending'): ?>
                            <td class="check-column">
                                <input type="checkbox" id="select-all" onclick="
                                    const checkboxes = document.querySelectorAll('input[name=\'event_ids[]\']');
                                    checkboxes.forEach(cb => cb.checked = this.checked);
                                ">
                            </td>
                            <?php endif; ?>
                            <th style="width: 25%;">Event Name</th>
                            <th style="width: 35%;">AI Description</th>
                            <th style="width: 20%;">Status</th>
                            <th style="width: 15%;">Date</th>
                            <th style="width: 5%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($descriptions as $desc): ?>
                        <tr>
                            <?php if ($status_filter === 'pending'): ?>
                            <th class="check-column">
                                <input type="checkbox" name="event_ids[]" value="<?php echo esc_attr($desc->event_id); ?>">
                            </th>
                            <?php endif; ?>
                            
                            <td>
                                <strong><?php echo esc_html($desc->event_name); ?></strong>
                                <br>
                                <span style="color: #666; font-size: 11px;">ID: <?php echo esc_html($desc->event_id); ?></span>
                            </td>
                            
                            <td>
                                <div style="max-height: 100px; overflow: hidden;">
                                    <?php echo esc_html(substr($desc->ai_description, 0, 200)); ?>
                                    <?php if (strlen($desc->ai_description) > 200): ?>...<?php endif; ?>
                                </div>
                                <button type="button" class="button button-small" 
                                        onclick="showPreview('<?php echo esc_js($desc->event_id); ?>')"
                                        style="margin-top: 5px;">
                                    View Full Description
                                </button>
                            </td>
                            
                            <td>
                                <?php if ($desc->status === 'pending'): ?>
                                    <span style="background: #ffb900; color: #000; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold;">
                                        ⏳ PENDING
                                    </span>
                                <?php elseif ($desc->status === 'approved'): ?>
                                    <span style="background: #008a00; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold;">
                                        ✓ APPROVED
                                    </span>
                                <?php else: ?>
                                    <span style="background: #d63301; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold;">
                                        ✗ REJECTED
                                    </span>
                                <?php endif; ?>
                            </td>
                            
                            <td>
                                <?php echo esc_html(date('M j, Y', strtotime($desc->created_at))); ?>
                                <br>
                                <span style="color: #666; font-size: 11px;">
                                    <?php echo esc_html(date('g:i a', strtotime($desc->created_at))); ?>
                                </span>
                            </td>
                            
                            <td>
                                <?php if ($desc->status === 'pending'): ?>
                                    <?php
                                    $approve_url = wp_nonce_url(
                                        admin_url('admin-post.php?action=pc_approve_description&event_id=' . urlencode($desc->event_id)),
                                        'pc_approve_description'
                                    );
                                    $reject_url = wp_nonce_url(
                                        admin_url('admin-post.php?action=pc_reject_description&event_id=' . urlencode($desc->event_id)),
                                        'pc_reject_description'
                                    );
                                    ?>
                                    <a href="<?php echo $approve_url; ?>" 
                                       class="button button-primary button-small"
                                       onclick="return confirm('Approve this description?');">
                                        Approve
                                    </a>
                                    <br><br>
                                    <a href="<?php echo $reject_url; ?>" 
                                       class="button button-small"
                                       onclick="return confirm('Reject this description? The original PC description will be used.');">
                                        Reject
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <!-- Hidden preview modal -->
                        <div id="preview-<?php echo esc_attr($desc->event_id); ?>" style="display: none;">
                            <div style="max-width: 800px;">
                                <h2><?php echo esc_html($desc->event_name); ?></h2>
                                
                                <div style="margin: 20px 0;">
                                    <h3 style="color: #666;">Original Planning Center Description:</h3>
                                    <div style="padding: 15px; background: #f5f5f5; border-left: 3px solid #666; white-space: pre-wrap;">
<?php echo esc_html($desc->original_description); ?>
                                    </div>
                                </div>
                                
                                <div style="margin: 20px 0;">
                                    <h3 style="color: #007acc;">AI-Enhanced Description:</h3>
                                    <div style="padding: 15px; background: #f0f7ff; border-left: 3px solid #007acc; white-space: pre-wrap;">
<?php echo esc_html($desc->ai_description); ?>
                                    </div>
                                </div>
                                
                                <?php if ($desc->status === 'pending'): ?>
                                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                                    <a href="<?php echo $approve_url; ?>" 
                                       class="button button-primary"
                                       onclick="return confirm('Approve this description?');">
                                        ✓ Approve Description
                                    </a>
                                    <a href="<?php echo $reject_url; ?>" 
                                       class="button"
                                       onclick="return confirm('Reject this description?');">
                                        ✗ Reject Description
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($status_filter === 'pending' && !empty($descriptions)): ?>
                </form>
                <?php endif; ?>
                
            <?php endif; ?>
            
            <!-- Help Text -->
            <div style="margin-top: 30px; padding: 15px; background: #fff; border: 1px solid #ddd;">
                <h3>💡 How This Works</h3>
                <ul>
                    <li><strong>Pending:</strong> New AI descriptions waiting for your approval</li>
                    <li><strong>Approved:</strong> Descriptions visible on your website</li>
                    <li><strong>Rejected:</strong> AI description rejected, original PC description used instead</li>
                </ul>
                <p><strong>Tip:</strong> Click "View Full Description" to see side-by-side comparison before approving.</p>
            </div>
        </div>
        
        <!-- JavaScript for preview modal -->
        <script>
        function showPreview(eventId) {
            // v1.9.21: Security fix - use cloneNode instead of innerHTML to prevent XSS
            const sourceElement = document.getElementById('preview-' + eventId);
            const content = sourceElement.cloneNode(true);
            content.style.display = 'block'; // Make visible in modal
            
            // Create modal backdrop
            const backdrop = document.createElement('div');
            backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000; display: flex; align-items: center; justify-content: center;';
            backdrop.onclick = function(e) {
                if (e.target === backdrop) backdrop.remove();
            };
            
            // Create modal
            const modal = document.createElement('div');
            modal.style.cssText = 'background: white; padding: 30px; border-radius: 5px; max-width: 90%; max-height: 90%; overflow: auto; box-shadow: 0 5px 15px rgba(0,0,0,0.3);';
            modal.appendChild(content);
            
            // Add close button
            const closeBtn = document.createElement('button');
            closeBtn.className = 'button';
            closeBtn.textContent = 'Close';
            closeBtn.style.marginTop = '20px';
            closeBtn.onclick = function() { backdrop.remove(); };
            modal.appendChild(closeBtn);
            
            backdrop.appendChild(modal);
            document.body.appendChild(backdrop);
        }
        </script>
        
        <style>
        .wp-list-table td {
            vertical-align: top;
        }
        </style>
        <?php
    }
    
    /**
     * Clear cache
     */
    /**
     * Clear only event data cache (preserves AI descriptions)
     */
    public function clear_events_cache() {
        check_admin_referer('clear_pc_events_cache_nonce');
        
        // v7.3.0: Use new cache deletion system
        $this->delete_from_cache('pc_events_data');
        $this->delete_from_cache('pc_events_debug');
        $this->delete_from_cache('pc_raw_api_response');
        
        $this->log_security_event('cache_cleared', array('type' => 'events'));
        
        wp_redirect(add_query_arg(array(
            'page' => 'planning-center-events-settings',
            'cache_cleared' => 'events'
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Clear only AI description cache (forces re-enhancement)
     */
    public function clear_ai_cache() {
        check_admin_referer('clear_pc_ai_cache_nonce');
        
        // Clear all AI descriptions from database (forces regeneration)
        global $wpdb;
        // v1.0.3: Security fix - use prepare() even without user input to prevent potential SQL injection
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_name} WHERE 1=%d", 1));
        
        // Also clear event data so AI runs on next load
        $this->delete_from_cache('pc_events_data'); // v7.3.0
        
        $this->log_security_event('cache_cleared', array('type' => 'ai', 'descriptions_deleted' => $deleted));
        
        wp_redirect(add_query_arg(array(
            'page' => 'planning-center-events-settings',
            'cache_cleared' => 'ai',
            'descriptions_deleted' => $deleted
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Clear all caches
     */
    public function clear_all_cache() {
        check_admin_referer('clear_pc_all_cache_nonce');
        
        // v7.3.0: Use new cache deletion system
        $this->delete_from_cache('pc_events_data');
        $this->delete_from_cache('pc_events_debug');
        $this->delete_from_cache('pc_raw_api_response');
        
        // v7.3.0: Clear performance stats
        delete_option('pc_performance_stats');
        delete_transient('pc_ai_calls_today');
        
        // Clear all AI descriptions from database
        global $wpdb;
        // v1.0.3: Security fix - use prepare() to prevent SQL injection
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_name} WHERE 1=%d", 1));
        
        $this->log_security_event('cache_cleared', array('type' => 'all', 'descriptions_deleted' => $deleted));
        
        wp_redirect(add_query_arg(array(
            'page' => 'planning-center-events-settings',
            'cache_cleared' => 'all',
            'descriptions_deleted' => $deleted
        ), admin_url('admin.php')));
        exit;
    }
    
    // Keep old function for backwards compatibility
    public function clear_cache() {
        $this->clear_all_cache();
    }
    
    /**
     * AJAX handler to test AI API key
     */
    public function ajax_test_ai_key() {
        // Verify nonce
        check_ajax_referer('pc_test_ai_key', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $service = sanitize_text_field($_POST['service'] ?? 'claude');
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'API key is required'));
        }
        
        // Test the API key with a simple request
        if ($service === 'claude') {
            // Test Anthropic API
            $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
                'headers' => array(
                    'x-api-key' => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ),
                'body' => json_encode(array(
                    'model' => 'claude-3-haiku-20240307',
                    'max_tokens' => 50,
                    'messages' => array(
                        array(
                            'role' => 'user',
                            'content' => 'Say "Hello"'
                        )
                    )
                )),
                'timeout' => 15,
            ));
        } else {
            // Test OpenAI API
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode(array(
                    'model' => 'gpt-3.5-turbo',
                    'messages' => array(
                        array(
                            'role' => 'user',
                            'content' => 'Say "Hello"'
                        )
                    ),
                    'max_tokens' => 50,
                )),
                'timeout' => 15,
            ));
        }
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Connection failed: ' . $response->get_error_message()));
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code === 200) {
            $model = $service === 'claude' ? 'Claude (Anthropic)' : 'GPT (OpenAI)';
            wp_send_json_success(array(
                'message' => "API key is valid! Connected to {$model}. Cost estimate: ~$0.001-0.003 per event description."
            ));
        } elseif ($status_code === 401) {
            wp_send_json_error(array('message' => 'Invalid API key. Please check your key and try again.'));
        } elseif ($status_code === 429) {
            wp_send_json_error(array('message' => 'Rate limit exceeded or no credits remaining. Check your account balance.'));
        } else {
            $error_msg = $body['error']['message'] ?? 'Unknown error occurred';
            wp_send_json_error(array('message' => 'API Error: ' . $error_msg));
        }
    }
    
    /**
     * Enqueue plugin styles
     */
    /**
     * ENQUEUE FUNCTIONS - HANDLED BY MAIN PLUGIN
     * Keeping here for reference only
     */
    /*
    public function enqueue_styles() {
        wp_enqueue_style(
            'planning-center-events-safe',
            plugin_dir_url(__FILE__) . 'css/style.css',
            array(),
            time() // Use timestamp to force refresh every time
        );
    }
    
    public function enqueue_admin_styles($hook) {
        // Only load on our settings pages
        if ($hook !== 'settings_page_planning-center-events' && $hook !== 'tools_page_pc-ai-queue') {
            return;
        }
        
        wp_enqueue_style(
            'planning-center-events-admin',
            plugin_dir_url(__FILE__) . 'css/admin-style.css',
            array(),
            '7.2.1'
        );
    }
    */
    
    /**
     * v1.9.21: Public method for cache warming (called by cron)
     * Replaces need for Reflection API
     */
    public function warm_events_cache() {
        return $this->fetch_events(false);
    }
    
    /**
     * Fetch events from Planning Center API
     */
    private function fetch_events($skip_global_tag_filter = false) {
        $start_time = microtime(true);
        
        $app_id = get_option('pc_app_id');
        $secret = get_option('pc_secret');
        
        if (empty($app_id) || empty($secret)) {
            return new WP_Error('missing_credentials', 'Please configure your Planning Center API credentials in Settings > Planning Center Events');
        }
        
        // v7.3.0: Check cache using new cache system (supports object cache)
        // Note: Skip cache when shortcode overrides global filter to prevent mixing filtered/unfiltered results
        $cache_duration = get_option('pc_cache_duration', 10800); // 3 hours default
        $cached_events = false;
        
        if (!$skip_global_tag_filter) {
            $cached_events = $this->get_from_cache('pc_events_data');
        }
        
        if ($cached_events !== false) {
            $this->track_performance('cache_hit', 1);
            return $cached_events;
        }
        
        $this->track_performance('cache_miss', 1);
        
        // Get selected API module
        $api_module = get_option('pc_api_module', 'registrations');
        
        if ($api_module === 'calendar') {
            $events = $this->fetch_calendar_events($app_id, $secret, $cache_duration, $skip_global_tag_filter);
        } else {
            $events = $this->fetch_registration_events($app_id, $secret, $cache_duration, $skip_global_tag_filter);
        }
        
        // Track API response time
        $response_time = microtime(true) - $start_time;
        $this->track_performance('api_response_time', $response_time);
        
        return $events;
    }
    
    /**
     * Fetch from Registrations API
     */
    private function fetch_registration_events($app_id, $secret, $cache_duration, $skip_global_tag_filter = false) {
        // Use the signups endpoint with unarchived filter and include categories
        $url = $this->registrations_api_url . '/signups?filter=unarchived&per_page=100&include=categories';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header($app_id, $secret),
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30, // v7.3.0: Increased timeout for reliability
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        // If 404, provide helpful debugging info
        if ($status_code === 404) {
            $debug_msg = "Planning Center Registrations API returned 404.\n\n";
            $debug_msg .= "URL attempted: " . $url . "\n\n";
            $debug_msg .= "Try switching to 'Calendar' in the API Module setting.";
            
            return new WP_Error('api_404', $debug_msg);
        }
        
        if ($status_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            return new WP_Error('api_error', 'Planning Center API returned status code: ' . $status_code . "\n\nResponse: " . substr($body, 0, 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Failed to parse API response');
        }
        
        // Store raw API response for debugging — only when debug mode is on (v1.9.22)
        if (current_user_can('manage_options') && get_option('pc_debug_mode', 0)) {
            set_transient('pc_raw_api_response', $data, 300);
        }
        
        // Process events
        $events = $this->process_registration_events($data);
        
        // Now fetch actual event dates and tags from Calendar API, plus next_signup_time
        if (!empty($events)) {
            $event_names = array_column($events, 'name');
            $calendar_data = $this->fetch_calendar_event_data($event_names, $app_id, $secret);
            
            // Add debug info about calendar matching
            $debug_info = get_transient('pc_events_debug') ?: array();
            $debug_info[] = "";
            $debug_info[] = "=== CALENDAR DATA & NEXT SIGNUP TIME MATCHING ===";
            $debug_info[] = "Attempted to match " . count($events) . " events with Calendar API";
            $debug_info[] = "Found " . count($calendar_data) . " matching calendar events";
            
            // Fetch next_signup_time for each event
            foreach ($events as &$event) {
                $signup_id = $event['id'];
                
                // Try to get next_signup_time first (highest priority)
                $next_signup_time = $this->fetch_next_signup_time($signup_id, $app_id, $secret);
                
                if ($next_signup_time) {
                    $event['starts_at'] = $next_signup_time['starts_at'];
                    $event['ends_at'] = $next_signup_time['ends_at'];
                    $event['date_source'] = 'next_signup_time';
                    $debug_info[] = "  🎯 " . $event['name'] . ": Using next_signup_time - " . $event['starts_at'];
                } elseif (isset($calendar_data[$event['name']])) {
                    // Fall back to calendar dates
                    $event['starts_at'] = $calendar_data[$event['name']]['starts_at'];
                    $event['ends_at'] = $calendar_data[$event['name']]['ends_at'];
                    if (!empty($calendar_data[$event['name']]['location'])) {
                        $event['location'] = $calendar_data[$event['name']]['location'];
                    }
                    if (!empty($calendar_data[$event['name']]['tags'])) {
                        $event['tags'] = $calendar_data[$event['name']]['tags'];
                    }
                    $event['date_source'] = 'calendar';
                    $debug_info[] = "  ✅ " . $event['name'] . ": Using calendar date - " . $event['starts_at'];
                } else {
                    // No date source available - don't show registration open/close dates as event dates
                    $event['starts_at'] = ''; // Clear registration date
                    $event['ends_at'] = '';   // Clear registration date
                    $event['date_source'] = 'none';
                    // Keep existing tags from Registrations (don't overwrite with empty array)
                    if (!isset($event['tags'])) {
                        $event['tags'] = array();
                    }
                    $debug_info[] = "  ⚠️ " . $event['name'] . ": No date available (next_signup_time failed, no calendar match)";
                }
                
                // Merge Calendar tags with existing Registration categories (no duplicates, case-insensitive)
                if (isset($calendar_data[$event['name']]['tags']) && !empty($calendar_data[$event['name']]['tags'])) {
                    $existing_tags = $event['tags'] ?? array();
                    $calendar_tags = $calendar_data[$event['name']]['tags'];
                    
                    // Merge tags, removing case-insensitive duplicates while preserving original case
                    $merged_tags = $existing_tags;
                    $lowercase_existing = array_map('strtolower', $existing_tags);
                    
                    foreach ($calendar_tags as $cal_tag) {
                        if (!in_array(strtolower($cal_tag), $lowercase_existing)) {
                            $merged_tags[] = $cal_tag;
                        }
                    }
                    
                    $event['tags'] = $merged_tags;
                } elseif (!isset($event['tags'])) {
                    $event['tags'] = array();
                }
            }
            
            if (current_user_can('manage_options')) {
                set_transient('pc_events_debug', $debug_info, 300);
            }
            
            // NOW filter by required tag (after tags have been added from Calendar API)
            // Skip global filtering if shortcode specified its own tag filter
            if (!$skip_global_tag_filter) {
                $required_category = trim(get_option('pc_required_category', ''));
                if (!empty($required_category)) {
                    // Log for debugging
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('PC Events: Filtering by required tag: ' . $required_category);
                        error_log('PC Events: Events before tag filter: ' . count($events));
                        foreach ($events as $event) {
                            $event_tags = $event['tags'] ?? array();
                            error_log('  - ' . $event['name'] . ' has tags: ' . (empty($event_tags) ? 'NONE' : implode(', ', $event_tags)));
                        }
                    }
                    
                    $events = array_filter($events, function($event) use ($required_category) {
                        $event_tags = $event['tags'] ?? array();
                        // Case-insensitive comparison
                        foreach ($event_tags as $tag) {
                            if (strcasecmp($tag, $required_category) === 0) {
                                return true;
                            }
                        }
                        return false;
                    });
                    // Re-index array after filtering
                    $events = array_values($events);
                    
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('PC Events: Events after tag filter: ' . count($events));
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('PC Events: Skipping global tag filter (shortcode override)');
                }
            }
        }
        
        // NOW enhance descriptions with AI (after calendar matching is complete)
        if (!empty($events)) {
            foreach ($events as &$event) {
                $event['description'] = $this->enhance_description_with_ai(
                    $event['description'],
                    $event['name'],
                    $event['id'] // Pass event ID for database storage
                );
            }
        }
        
        // Cache the results
        $this->set_to_cache('pc_events_data', $events, $cache_duration); // v7.3.0: Object cache support
        
        return $events;
    }
    
    /**
     * Fetch from Calendar API
     */
    private function fetch_calendar_events($app_id, $secret, $cache_duration, $skip_global_tag_filter = false) {
        $url = $this->calendar_api_url . '/event_instances?filter=future&include=event,tags&order=starts_at&per_page=50';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header($app_id, $secret),
                'Content-Type' => 'application/json',
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 404) {
            $debug_msg = "Planning Center Calendar API returned 404. This could mean:\n\n";
            $debug_msg .= "1. Your Planning Center account doesn't have the Calendar module\n";
            $debug_msg .= "2. The Calendar module isn't enabled for API access\n\n";
            $debug_msg .= "Try switching to 'Registrations' in the API Module setting.";
            
            return new WP_Error('api_404', $debug_msg);
        }
        
        if ($status_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            return new WP_Error('api_error', 'Planning Center API returned status code: ' . $status_code . "\n\nResponse: " . substr($body, 0, 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Failed to parse API response');
        }
        
        // Process events
        $events = $this->process_calendar_events($data);
        
        // Enhance descriptions with AI if enabled
        if (!empty($events)) {
            foreach ($events as &$event) {
                $event['description'] = $this->enhance_description_with_ai(
                    $event['description'],
                    $event['name'],
                    $event['id'] // Pass event ID for database storage
                );
            }
        }
        
        // Cache the results
        $this->set_to_cache('pc_events_data', $events, $cache_duration); // v7.3.0: Object cache support
        
        return $events;
    }
    
    /**
     * Fetch matching Calendar events to get actual event dates and tags
     */
    private function fetch_calendar_event_data($event_names, $app_id, $secret) {
        if (empty($event_names)) {
            return array();
        }
        
        // Fetch from Calendar API with tags included
        $url = $this->calendar_api_url . '/event_instances?filter=future&per_page=100&include=event,tags';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header($app_id, $secret),
                'Content-Type' => 'application/json',
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array();
        }
        
        // Build lookup of event names to dates and tags
        $event_lookup = array();
        $included_events = array();
        $included_tags = array();
        
        // First, get all Event objects and Tags from included
        if (isset($data['included'])) {
            foreach ($data['included'] as $included) {
                if ($included['type'] === 'Event') {
                    $included_events[$included['id']] = $included;
                } elseif ($included['type'] === 'Tag') {
                    $included_tags[$included['id']] = $included['attributes']['name'] ?? '';
                }
            }
        }
        
        // Now match event instances to names
        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $instance) {
                $event_id = $instance['relationships']['event']['data']['id'] ?? null;
                if ($event_id && isset($included_events[$event_id])) {
                    $event_obj = $included_events[$event_id];
                    $event_name = $event_obj['attributes']['name'] ?? '';
                    
                    // Extract tags for this event
                    $event_tags = array();
                    if (isset($event_obj['relationships']['tags']['data']) && is_array($event_obj['relationships']['tags']['data'])) {
                        foreach ($event_obj['relationships']['tags']['data'] as $tag_ref) {
                            $tag_id = $tag_ref['id'] ?? null;
                            if ($tag_id && isset($included_tags[$tag_id])) {
                                $event_tags[] = $included_tags[$tag_id];
                            }
                        }
                    }
                    
                    // Check if this event name matches any of our registration events
                    foreach ($event_names as $reg_event_name) {
                        // Case-insensitive partial match
                        if (stripos($event_name, $reg_event_name) !== false || 
                            stripos($reg_event_name, $event_name) !== false) {
                            
                            // Store the earliest instance for this event if not already stored
                            if (!isset($event_lookup[$reg_event_name])) {
                                $event_lookup[$reg_event_name] = array(
                                    'starts_at' => $instance['attributes']['starts_at'] ?? '',
                                    'ends_at' => $instance['attributes']['ends_at'] ?? '',
                                    'location' => $instance['attributes']['location'] ?? '',
                                    'tags' => $event_tags,
                                );
                            }
                        }
                    }
                }
            }
        }
        
        return $event_lookup;
    }
    
    /**
     * Fetch next_signup_time for a specific signup
     * Returns the most accurate event date from the Registrations API
     */
    private function fetch_next_signup_time($signup_id, $app_id, $secret) {
        if (empty($signup_id)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PC Events: next_signup_time - No signup_id provided');
            }
            return null;
        }

        // v1.9.22: Cache each signup's next_signup_time individually.
        // Previously this was called in a loop with no caching, causing one API
        // request per event on every cache-miss cycle (N+1 problem).
        $cache_key = 'pc_nst_' . md5( (string) $signup_id );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached; // null is a valid cached value meaning "no signup time available"
        }

        $url = $this->registrations_api_url . '/signups/' . $signup_id . '/next_signup_time';

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('PC Events: Fetching next_signup_time for signup ' . $signup_id);
            error_log('PC Events: URL: ' . $url);
        }

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header($app_id, $secret),
                'Content-Type' => 'application/json',
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PC Events: next_signup_time API error: ' . $response->get_error_message());
            }
            // Do not cache WP_Errors — allow a retry on the next cycle
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PC Events: next_signup_time returned status ' . $status_code . ' for signup ' . $signup_id);
                $body = wp_remote_retrieve_body($response);
                error_log('PC Events: Response body: ' . substr($body, 0, 500));
            }
            // Cache the null so we don't hammer the API for events that genuinely
            // have no next signup time. Use a shorter TTL (1 hour) than the main cache.
            set_transient( $cache_key, null, HOUR_IN_SECONDS );
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PC Events: next_signup_time JSON decode error: ' . json_last_error_msg());
            }
            return null;
        }

        if (isset($data['data']['attributes'])) {
            $attrs = $data['data']['attributes'];
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PC Events: next_signup_time SUCCESS for signup ' . $signup_id . ' - starts_at: ' . ($attrs['starts_at'] ?? 'empty'));
            }
            $result = array(
                'starts_at' => $attrs['starts_at'] ?? '',
                'ends_at'   => $attrs['ends_at'] ?? '',
            );
            set_transient( $cache_key, $result, HOUR_IN_SECONDS );
            return $result;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('PC Events: next_signup_time - No data/attributes in response for signup ' . $signup_id);
        }
        set_transient( $cache_key, null, HOUR_IN_SECONDS );
        return null;
    }
    
    /**
     * Process events from Registrations API (signups endpoint)
     */
    private function process_registration_events($data) {
        $events = array();
        $debug_info = array();
        
        $debug_info[] = "=== DEBUGGING INFO (Registrations API - Signups) ===";
        
        // Build lookup of Category IDs to names from included data
        $category_lookup = array();
        if (isset($data['included']) && is_array($data['included'])) {
            foreach ($data['included'] as $included) {
                if (isset($included['type']) && $included['type'] === 'Category') {
                    $category_id = $included['id'] ?? null;
                    $category_name = $included['attributes']['name'] ?? '';
                    if ($category_id && $category_name) {
                        $category_lookup[$category_id] = $category_name;
                    }
                }
            }
        }
        
        $debug_info[] = "Found " . count($category_lookup) . " categories in API response";
        if (!empty($category_lookup)) {
            $debug_info[] = "Available categories: " . implode(', ', $category_lookup);
        }
        $debug_info[] = "";
        
        // Get filter settings
        $public_only = get_option('pc_public_only', 1);
        $required_category = trim(get_option('pc_required_category', ''));
        $excluded_events_raw = get_option('pc_excluded_events', '');
        $excluded_events = array_filter(array_map('trim', explode("\n", $excluded_events_raw)));
        
        $debug_info[] = "Public Only Filter: " . ($public_only ? 'YES' : 'NO');
        $debug_info[] = "Required Category: " . ($required_category ? "'{$required_category}'" : 'NONE');
        $debug_info[] = "";
        
        // Process Signup objects directly from data array
        if (isset($data['data']) && is_array($data['data'])) {
            $debug_info[] = "Found " . count($data['data']) . " Signup objects";
            $debug_info[] = "";
            
            foreach ($data['data'] as $signup) {
                // Signups are the events! Extract data directly
                $event_name = $signup['attributes']['name'] ?? 'Unnamed Event';
                $archived = $signup['attributes']['archived'] ?? false;
                
                $debug_info[] = "--- Event: {$event_name} ---";
                
                // Check if archived
                if ($archived) {
                    $debug_info[] = "  ❌ FILTERED: Event is archived";
                    continue;
                }
                
                // Check exclusion list
                if ($this->is_event_excluded($event_name, $excluded_events)) {
                    $debug_info[] = "  ❌ FILTERED: In exclusion list";
                    continue;
                }
                
                // Extract dates
                $open_at = $signup['attributes']['open_at'] ?? '';
                $close_at = $signup['attributes']['close_at'] ?? '';
                
                $debug_info[] = "  - Opens at: " . ($open_at ?: 'Not set');
                $debug_info[] = "  - Closes at: " . ($close_at ?: 'Not set');
                
                // FIXED: Only filter if close_at is in the past
                // If there's no close_at date, the event is still open
                if (!empty($close_at)) {
                    try {
                        $close_date = new DateTime($close_at);
                        $now = new DateTime('now', new DateTimeZone('UTC'));
                        
                        if ($close_date <= $now) {
                            $debug_info[] = "  ❌ FILTERED: Registration has closed";
                            continue;
                        }
                        $debug_info[] = "  ✓ Registration still open (closes in future)";
                    } catch (Exception $e) {
                        $debug_info[] = "  ⚠️ WARNING: Invalid close_at date format";
                    }
                } else {
                    $debug_info[] = "  ✓ No close date - registration remains open";
                }
                
                // Extract all the data
                $event_data = array(
                    'id' => $signup['id'] ?? '',
                    'name' => $event_name,
                    'description' => $signup['attributes']['description'] ?? '',
                    'image_url' => $signup['attributes']['logo_url'] ?? '',
                    'registration_url' => $signup['attributes']['new_registration_url'] ?? '',
                    'starts_at' => '', // Will be set from next_signup_time or Calendar API
                    'ends_at' => '',   // Will be set from next_signup_time or Calendar API
                    'location' => '',
                    'tags' => array(), // Will be populated from categories
                );
                
                // Extract categories from relationships
                if (isset($signup['relationships']['categories']['data']) && is_array($signup['relationships']['categories']['data'])) {
                    foreach ($signup['relationships']['categories']['data'] as $category_ref) {
                        $category_id = $category_ref['id'] ?? null;
                        if ($category_id && isset($category_lookup[$category_id])) {
                            $event_data['tags'][] = $category_lookup[$category_id];
                        }
                    }
                }
                
                $debug_info[] = "  - Has description: " . (!empty($event_data['description']) ? 'YES' : 'NO');
                $debug_info[] = "  - Has image: " . (!empty($event_data['image_url']) ? 'YES (' . $event_data['image_url'] . ')' : 'NO');
                $debug_info[] = "  - Has registration URL: " . (!empty($event_data['registration_url']) ? 'YES' : 'NO');
                $debug_info[] = "  - Registration opens: " . ($open_at ?: 'Not set');
                $debug_info[] = "  - Registration closes: " . ($close_at ?: 'Not set');
                $debug_info[] = "  - Categories/Tags: " . (!empty($event_data['tags']) ? implode(', ', $event_data['tags']) : 'NONE');
                $debug_info[] = "  ℹ️ NOTE: Event dates will be fetched from next_signup_time API (or Calendar if no next_signup_time)";
                $debug_info[] = "  ✅ PASSED ALL FILTERS";
                
                // v1.0.3: Enhanced debug logging
                $this->debug_log('Processing event: ' . $event_name, array(
                    'id' => $event_data['id'],
                    'has_image' => !empty($event_data['image_url']),
                    'image_url' => $event_data['image_url'],
                    'has_reg_url' => !empty($event_data['registration_url']),
                    'registration_url' => $event_data['registration_url'],
                    'all_attributes' => array_keys($signup['attributes'] ?? [])
                ));
                
                // Don't enhance with AI yet - we'll do it after calendar matching
                
                $events[] = $event_data;
            }
        } else {
            $debug_info[] = "⚠️ ERROR: No 'data' array found in API response";
        }
        
        // Sort by start date (open_at)
        usort($events, function($a, $b) {
            return strcmp($a['starts_at'], $b['starts_at']);
        });
        
        $debug_info[] = "";
        $debug_info[] = "Total events after filtering: " . count($events);
        
        // Store debug info for admin view
        if (current_user_can('manage_options')) {
            set_transient('pc_events_debug', $debug_info, 300);
        }
        
        return $events;
    }
    
    /**
     * Process events from Calendar API
     */
    private function process_calendar_events($data) {
        $events = array();
        $included_events = array();
        $included_tags = array();
        $debug_info = array();
        
        // Build a lookup for included event data and tags
        if (isset($data['included'])) {
            foreach ($data['included'] as $included) {
                if ($included['type'] === 'Event') {
                    $included_events[$included['id']] = $included;
                } elseif ($included['type'] === 'Tag') {
                    $included_tags[$included['id']] = $included['attributes']['name'] ?? '';
                }
            }
        }
        
        // Get filter settings
        $public_only = get_option('pc_public_only', 1);
        // Only apply global tag filter if not skipped by shortcode
        $required_category = '';
        if (!$skip_global_tag_filter) {
            $required_category = trim(get_option('pc_required_category', ''));
        }
        $excluded_events_raw = get_option('pc_excluded_events', '');
        $excluded_events = array_filter(array_map('trim', explode("\n", $excluded_events_raw)));
        
        $debug_info[] = "=== DEBUGGING INFO (Calendar API) ===";
        $debug_info[] = "Public Only Filter: " . ($public_only ? 'YES' : 'NO');
        $debug_info[] = "Required Tag/Category: " . ($required_category ? "'{$required_category}'" : ($skip_global_tag_filter ? 'SKIPPED (shortcode override)' : 'NONE'));
        $debug_info[] = "Available Tags: " . (!empty($included_tags) ? implode(', ', $included_tags) : 'NONE FOUND');
        $debug_info[] = "Total Event Instances Found: " . (isset($data['data']) ? count($data['data']) : 0);
        $debug_info[] = "";
        
        // Process event instances
        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $instance) {
                $event_id = $instance['relationships']['event']['data']['id'] ?? null;
                
                if ($event_id && isset($included_events[$event_id])) {
                    $event = $included_events[$event_id];
                    $event_name = $event['attributes']['name'] ?? '';
                    
                    $debug_info[] = "--- Event: {$event_name} ---";
                    
                    // Filter 1: Check if event is in exclusion list
                    if ($this->is_event_excluded($event_name, $excluded_events)) {
                        $debug_info[] = "  ❌ EXCLUDED: In exclusion list";
                        continue;
                    }
                    
                    // Filter 2: Check visibility (public only)
                    $visibility = $event['attributes']['visible_in_church_center'] ?? false;
                    $debug_info[] = "  Visibility: " . ($visibility ? 'PUBLIC' : 'PRIVATE');
                    
                    if ($public_only && !$visibility) {
                        $debug_info[] = "  ❌ FILTERED: Not public";
                        continue;
                    }
                    
                    // Filter 3: Check required tag/category if set
                    if (!empty($required_category)) {
                        $event_tags = $this->get_event_tags($event, $instance, $included_tags);
                        $debug_info[] = "  Tags: " . (!empty($event_tags) ? implode(', ', $event_tags) : 'NONE');
                        
                        // Case-insensitive tag matching
                        $tag_found = false;
                        foreach ($event_tags as $tag) {
                            if (strcasecmp($tag, $required_category) === 0) {
                                $tag_found = true;
                                break;
                            }
                        }
                        
                        if (!$tag_found) {
                            $debug_info[] = "  ❌ FILTERED: Doesn't have required tag '{$required_category}'";
                            continue;
                        }
                    }
                    
                    $debug_info[] = "  ✅ PASSED ALL FILTERS";
                    
                    // Get event tags for storage
                    $event_tags = $this->get_event_tags($event, $instance, $included_tags);
                    
                    // Event passed all filters - add to results
                    $original_description = $event['attributes']['summary'] ?? $event['attributes']['description'] ?? '';
                    
                    // Enhance description with AI if enabled
                    $enhanced_description = $this->enhance_description_with_ai(
                        $original_description,
                        $event_name,
                        $instance['id'] // Pass event ID for database storage
                    );
                    
                    $events[] = array(
                        'id' => $instance['id'],
                        'name' => $event_name,
                        'description' => $enhanced_description,
                        'original_description' => $original_description,
                        'starts_at' => $instance['attributes']['starts_at'] ?? '',
                        'ends_at' => $instance['attributes']['ends_at'] ?? '',
                        'image_url' => $event['attributes']['image_url'] ?? '',
                        'location' => $instance['attributes']['location'] ?? '',
                        'registration_url' => $event['attributes']['registration_url'] ?? '',
                        'tags' => $event_tags, // Add tags for filtering
                    );
                }
            }
        }
        
        // Store debug info for admin view
        if (current_user_can('manage_options')) {
            set_transient('pc_events_debug', $debug_info, 300); // 5 minutes
        }
        
        return $events;
    }
    
    /**
     * Get tags for an event (Calendar API)
     */
    private function get_event_tags($event, $instance, $included_tags) {
        $tags = array();
        
        // Check if event has tag relationships
        if (isset($event['relationships']['tags']['data']) && is_array($event['relationships']['tags']['data'])) {
            foreach ($event['relationships']['tags']['data'] as $tag_ref) {
                $tag_id = $tag_ref['id'] ?? null;
                if ($tag_id && isset($included_tags[$tag_id])) {
                    $tags[] = $included_tags[$tag_id];
                }
            }
        }
        
        return $tags;
    }
    
    /**
     * Check if event is excluded
     */
    private function is_event_excluded($event_name, $excluded_list) {
        if (empty($excluded_list) || empty($event_name)) {
            return false;
        }
        
        foreach ($excluded_list as $excluded_name) {
            if (trim($excluded_name) === trim($event_name)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Enhance description using AI
     */
    /**
     * Get stored AI description from database
     */
    private function get_stored_description($event_id) {
        global $wpdb;
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE event_id = %s AND status = 'approved'",
            $event_id
        ));
        
        return $result;
    }
    
    /**
     * PERFORMANCE: Batch get multiple stored descriptions in one query
     */
    private function batch_get_stored_descriptions($event_ids) {
        global $wpdb;
        
        if (empty($event_ids)) {
            return array();
        }
        
        // Create placeholders for prepared statement
        $placeholders = implode(',', array_fill(0, count($event_ids), '%s'));
        
        $query = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE event_id IN ($placeholders) AND status = 'approved'",
            $event_ids
        );
        
        $results = $wpdb->get_results($query);
        
        // Index by event_id for quick lookup
        $indexed = array();
        foreach ($results as $row) {
            $indexed[$row->event_id] = $row;
        }
        
        return $indexed;
    }
    
    /**
     * Store AI description in database
     */
    private function store_description($event_id, $event_name, $original_desc, $ai_desc) {
        global $wpdb;
        
        // Determine status based on approval setting
        $require_approval = get_option('pc_ai_require_approval', 0);
        $status = $require_approval ? 'pending' : 'approved';
        
        $data = array(
            'event_id' => $event_id,
            'event_name' => $event_name,
            'original_description' => $original_desc,
            'ai_description' => $ai_desc,
            'status' => $status,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );
        
        // Try to update first
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE event_id = %s",
            $event_id
        ));
        
        if ($exists) {
            // Update existing
            $result = $wpdb->update(
                $this->table_name,
                $data,
                array('event_id' => $event_id),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s'),
                array('%s')
            );
        } else {
            // Insert new
            $result = $wpdb->insert(
                $this->table_name,
                $data,
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            
            // Send email notification if pending and notifications enabled
            if ($status === 'pending' && get_option('pc_ai_send_notifications', 1)) {
                $this->send_approval_notification($event_id, $event_name, $original_desc, $ai_desc);
            }
        }
        
        return $result;
    }
    
    /**
     * Delete stored description (for regeneration)
     */
    private function delete_stored_description($event_id) {
        global $wpdb;
        
        return $wpdb->delete(
            $this->table_name,
            array('event_id' => $event_id),
            array('%s')
        );
    }
    
    /**
     * Get description statistics
     * v1.0.3: PERFORMANCE - Add caching to reduce database queries
     */
    private function get_description_stats() {
        // Try to get from cache first
        $cache_key = 'pc_description_stats';
        $cached_stats = wp_cache_get($cache_key, 'planning_center');
        
        if ($cached_stats !== false) {
            return $cached_stats;
        }
        
        global $wpdb;
        
        // v1.0.3: Security fix - use prepare() for all queries
        $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE 1=%d", 1));
        $approved = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s", 'approved'));
        $pending = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s", 'pending'));
        
        $stats = array(
            'total' => intval($total),
            'approved' => intval($approved),
            'pending' => intval($pending),
        );
        
        // Cache for 5 minutes
        wp_cache_set($cache_key, $stats, 'planning_center', 300);
        
        return $stats;
    }
    
    /**
     * Send email notification for pending approval
     */
    private function send_approval_notification($event_id, $event_name, $original_desc, $ai_desc) {
        $to = get_option('pc_ai_approval_email');
        
        if (empty($to)) {
            $to = get_option('admin_email'); // Fallback to WordPress admin email
        }
        
        $church_name = get_option('pc_church_name', get_bloginfo('name'));
        $subject = "[{$church_name}] New Event Description Needs Approval";
        
        $approval_url = admin_url('tools.php?page=pc-ai-queue');
        
        $message = "Hi Admin,\n\n";
        $message .= "A new AI-enhanced event description needs your review:\n\n";
        $message .= "Event: {$event_name}\n";
        $message .= "Event ID: {$event_id}\n\n";
        $message .= "---\n\n";
        $message .= "ORIGINAL DESCRIPTION:\n";
        $message .= wp_strip_all_tags($original_desc) . "\n\n";
        $message .= "---\n\n";
        $message .= "AI-ENHANCED DESCRIPTION:\n";
        $message .= wp_strip_all_tags($ai_desc) . "\n\n";
        $message .= "---\n\n";
        $message .= "Click here to review and approve:\n";
        $message .= $approval_url . "\n\n";
        $message .= "---\n";
        $message .= "Planning Center Events Plugin\n";
        $message .= "Sent: " . current_time('mysql');
        
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        
        wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Approve a description
     */
    public function approve_description() {
        check_admin_referer('pc_approve_description');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $event_id = isset($_GET['event_id']) ? sanitize_text_field($_GET['event_id']) : '';
        
        if (empty($event_id)) {
            wp_die('Invalid event ID');
        }
        
        global $wpdb;
        $wpdb->update(
            $this->table_name,
            array('status' => 'approved', 'updated_at' => current_time('mysql')),
            array('event_id' => $event_id),
            array('%s', '%s'),
            array('%s')
        );
        
        // Clear events cache so approved description shows up
        delete_transient('pc_events_data');
        
        // v1.0.3: PERFORMANCE - Clear stats cache
        wp_cache_delete('pc_description_stats', 'planning_center');
        
        wp_redirect(add_query_arg(array(
            'page' => 'pc-ai-queue',
            'approved' => 1
        ), admin_url('tools.php')));
        exit;
    }
    
    /**
     * Reject a description
     */
    public function reject_description() {
        check_admin_referer('pc_reject_description');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $event_id = isset($_GET['event_id']) ? sanitize_text_field($_GET['event_id']) : '';
        
        if (empty($event_id)) {
            wp_die('Invalid event ID');
        }
        
        global $wpdb;
        $wpdb->update(
            $this->table_name,
            array('status' => 'rejected', 'updated_at' => current_time('mysql')),
            array('event_id' => $event_id),
            array('%s', '%s'),
            array('%s')
        );
        
        // v1.0.3: PERFORMANCE - Clear stats cache
        wp_cache_delete('pc_description_stats', 'planning_center');
        
        wp_redirect(add_query_arg(array(
            'page' => 'pc-ai-queue',
            'rejected' => 1
        ), admin_url('tools.php')));
        exit;
    }
    
    /**
     * Bulk approve descriptions
     */
    public function bulk_approve_descriptions() {
        check_admin_referer('pc_bulk_approve');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $event_ids = isset($_POST['event_ids']) ? (array) $_POST['event_ids'] : array();
        
        if (empty($event_ids)) {
            wp_redirect(admin_url('tools.php?page=pc-ai-queue'));
            exit;
        }
        
        global $wpdb;
        foreach ($event_ids as $event_id) {
            $wpdb->update(
                $this->table_name,
                array('status' => 'approved', 'updated_at' => current_time('mysql')),
                array('event_id' => sanitize_text_field($event_id)),
                array('%s', '%s'),
                array('%s')
            );
        }
        
        // Clear events cache
        delete_transient('pc_events_data');
        
        // v1.0.3: PERFORMANCE - Clear stats cache
        wp_cache_delete('pc_description_stats', 'planning_center');
        
        wp_redirect(add_query_arg(array(
            'page' => 'pc-ai-queue',
            'bulk_approved' => count($event_ids)
        ), admin_url('tools.php')));
        exit;
    }
    
    /**
     * Enhance description with AI (with permanent database storage)
     */
    private function enhance_description_with_ai($description, $event_name, $event_id = null) {
        $debug_info = array();
        $debug_info[] = "=== AI ENHANCEMENT DEBUG for: {$event_name} ===";
        
        // Check if AI is enabled
        $ai_enabled = get_option('pc_ai_enabled');
        $debug_info[] = "AI Enabled: " . ($ai_enabled ? 'YES' : 'NO');
        
        if (!$ai_enabled) {
            if (current_user_can('manage_options') && get_option('pc_debug_mode')) {
                $this->append_ai_debug($debug_info);
            }
            return $description;
        }
        
        // Check for API key
        $api_key = get_option('pc_ai_api_key');
        $has_key = !empty($api_key);
        $debug_info[] = "API Key Present: " . ($has_key ? 'YES' : 'NO');
        
        if (!$has_key) {
            if (current_user_can('manage_options') && get_option('pc_debug_mode')) {
                $this->append_ai_debug($debug_info);
            }
            return $description;
        }
        
        // Skip if description is too short or empty
        $desc_length = strlen($description);
        $debug_info[] = "Description Length: {$desc_length} chars";
        
        if (empty($description) || $desc_length < 20) {
            $debug_info[] = "❌ SKIPPED: Description too short (need 20+ chars)";
            if (current_user_can('manage_options') && get_option('pc_debug_mode')) {
                $this->append_ai_debug($debug_info);
            }
            return $description;
        }
        
        // NEW: Check database for existing AI description
        if ($event_id) {
            $stored = $this->get_stored_description($event_id);
            $debug_info[] = "Database Check: " . ($stored ? 'FOUND' : 'NOT FOUND');
            
            if ($stored && !empty($stored->ai_description)) {
                $debug_info[] = "✅ USING STORED DATABASE VERSION (created: {$stored->created_at})";
                if (current_user_can('manage_options') && get_option('pc_debug_mode')) {
                    $this->append_ai_debug($debug_info);
                }
                return $stored->ai_description;
            }
        }
        
        // v7.3.0: Check rate limit before making API call
        if (!$this->check_ai_rate_limit()) {
            $debug_info[] = "❌ RATE LIMIT EXCEEDED: Max calls per day reached";
            if (current_user_can('manage_options') && get_option('pc_debug_mode')) {
                $this->append_ai_debug($debug_info);
            }
            return $description; // Return original if rate limited
        }
        
        $service = get_option('pc_ai_service', 'claude');
        $debug_info[] = "AI Service: {$service}";
        $debug_info[] = "🔄 CALLING AI API...";
        
        if ($service === 'claude') {
            $enhanced = $this->enhance_with_claude($description, $event_name, $this->get_api_key()); // v7.3.0: Decrypt key
        } else {
            $enhanced = $this->enhance_with_openai($description, $event_name, $this->get_api_key()); // v7.3.0: Decrypt key
        }
        
        // If enhancement failed, return original
        if (is_wp_error($enhanced)) {
            $debug_info[] = "❌ AI ERROR: " . $enhanced->get_error_message();
            if (current_user_can('manage_options') && get_option('pc_debug_mode')) {
                $this->append_ai_debug($debug_info);
            }
            return $description;
        }
        
        // v7.3.0: Increment rate counter on successful call
        $this->increment_ai_rate_counter();
        
        $debug_info[] = "✅ AI ENHANCEMENT SUCCESSFUL";
        $debug_info[] = "Original length: {$desc_length} chars";
        $debug_info[] = "Enhanced length: " . strlen($enhanced) . " chars";
        
        // NEW: Store in database permanently
        if ($event_id) {
            $stored = $this->store_description($event_id, $event_name, $description, $enhanced);
            $debug_info[] = $stored ? "💾 STORED IN DATABASE" : "⚠️ DATABASE STORAGE FAILED";
        }
        
        if (current_user_can('manage_options') && get_option('pc_debug_mode')) {
            $this->append_ai_debug($debug_info);
        }
        
        return $enhanced;
    }
    
    /**
     * Append AI debug info to the main debug output
     */
    private function append_ai_debug($debug_info) {
        $existing = get_transient('pc_events_debug') ?: array();
        $existing[] = "";
        $existing = array_merge($existing, $debug_info);
        set_transient('pc_events_debug', $existing, 300);
    }
    
    /**
     * Enhance with Claude API
     */
    private function enhance_with_claude($description, $event_name, $api_key) {
        $prompt = get_option('pc_ai_prompt', $this->get_default_prompt());
        
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => 'claude-3-haiku-20240307', // Fast, cheap, always available
                'max_tokens' => 300, // Shorter descriptions (~500 characters)
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt . "\n\nEvent Name: " . $event_name . "\n\nOriginal Description:\n" . $description
                    )
                )
            )),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            $error_detail = '';
            if (isset($body['error'])) {
                $error_detail = ' - ' . ($body['error']['message'] ?? $body['error']['type'] ?? 'Unknown error');
            }
            return new WP_Error('claude_error', 'Claude API returned status: ' . $status_code . $error_detail);
        }
        
        if (isset($body['content'][0]['text'])) {
            $text = trim($body['content'][0]['text']);
            
            // Strip common AI preambles that might slip through
            $preambles = array(
                '/^Here is the rewritten event description.*?:\s*/is',
                '/^Here\'s the rewritten.*?:\s*/is',
                '/^Rewritten event description.*?:\s*/is',
                '/^Here is the enhanced.*?:\s*/is',
                '/^Here\'s the enhanced.*?:\s*/is',
            );
            
            foreach ($preambles as $pattern) {
                $text = preg_replace($pattern, '', $text);
            }
            
            // v1.0.3: Remove any emojis that slip through
            $text = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $text);
            
            // v1.0.3: Hard cap at 200 characters to ensure uniformity
            if (strlen($text) > 200) {
                $text = substr($text, 0, 197) . '...';
            }
            
            // v1.0.3: Convert markdown bold to HTML bold for display
            $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
            
            return trim($text);
        }
        
        return new WP_Error('claude_parse_error', 'Could not parse Claude response');
    }
    
    /**
     * Enhance with OpenAI API
     */
    private function enhance_with_openai($description, $event_name, $api_key) {
        $prompt = get_option('pc_ai_prompt', $this->get_default_prompt());
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt . "\n\nEvent Name: " . $event_name . "\n\nOriginal Description:\n" . $description
                    )
                ),
                'max_tokens' => 300, // Shorter descriptions (~500 characters)
                'temperature' => 0.7,
            )),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('openai_error', 'OpenAI API returned status: ' . $status_code);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['choices'][0]['message']['content'])) {
            $text = trim($body['choices'][0]['message']['content']);
            
            // v1.0.3: Remove any emojis that slip through
            $text = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $text);
            
            // v1.0.3: Hard cap at 200 characters to ensure uniformity
            if (strlen($text) > 200) {
                $text = substr($text, 0, 197) . '...';
            }
            
            // v1.0.3: Convert markdown bold to HTML bold for display
            $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
            
            return trim($text);
        }
        
        return new WP_Error('openai_parse_error', 'Could not parse OpenAI response');
    }
    
    /**
     * Get default AI prompt
     */
    private function get_default_prompt() {
        // Get church-specific information from settings
        $church_name = get_option('pc_church_name', 'Living Hope Church');
        $church_nickname = get_option('pc_church_nickname', '');
        $church_city = get_option('pc_church_city', 'Lehighton');
        $church_state = get_option('pc_church_state', 'PA');
        $target_audience = get_option('pc_target_audience', '');
        $denomination = get_option('pc_denomination', '');
        
        // Build church identity string
        $church_identity = $church_name;
        if (!empty($church_nickname)) {
            $church_identity .= " (also known as {$church_nickname})";
        }
        $church_location = "{$church_city}, {$church_state}";
        
        // Build audience context
        $audience_context = !empty($target_audience) ? 
            "\n- Target audience: {$target_audience}" : '';
        
        $denomination_context = !empty($denomination) ?
            "\n- Denomination: {$denomination}" : '';
        
        return "You are a professional copywriter for {$church_identity} in {$church_location}.{$audience_context}{$denomination_context}

CRITICAL CONSTRAINTS - DO NOT VIOLATE:
- ONLY use \"{$church_name}\" or \"{$church_nickname}\" when mentioning the church name
- ONLY use \"{$church_location}\" when mentioning location
- DO NOT invent or assume any addresses, building names, or location details not in the original
- DO NOT add information about specific rooms, facilities, or addresses unless they're in the original description
- If the original mentions a specific address or location, keep it EXACTLY as written
- ABSOLUTELY NO EMOJIS - do not use any emoji characters whatsoever
- MAXIMUM LENGTH: 150-180 characters total - be extremely concise

GOAL: Create a 3-second attention-grabber. People will only glance at this for 5-10 seconds total.

Rewrite this church event description to be:

TONE & STYLE:
- Friendly and inviting (like talking to a neighbor)
- Warm but NOT salesy or pushy
- Focus on the essential info someone needs to decide if this is for them
- Natural and conversational

STRUCTURE:
- 2 SHORT sentences maximum (separated by a blank line for breathing room)
- First sentence: What it is + who it's for OR what it is + when
- Second sentence: One key detail or simple invitation
- Total: 150-180 characters

WHAT TO INCLUDE (pick the most important):
- WHO it's for (youth, families, everyone, etc.)
- WHAT makes it special or unique
- WHEN (if it's time-sensitive like \"this Saturday\")
- ONE appealing detail (free meal, childcare, outdoor, etc.)

FORMATTING FOR READABILITY:
- Use **bold text** (markdown format) on 1-2 key phrases that catch attention
- Examples: **youth group**, **free dinner**, **all ages**, **this Saturday**
- Keep it punchy and scannable

DO NOT:
- Use ANY emoji characters
- Exceed 180 characters
- Be overly enthusiastic or salesy (avoid \"Amazing!\" \"Don't miss!\" \"You won't believe!\")
- Use religious jargon (assume they're new to church)
- Change dates, times, costs, or factual details
- Add information not in the original
- Invent addresses or specific locations
- Make assumptions about facilities

CRITICAL: Return ONLY the description text. No preambles or explanations.

Rewrite the following event description:";
    }
    
    /**
     * Display events shortcode
     */
    public function display_events_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => -1,
            'tag' => '', // New: filter by tag(s) - comma-separated
            'cards' => 0, // New: override cards_per_row (0 = use global setting)
            'mode' => '', // New: override display_mode ('grid' or 'carousel')
            'button_color' => '', // v1.8.5: override button color
            'button_text_color' => '', // v1.8.5: override button text color
            'card_bg_color' => '', // v1.8.5: override card background color
        ), $atts);
        
        // Skip global tag filter if shortcode specifies its own tag
        $skip_global_filter = !empty($atts['tag']);
        $events = $this->fetch_events($skip_global_filter);
        
        if (is_wp_error($events)) {
            if (current_user_can('manage_options')) {
                return '<div class="pc-events-error">Error: ' . esc_html($events->get_error_message()) . '</div>';
            }
            return '';
        }
        
        // Filter by tag if specified
        if (!empty($atts['tag'])) {
            $requested_tags = array_map('trim', explode(',', $atts['tag']));
            
            // Debug logging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PC Events Shortcode: Filtering by tag(s): ' . implode(', ', $requested_tags));
                error_log('PC Events Shortcode: Events before shortcode tag filter: ' . count($events));
            }
            
            $events = array_filter($events, function($event) use ($requested_tags) {
                if (empty($event['tags'])) {
                    return false;
                }
                // Check if event has ANY of the requested tags (OR logic)
                // Case-insensitive comparison
                foreach ($requested_tags as $requested_tag) {
                    foreach ($event['tags'] as $event_tag) {
                        if (strcasecmp($requested_tag, $event_tag) === 0) {
                            return true;
                        }
                    }
                }
                return false;
            });
            
            // Debug logging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PC Events Shortcode: Events after shortcode tag filter: ' . count($events));
                if (count($events) === 0) {
                    error_log('PC Events Shortcode: WARNING - No events matched the requested tags!');
                }
            }
        }
        
        // Show debug info for admins (only if debug mode is enabled)
        $debug_output = '';
        $debug_mode = get_option('pc_debug_mode', 0);
        if (current_user_can('manage_options') && $debug_mode) {
            $debug_info = get_transient('pc_events_debug');
            
            $debug_output = '<div class="pc-events-debug" style="background: #f0f0f0; padding: 20px; margin: 20px 0; border-left: 4px solid #007acc; font-family: monospace; white-space: pre-wrap; font-size: 12px;">';
            $debug_output .= '<strong style="font-size: 14px; display: block; margin-bottom: 10px;">🔍 DEBUG INFO (Only visible to admins)</strong>';
            
            // Show events data
            $debug_output .= "\n=== EVENTS DATA RECEIVED IN SHORTCODE ===\n";
            $debug_output .= "Total events: " . count($events) . "\n\n";
            
            foreach ($events as $idx => $event) {
                $debug_output .= "Event #" . ($idx + 1) . ": " . esc_html($event['name']) . "\n";
                $debug_output .= "  - Tags: " . (!empty($event['tags']) ? esc_html(implode(', ', $event['tags'])) : 'NONE') . "\n";
                $debug_output .= "  - Has image_url: " . (!empty($event['image_url']) ? 'YES' : 'NO') . "\n";
                if (!empty($event['image_url'])) {
                    $debug_output .= "  - Image URL: " . esc_html($event['image_url']) . "\n";
                }
                $debug_output .= "  - Has registration_url: " . (!empty($event['registration_url']) ? 'YES' : 'NO') . "\n";
                if (!empty($event['registration_url'])) {
                    $debug_output .= "  - Registration URL: " . esc_html($event['registration_url']) . "\n";
                }
                $debug_output .= "  - All keys: " . implode(', ', array_keys($event)) . "\n\n";
            }
            
            if ($debug_info && is_array($debug_info)) {
                $debug_output .= "\n=== API PROCESSING DEBUG ===\n";
                $debug_output .= esc_html(implode("\n", $debug_info));
            }
            
            $debug_output .= '</div>';
        }
        
        if (empty($events)) {
            $empty_message = '<div class="pc-events-empty">No upcoming events at this time.</div>';
            return $debug_output . $empty_message;
        }
        
        // Limit events if specified
        if ($atts['limit'] > 0) {
            $events = array_slice($events, 0, $atts['limit']);
        }
        
        // Get display settings
        $display_mode = !empty($atts['mode']) ? $atts['mode'] : get_option('pc_display_mode', 'grid');
        $cards_per_row = intval($atts['cards']) > 0 ? intval($atts['cards']) : intval(get_option('pc_cards_per_row', 3));
        $button_text = get_option('pc_button_text', 'Register Now');
        
        // v1.8.5: Use shortcode colors if provided, otherwise use global settings
        $button_color = !empty($atts['button_color']) ? $atts['button_color'] : get_option('pc_button_color', '#007acc');
        $button_text_color = !empty($atts['button_text_color']) ? $atts['button_text_color'] : get_option('pc_button_text_color', '#ffffff');
        $card_background_color = !empty($atts['card_bg_color']) ? $atts['card_bg_color'] : get_option('pc_card_background_color', '#ffffff');
        
        // v1.9.9: Store button colors so groups can inherit them on same page
        global $pc_events_button_color, $pc_events_button_text_color;
        $pc_events_button_color = $button_color;
        $pc_events_button_text_color = $button_text_color;
        
        // v1.9.6: Add # to colors if missing (WordPress strips # from shortcode attributes)
        if (!empty($button_color) && substr($button_color, 0, 1) !== '#') {
            $button_color = '#' . $button_color;
        }
        if (!empty($button_text_color) && substr($button_text_color, 0, 1) !== '#') {
            $button_text_color = '#' . $button_text_color;
        }
        if (!empty($card_background_color) && substr($card_background_color, 0, 1) !== '#') {
            $card_background_color = '#' . $card_background_color;
        }
        
        // TEMPORARY DEBUG - Remove after testing
        if (current_user_can('manage_options')) {
            echo '<!-- EVENTS DEBUG v1.9.9: button_color from shortcode = "' . esc_attr($atts['button_color']) . '" | final button_color = "' . esc_attr($button_color) . '" -->';
        }
        
        // Other colors always use global settings
        $badge_color = get_option('pc_badge_color', '#007acc');
        $title_color = get_option('pc_title_color', '#333333');
        $background_color = get_option('pc_background_color', 'transparent');
        $text_color = get_option('pc_text_color', '#666666');
        $sort_order = get_option('pc_sort_order', 'date_asc');
        $truncate_descriptions = get_option('pc_truncate_descriptions', 0);
        $truncate_length = get_option('pc_truncate_length', 266);
        
        // v1.8.6: Get link behavior setting
        $open_in_new_tab = get_option('pc_open_in_new_tab', 1);
        $target_attr = $open_in_new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
        
        // See All Events card settings
        $see_all_enabled = get_option('pc_see_all_enabled', 0);
        $see_all_position = get_option('pc_see_all_position', 'last');
        $see_all_text = get_option('pc_see_all_text', 'See All Events');
        $see_all_link = get_option('pc_see_all_link', '');
        $see_all_image = get_option('pc_see_all_image', '');
        
        // Sort events
        switch ($sort_order) {
            case 'date_desc':
                usort($events, function($a, $b) {
                    return strcmp($b['starts_at'], $a['starts_at']);
                });
                break;
            case 'name_asc':
                usort($events, function($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
                break;
            case 'name_desc':
                usort($events, function($a, $b) {
                    return strcmp($b['name'], $a['name']);
                });
                break;
            case 'date_asc':
            default:
                // Already sorted by date ascending in processing function
                break;
        }
        
        // Generate a stable (not random-per-request) ID for this shortcode instance so
        // CSS optimizers that cache "used CSS" per page (e.g. WP Rocket's Remove Unused
        // CSS) keep matching it on every load instead of going stale immediately.
        static $pc_events_instance_counter = 0;
        $pc_events_instance_counter++;
        $instance_id = 'pc-events-' . (get_the_ID() ?: 0) . '-' . $pc_events_instance_counter;
        
        // Generate custom CSS for colors with instance-specific selectors
        $custom_css = "
            <style>
                #{$instance_id} .pc-events-container { 
                    background: transparent !important; 
                }
                #{$instance_id} .pc-event-card { background: {$card_background_color} !important; }
                #{$instance_id} .pc-event-badge { background: {$button_color}; } /* v1.9.0: Badge inherits button color */
                #{$instance_id} .pc-event-title { color: {$title_color}; }
                #{$instance_id} .pc-event-description, #{$instance_id} .pc-event-description p { color: {$text_color} !important; }
                #{$instance_id} .pc-event-date, #{$instance_id} .pc-event-location { color: {$text_color}; }
                #{$instance_id} .pc-event-button { 
                    background: {$button_color} !important; 
                    color: {$button_text_color} !important;
                }
                #{$instance_id} .pc-event-button:hover { 
                    background: " . $this->darken_color($button_color, 15) . " !important; 
                    color: {$button_text_color} !important;
                }
            </style>
        ";
        
        ob_start();
        echo $debug_output;
        echo $custom_css;
        
        // Add version marker for debugging
        echo '<!-- Planning Center Integration v' . PC_INTEGRATION_VERSION . ' - Display Mode: ' . esc_html($display_mode) . ' - Instance: ' . esc_html($instance_id) . ' -->';
        
        // Wrap entire output in instance-specific div
        echo '<div id="' . esc_attr($instance_id) . '">';
        
        if ($display_mode === 'carousel'): ?>
        <!-- CAROUSEL MODE v1.2.1 - Image-only clickable cards with 16:9 aspect ratio -->
        <?php if ($debug_mode): ?>
        <div style="background: #fff3cd; border: 2px solid #856404; padding: 15px; margin: 20px 0; font-family: monospace; font-size: 12px;">
            <strong style="color: #856404;">🔍 EVENTS CAROUSEL DEBUG v1.2.1</strong><br><br>
            <strong>Total Events Found:</strong> <?php echo count($events); ?><br>
            <strong>Display Mode:</strong> <?php echo esc_html($display_mode); ?><br>
            <strong>Cards Per Row:</strong> <?php echo esc_attr($cards_per_row); ?><br><br>
            
            <?php 
            $carousel_count = 0;
            foreach ($events as $idx => $event):
                $has_image = !empty($event['image_url']);
                $has_url = !empty($event['registration_url']);
                $will_render = $has_image && $has_url;
                if ($will_render) $carousel_count++;
            ?>
                <div style="margin: 10px 0; padding: 10px; background: <?php echo $will_render ? '#d4edda' : '#f8d7da'; ?>;">
                    <strong>Event #<?php echo ($idx + 1); ?>:</strong> <?php echo esc_html($event['name']); ?><br>
                    &nbsp;&nbsp;Has Image: <?php echo $has_image ? '✅ YES' : '❌ NO'; ?><br>
                    <?php if ($has_image): ?>
                        &nbsp;&nbsp;Image URL: <?php echo esc_html(substr($event['image_url'], 0, 80)); ?>...<br>
                    <?php endif; ?>
                    &nbsp;&nbsp;Has Registration URL: <?php echo $has_url ? '✅ YES' : '❌ NO'; ?><br>
                    <?php if ($has_url): ?>
                        &nbsp;&nbsp;Registration URL: <?php echo esc_html($event['registration_url']); ?><br>
                    <?php endif; ?>
                    &nbsp;&nbsp;<strong>Will Render in Carousel: <?php echo $will_render ? '✅ YES' : '❌ NO'; ?></strong><br>
                </div>
            <?php endforeach; ?>
            
            <br><strong>Cards That Will Render:</strong> <?php echo $carousel_count; ?> / <?php echo count($events); ?><br>
        </div>
        <?php endif; ?>
        <div class="pc-events-carousel-wrapper">
            <div class="pc-events-container pc-carousel pc-carousel-<?php echo esc_attr($cards_per_row); ?>">
                <div class="pc-carousel-track">
                    <?php 
                    $carousel_image_index = 0;
                    foreach ($events as $event): 
                        // v1.8.4: Build event page URL (fallback to registration URL if base URL not configured)
                        $event_url = $this->build_event_url($event['id']);
                        if (empty($event_url)) {
                            $event_url = $event['registration_url'];
                        }
                    ?>
                        <?php if (!empty($event['image_url']) && !empty($event_url)): ?>
                            <a href="<?php echo esc_url($event_url); ?>" 
                               class="pc-event-card-link"<?php echo $target_attr; ?>
                               aria-label="<?php echo esc_attr($event['name']); ?>"
                               title="<?php echo esc_attr($event['name']); ?>">
                                <div class="pc-event-card">
                                    <div class="pc-event-image">
                                        <img src="<?php echo esc_url($event['image_url']); ?>" 
                                             alt="<?php echo esc_attr($event['name']); ?>"
                                             width="800"
                                             height="450"
                                             loading="eager"
                                             <?php echo ($carousel_image_index === 0) ? 'fetchpriority="high"' : ''; ?> />
                                    </div>
                                </div>
                            </a>
                            <?php $carousel_image_index++; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <button class="pc-carousel-nav pc-carousel-prev" aria-label="Previous">‹</button>
            <button class="pc-carousel-nav pc-carousel-next" aria-label="Next">›</button>
            <div class="pc-carousel-indicators"></div>
        </div>
        <?php else: ?>
        <div class="pc-events-container pc-cards-<?php echo esc_attr($cards_per_row); ?>">
            <?php 
            // Render See All Events card if enabled and position is first
            if ($see_all_enabled && $see_all_position === 'first' && !empty($see_all_link)): 
            ?>
                <a href="<?php echo esc_url($see_all_link); ?>" class="pc-event-card pc-see-all-card"<?php echo $target_attr; ?>>
                    <?php if (!empty($see_all_image)): ?>
                        <div class="pc-event-image">
                            <img src="<?php echo esc_url($see_all_image); ?>" 
                                 alt="<?php echo esc_attr($see_all_text); ?>"
                                 loading="lazy" />
                        </div>
                    <?php endif; ?>
                    
                    <div class="pc-event-content pc-see-all-content">
                        <h3 class="pc-event-title pc-see-all-title">
                            <?php echo esc_html($see_all_text); ?>
                        </h3>
                    </div>
                </a>
            <?php endif; ?>
            
            <?php 
            $index = 0;
            foreach ($events as $event): 
                // v1.8.4: Build event page URL (fallback to registration URL if base URL not configured)
                $event_url = $this->build_event_url($event['id']);
                if (empty($event_url)) {
                    $event_url = $event['registration_url'];
                }
                
                $is_even = ($index % 2 == 0);
                $reverse_class = ($cards_per_row == 1 && !$is_even) ? ' pc-reverse' : '';
            ?>
                <div class="pc-event-card<?php echo $reverse_class; ?>" style="background-color: <?php echo esc_attr($card_background_color); ?>;">
                    <?php if (!empty($event['image_url'])): ?>
                        <div class="pc-event-image">
                            <img src="<?php echo esc_url($event['image_url']); ?>" 
                                 alt="<?php echo esc_attr($event['name']); ?>"
                                 width="800"
                                 height="450"
                                 loading="lazy" />
                        </div>
                    <?php endif; ?>
                    
                    <div class="pc-event-content">
                        <div class="pc-event-badge" style="background-color: <?php echo esc_attr($button_color); ?>;">Join us!</div>

                        <h3 class="pc-event-title" style="color: <?php echo esc_attr($title_color); ?>;">
                            <?php echo esc_html($event['name']); ?>
                        </h3>

                        <?php if (!empty($event['starts_at'])): ?>
                            <div class="pc-event-date" style="color: <?php echo esc_attr($text_color); ?>;">
                                <?php 
                                try {
                                    // Parse UTC time and convert to site timezone
                                    $date = new DateTime($event['starts_at'], new DateTimeZone('UTC'));
                                    $site_timezone = wp_timezone();
                                    $date->setTimezone($site_timezone);
                                    echo $date->format('F j, Y \a\t g:i A');
                                } catch (Exception $e) {
                                    echo esc_html($event['starts_at']);
                                }
                                ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($event['location'])): ?>
                            <div class="pc-event-location" style="color: <?php echo esc_attr($text_color); ?>;">
                                📍 <?php echo esc_html($event['location']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($event['description'])): ?>
                            <div class="pc-event-description" style="color: <?php echo esc_attr($text_color); ?>;">
                                <?php 
                                $description = $event['description'];
                                
                                // Apply truncation if enabled
                                if ($truncate_descriptions) {
                                    // Strip HTML tags first to avoid breaking tags mid-truncation
                                    $plain_text = strip_tags($description);
                                    
                                    if (strlen($plain_text) > $truncate_length) {
                                        $plain_text = substr($plain_text, 0, $truncate_length);
                                        // Cut at last complete word
                                        $last_space = strrpos($plain_text, ' ');
                                        if ($last_space !== false && $last_space > 0) {
                                            $plain_text = substr($plain_text, 0, $last_space);
                                        }
                                        $plain_text .= '...';
                                    }
                                    
                                    // Convert line breaks to <p> tags safely
                                    echo '<p>' . esc_html($plain_text) . '</p>';
                                } else {
                                    // Show full description with normal formatting
                                    echo wp_kses_post(wpautop($description));
                                }
                                ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($event_url)): ?>
                            <?php
                            $aria = $button_text . ' about ' . $event['name'] . ' on Planning Center';
                            if ($open_in_new_tab) {
                                $aria .= ', opens in a new tab';
                            }
                            ?>
                            <a href="<?php echo esc_url($event_url); ?>" 
                               class="pc-event-button"
                               aria-label="<?php echo esc_attr($aria); ?>"
                               style="background-color: <?php echo esc_attr($button_color); ?> !important; color: <?php echo esc_attr($button_text_color); ?> !important;"<?php echo $target_attr; ?>>
                                <?php echo esc_html($button_text); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php 
                $index++;
            endforeach; 
            ?>
            
            <?php 
            // Render See All Events card if enabled and position is last
            if ($see_all_enabled && $see_all_position === 'last' && !empty($see_all_link)): 
            ?>
                <a href="<?php echo esc_url($see_all_link); ?>" class="pc-event-card pc-see-all-card"<?php echo $target_attr; ?>>
                    <?php if (!empty($see_all_image)): ?>
                        <div class="pc-event-image">
                            <img src="<?php echo esc_url($see_all_image); ?>" 
                                 alt="<?php echo esc_attr($see_all_text); ?>"
                                 loading="lazy" />
                        </div>
                    <?php endif; ?>
                    
                    <div class="pc-event-content pc-see-all-content">
                        <h3 class="pc-event-title pc-see-all-title">
                            <?php echo esc_html($see_all_text); ?>
                        </h3>
                    </div>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        </div><!-- End instance-specific wrapper -->
        <?php
        return ob_get_clean();
    }
    
    /**
     * Darken a hex color by a percentage
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
    
    /**
     * v1.8.4: Build event page URL from Church Center base URL and event ID
     * Flexible - accepts either full path or just domain
     */
    private function build_event_url($event_id) {
        $base_url = get_option('pc_church_center_url', '');
        
        // If no base URL configured, return empty (fallback to registration URL)
        if (empty($base_url)) {
            return '';
        }
        
        // Remove trailing slash for consistent handling
        $base_url = rtrim($base_url, '/');
        
        // Check if URL already includes the full path
        if (strpos($base_url, '/registrations/events') !== false) {
            // Full path provided: https://church.churchcenter.com/registrations/events
            return $base_url . '/' . $event_id;
        } else {
            // Just domain provided: https://church.churchcenter.com
            return $base_url . '/registrations/events/' . $event_id;
        }
    }
    
    /**
     * v1.0.3: Debug helper - Log message if debug mode is enabled
     */
    private function debug_log($message, $data = null) {
        if (get_option('pci_debug_mode', false)) {
            $log_message = 'PCI Debug: ' . $message;
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
        
        check_admin_referer('pc_debug_api_response_nonce');
        
        $app_id = get_option('pc_app_id');
        $secret = get_option('pc_secret');
        
        if (empty($app_id) || empty($secret)) {
            wp_die('Planning Center credentials not configured');
        }
        
        $url = $this->registrations_api_url . '/events?per_page=5&include=event_instances';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header($app_id, $secret)
            ),
            'timeout' => 30
        ));
        
        echo '<html><head><title>Planning Center API Debug</title>';
        echo '<style>
            body { font-family: monospace; padding: 20px; background: #f0f0f0; }
            .debug-container { background: white; padding: 20px; border-radius: 5px; }
            pre { background: #f5f5f5; padding: 15px; overflow-x: auto; border: 1px solid #ddd; }
            h2 { color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 10px; }
            .back-button { display: inline-block; padding: 10px 20px; background: #0073aa; 
                          color: white; text-decoration: none; border-radius: 3px; margin-bottom: 20px; }
            .back-button:hover { background: #005177; }
        </style></head><body>';
        
        echo '<div class="debug-container">';
        echo '<a href="' . admin_url('admin.php?page=planning-center-settings') . '" class="back-button">← Back to Settings</a>';
        echo '<h2>Planning Center API Raw Response</h2>';
        
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
                    foreach ($data['data'] as $index => $event) {
                        echo '<h4>Event ' . ($index + 1) . ': ' . esc_html($event['attributes']['name'] ?? 'Unknown') . '</h4>';
                        echo '<ul>';
                        echo '<li><strong>ID:</strong> ' . esc_html($event['id'] ?? 'N/A') . '</li>';
                        echo '<li><strong>Has image_url:</strong> ' . (isset($event['attributes']['image_url']) ? 'YES' : 'NO');
                        if (isset($event['attributes']['image_url'])) {
                            echo ' (' . esc_html($event['attributes']['image_url']) . ')';
                        }
                        echo '</li>';
                        echo '<li><strong>Has registration_url:</strong> ' . (isset($event['attributes']['registration_url']) ? 'YES' : 'NO');
                        if (isset($event['attributes']['registration_url'])) {
                            echo ' (' . esc_html($event['attributes']['registration_url']) . ')';
                        }
                        echo '</li>';
                        echo '<li><strong>All attributes:</strong> ' . esc_html(implode(', ', array_keys($event['attributes'] ?? []))) . '</li>';
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
}
