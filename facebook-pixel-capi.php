<?php
/**
 * Plugin Name: W3 Pixel Server-Side Tracking
 * Plugin URI: https://github.com/mdshukurmiah/w3-pixel-capi
 * Description: A WordPress plugin that enables Facebook Pixel server-side tracking using the Conversions API (CAPI) for improved tracking accuracy and reliability.
 * Version: 1.0.3
 * Author: Md Shukur Miah
 * Author URI: https://www.shukurs.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: w3-pixel-capi
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.9.4
 * Requires PHP: 7.4
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('FBPIXEL_CAPI_VERSION', '1.0.3');
define('FBPIXEL_CAPI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FBPIXEL_CAPI_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FBPIXEL_CAPI_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class FacebookPixelCAPI {
    
    /**
     * Plugin instance
     */
    private static $instance = null;

    /**
     * @var FBPixel_CAPI_API_Client
     */
    private $api_client;

    /**
     * @var FBPixel_CAPI_Event_Tracker
     */
    private $event_tracker;

    /**
     * @var FBPixel_CAPI_Admin
     */
    private $admin;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize the plugin
     */
    private function init() {
        // Load plugin text domain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Include required files
        $this->include_files();
        
        // Initialize components
        add_action('init', array($this, 'init_components'));

        // Ensure Facebook cookies are set early
        add_action('init', array($this, 'ensure_facebook_cookies'), 1);

        // Queue processing cron
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action('fbpixel_capi_process_queue', array($this, 'process_queue'));
        
        // Initialize WooCommerce hooks after plugins are loaded
        add_action('plugins_loaded', array($this, 'init_woocommerce_hooks'));
        
        // Plugin activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'admin_init'));
            add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        }
        
        // Frontend hooks for event tracking
        add_action('wp_head', array($this, 'track_page_view'));
        add_action('wp_footer', array($this, 'track_deferred_events'));

        // Browser-side (Pixel) tracking
        add_action('wp_head', array($this, 'output_pixel_base_code'), 1);
        add_action('wp_footer', array($this, 'output_browser_events'), 20);
    }
    
    /**
     * Include required files
     */
    private function include_files() {
        require_once FBPIXEL_CAPI_PLUGIN_PATH . 'includes/class-fbpixel-capi-api-client.php';
        require_once FBPIXEL_CAPI_PLUGIN_PATH . 'includes/class-fbpixel-capi-event-tracker.php';
        require_once FBPIXEL_CAPI_PLUGIN_PATH . 'includes/class-fbpixel-capi-helpers.php';
        require_once FBPIXEL_CAPI_PLUGIN_PATH . 'admin/class-fbpixel-capi-admin.php';
    }
    
    /**
     * Initialize components
     */
    public function init_components() {
        // Initialize API client
        $this->api_client = new FBPixel_CAPI_API_Client();
        
        // Initialize event tracker
        $this->event_tracker = new FBPixel_CAPI_Event_Tracker($this->api_client);
        
        // Initialize admin (if in admin area)
        if (is_admin()) {
            $this->admin = new FBPixel_CAPI_Admin();
        }
    }
    
    /**
     * Initialize WooCommerce hooks
     */
    public function init_woocommerce_hooks() {
        // Get settings for debug logging
        $settings = get_option('fbpixel_capi_settings', array());
        
        if (!empty($settings['debug_mode'])) {
            error_log('Facebook Pixel CAPI: init_woocommerce_hooks called');
        }
        
        // WooCommerce hooks (if WooCommerce is active)
        if (class_exists('WooCommerce')) {
            if (!empty($settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: WooCommerce detected, registering hooks');
            }
            
            add_action('woocommerce_add_to_cart', array($this, 'track_add_to_cart'), 10, 6);
            add_action('woocommerce_thankyou', array($this, 'track_purchase'), 10, 1);
            add_action('woocommerce_before_checkout_form', array($this, 'track_initiate_checkout'));
            
            // Add ViewContent tracking for product pages
            add_action('wp_head', array($this, 'track_view_content'));
            
            if (!empty($settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: WooCommerce hooks registered successfully');
            }
        } else {
            if (!empty($settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: WooCommerce not detected, skipping WooCommerce hooks');
            }
        }
    }
    
    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('w3-pixel-capi', false, dirname(FBPIXEL_CAPI_PLUGIN_BASENAME) . '/languages');
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $default_options = array(
            'pixel_id' => '',
            'access_token' => '',
            'test_event_code' => '',
            'enabled_events' => array(
                'PageView' => true,
                'AddToCart' => true,
                'InitiateCheckout' => true,
                'Purchase' => true,
                'ViewContent' => false,
                'Search' => false,
                'Lead' => false,
                'CompleteRegistration' => false
            ),
            'debug_mode' => false
        );
        
        add_option('fbpixel_capi_settings', $default_options);
        
        // Create log table if needed
        $this->create_log_table();

        // Create queue table if needed
        $this->create_queue_table();

        if (!wp_next_scheduled('fbpixel_capi_process_queue')) {
            wp_schedule_event(time() + 300, 'fbpixel_capi_five_minutes', 'fbpixel_capi_process_queue');
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up scheduled events if any
        wp_clear_scheduled_hook('fbpixel_capi_cleanup_logs');
        wp_clear_scheduled_hook('fbpixel_capi_process_queue');
    }
    
    /**
     * Create log table for debugging
     */
    private function create_log_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fbpixel_capi_logs';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_name varchar(100) NOT NULL,
            event_data longtext NOT NULL,
            response_data longtext,
            status varchar(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Create queue table for retrying failed events
     */
    private function create_queue_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'fbpixel_capi_queue';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_data longtext NOT NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            last_error text,
            next_attempt_at datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('Facebook Pixel CAPI Settings', 'w3-pixel-capi'),
            __('Facebook Pixel CAPI', 'w3-pixel-capi'),
            'manage_options',
            'fbpixel-capi-settings',
            array($this, 'admin_page_callback')
        );
    }
    
    /**
     * Admin page callback
     */
    public function admin_page_callback() {
        if (isset($this->admin)) {
            $this->admin->render_settings_page();
        }
    }
    
    /**
     * Admin init
     */
    public function admin_init() {
        if (isset($this->admin)) {
            $this->admin->init_settings();
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts($hook) {
        if ('settings_page_fbpixel-capi-settings' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'fbpixel-capi-admin',
            FBPIXEL_CAPI_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            FBPIXEL_CAPI_VERSION
        );
        
        wp_enqueue_script(
            'fbpixel-capi-admin',
            FBPIXEL_CAPI_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            FBPIXEL_CAPI_VERSION,
            true
        );
    }
    
    /**
     * Track page view event
     */
    public function track_page_view() {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_page_view();
        }
    }

    /**
     * Ensure Facebook cookies exist
     */
    public function ensure_facebook_cookies() {
        FBPixel_CAPI_Helpers::ensure_fbp();
        FBPixel_CAPI_Helpers::ensure_fbc();
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules
     * @return array
     */
    public function add_cron_schedules($schedules) {
        if (!isset($schedules['fbpixel_capi_five_minutes'])) {
            $schedules['fbpixel_capi_five_minutes'] = array(
                'interval' => 300,
                'display' => __('Every 5 Minutes', 'w3-pixel-capi')
            );
        }
        return $schedules;
    }

    /**
     * Process queued events
     */
    public function process_queue() {
        if (isset($this->api_client)) {
            $this->api_client->process_queue();
        }
    }
    
    /**
     * Track deferred events
     */
    public function track_deferred_events() {
        if (isset($this->event_tracker)) {
            $this->event_tracker->process_deferred_events();
        }
    }
    
    /**
     * Track add to cart event (WooCommerce)
     */
    public function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_add_to_cart($product_id, $quantity, $variation_id);
        }
    }
    
    /**
     * Track purchase event (WooCommerce)
     */
    public function track_purchase($order_id) {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_purchase($order_id);
        }
    }
    
    /**
     * Track initiate checkout event (WooCommerce)
     */
    public function track_initiate_checkout() {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_initiate_checkout();
        }
    }
    
    /**
     * Track view content event (WooCommerce product pages)
     */
    public function track_view_content() {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_view_content();
        }
    }

    /**
     * Output Facebook Pixel base code for browser-side tracking
     */
    public function output_pixel_base_code() {
        if (is_admin()) {
            return;
        }

        $settings = get_option('fbpixel_capi_settings', array());
        if (empty($settings['pixel_id'])) {
            return;
        }

        $pixel_id = esc_js($settings['pixel_id']);
        $pageview_enabled = !empty($settings['enabled_events']['PageView']);
        ?>
        <!-- Facebook Pixel Base Code (W3 Pixel CAPI) -->
        <script>
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
        n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
        document,'script','https://connect.facebook.net/en_US/fbevents.js');
        fbq('init','<?php echo $pixel_id; ?>');
        </script>
        <?php if ($pageview_enabled): ?>
        <noscript>
            <img height="1" width="1" style="display:none"
                 src="https://www.facebook.com/tr?id=<?php echo esc_attr($settings['pixel_id']); ?>&ev=PageView&noscript=1" />
        </noscript>
        <?php endif; ?>
        <!-- End Facebook Pixel Base Code -->
        <?php
    }

    /**
     * Output browser-side events with matching parameters and event IDs
     */
    public function output_browser_events() {
        if (is_admin()) {
            return;
        }

        if (!isset($this->event_tracker)) {
            return;
        }

        $settings = get_option('fbpixel_capi_settings', array());
        if (empty($settings['pixel_id'])) {
            return;
        }

        $events = $this->event_tracker->get_browser_events();
        if (empty($events)) {
            return;
        }

        $events_json = wp_json_encode($events);
        ?>
        <!-- Facebook Pixel Events (W3 Pixel CAPI) -->
        <script>
        (function(){
            if (!window.fbq) { return; }
            var events = <?php echo $events_json; ?> || [];
            for (var i = 0; i < events.length; i++) {
                var evt = events[i] || {};
                if (!evt.event_name) { continue; }
                var params = evt.custom_data || {};
                var eventId = evt.event_id || '';
                if (eventId) {
                    fbq('track', evt.event_name, params, {eventID: eventId});
                } else {
                    fbq('track', evt.event_name, params);
                }
            }
        })();
        </script>
        <!-- End Facebook Pixel Events -->
        <?php
    }
}

// Initialize the plugin
FacebookPixelCAPI::get_instance();

