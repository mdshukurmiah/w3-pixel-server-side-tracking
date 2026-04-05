# W3 Pixel Server-Side Tracking

**Contributors:** Md Shukur Miah  
**Tags:** facebook, pixel, conversions api, server-side tracking, woocommerce
**Requires at least:** 5.0  
**Tested up to:** 6.9.4  
**Requires PHP:** 7.4  
**Stable tag:** 1.0.1 
**License:** GPLv2 or later  
**License URI:** [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

Enable Facebook Pixel server-side tracking using the Conversions API (CAPI) for improved tracking accuracy and reliability.

---

## Description

The Facebook Pixel Server-Side Tracking (CAPI) plugin enables you to send Facebook Pixel events directly from your WordPress server to Meta using the Conversions API. This provides more reliable tracking compared to browser-based tracking, especially in an era of increasing privacy restrictions and ad blockers.

### Key Features

- **Server-Side Tracking**: Send events directly from your server to Facebook, bypassing browser limitations
- **WooCommerce Integration**: Automatic tracking of e-commerce events (AddToCart, Purchase, InitiateCheckout)
- **Event Deduplication**: Prevent duplicate events when using both client-side and server-side tracking
- **Privacy Compliant**: Automatically hash sensitive customer data before sending to Facebook
- **Easy Setup**: Simple configuration through WordPress admin interface
- **Debug Mode**: Comprehensive logging for troubleshooting and development
- **Test Connection**: Built-in tool to verify your Conversions API setup

### Supported Events

- `PageView` – Track page visits  
- `ViewContent` – Track product page views (WooCommerce)  
- `AddToCart` – Track when products are added to cart (WooCommerce)  
- `InitiateCheckout` – Track checkout initiation (WooCommerce)  
- `Purchase` – Track completed orders (WooCommerce)

### Why Use Server-Side Tracking?

- **Improved Data Quality**: Not affected by ad blockers or browser restrictions
- **Better Attribution**: More accurate Facebook Ads conversion data
- **Privacy Compliant**: Customer data is hashed before transmission
- **Reliable Tracking**: Events are sent even when JS fails or doesn’t load
- **Future-Proof**: Ready for a cookieless future

---

## Requirements

- WordPress 5.0 or higher  
- PHP 7.4 or higher  
- Facebook Business Manager account  
- Facebook Pixel ID  
- Conversions API Access Token  
- WooCommerce (optional, for e-commerce tracking)

---

## Installation

1. Upload the plugin files to `/wp-content/plugins/w3-pixel-capi`, or install through WordPress plugins screen.
2. Activate the plugin via the ‘Plugins’ screen.
3. Navigate to `Settings > Facebook Pixel CAPI`.
4. Enter your Facebook Pixel ID and CAPI Access Token.
5. Select events to track.
6. Test your connection.

---

## Frequently Asked Questions

### How do I get a Facebook Pixel ID?

1. Go to [Facebook Events Manager](https://business.facebook.com/events_manager)  
2. Create or select a Pixel  
3. Copy the Pixel ID from the details page

### How do I generate a Conversions API Access Token?

1. Open your Pixel in Events Manager  
2. Go to the **Settings** tab  
3. Find **Conversions API** section  
4. Click “Generate access token” under “Set up manually”

### Do I need to remove my existing Facebook Pixel code?

No. This plugin works alongside your existing Pixel and handles event deduplication.

### What data is sent to Facebook?

Standard Pixel events + customer data. Sensitive data is **hashed using SHA256** before sending.

### Does this work with WooCommerce?

Yes! Events like AddToCart, InitiateCheckout, and Purchase are auto-tracked when WooCommerce is active.

### How can I test if the plugin is working?

Use the **Test Connection** tool in plugin settings. Enable **Debug Mode** for API log visibility.

### Is this plugin GDPR compliant?

Yes — sensitive data is hashed. However, you must still handle user consent on your site.

---

## Screenshots

1. Plugin settings page  
2. Event tracking configuration  
3. Debug logs and test connection  
4. WooCommerce integration status  

---

## Changelog

### 1.1.0

- Added `ViewContent` event for product pages  
- Improved product brand detection  
- Enhanced price handling for variable products  
- Added debug logging for `ViewContent`  
- Updated admin UI

### 1.0.0

- Initial release  
- Events: `PageView`, `AddToCart`, `InitiateCheckout`, `Purchase`  
- WooCommerce integration  
- Event deduplication  
- Debug mode  
- Connection test  
- Data hashing

---

## Upgrade Notice

### 1.0.1

Improved EMQ

### 1.0.0

Initial release of the plugin.

---

## Privacy Policy

This plugin sends data to Facebook via Conversions API. Includes:

- Event info (e.g., PageView, Purchase)  
- Hashed customer data (email, phone, etc.)  
- Technical info (IP address, user agent)  
- Product details

**Sensitive data is hashed before transmission.** No plain-text personal info is shared.

For more details, refer to [Facebook’s Privacy Policy](https://www.facebook.com/policy.php) and [Data Processing Terms](https://www.facebook.com/legal/terms/dataprocessing).

---

## Support

For help, visit the [GitHub Issues tab](../../issues) or contact the author via the WordPress plugin page.

---

## Contributing

Open-source contributions welcome! Please report issues or submit pull requests via GitHub.

---

## Credits

Developed by **Md Shukur Miah**  
Facebook CAPI Docs: [https://developers.facebook.com/docs/marketing-api/conversions-api/](https://developers.facebook.com/docs/marketing-api/conversions-api/)
