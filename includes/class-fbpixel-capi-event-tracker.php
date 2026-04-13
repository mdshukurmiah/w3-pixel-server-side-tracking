<?php
/**
 * W3 Pixel CAPI Event Tracker
 * 
 * Handles event tracking and data collection
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FBPixel_CAPI_Event_Tracker {
    
    /**
     * API client instance
     */
    private $api_client;
    
    /**
     * Plugin settings
     */
    private $settings;
    
    /**
     * Deferred events queue
     */
    private $deferred_events = array();

    /**
     * Browser-side events queue (for Pixel)
     */
    private $browser_events = array();
    
    /**
     * Constructor
     * 
     * @param FBPixel_CAPI_API_Client $api_client API client instance
     */
    public function __construct($api_client) {
        $this->api_client = $api_client;
        $this->settings = get_option('fbpixel_capi_settings', array());
    }
    
    /**
     * Track PageView event
     */
    public function track_page_view() {
        if (!$this->is_event_enabled('PageView')) {
            return;
        }
        
        $event_data = array(
            'event_name' => 'PageView',
            'event_time' => time(),
            'action_source' => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data' => $this->get_user_data(),
            'custom_data' => array(),
            'event_id' => $this->generate_event_id('PageView')
        );
        
        // Add referrer if available
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $event_data['referrer_url'] = esc_url_raw($_SERVER['HTTP_REFERER']);
        }

        $this->queue_browser_event($event_data);
        $this->send_event($event_data);
    }
    
    /**
     * Track ViewContent event (WooCommerce product pages)
     */
    public function track_view_content() {
        if (!empty($this->settings['debug_mode'])) {
            error_log('Facebook Pixel CAPI: track_view_content called');
        }
        
        if (!$this->is_event_enabled('ViewContent')) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: ViewContent event is not enabled in settings');
            }
            return;
        }
        
        if (!class_exists('WooCommerce')) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: WooCommerce is not active');
            }
            return;
        }
        
        // Check if we're on a product page
        if (!is_product()) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: Not on a product page, skipping ViewContent');
            }
            return;
        }
        
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: No valid product found on product page');
            }
            return;
        }
        
        $product_id = $product->get_id();
        $product_price = $product->get_price();
        
        // Handle variable products
        if ($product->is_type('variable')) {
            $variation_prices = $product->get_variation_prices();
            if (!empty($variation_prices['price'])) {
                $product_price = min($variation_prices['price']); // Use minimum price for variable products
            }
        }
        
        $event_data = array(
            'event_name' => 'ViewContent',
            'event_time' => time(),
            'action_source' => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data' => $this->get_user_data(),
            'custom_data' => array(
                'content_ids' => array((string) $product_id),
                'content_type' => 'product',
                'content_name' => $product->get_name(),
                'content_category' => $this->get_product_categories($product_id),
                'value' => (float) $product_price,
                'currency' => get_woocommerce_currency(),
                'contents' => array(
                    array(
                        'id' => (string) $product_id,
                        'quantity' => 1,
                        'item_price' => (float) $product_price
                    )
                )
            ),
            'event_id' => $this->generate_event_id('ViewContent', $product_id)
        );
        
        // Add additional product data if available
        if ($product->get_sku()) {
            $event_data['custom_data']['content_sku'] = $product->get_sku();
        }
        
        // Add brand if available (from product attributes or custom fields)
        $brand = $this->get_product_brand($product);
        if ($brand) {
            $event_data['custom_data']['brand'] = $brand;
        }
        
        if (!empty($this->settings['debug_mode'])) {
            error_log('Facebook Pixel CAPI: ViewContent event data prepared for product ID: ' . $product_id);
        }

        $this->queue_browser_event($event_data);
        $this->send_event($event_data);
    }
    
    /**
     * Track AddToCart event (WooCommerce)
     * 
     * @param int $product_id Product ID
     * @param int $quantity Quantity
     * @param int $variation_id Variation ID (optional)
     */
    public function track_add_to_cart($product_id, $quantity = 1, $variation_id = 0) {
        if (!empty($this->settings['debug_mode'])) {
            error_log('Facebook Pixel CAPI: track_add_to_cart called with product_id=' . $product_id . ', quantity=' . $quantity . ', variation_id=' . $variation_id);
        }
        
        if (!$this->is_event_enabled('AddToCart')) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: AddToCart event is not enabled in settings');
            }
            return;
        }
        
        if (!class_exists('WooCommerce')) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: WooCommerce is not active');
            }
            return;
        }
        
        $product = wc_get_product($variation_id ? $variation_id : $product_id);
        if (!$product) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: Could not load product with ID ' . ($variation_id ? $variation_id : $product_id));
            }
            return;
        }
        
        $event_data = array(
            'event_name' => 'AddToCart',
            'event_time' => time(),
            'action_source' => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data' => $this->get_user_data(),
            'custom_data' => array(
                'content_ids' => array((string) $product_id),
                'content_type' => 'product',
                'value' => (float) $product->get_price(),
                'currency' => get_woocommerce_currency(),
                'content_name' => $product->get_name(),
                'content_category' => $this->get_product_categories($product_id),
                'num_items' => (int) $quantity,
                'contents' => array(
                    array(
                        'id' => (string) $product_id,
                        'quantity' => (int) $quantity,
                        'item_price' => (float) $product->get_price()
                    )
                )
            ),
            'event_id' => $this->generate_event_id('AddToCart', $product_id . '_' . $quantity)
        );

        // Store for browser-side tracking (covers AJAX add-to-cart flows)
        $this->queue_browser_event($event_data, true);
        $this->send_event($event_data);
    }
    
    /**
     * Track Purchase event (WooCommerce)
     * 
     * @param int $order_id Order ID
     */
    public function track_purchase($order_id) {
        if (!empty($this->settings['debug_mode'])) {
            error_log('Facebook Pixel CAPI: track_purchase called with order_id=' . $order_id);
        }
        
        if (!$this->is_event_enabled('Purchase')) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: Purchase event is not enabled in settings');
            }
            return;
        }
        
        if (!class_exists('WooCommerce')) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: WooCommerce is not active');
            }
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: Could not load order with ID ' . $order_id);
            }
            return;
        }
        
        // Get order items
        $content_ids = array();
        $contents = array();
        $total_quantity = 0;
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $quantity = $item->get_quantity();
            $price = $item->get_total() / $quantity;
            
            $content_ids[] = (string) $product_id;
            $contents[] = array(
                'id' => (string) $product_id,
                'quantity' => (int) $quantity,
                'item_price' => (float) $price
            );
            $total_quantity += $quantity;
        }
        
        $event_data = array(
            'event_name' => 'Purchase',
            'event_time' => time(),
            'action_source' => 'website',
            'event_source_url' => $order->get_checkout_order_received_url(),
            'user_data' => $this->get_user_data_from_order($order),
            'custom_data' => array(
                'content_ids' => $content_ids,
                'content_type' => 'product',
                'contents' => $contents,
                'value' => (float) $order->get_total(),
                'currency' => $order->get_currency(),
                'num_items' => $total_quantity,
                'order_id' => (string) $order_id
            ),
            'event_id' => $this->generate_event_id('Purchase', $order_id)
        );

        $this->queue_browser_event($event_data);
        $this->send_event($event_data);
    }
    
    /**
     * Track InitiateCheckout event (WooCommerce)
     */
    public function track_initiate_checkout() {
        if (!empty($this->settings['debug_mode'])) {
            error_log('Facebook Pixel CAPI: track_initiate_checkout called');
        }
        
        if (!$this->is_event_enabled('InitiateCheckout')) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: InitiateCheckout event is not enabled in settings');
            }
            return;
        }
        
        if (!class_exists('WooCommerce')) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: WooCommerce is not active');
            }
            return;
        }
        
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI: Cart is empty or not available');
            }
            return;
        }
        
        // Get cart items
        $content_ids = array();
        $contents = array();
        $total_quantity = 0;
        
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $quantity = $cart_item['quantity'];
            $price = $cart_item['line_total'] / $quantity;
            
            $content_ids[] = (string) $product_id;
            $contents[] = array(
                'id' => (string) $product_id,
                'quantity' => (int) $quantity,
                'item_price' => (float) $price
            );
            $total_quantity += $quantity;
        }
        
        $event_data = array(
            'event_name' => 'InitiateCheckout',
            'event_time' => time(),
            'action_source' => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data' => $this->get_user_data(),
            'custom_data' => array(
                'content_ids' => $content_ids,
                'content_type' => 'product',
                'contents' => $contents,
                'value' => (float) $cart->get_total('edit'),
                'currency' => get_woocommerce_currency(),
                'num_items' => $total_quantity
            ),
            'event_id' => $this->generate_event_id('InitiateCheckout', md5(serialize($content_ids)))
        );
        
        // Defer this event to be sent in footer to ensure cart data is complete
        $this->deferred_events[] = $event_data;

        // Queue browser event to fire in footer
        $this->queue_browser_event($event_data);
    }
    
    /**
     * Process deferred events
     */
    public function process_deferred_events() {
        if (empty($this->deferred_events)) {
            return;
        }
        
        foreach ($this->deferred_events as $event_data) {
            $this->send_event($event_data);
        }
        
        $this->deferred_events = array();
    }

    /**
     * Get queued browser-side events (Pixel)
     *
     * @return array
     */
    public function get_browser_events() {
        $events = $this->browser_events;

        // Merge and clear any WooCommerce-session queued events
        if (class_exists('WooCommerce') && function_exists('WC')) {
            $session = WC()->session;
            if ($session) {
                $pending = $session->get('fbpixel_capi_browser_events', array());
                if (!empty($pending) && is_array($pending)) {
                    $events = array_merge($events, $pending);
                    $session->set('fbpixel_capi_browser_events', array());
                }
            }
        }

        return $events;
    }
    
    /**
     * Send event to API
     * 
     * @param array $event_data Event data
     */
    private function send_event($event_data) {
        // Validate required fields
        if (empty($event_data['event_name']) || empty($event_data['event_time'])) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI Error: Missing required event data - event_name or event_time');
            }
            return;
        }
        
        // Check if we have valid API credentials
        if (empty($this->settings['pixel_id']) || empty($this->settings['access_token'])) {
            if (!empty($this->settings['debug_mode'])) {
                error_log('Facebook Pixel CAPI Error: Missing Pixel ID or Access Token');
            }
            return;
        }
        
        // Log event attempt if debug mode is enabled
        if (!empty($this->settings['debug_mode'])) {
            error_log('Facebook Pixel CAPI: Attempting to send ' . $event_data['event_name'] . ' event');
            error_log('Facebook Pixel CAPI: Event data: ' . json_encode($event_data, JSON_PRETTY_PRINT));
        }
        
        // Send to API
        $result = $this->api_client->send_event($event_data);
        
        // Log result if debug mode is enabled
        if (!empty($this->settings['debug_mode'])) {
            if (is_wp_error($result)) {
                error_log('Facebook Pixel CAPI Error: ' . $result->get_error_message());
                error_log('Facebook Pixel CAPI Error Details: ' . print_r($result->get_error_data(), true));
            } else {
                error_log('Facebook Pixel CAPI Success: Event ' . $event_data['event_name'] . ' sent successfully');
                if (is_array($result) && isset($result['response'])) {
                    error_log('Facebook Pixel CAPI Response: ' . json_encode($result['response'], JSON_PRETTY_PRINT));
                }
            }
        }
        
        return $result;
    }

    /**
     * Queue browser-side event for Pixel tracking
     *
     * @param array $event_data
     * @param bool $store_in_session
     */
    private function queue_browser_event($event_data, $store_in_session = false) {
        if (empty($event_data['event_name'])) {
            return;
        }

        $browser_event = array(
            'event_name' => $event_data['event_name'],
            'event_id' => isset($event_data['event_id']) ? $event_data['event_id'] : '',
            'custom_data' => isset($event_data['custom_data']) ? $event_data['custom_data'] : array()
        );

        if ($store_in_session && class_exists('WooCommerce') && function_exists('WC')) {
            $session = WC()->session;
            if ($session) {
                $existing = $session->get('fbpixel_capi_browser_events', array());
                if (!is_array($existing)) {
                    $existing = array();
                }
                $existing[] = $browser_event;
                $session->set('fbpixel_capi_browser_events', $existing);
                return;
            }
        }

        $this->browser_events[] = $browser_event;
    }
    
    /**
     * Get user data for event
     * 
     * @return array User data
     */
    private function get_user_data() {
        $user_data = array(
            'client_ip_address' => FBPixel_CAPI_Helpers::get_client_ip(),
            'client_user_agent' => FBPixel_CAPI_Helpers::get_user_agent()
        );
        
        // Ensure cookies and add Facebook click/browser IDs
        $fbc = FBPixel_CAPI_Helpers::ensure_fbc();
        if (!empty($fbc)) {
            $user_data['fbc'] = $fbc;
        }

        $fbp = FBPixel_CAPI_Helpers::ensure_fbp();
        if (!empty($fbp)) {
            $user_data['fbp'] = $fbp;
        }
        
        // Add user email if logged in
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            if (!empty($current_user->user_email)) {
                $hashed = FBPixel_CAPI_Helpers::normalize_and_hash($current_user->user_email);
                if ($hashed) {
                    $user_data['em'] = $hashed;
                }
            }
            
            // Add user names if available
            if (!empty($current_user->first_name)) {
                $hashed = FBPixel_CAPI_Helpers::normalize_and_hash($current_user->first_name);
                if ($hashed) {
                    $user_data['fn'] = $hashed;
                }
            }
            
            if (!empty($current_user->last_name)) {
                $hashed = FBPixel_CAPI_Helpers::normalize_and_hash($current_user->last_name);
                if ($hashed) {
                    $user_data['ln'] = $hashed;
                }
            }
        }

        // Add WooCommerce customer data when available
        if (class_exists('WooCommerce')) {
            $customer = WC()->customer;
            if ($customer) {
                $customer_data = FBPixel_CAPI_Helpers::get_wc_customer_data($customer);
                $user_data = array_merge($user_data, $customer_data);
            }
        }

        // External ID (hashed unique user ID)
        $user_id = get_current_user_id();
        if (!empty($user_id)) {
            $external_id = FBPixel_CAPI_Helpers::normalize_and_hash((string) $user_id);
            if ($external_id) {
                $user_data['external_id'] = $external_id;
            }
        } elseif (class_exists('WooCommerce') && !empty($customer) && method_exists($customer, 'get_id')) {
            $customer_id = $customer->get_id();
            if (!empty($customer_id)) {
                $external_id = FBPixel_CAPI_Helpers::normalize_and_hash((string) $customer_id);
                if ($external_id) {
                    $user_data['external_id'] = $external_id;
                }
            }
        }

        $user_data = array_filter($user_data, function ($value) {
            return $value !== null && $value !== '';
        });
        
        return $user_data;
    }
    
    /**
     * Get user data from WooCommerce order
     * 
     * @param WC_Order $order Order object
     * @return array User data
     */
    private function get_user_data_from_order($order) {
        $user_data = array(
            'client_ip_address' => $order->get_customer_ip_address(),
            'client_user_agent' => FBPixel_CAPI_Helpers::get_user_agent()
        );
        
        // Ensure cookies and add Facebook click/browser IDs
        $fbc = FBPixel_CAPI_Helpers::ensure_fbc();
        if (!empty($fbc)) {
            $user_data['fbc'] = $fbc;
        }

        $fbp = FBPixel_CAPI_Helpers::ensure_fbp();
        if (!empty($fbp)) {
            $user_data['fbp'] = $fbp;
        }
        
        // Add customer data from order
        $billing_email = $order->get_billing_email();
        if (!empty($billing_email)) {
            $hashed = FBPixel_CAPI_Helpers::normalize_and_hash($billing_email);
            if ($hashed) {
                $user_data['em'] = $hashed;
            }
        }
        
        $billing_first_name = $order->get_billing_first_name();
        if (!empty($billing_first_name)) {
            $hashed = FBPixel_CAPI_Helpers::normalize_and_hash($billing_first_name);
            if ($hashed) {
                $user_data['fn'] = $hashed;
            }
        }
        
        $billing_last_name = $order->get_billing_last_name();
        if (!empty($billing_last_name)) {
            $hashed = FBPixel_CAPI_Helpers::normalize_and_hash($billing_last_name);
            if ($hashed) {
                $user_data['ln'] = $hashed;
            }
        }
        
        $billing_phone = $order->get_billing_phone();
        if (!empty($billing_phone)) {
            $hashed = FBPixel_CAPI_Helpers::normalize_and_hash_phone($billing_phone);
            if ($hashed) {
                $user_data['ph'] = $hashed;
            }
        }
        
        $billing_city = $order->get_billing_city();
        if (!empty($billing_city)) {
            $hashed = FBPixel_CAPI_Helpers::normalize_and_hash($billing_city);
            if ($hashed) {
                $user_data['ct'] = $hashed;
            }
        }
        
        $billing_state = $order->get_billing_state();
        if (!empty($billing_state)) {
            $hashed = FBPixel_CAPI_Helpers::normalize_and_hash($billing_state);
            if ($hashed) {
                $user_data['st'] = $hashed;
            }
        }
        
        $billing_postcode = $order->get_billing_postcode();
        if (!empty($billing_postcode)) {
            $hashed = FBPixel_CAPI_Helpers::normalize_and_hash($billing_postcode);
            if ($hashed) {
                $user_data['zp'] = $hashed;
            }
        }
        
        $billing_country = $order->get_billing_country();
        if (!empty($billing_country)) {
            $hashed = FBPixel_CAPI_Helpers::normalize_and_hash($billing_country);
            if ($hashed) {
                $user_data['country'] = $hashed;
            }
        }

        $customer_id = $order->get_customer_id();
        if (!empty($customer_id)) {
            $external_id = FBPixel_CAPI_Helpers::normalize_and_hash((string) $customer_id);
            if ($external_id) {
                $user_data['external_id'] = $external_id;
            }
        }

        $user_data = array_filter($user_data, function ($value) {
            return $value !== null && $value !== '';
        });
        
        return $user_data;
    }
    
    /**
     * Get product brand
     * 
     * @param WC_Product $product Product object
     * @return string Product brand
     */
    private function get_product_brand($product) {
        $brand = '';
        
        // Try to get brand from product attributes
        $attributes = $product->get_attributes();
        
        // Common brand attribute names
        $brand_attributes = array('brand', 'pa_brand', 'product_brand', 'pa_product_brand');
        
        foreach ($brand_attributes as $brand_attr) {
            if (isset($attributes[$brand_attr])) {
                $attribute = $attributes[$brand_attr];
                if ($attribute->is_taxonomy()) {
                    $terms = wp_get_post_terms($product->get_id(), $attribute->get_name());
                    if (!empty($terms) && !is_wp_error($terms)) {
                        $brand = $terms[0]->name;
                        break;
                    }
                } else {
                    $brand = $attribute->get_options()[0] ?? '';
                    if ($brand) {
                        break;
                    }
                }
            }
        }
        
        // Try custom fields if no brand attribute found
        if (empty($brand)) {
            $custom_brand_fields = array('_brand', '_product_brand', 'brand', 'product_brand');
            foreach ($custom_brand_fields as $field) {
                $brand = get_post_meta($product->get_id(), $field, true);
                if (!empty($brand)) {
                    break;
                }
            }
        }
        
        return sanitize_text_field($brand);
    }
    
    /**
     * Get product categories
     * 
     * @param int $product_id Product ID
     * @return string Product categories
     */
    private function get_product_categories($product_id) {
        $terms = get_the_terms($product_id, 'product_cat');
        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }
        
        $categories = array();
        foreach ($terms as $term) {
            $categories[] = $term->name;
        }
        
        return implode(', ', $categories);
    }
    
    /**
     * Generate unique event ID for deduplication
     * 
     * @param string $event_name Event name
     * @param string $additional_data Additional data for uniqueness
     * @return string Event ID
     */
    private function generate_event_id($event_name, $additional_data = '') {
        $data = $event_name . '_' . time() . '_' . $additional_data . '_' . FBPixel_CAPI_Helpers::get_client_ip();
        $event_id = substr(md5($data), 0, 16);
        return apply_filters('fbpixel_capi_event_id', $event_id, $event_name, $additional_data);
    }
    
    /**
     * Check if event is enabled
     * 
     * @param string $event_name Event name
     * @return bool Whether event is enabled
     */
    private function is_event_enabled($event_name) {
        if (empty($this->settings['enabled_events'])) {
            return false;
        }
        
        return !empty($this->settings['enabled_events'][$event_name]);
    }
}

