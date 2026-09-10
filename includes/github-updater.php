<?php
/**
 * GitHub Plugin Updater
 * Checks GitHub for plugin updates and integrates with WordPress update system
 */

if (!defined('ABSPATH')) exit;

class PC_GitHub_Updater {
    private $file;
    private $plugin;
    private $basename;
    private $active;
    private $username;
    private $repository;
    private $github_response;
    private $authorize_token;
    
    public function __construct($file, $username, $repository) {
        $this->file = $file;
        $this->username = $username;
        $this->repository = $repository;
        
        add_action('admin_init', array($this, 'set_plugin_properties'));
        
        return $this;
    }
    
    public function set_plugin_properties() {
        $this->plugin = get_plugin_data($this->file);
        $this->basename = plugin_basename($this->file);
        $this->active = is_plugin_active($this->basename);
    }
    
    public function set_token($token) {
        $this->authorize_token = $token;
    }
    
    /**
     * v1.9.21: Encrypt GitHub token for storage
     */
    private function encrypt_token($token) {
        if (empty($token)) {
            return '';
        }
        
        // Use WordPress authentication salts for encryption
        if (!defined('AUTH_KEY') || !defined('SECURE_AUTH_KEY')) {
            return base64_encode($token);
        }
        
        $encryption_key = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY);
        $iv = openssl_random_pseudo_bytes(16);
        
        $encrypted = openssl_encrypt(
            $token,
            'AES-256-CBC',
            $encryption_key,
            0,
            $iv
        );
        
        return base64_encode($iv . '::' . $encrypted);
    }
    
    /**
     * v1.9.21: Decrypt GitHub token from storage
     */
    private function decrypt_token($encrypted_token) {
        if (empty($encrypted_token)) {
            return '';
        }
        
        if (!defined('AUTH_KEY') || !defined('SECURE_AUTH_KEY')) {
            $decoded = base64_decode($encrypted_token);
            if (strpos($decoded, '::') === false) {
                return $decoded;
            }
        }
        
        $encryption_key = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY);
        $decoded = base64_decode($encrypted_token);
        
        if (strpos($decoded, '::') === false) {
            return $decoded;
        }
        
        list($iv, $encrypted) = explode('::', $decoded, 2);
        
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
     * v1.9.21: Get decrypted token
     */
    private function get_token() {
        $encrypted = get_option('pc_github_token', '');
        return $this->decrypt_token($encrypted);
    }
    
    private function get_repository_info() {
        if (is_null($this->github_response)) {
            $args = array();
            $url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";
            
            // v1.9.21: Get decrypted token if not already set
            $token = $this->authorize_token ? $this->authorize_token : $this->get_token();
            
            if ($token) {
                $args['headers']['Authorization'] = "token {$token}";
            }
            
            $response = wp_remote_get($url, $args);
            
            if (is_wp_error($response)) {
                return false;
            }
            
            $this->github_response = json_decode(wp_remote_retrieve_body($response));
        }
        
        return $this->github_response;
    }
    
    public function initialize() {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 10, 1);
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        
        // Add action for settings page
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    public function register_settings() {
        // GitHub token setting will be added to existing settings
        add_settings_field(
            'pc_github_token',
            'GitHub Access Token',
            array($this, 'github_token_field'),
            'planning-center-integration',
            'pc_integration_settings_section'
        );
        
        // v1.9.21: Encrypt token on save
        register_setting('pc_integration_settings', 'pc_github_token', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_and_encrypt_token'),
            'default' => ''
        ));
    }
    
    /**
     * v1.9.21: Sanitize and encrypt token before saving
     */
    public function sanitize_and_encrypt_token($value) {
        $value = sanitize_text_field($value);
        if (empty($value)) {
            return '';
        }
        return $this->encrypt_token($value);
    }
    
    public function github_token_field() {
        $token = $this->get_token();
        $masked_token = !empty($token) ? str_repeat('•', 40) : '';
        
        echo '<input type="password" 
                   id="pc_github_token" 
                   name="pc_github_token" 
                   value="' . esc_attr($token) . '" 
                   data-original="' . esc_attr($token) . '"
                   data-masked="' . esc_attr($masked_token) . '"
                   class="regular-text pc-github-token-field" 
                   autocomplete="off" />';
        echo '<button type="button" class="button pc-toggle-github-token" style="margin-left: 5px;">Show</button>';
        echo '<p class="description">Required for automatic updates. <a href="https://github.com/settings/tokens/new" target="_blank">Create token</a> with "repo" access for private repositories.</p>';
        
        // Add JavaScript for show/hide toggle
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.pc-toggle-github-token').on('click', function() {
                var input = $('#pc_github_token');
                var button = $(this);
                
                if (input.attr('type') === 'password') {
                    input.attr('type', 'text');
                    input.val(input.data('original'));
                    button.text('Hide');
                } else {
                    input.attr('type', 'password');
                    input.val(input.data('masked'));
                    button.text('Show');
                }
            });
            
            // Mask on page load if there's a value
            var input = $('#pc_github_token');
            if (input.val() && input.data('masked')) {
                input.val(input.data('masked'));
            }
        });
        </script>
        <?php
    }
    
    public function modify_transient($transient) {
        if (property_exists($transient, 'checked')) {
            if ($checked = $transient->checked) {
                $this->get_repository_info();
                
                $out_of_date = version_compare(
                    $this->github_response->tag_name,
                    $checked[$this->basename],
                    'gt'
                );
                
                if ($out_of_date) {
                    $new_files = $this->github_response->zipball_url;
                    $slug = current(explode('/', $this->basename));
                    
                    $plugin = array(
                        'url' => $this->plugin["PluginURI"],
                        'slug' => $slug,
                        'package' => $new_files,
                        'new_version' => $this->github_response->tag_name
                    );
                    
                    $transient->response[$this->basename] = (object) $plugin;
                }
            }
        }
        
        return $transient;
    }
    
    public function plugin_popup($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        
        if (!empty($args->slug)) {
            if ($args->slug == current(explode('/', $this->basename))) {
                $this->get_repository_info();
                
                $plugin = array(
                    'name' => $this->plugin["Name"],
                    'slug' => $this->basename,
                    'version' => $this->github_response->tag_name,
                    'author' => $this->plugin["AuthorName"],
                    'author_profile' => $this->plugin["AuthorURI"],
                    'last_updated' => $this->github_response->published_at,
                    'homepage' => $this->plugin["PluginURI"],
                    'short_description' => $this->plugin["Description"],
                    'sections' => array(
                        'Description' => $this->plugin["Description"],
                        'Updates' => $this->github_response->body,
                    ),
                    'download_link' => $this->github_response->zipball_url
                );
                
                return (object) $plugin;
            }
        }
        
        return $result;
    }
    
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        $install_directory = plugin_dir_path($this->file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;
        
        if ($this->active) {
            activate_plugin($this->basename);
        }
        
        return $result;
    }
}
