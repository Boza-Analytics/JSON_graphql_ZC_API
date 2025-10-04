# ZC API Plugin Documentation

## Overview
The ZC API Plugin synchronizes product stock availability from the Z Portal API to WooCommerce products. It runs scheduled daily syncs and provides manual synchronization options through the WordPress admin interface.

---

## Table of Contents

1. [Installation & Setup](#installation--setup)
2. [Configuration](#configuration)
3. [Features](#features)
4. [Synchronization Process](#synchronization-process)
5. [Admin Interface](#admin-interface)
6. [Technical Details](#technical-details)
7. [Troubleshooting](#troubleshooting)
8. [Security](#security)

---

## Installation & Setup

### Requirements
- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+
- Valid Z Portal API Secure Key

### Installation
1. Upload the plugin file to `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **ZC API** in the admin menu
4. Enter your Secure Key from Z Portal API
5. Save settings

### Initial Configuration
```php
// The plugin automatically schedules daily synchronization upon activation
wp_schedule_event(time(), 'daily', 'zc_sync_stock_price');
```

---

## Configuration

### API Settings

**Secure Key**
- Location: `ZC API > API Nastavení`
- Required: Yes
- Purpose: Authenticates requests to Z Portal API
- Storage: WordPress options table (`zc_api_secure_key`)

### API Endpoint
```php
private $api_url = 'https://api.zcportal.cz/public/graphql';
```

---

## Features

### 1. Automated Daily Synchronization
- Runs once per day via WordPress cron
- Scheduled on plugin activation
- Can be manually triggered from admin panel

### 2. Manual Synchronization
- On-demand sync via admin interface
- Real-time status updates
- Progress tracking

### 3. Stock Status Management
- Maps Z Portal availability codes to WooCommerce stock statuses
- Updates stock quantities for products with stock management enabled
- **Does not modify product prices**

### 4. Batch Processing
- Processes 100 products per batch
- Prevents API overload
- Includes rate limit handling

### 5. Real-time Logging
- Detailed activity log
- Error tracking
- Auto-updating status display (refreshes every 5 seconds)

### 6. Rate Limit Protection
- Automatic detection of API rate limits
- 1-hour wait period when limit reached
- Automatic retry after waiting period

---

## Synchronization Process

### Workflow

1. **Token Acquisition**
```php
// Request authentication token using secure key
mutation RequestToken {
    requestToken(input: {secure: "SECURE_KEY", scope: "products"}) {
        token
    }
}
```

2. **Product Fetching**
```php
// Fetch products in batches of 100
query Products {
    products(pagination: {limit: 100, offset: OFFSET}) {
        edges {
            barcodes
            supplies { availability }
        }
    }
}
```

3. **Stock Update**
- Matches products by SKU (barcode)
- Updates WooCommerce stock status
- Updates stock quantity if stock management is enabled

### Availability Mapping

```php
$availability = ($availability_code === 'A') ? 'instock' : 'outofstock';
```

| Z Portal Code | WooCommerce Status | Stock Quantity |
|---------------|-------------------|----------------|
| A | In Stock | 100 (if managed) |
| Other | Out of Stock | 0 (if managed) |

---

## Admin Interface

### Menu Location
**WordPress Admin > ZC API**

### Available Actions

#### 1. Save Settings
- Saves the Secure Key configuration
- Required before first synchronization

#### 2. Synchronize Now
- Triggers immediate synchronization
- Runs as background process
- Status updates in real-time

#### 3. Clear Log
- Removes all log entries
- Useful for maintenance and debugging

#### 4. Stop Synchronization
- Immediately halts running sync
- Safe to use mid-process
- Can be resumed with "Synchronize Now"

### Status Display

The interface shows:
- **Current Status**: Running or Stopped
- **Recent Log Entries**: Last 20 entries (auto-refreshing)
- **Timestamp**: For each log entry
- **Error Messages**: Highlighted for easy identification

---

## Technical Details

### Class Structure

```php
class ZC_API_Sync {
    private $api_url;           // API endpoint
    private $log;               // Runtime log array
    private $batch_size;        // Products per batch (100)
    private $api_limit_wait;    // Rate limit wait time (3600s)
    private $max_log_entries;   // Maximum log entries (100)
}
```

### Key Methods

#### `get_token()`
Obtains authentication token from Z Portal API.

**Returns:**
- `string` Token on success
- `null` on failure

#### `sync_stock_price()`
Main synchronization method. Processes all products in batches.

**Process:**
1. Checks if sync was manually stopped
2. Acquires authentication token
3. Fetches products in batches
4. Updates WooCommerce stock status
5. Handles rate limiting
6. Logs all activities

#### `update_wc_stock($product)`
Updates individual WooCommerce product stock.

**Parameters:**
- `$product` (array) Product data from Z Portal API

**Returns:**
- `bool` True on success, false on failure

**Updates:**
- Stock status (instock/outofstock)
- Stock quantity (if stock management enabled)
- **DOES NOT update prices**

#### `graphql_request($query, $token = null)`
Makes GraphQL requests to Z Portal API.

**Parameters:**
- `$query` (string) GraphQL query
- `$token` (string|null) Authentication token

**Returns:**
- `array` Decoded JSON response
- `null` on error

---

## Troubleshooting

### Common Issues

#### 1. "Secure Key není nastaven"
**Solution:** Enter your Secure Key in ZC API settings and save.

#### 2. "Nelze se připojit k API"
**Possible causes:**
- Server firewall blocking outbound connections
- SSL certificate issues
- API endpoint unavailable

**Solution:**
```php
// Check if wp_remote_post is working
$test = wp_remote_post('https://api.zcportal.cz/public/graphql');
if (is_wp_error($test)) {
    echo $test->get_error_message();
}
```

#### 3. Rate Limit Errors
**Symptom:** Synchronization pauses for 1 hour

**Cause:** Too many API requests in short time

**Solution:** Wait for automatic retry or reduce batch size:
```php
private $batch_size = 50; // Reduce from 100
```

#### 4. Products Not Updating
**Check:**
- Product has correct SKU matching Z Portal barcode
- Product exists in WooCommerce
- SKU field is not empty

### Debug Mode

Enable WordPress debug logging:
```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

View logs at: `wp-content/debug.log`

---

## Security

### Data Protection

1. **Secure Key Storage**
   - Stored in WordPress options table
   - Not exposed in frontend
   - Escaped in SQL queries

2. **Nonce Verification**
```php
wp_verify_nonce($_POST['zc_api_nonce'], 'zc_api_actions')
```

3. **Capability Checks**
```php
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
}
```

4. **AJAX Security**
- Nonce validation on all AJAX requests
- Admin-only access
- Sanitized inputs

### Best Practices

1. **Secure Key Protection**
   - Never commit secure key to version control
   - Use environment variables for production
   - Rotate keys periodically

2. **API Communication**
   - Uses SSL/TLS (sslverify: true)
   - 30-second timeout prevents hanging
   - Error logging without exposing sensitive data

3. **Database Operations**
   - Uses WordPress database API
   - Prepared statements via `esc_sql()`
   - Transaction-safe updates

---

## Cron Schedule

### Default Schedule
**Frequency:** Daily
**Hook:** `zc_sync_stock_price`

### Manual Scheduling
```php
// Change to twice daily
wp_clear_scheduled_hook('zc_sync_stock_price');
wp_schedule_event(time(), 'twicedaily', 'zc_sync_stock_price');
```

### Disable Automatic Sync
```php
wp_clear_scheduled_hook('zc_sync_stock_price');
```

---

## Plugin Activation/Deactivation

### On Activation
```php
public static function activate() {
    // Schedule daily cron event
    if (!wp_next_scheduled('zc_sync_stock_price')) {
        wp_schedule_event(time(), 'daily', 'zc_sync_stock_price');
    }
}
```

### On Deactivation
```php
public static function deactivate() {
    // Remove scheduled events
    wp_clear_scheduled_hook('zc_sync_stock_price');
    
    // Clean up options
    delete_option('zc_api_debug_log');
    delete_option('zc_api_sync_status');
    
    // Note: zc_api_secure_key is preserved
}
```

---

## Performance Considerations

### Optimization Features

1. **Batch Processing**
   - Prevents memory overflow
   - Reduces server load
   - Enables progress tracking

2. **Log Rotation**
   - Maximum 100 entries in memory
   - Automatic trimming of old entries
   - Prevents database bloat

3. **Conditional Updates**
   - Only logs when stock status changes
   - Skips products without SKU
   - Validates product existence before update

4. **Rate Limiting**
   - 1-second delay between batches
   - Automatic pause on API limits
   - Prevents server overload

### Server Requirements

**Minimum:**
- PHP memory_limit: 128M
- max_execution_time: 300 seconds
- WordPress memory limit: 128M

**Recommended:**
```php
// wp-config.php
define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');
```

---

## Extending the Plugin

### Custom Hooks

You can add actions before/after sync:

```php
// Before sync starts
add_action('zc_sync_before_start', function() {
    // Your custom code
});

// After sync completes
add_action('zc_sync_after_complete', function($total_updated, $total_errors) {
    // Send notification email, etc.
});
```

### Modify Batch Size

```php
add_filter('zc_api_batch_size', function($size) {
    return 50; // Change from default 100
});
```

---

## Changelog

### Version 2.0
- Complete rewrite for better performance
- Added batch processing
- Improved error handling
- Real-time status updates
- Rate limit protection
- Enhanced logging system
- Stock-only updates (prices preserved)

---

## Support

For issues or questions:
1. Check the log in **ZC API > Synchronization Status**
2. Enable WordPress debug mode
3. Verify Secure Key is correct
4. Test API connection manually
5. Check WooCommerce product SKUs match Z Portal barcodes

---

## License

Proprietary - For use with Z Portal API integration only.

---

## Author

**Bohuslav Sedláček**
