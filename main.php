<?php
/*
Plugin Name: ZC API
Description: Synchronizuje skladovou dostupnost produktů z API Z Portal.
Version: 2.0
Author: Bohuslav Sedláček
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class ZC_API_Sync {
    private $api_url = 'https://api.zcportal.cz/public/graphql';
    private $log = [];
    private $batch_size = 100;
    private $api_limit_wait = 3600; // Čekání 1 hodinu (3600 sekund) při dosažení limitu API
    private $max_log_entries = 100;

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_action('wp_ajax_zc_get_sync_status', [$this, 'get_sync_status']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        if (! wp_next_scheduled('zc_sync_stock_price')) {
            wp_schedule_event(time(), 'daily', 'zc_sync_stock_price');
        }

        add_action('zc_sync_stock_price', [$this, 'sync_stock_price']);
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_zc_api') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'zc_api_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zc_api_nonce')
        ]);
    }

    public function add_admin_menu() {
        add_menu_page(
            'ZC API', 
            'ZC API', 
            'manage_options', 
            'zc_api', 
            [$this, 'settings_page'],
            'dashicons-update',
            100
        );
    }

    public function settings_init() {
        register_setting('zc_api_settings', 'zc_api_secure_key');
        register_setting('zc_api_settings', 'zc_api_debug_log');
        register_setting('zc_api_settings', 'zc_api_sync_status');

        add_settings_section(
            'zc_api_section',
            __('API Nastavení', 'zc_api'),
            null,
            'zc_api_settings'
        );

        add_settings_field(
            'zc_api_secure_key',
            __('Secure Key', 'zc_api'),
            [$this, 'secure_key_render'],
            'zc_api_settings',
            'zc_api_section'
        );
    }

    public function secure_key_render() {
        $value = get_option('zc_api_secure_key', '');
        echo "<input type='text' name='zc_api_secure_key' value='" . esc_attr($value) . "' style='width: 400px;'>";
        echo "<p class='description'>Zadejte váš secure key z ZC Portal API</p>";
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>ZC API Nastavení</h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('zc_api_settings');
                do_settings_sections('zc_api_settings');
                submit_button('Uložit nastavení');
                ?>
            </form>

            <hr>

            <h2>Synchronizace skladových zásob</h2>
           
            <form method="post" id="sync-form">
                <?php wp_nonce_field('zc_api_actions', 'zc_api_nonce'); ?>
                <?php submit_button('Synchronizovat nyní', 'primary', 'zc_api_sync_now', false); ?>
                <?php submit_button('Vymazat log', 'secondary', 'zc_api_clear_log', false); ?>
                <?php submit_button('Zastavit synchronizaci', 'delete', 'zc_api_stop_sync', false); ?>
            </form>

            <div id="sync-status" style="margin-top: 20px; padding: 10px; background: #f1f1f1; border-radius: 5px;">
                <p>Načítání stavu synchronizace...</p>
            </div>

            <script>
            jQuery(document).ready(function($) {
                function fetchStatus() {
                    $.post(zc_api_ajax.ajax_url, {
                        action: 'zc_get_sync_status',
                        nonce: zc_api_ajax.nonce
                    }, function(response) {
                        if (response.success) {
                            $('#sync-status').html(response.data);
                        }
                    });
                }
                
                // Initial fetch
                fetchStatus();
                
                // Update every 5 seconds
                setInterval(fetchStatus, 5000);
            });
            </script>
        </div>
        <?php

        // Handle form submissions
        if (isset($_POST['zc_api_nonce']) && wp_verify_nonce($_POST['zc_api_nonce'], 'zc_api_actions')) {
            if (isset($_POST['zc_api_sync_now'])) {
                update_option('zc_api_sync_status', 'running');
                wp_schedule_single_event(time(), 'zc_sync_stock_price');
                echo '<div class="notice notice-success"><p>Synchronizace byla spuštěna na pozadí.</p></div>';
            }

            if (isset($_POST['zc_api_clear_log'])) {
                delete_option('zc_api_debug_log');
                echo '<div class="notice notice-success"><p>Log byl úspěšně vymazán.</p></div>';
            }

            if (isset($_POST['zc_api_stop_sync'])) {
                update_option('zc_api_sync_status', 'stopped');
                echo '<div class="notice notice-success"><p>Synchronizace byla zastavena.</p></div>';
            }
        }
    }

    public function get_sync_status() {
        // Verify nonce and capabilities
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'zc_api_nonce')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $log = get_option('zc_api_debug_log', []);
        $status = get_option('zc_api_sync_status', 'stopped');
        
        $status_html = '<div style="margin-bottom: 20px;">';
        if ($status === 'running') {
            $status_html .= '<span style="color: #0073aa; font-weight: bold;">⏳ Probíhá synchronizace skladových zásob...</span>';
        } else {
            $status_html .= '<span style="color: #46b450; font-weight: bold;">✓ Synchronizace je neaktivní</span>';
        }
        $status_html .= '</div>';
        
        $log_html = '<h3>Poslední záznamy logu</h3>';
        if (empty($log)) {
            $log_html .= '<p>Log je prázdný.</p>';
        } else {
            $log_html .= '<ul style="max-height: 300px; overflow-y: auto; background: white; padding: 10px; border: 1px solid #ddd;">';
            $recent_logs = array_slice(array_reverse($log), 0, 20);
            foreach ($recent_logs as $entry) {
                $log_html .= '<li style="margin-bottom: 5px;">' . esc_html($entry) . '</li>';
            }
            $log_html .= '</ul>';
        }

        wp_send_json_success($status_html . $log_html);
    }

    private function add_to_log($message) {
        $this->log[] = date('Y-m-d H:i:s') . ' - ' . $message;
        
        // Keep only last entries to prevent memory issues
        if (count($this->log) > $this->max_log_entries) {
            $this->log = array_slice($this->log, -$this->max_log_entries);
        }
        
        update_option('zc_api_debug_log', $this->log);
    }

    public function get_token() {
        $secure_key = get_option('zc_api_secure_key', '');
        if (empty($secure_key)) {
            $this->add_to_log('ERROR: Secure Key není nastaven.');
            return null;
        }

        $query = 'mutation RequestToken {
            requestToken(input: {secure: "' . esc_sql($secure_key) . '", scope: "products"}) {
                token
            }
        }';
        
        $response = $this->graphql_request($query);
        
        if (!$response) {
            $this->add_to_log('ERROR: Nelze se připojit k API.');
            return null;
        }
        
        if (isset($response['errors'])) {
            $this->add_to_log('ERROR: API vrátilo chybu při získávání tokenu: ' . json_encode($response['errors']));
            return null;
        }
        
        $token = $response['data']['requestToken']['token'] ?? null;
        if ($token) {
            $this->add_to_log('Token úspěšně získán.');
        } else {
            $this->add_to_log('ERROR: Token nebyl vrácen API.');
        }
        
        return $token;
    }

    public function sync_stock_price() {
        // Check if sync was manually stopped
        if (get_option('zc_api_sync_status') === 'stopped') {
            $this->add_to_log('Synchronizace byla manuálně zastavena.');
            return;
        }

        update_option('zc_api_sync_status', 'running');
        $this->add_to_log('=== Začátek synchronizace skladových zásob ===');

        $token = $this->get_token();
        if (!$token) {
            $this->add_to_log('ERROR: Synchronizace přerušena - nelze získat token.');
            update_option('zc_api_sync_status', 'stopped');
            return;
        }

        $offset = 0;
        $total_updated = 0;
        $total_errors = 0;
        
        do {
            // Check if sync should stop
            if (get_option('zc_api_sync_status') === 'stopped') {
                $this->add_to_log('Synchronizace byla zastavena uživatelem.');
                break;
            }

            $query = 'query Products {
                products(pagination: {limit: ' . $this->batch_size . ', offset: ' . $offset . '}) {
                    edges {
                        barcodes
                        supplies { availability }
                    }
                }
            }';

            $products = $this->graphql_request($query, $token);
            
            if (!$products) {
                $this->add_to_log('ERROR: Chyba připojení k API.');
                break;
            }
            
            if (isset($products['errors'])) {
                // Check for rate limit error
                $error_message = json_encode($products['errors']);
                $this->add_to_log("ERROR: API vrátilo chybu: " . $error_message);
                
                if (strpos($error_message, 'rate limit') !== false || strpos($error_message, 'too many requests') !== false) {
                    $this->add_to_log("RATE LIMIT: Čekání " . ($this->api_limit_wait / 60) . " minut...");
                    sleep($this->api_limit_wait);
                    continue;
                }
                break;
            }

            $edges = $products['data']['products']['edges'] ?? [];
            $batch_count = count($edges);
            
            if ($batch_count === 0) {
                $this->add_to_log('Všechny produkty byly zpracovány.');
                break;
            }
            
            $this->add_to_log("Zpracovávání dávky: " . ($offset + 1) . " - " . ($offset + $batch_count));

            foreach ($edges as $product) {
                $result = $this->update_wc_stock($product);
                if ($result) {
                    $total_updated++;
                } else {
                    $total_errors++;
                }
            }

            $offset += $this->batch_size;
            
            // Small delay to prevent overwhelming the server
            sleep(1);
            
        } while ($batch_count === $this->batch_size);

        $this->add_to_log("=== Konec synchronizace skladových zásob ===");
        $this->add_to_log("Celkem aktualizováno: $total_updated produktů");
        if ($total_errors > 0) {
            $this->add_to_log("Celkem chyb: $total_errors");
        }
        
        update_option('zc_api_sync_status', 'stopped');
    }

    private function update_wc_stock($product) {
        $sku = $product['barcodes'][0] ?? null;
        if (!$sku) {
            return false;
        }

        $availability_code = $product['supplies']['availability'] ?? '';
		$availability = ($availability_code === 'A') ? 'instock' : 'outofstock';


        $wc_product_id = wc_get_product_id_by_sku($sku);
        if (!$wc_product_id) {
            return false;
        }

        try {
            $wc_product = wc_get_product($wc_product_id);
            if (!$wc_product) {
                return false;
            }

            // Store old stock status for comparison
            $old_stock_status = $wc_product->get_stock_status();

            // Update only stock-related fields - NO PRICE CHANGES
            $wc_product->set_stock_status($availability);
            
            if ($wc_product->get_manage_stock()) {
                $wc_product->set_stock_quantity($availability === 'instock' ? 100 : 0);
            }
            
            $wc_product->save();

            // Log only if there were changes
            if ($old_stock_status != $availability) {
                $this->add_to_log("Aktualizováno SKU: $sku | Dostupnost: $old_stock_status → $availability");
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->add_to_log("ERROR při aktualizaci SKU $sku: " . $e->getMessage());
            return false;
        }
    }

    private function graphql_request($query, $token = null) {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        
        if ($token) {
            $headers['X-Auth-Token'] = $token;
        }
        
        $args = [
            'body' => json_encode(['query' => $query]),
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true,
        ];
        
        $response = wp_remote_post($this->api_url, $args);
        
        if (is_wp_error($response)) {
            error_log('ZC API Error: ' . $response->get_error_message());
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('ZC API JSON Decode Error: ' . json_last_error_msg());
            return null;
        }
        
        return $decoded;
    }

    public static function activate() {
        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('zc_sync_stock_price')) {
            wp_schedule_event(time(), 'daily', 'zc_sync_stock_price');
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('zc_sync_stock_price');
        delete_option('zc_api_debug_log');
        delete_option('zc_api_sync_status');
    }
}

// Initialize plugin
new ZC_API_Sync();

// Activation/Deactivation hooks
register_activation_hook(__FILE__, ['ZC_API_Sync', 'activate']);
register_deactivation_hook(__FILE__, ['ZC_API_Sync', 'deactivate']);
