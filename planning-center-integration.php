<?php
/**
 * Plugin Name: Planning Center
 * Plugin URI: https://churchlh.com
 * Description: Unified integration for Planning Center Events and Groups with AI-enhanced descriptions
 * Version: 1.9.23
 * Author: Living Hope Church
 * Author URI: https://churchlh.com
 * License: GPL v2 or later
 * Text Domain: planning-center-integration
 *
 * Version History:
 * - v1.9.23: BUG FIX - Fixed button/card colors reverting to plugin defaults for logged-out
 *            visitors on pages behind CSS-optimization caching (e.g. WP Rocket's Remove
 *            Unused CSS). Root cause: colors were scoped to a per-request random CSS ID
 *            (uniqid()) that never matched between renders, so cached "used CSS" always went
 *            stale; colors also relied solely on that CSS block for several elements. Fixed
 *            by using a stable per-page instance ID and adding inline style attributes as an
 *            authoritative fallback (immune to CSS-block stripping) on card backgrounds,
 *            badges, titles, text, and the Groups button. Also fixed GitHub auto-update
 *            configuration (repo owner placeholder, version constant mismatch that would
 *            have shown a false "update available" notice).
 * - v1.9.22: PERFORMANCE - Per-event next_signup_time caching (eliminates N+1 API calls on cache
 *            refresh), per-group tag caching (eliminates N+1 group tag API calls), auth header
 *            cached as class property (base64 computed once per request), performance stats moved
 *            from autoloaded option to transient (eliminates DB read on every page load), raw API
 *            response storage gated behind debug mode. ACCESSIBILITY - aria-label on event buttons.
 * - v1.9.21: SECURITY FIXES - Fixed SQL injection in queue query, encrypted GitHub token storage, replaced innerHTML with DOM cloning (XSS prevention), removed Reflection API usage
 * - v1.9.20: GITHUB UPDATES - Integrated automatic update system via GitHub (private repo support, hourly checks, dashboard notifications)
 * - v1.9.19: CACHE FIX - Changed CSS versioning to use plugin version for reliable cache busting (fixes CSS not updating issue)
 * - v1.9.18: UI REFINEMENT - More compact event cards with reduced padding and spacing, button closer to content
 * - v1.9.17: PERFORMANCE - Added width/height attributes to images for better Core Web Vitals scores and faster rendering
 * - v1.9.16: PERFORMANCE - Added hourly cache warming cron job, converted group images to lazy-loaded img tags for faster page loads
 * - v1.9.15: FEATURE - Added customizable character limit setting for description truncation (default 266)
 * - v1.9.14: UI REFINEMENT - Description truncation reduced from 350 to 266 characters for more concise cards
 * - v1.9.13: UI FIX - Events container background now transparent (inherits page background color)
 * - v1.9.12: UI REFINEMENT - Event cards 20% smaller (max-width: 340px/450px/1040px for 3/2/1 card layouts)
 * - v1.9.11: UI REFINEMENT - Clock icon inherits button color, title 20% smaller (1.2em), clock centers with first line of schedule text
 * - v1.9.10: UI REFINEMENT - Cards max 400px width with 10px gap for compact, centered grid layout
 * - v1.9.9: INHERITANCE FIX - Groups automatically inherit button colors from events when on same page (perfect solution!)
 * - v1.9.8: SAME PAGE FIX - Added 'btn_bg' parameter (WordPress conflicts when same attribute used by multiple shortcodes on same page)
 * - v1.9.7: WORKAROUND - Added 'btncolor' as alternative parameter (something is stripping 'button_color' from groups only)
 * - v1.9.6: WORDPRESS FIX - Auto-add # to color values (WordPress strips # from shortcode attributes)
 * - v1.9.5: DEBUG - Changed groups debug to echo immediately (like events) to ensure it's visible in page source
 * - v1.9.4: GROUPS FIX - Removed all inline styles from groups (buttons/cards); now uses ONLY instance-specific CSS like events
 * - v1.9.3: DEBUG - Enhanced debugging to diagnose why groups shortcode colors aren't applying
 * - v1.9.2: BUG FIX - Fixed output variable overwrite that was preventing debug comments from showing
 * - v1.9.1: GROUPS + BADGE FIX - Applied instance-specific CSS to groups; made "Join us!" badge inherit button color
 * - v1.9.0: COLOR FIX v3 - Instance-specific CSS selectors prevent conflicts when multiple shortcodes on same page
 * - v1.8.9: COLOR FIX v2 - Added inline styles directly on button elements for maximum CSS specificity (fixes global color override issue)
 * - v1.8.8: COLOR FIX - Added !important to shortcode color parameters to override theme/plugin CSS
 * - v1.8.7: OPEN IN NEW TAB - Added global settings to control whether event/group links open in new tabs (keeps visitors on your site)
 * - v1.8.6: SHORTCODE COLORS - Added button_color, button_text_color, and card_bg_color parameters to events and groups shortcodes
 * - v1.8.5: EVENTS URL FIX - Added Church Center base URL setting, event buttons now link to event pages instead of registration pages
 * - v1.8.4: SORTING IMPROVEMENT - Enhanced schedule parsing to handle complex formats like "Two Saturdays per month at 5:30pm"
 * - v1.8.3: TAG FILTER FIX + AUTO SORT - Fixed shortcode tag filtering (removed pre-filtering), added automatic sorting by Day→Time→Name
 * - v1.8.2: VISIBILITY FILTER FIX - Manual filtering by public_church_center_web_url to only show Church Center visible groups
 * - v1.8.1: GROUPS TAG FILTERING - Filter groups by church_center_visible at API level, fetch tags for each visible group, connect tags to groups for proper filtering
 * - v1.7.2: BUG FIX - Fixed shortcode tag parameter filtering (was case-sensitive, now matches API behavior), added debug logging for shortcode filtering
 * - v1.7.1: BUG FIX - Fixed tag/category filtering for Registrations API mode (was filtering before tags were added), made tag matching case-insensitive
 * - v1.7.0: SECURITY ENHANCEMENTS - API key masking with show/hide toggles, scheduled transient cleanup, improved security for credential fields
 * - v1.6.3: SECURITY & OPTIMIZATION - Fixed version constant, added capability checks to cache clearing, security hardening
 * - v1.6.2: BUG FIX - Improved truncation to strip HTML tags first, preventing malformed HTML from breaking card grid layout
 * - v1.6.1: BUG FIX - Fixed truncation feature that was breaking card grid layout (improved strrpos() error handling)
 * - v1.6.0: GROUPS COMPLETE - Added Groups exclusion list, See All Groups card feature (first/last position, custom text/link/image)
 * - v1.5.0: MAJOR FEATURES - Fixed cache buttons, added description truncation toggle, See All Events card, fixed groups display (show all 16), removed groups description/location, added exclusion lists
 * - v1.4.2: MODERN SETTINGS REDESIGN - Added card styling to Events & Groups settings pages, empty state for performance metrics
 * - v1.4.1: MENU FIX - Fixed dashboard not appearing when clicking Planning Center menu item
 * - v1.4.0: ADMIN REDESIGN - Unified dashboard with clickable cards, collapsible AI settings, streamlined menu structure
 * - v1.3.0: CAROUSEL DISABLED - Removed carousel option from both Events and Groups display mode settings (code remains for future use)
 * - v1.2.9: CAROUSEL ASPECT RATIO FIX - Remove conflicting padding-top rule causing 2306px height in carousel mode
 * - v1.2.8: OBJECT-FIT FIX - Change from contain to cover for perfect 1920x1080 image display
 * - v1.2.7: CRITICAL FIX - Add max-width constraints to prevent massive image overflow
 * - v1.2.6: HOTFIX - Replace padding-top hack with aspect-ratio for reliable 16:9 display
 * - v1.2.5: CAROUSEL IMAGE FIX - Eager load carousel images, add hover zoom, optimize first image with fetchpriority
 * - v1.2.4: CRITICAL FIX - Removed inline padding styles from Groups carousel container that broke width calculations
 * - v1.2.3: CSS specificity fixes - Added explicit overrides and debug border for Groups carousel troubleshooting
 * - v1.2.2: Debug mode additions - Added comprehensive carousel debug output for troubleshooting
 * - v1.2.1: Bug fixes - Fixed Groups URL (use public_church_center_web_url), removed conflicting CSS, ensured 16:9 aspect ratio
 * - v1.2.0: Simplified carousel - Image-only clickable cards in 16:9 aspect ratio (removed all text content from carousel)
 * - v1.1.1: Fixed height approach - Replaced padding-top % with fixed heights to prevent massive image upscaling
 * - v1.1.0: Complete image override - !important on ALL properties to force proper display
 * - v1.0.9: Image fix with !important - Forces full image display without cropping
 * - v1.0.8: 30% size reduction - Smaller cards, fixed image cropping (contain vs cover)
 * - v1.0.4: Carousel display mode - Add carousel with 1, 2, or 3 cards visible, navigation arrows, touch support
 * - v1.0.3: Debug mode enhancements - Raw API viewer, enhanced logging, debug panel
 * - v1.0.2: Groups enhancements - Image cards, filtering, responsive layout
 * - v1.0.1: Bug fix - Fixed handler initialization timing issue
 * - v1.0.0: Initial release (Events v7.3.0 + Groups v1.0.0)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PC_INTEGRATION_VERSION', '1.9.23');
define('PC_INTEGRATION_PATH', plugin_dir_path(__FILE__));
define('PC_INTEGRATION_URL', plugin_dir_url(__FILE__));

/**
 * Main Planning Center Integration Class
 */
class PlanningCenterIntegration {
    
    private $events_handler;
    private $groups_handler;
    
    public function __construct() {
        // Load dependencies first
        $this->load_dependencies();
        
        // Initialize handlers immediately (before admin_menu hook)
        $this->events_handler = new PC_Events_Handler();
        $this->groups_handler = new PC_Groups_Handler();
        
        // Initialize GitHub updater (v1.9.20)
        // User will set their GitHub username and repo name in instructions
        if (class_exists('PC_GitHub_Updater')) {
            $updater = new PC_GitHub_Updater(__FILE__, 'livinghope-aiassistants', 'planning-center-integration');
            if ($token = get_option('pc_github_token')) {
                $updater->set_token($token);
            }
            $updater->initialize();
        }
        
        // Add main admin menu
        add_action('admin_menu', array($this, 'add_main_menu'));
        
        // Add version notice in admin
        add_action('admin_notices', array($this, 'show_version_notice'));
        
        // Enqueue admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        
        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));
        
        // Schedule cleanup for transients
        add_action('pc_cleanup_transients', array($this, 'cleanup_old_transients'));
        
        // Schedule cache warming (v1.9.16)
        add_action('pc_warm_cache', array($this, 'warm_cache'));
        
        // Register activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Show version notice in admin
     */
    public function show_version_notice() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'planning-center') === false) {
            return;
        }
        
        $css_file = PC_INTEGRATION_PATH . 'css/style.css';
        $css_exists = file_exists($css_file);
        $css_time = $css_exists ? date('Y-m-d H:i:s', filemtime($css_file)) : 'N/A';
        
        echo '<div class="notice notice-info">';
        echo '<p><strong>Planning Center Integration Status:</strong></p>';
        echo '<ul style="list-style: disc; margin-left: 20px;">';
        echo '<li>Plugin Version: <strong>' . esc_html(PC_INTEGRATION_VERSION) . '</strong></li>';
        echo '<li>CSS File: ' . ($css_exists ? '<span style="color: green;">✓ Found</span>' : '<span style="color: red;">✗ Missing</span>') . '</li>';
        echo '<li>CSS Last Modified: ' . esc_html($css_time) . '</li>';
        echo '<li>Plugin Path: <code>' . esc_html(PC_INTEGRATION_PATH) . '</code></li>';
        echo '</ul>';
        echo '</div>';
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once PC_INTEGRATION_PATH . 'includes/events-handler.php';
        require_once PC_INTEGRATION_PATH . 'includes/groups-handler.php';
        require_once PC_INTEGRATION_PATH . 'includes/github-updater.php';
    }
    
    /**
     * Add main admin menu
     */
    public function add_main_menu() {
        // Main dashboard page - clicking "Planning Center" goes here
        add_menu_page(
            'Planning Center Dashboard',
            'Planning Center',
            'manage_options',
            'planning-center',
            array($this, 'dashboard_page'),
            'dashicons-calendar-alt',
            30
        );
        
        // Events settings submenu (includes AI settings on same page)
        add_submenu_page(
            'planning-center',
            'Events Settings',
            'Events Settings',
            'manage_options',
            'planning-center-events-settings',
            array($this->events_handler, 'settings_page')
        );
        
        // Groups settings submenu (includes AI settings on same page)
        add_submenu_page(
            'planning-center',
            'Groups Settings',
            'Groups Settings',
            'manage_options',
            'planning-center-groups-settings',
            array($this->groups_handler, 'settings_page')
        );
    }
    
    /**
     * Main dashboard page with performance metrics
     */
    public function dashboard_page() {
        // Get color settings for styling
        $button_color_events = get_option('pc_button_color', '#007acc');
        $button_color_groups = get_option('pc_groups_button_color', '#007acc');
        $card_bg_events = get_option('pc_card_bg_color', '#ffffff');
        $card_bg_groups = get_option('pc_groups_card_bg_color', '#ffffff');
        
        // Get performance stats
        $stats = get_option('pc_performance_stats', array());
        ?>
        <div class="wrap pc-dashboard">
            <h1 style="margin-bottom: 30px;">
                <span class="dashicons dashicons-calendar-alt" style="font-size: 32px; width: 32px; height: 32px; vertical-align: middle;"></span>
                Planning Center Integration
            </h1>
            
            <!-- Main Module Cards (Clickable) -->
            <div class="pc-dashboard-section">
                <div class="pc-dashboard-grid pc-dashboard-main-grid">
                    
                    <!-- Events Card (Fully Clickable) -->
                    <a href="<?php echo admin_url('admin.php?page=planning-center-events-settings'); ?>" 
                       class="pc-module-card pc-module-card-events" 
                       style="border-top: 4px solid <?php echo esc_attr($button_color_events); ?>;">
                        <div class="pc-card-icon" style="background: <?php echo esc_attr($button_color_events); ?>15;">
                            <span class="dashicons dashicons-calendar" style="color: <?php echo esc_attr($button_color_events); ?>; font-size: 48px;"></span>
                        </div>
                        <h3 style="margin: 20px 0 10px 0; font-size: 24px; color: #23282d;">Events</h3>
                        <p style="color: #666; margin-bottom: 0; line-height: 1.6;">Configure event display, colors, and AI-powered descriptions</p>
                    </a>
                    
                    <!-- Groups Card (Fully Clickable) -->
                    <a href="<?php echo admin_url('admin.php?page=planning-center-groups-settings'); ?>" 
                       class="pc-module-card pc-module-card-groups" 
                       style="border-top: 4px solid <?php echo esc_attr($button_color_groups); ?>;">
                        <div class="pc-card-icon" style="background: <?php echo esc_attr($button_color_groups); ?>15;">
                            <span class="dashicons dashicons-groups" style="color: <?php echo esc_attr($button_color_groups); ?>; font-size: 48px;"></span>
                        </div>
                        <h3 style="margin: 20px 0 10px 0; font-size: 24px; color: #23282d;">Groups</h3>
                        <p style="color: #666; margin-bottom: 0; line-height: 1.6;">Manage group settings, layouts, and automated descriptions</p>
                    </a>
                    
                </div>
            </div>
            
            <!-- Shortcode Guide Cards -->
            <div class="pc-dashboard-section" style="margin-top: 30px;">
                <h2 style="margin-bottom: 20px; color: #23282d;">Display on Your Site</h2>
                <div class="pc-dashboard-grid pc-dashboard-main-grid">
                    
                    <!-- Events Shortcode Card -->
                    <div class="pc-shortcode-card">
                        <div class="pc-shortcode-header">
                            <span class="dashicons dashicons-calendar" style="color: <?php echo esc_attr($button_color_events); ?>; font-size: 24px; margin-right: 10px;"></span>
                            <h3 style="margin: 0; font-size: 18px;">Events Shortcode</h3>
                        </div>
                        <div class="pc-shortcode-code">
                            <code>[planning_center_events]</code>
                        </div>
                        <div class="pc-shortcode-params">
                            <strong>Available Parameters:</strong>
                            <ul style="margin: 10px 0 0 20px; color: #666;">
                                <li><code>limit="10"</code> - Number of events to display</li>
                                <li><code>tag="youth"</code> - Filter by tag</li>
                                <li><code>cards="3"</code> - Cards per row (1-3)</li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Groups Shortcode Card -->
                    <div class="pc-shortcode-card">
                        <div class="pc-shortcode-header">
                            <span class="dashicons dashicons-groups" style="color: <?php echo esc_attr($button_color_groups); ?>; font-size: 24px; margin-right: 10px;"></span>
                            <h3 style="margin: 0; font-size: 18px;">Groups Shortcode</h3>
                        </div>
                        <div class="pc-shortcode-code">
                            <code>[planning_center_groups]</code>
                        </div>
                        <div class="pc-shortcode-params">
                            <strong>Available Parameters:</strong>
                            <ul style="margin: 10px 0 0 20px; color: #666;">
                                <li><code>limit="10"</code> - Number of groups to display</li>
                                <li><code>type="Small Groups"</code> - Filter by group type</li>
                                <li><code>cards="3"</code> - Cards per row (1-3)</li>
                            </ul>
                        </div>
                    </div>
                    
                </div>
            </div>
            
            <?php if (!empty($stats)): ?>
            <!-- Performance Metrics -->
            <div class="pc-dashboard-section" style="margin-top: 30px;">
                <h2 style="margin-bottom: 20px; color: #23282d;">📊 Performance Metrics</h2>
                <div class="pc-dashboard-grid">
                    <?php if (isset($stats['api_call_time'])): ?>
                    <div class="pc-metric-card">
                        <div class="pc-metric-label">API Response Time</div>
                        <div class="pc-metric-value"><?php echo number_format($stats['api_call_time']['avg'], 2); ?>ms</div>
                        <div class="pc-metric-detail">
                            Min: <?php echo number_format($stats['api_call_time']['min'], 2); ?>ms | 
                            Max: <?php echo number_format($stats['api_call_time']['max'], 2); ?>ms
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($stats['cache_hit_rate'])): ?>
                    <div class="pc-metric-card">
                        <div class="pc-metric-label">Cache Hit Rate</div>
                        <div class="pc-metric-value"><?php echo number_format($stats['cache_hit_rate']['avg'], 1); ?>%</div>
                        <div class="pc-metric-detail">
                            <?php echo $stats['cache_hit_rate']['count']; ?> checks
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($stats['ai_generation_time'])): ?>
                    <div class="pc-metric-card">
                        <div class="pc-metric-label">AI Generation Time</div>
                        <div class="pc-metric-value"><?php echo number_format($stats['ai_generation_time']['avg'] / 1000, 1); ?>s</div>
                        <div class="pc-metric-detail">
                            <?php echo $stats['ai_generation_time']['count']; ?> generations
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <!-- Performance Metrics - No Data Yet -->
            <div class="pc-dashboard-section" style="margin-top: 30px;">
                <h2 style="margin-bottom: 20px; color: #23282d;">📊 Performance Metrics</h2>
                <div class="pc-empty-state">
                    <div class="pc-empty-icon">📈</div>
                    <h3>No Performance Data Yet</h3>
                    <p>Performance metrics will appear here once your events and groups are displayed on your site. These stats help you monitor:</p>
                    <ul style="text-align: left; display: inline-block; margin: 15px 0;">
                        <li>API response times from Planning Center</li>
                        <li>Cache efficiency and hit rates</li>
                        <li>AI description generation speeds</li>
                    </ul>
                    <p style="margin-top: 15px;"><strong>Tip:</strong> Add the shortcode <code>[planning_center_events]</code> or <code>[planning_center_groups]</code> to a page to start collecting data.</p>
                </div>
            </div>
            <?php endif; ?>
            
            <style>
                .pc-dashboard {
                    max-width: 1400px;
                }
                
                .pc-dashboard-grid {
                    display: grid;
                    gap: 20px;
                    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                }
                
                .pc-dashboard-main-grid {
                    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                }
                
                /* Module Cards (Clickable) */
                .pc-module-card {
                    background: white;
                    border-radius: 8px;
                    padding: 30px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                    transition: all 0.3s ease;
                    text-decoration: none;
                    display: block;
                    cursor: pointer;
                }
                
                .pc-module-card:hover {
                    transform: translateY(-4px);
                    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
                }
                
                .pc-module-card:focus {
                    outline: 2px solid #007acc;
                    outline-offset: 2px;
                }
                
                /* Shortcode Cards */
                .pc-shortcode-card {
                    background: white;
                    border-radius: 8px;
                    padding: 25px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
                    border: 1px solid #e5e5e5;
                }
                
                .pc-shortcode-header {
                    display: flex;
                    align-items: center;
                    margin-bottom: 15px;
                    padding-bottom: 15px;
                    border-bottom: 2px solid #f0f0f0;
                }
                
                .pc-shortcode-code {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 6px;
                    border-left: 4px solid #007acc;
                    margin-bottom: 15px;
                }
                
                .pc-shortcode-code code {
                    font-size: 14px;
                    font-weight: 600;
                    color: #23282d;
                    font-family: 'Courier New', monospace;
                }
                
                .pc-shortcode-params {
                    font-size: 13px;
                    color: #666;
                }
                
                .pc-shortcode-params code {
                    background: #fff3cd;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 12px;
                    color: #856404;
                }
                
                /* Metric Cards */
                .pc-metric-card {
                    background: white;
                    border-radius: 8px;
                    padding: 25px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
                    border-left: 4px solid #007acc;
                }
                
                .pc-metric-label {
                    font-size: 13px;
                    color: #666;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    margin-bottom: 10px;
                    font-weight: 600;
                }
                
                .pc-metric-value {
                    font-size: 32px;
                    font-weight: 700;
                    color: #23282d;
                    margin-bottom: 8px;
                }
                
                .pc-metric-detail {
                    font-size: 13px;
                    color: #999;
                }
                
                .pc-card-icon {
                    width: 80px;
                    height: 80px;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                .pc-dashboard-section {
                    margin-bottom: 30px;
                }
                
                /* Empty State */
                .pc-empty-state {
                    background: white;
                    border-radius: 8px;
                    padding: 40px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
                    text-align: center;
                    border: 2px dashed #e5e5e5;
                }
                
                .pc-empty-icon {
                    font-size: 48px;
                    margin-bottom: 15px;
                }
                
                .pc-empty-state h3 {
                    margin: 0 0 10px 0;
                    color: #23282d;
                    font-size: 20px;
                }
                
                .pc-empty-state p {
                    color: #666;
                    margin: 10px 0;
                    line-height: 1.6;
                }
                
                .pc-empty-state ul {
                    color: #666;
                    line-height: 1.8;
                }
                
                .pc-empty-state code {
                    background: #f0f0f0;
                    padding: 3px 8px;
                    border-radius: 3px;
                    font-size: 13px;
                }
                
                @media (max-width: 768px) {
                    .pc-dashboard-main-grid {
                        grid-template-columns: 1fr;
                    }
                }
            </style>
        </div>
        <?php
    }
    
    /**
     * Main menu page (redirect to events) - DEPRECATED
     */
    public function main_menu_page() {
        wp_redirect(admin_url('admin.php?page=planning-center'));
        exit;
    }
    
    /**
     * Events menu page
     */
    public function events_menu_page() {
        ?>
        <div class="wrap pc-admin-wrap">
            <h1>Planning Center Events</h1>
            <div class="pc-menu-card">
                <h2>Manage Your Events</h2>
                <p>Display and manage events from Planning Center on your WordPress site.</p>
                <div class="pc-menu-links">
                    <a href="<?php echo admin_url('admin.php?page=planning-center-events-settings'); ?>" class="button button-primary button-large">
                        Configure Event Settings
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=planning-center-events-ai'); ?>" class="button button-secondary button-large">
                        Manage AI Descriptions
                    </a>
                </div>
                
                <div class="pc-shortcode-info">
                    <h3>Display Events on Your Site</h3>
                    <p>Use this shortcode to display events:</p>
                    <code>[planning_center_events]</code>
                    <p style="margin-top: 15px;"><strong>Parameters:</strong></p>
                    <ul>
                        <li><code>limit="10"</code> - Number of events to display</li>
                        <li><code>tag="youth"</code> - Filter by tag</li>
                        <li><code>cards="3"</code> - Cards per row (1-3)</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Groups menu page
     */
    public function groups_menu_page() {
        ?>
        <div class="wrap pc-admin-wrap">
            <h1>Planning Center Groups</h1>
            <div class="pc-menu-card">
                <h2>Manage Your Groups</h2>
                <p>Display and manage groups from Planning Center on your WordPress site.</p>
                <div class="pc-menu-links">
                    <a href="<?php echo admin_url('admin.php?page=planning-center-groups-settings'); ?>" class="button button-primary button-large">
                        Configure Group Settings
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=planning-center-groups-ai'); ?>" class="button button-secondary button-large">
                        Manage AI Descriptions
                    </a>
                </div>
                
                <div class="pc-shortcode-info">
                    <h3>Display Groups on Your Site</h3>
                    <p>Use this shortcode to display groups:</p>
                    <code>[planning_center_groups]</code>
                    <p style="margin-top: 15px;"><strong>Parameters:</strong></p>
                    <ul>
                        <li><code>limit="10"</code> - Number of groups to display</li>
                        <li><code>type="Small Groups"</code> - Filter by group type</li>
                        <li><code>cards="3"</code> - Cards per row (1-3)</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Performance page (unified for both Events and Groups)
     */
    public function performance_page() {
        ?>
        <div class="wrap pc-admin-wrap">
            <h1>Performance Dashboard</h1>
            <div class="pc-menu-card">
                <h2>Coming Soon</h2>
                <p>Unified performance monitoring for Events and Groups will be available here.</p>
                <p>This dashboard will show:</p>
                <ul>
                    <li>Cache statistics and hit rates</li>
                    <li>API call tracking and rate limits</li>
                    <li>Database query performance</li>
                    <li>AI generation metrics</li>
                    <li>Overall system health</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'planning-center') === false) {
            return;
        }
        
        wp_enqueue_style(
            'planning-center-admin',
            PC_INTEGRATION_URL . 'css/admin-style.css',
            array(),
            PC_INTEGRATION_VERSION
        );
    }
    
    /**
     * Enqueue frontend styles
     * Note: Loaded on all pages for page builder compatibility. Page builders such as
     * Elementor store shortcode content in post meta rather than post_content, so a
     * has_shortcode() check against post_content would incorrectly skip these pages.
     */
    public function enqueue_frontend_styles() {
        $css_version = PC_INTEGRATION_VERSION;
        $js_version  = PC_INTEGRATION_VERSION;

        wp_enqueue_style(
            'planning-center-frontend',
            PC_INTEGRATION_URL . 'css/style.css',
            array(),
            $css_version
        );

        wp_enqueue_script(
            'planning-center-carousel',
            PC_INTEGRATION_URL . 'js/carousel.js',
            array(),
            $js_version,
            true // Load in footer
        );
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Events activation
        if (method_exists($this->events_handler, 'activate')) {
            $this->events_handler->activate();
        }
        
        // Groups activation
        if (method_exists($this->groups_handler, 'activate')) {
            $this->groups_handler->activate();
        }
        
        // Schedule daily transient cleanup
        if (!wp_next_scheduled('pc_cleanup_transients')) {
            wp_schedule_event(time(), 'daily', 'pc_cleanup_transients');
        }
        
        // Schedule hourly cache warming (v1.9.16)
        if (!wp_next_scheduled('pc_warm_cache')) {
            wp_schedule_event(time(), 'hourly', 'pc_warm_cache');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('pc_cleanup_transients');
        wp_clear_scheduled_hook('pc_generate_ai_description');
        wp_clear_scheduled_hook('pc_warm_cache'); // v1.9.16
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Cleanup old expired transients
     * Runs daily via scheduled cron job
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
        
        // Log the cleanup
        error_log('Planning Center Integration: Cleaned up ' . $wpdb->rows_affected . ' expired transients');
    }
    
    /**
     * Warm the cache by fetching events and groups in background
     * Runs hourly via scheduled cron job (v1.9.16)
     * v1.9.21: Removed Reflection API usage for security
     */
    public function warm_cache() {
        // Only run if handlers are initialized
        if (!$this->events_handler || !$this->groups_handler) {
            return;
        }
        
        // Fetch events (this will cache them)
        if (method_exists($this->events_handler, 'warm_events_cache')) {
            try {
                $this->events_handler->warm_events_cache();
                error_log('Planning Center Integration: Cache warmed - Events');
            } catch (Exception $e) {
                error_log('Planning Center Integration: Cache warming failed for events - ' . $e->getMessage());
            }
        }
        
        // Fetch groups (this will cache them)
        if (method_exists($this->groups_handler, 'warm_groups_cache')) {
            try {
                $this->groups_handler->warm_groups_cache();
                error_log('Planning Center Integration: Cache warmed - Groups');
            } catch (Exception $e) {
                error_log('Planning Center Integration: Cache warming failed for groups - ' . $e->getMessage());
            }
        }
    }
}

// Initialize the plugin
function planning_center_integration_init() {
    new PlanningCenterIntegration();
}
add_action('init', 'planning_center_integration_init');
