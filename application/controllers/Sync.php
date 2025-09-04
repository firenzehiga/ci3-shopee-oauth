<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Sync extends CI_Controller {
    
    private $mapping_config;
    
    public function __construct() {
        parent::__construct();
        $this->load->model('Product_model');
        $this->load->library('shopee_signer');
        $this->load->helper(['url', 'file']);
        $this->load->library('session');
        
        // Ensure config directory exists
        if (!is_dir(APPPATH . 'config/sync/')) {
            mkdir(APPPATH . 'config/sync/', 0755, true);
        }
    }
    
    /**
     * Analyze existing database structure
     * GET: sync/analyze_database
     */
    public function analyze_database() {
        try {
            $analysis = $this->Product_model->analyze_database();
            
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => true,
                    'database_analysis' => [
                        'total_tables' => count($analysis),
                        'tables' => $analysis
                    ],
                    'mapping_suggestions' => [
                        'message' => 'Analyze the tables above and create mapping configuration',
                        'required_mappings' => [
                            'product_id' => 'ID produk unik (integer/string)',
                            'product_name' => 'Nama produk',
                            'stock_quantity' => 'Jumlah stock (integer)',
                            'shopee_item_id' => 'ID produk di Shopee (jika ada)',
                            'sku' => 'SKU produk (jika ada)',
                            'last_updated' => 'Timestamp terakhir update stock'
                        ]
                    ],
                    'next_step' => base_url('sync/setup_mapping'),
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Failed to analyze database',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Setup mapping configuration
     * POST: sync/setup_mapping
     */
    public function setup_mapping() {
        try {
            $input = json_decode($this->input->raw_input_stream, true);
            
            if (!$input) {
                throw new Exception('Invalid JSON input');
            }
            
            $required = ['table_name', 'shop_id', 'column_mappings'];
            foreach ($required as $field) {
                if (!isset($input[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }
            
            $mapping_config = [
                'table_name' => $input['table_name'],
                'shop_id' => (int)$input['shop_id'],
                'column_mappings' => $input['column_mappings'],
                'where_condition' => $input['where_condition'] ?? '',
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];
            
            // Save configuration
            $config_file = APPPATH . "config/sync/mapping_{$input['shop_id']}.json";
            if (!write_file($config_file, json_encode($mapping_config, JSON_PRETTY_PRINT))) {
                throw new Exception('Failed to save mapping configuration');
            }
            
            // Test mapping with sample data
            $test_products = $this->Product_model->get_products_for_sync($mapping_config);
            $sample_products = array_slice($test_products, 0, 5);
            
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => true,
                    'message' => 'Mapping configuration saved successfully',
                    'config_file' => $config_file,
                    'sample_mapped_data' => $sample_products,
                    'total_products_found' => count($test_products),
                    'next_steps' => [
                        'Test sync: ' . base_url("sync/test_sync/{$input['shop_id']}"),
                        'Run sync: ' . base_url("sync/run_sync/{$input['shop_id']}"),
                        'Check status: ' . base_url("sync/status/{$input['shop_id']}")
                    ],
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Failed to setup mapping',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Get sync status and statistics
     * GET: sync/status/37419605
     */
    public function status($shop_id) {
        try {
            $this->load_mapping_config($shop_id);
            
            $stats = $this->Product_model->get_sync_stats($this->mapping_config);
            $token_status = $this->get_token_status($shop_id);
            
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => true,
                    'shop_id' => (int)$shop_id,
                    'token_status' => $token_status,
                    'sync_statistics' => $stats,
                    'mapping_config' => [
                        'table_name' => $this->mapping_config['table_name'],
                        'configured_at' => $this->mapping_config['created_at'] ?? 'Unknown'
                    ],
                    'available_actions' => [
                        'test_sync' => base_url("sync/test_sync/{$shop_id}"),
                        'run_sync' => base_url("sync/run_sync/{$shop_id}"),
                        'sync_product' => base_url("sync/sync_product/{$shop_id}/PRODUCT_ID")
                    ],
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Failed to get sync status',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Test sync (dry run)
     * GET: sync/test_sync/37419605
     */
    public function test_sync($shop_id) {
        try {
            $this->load_mapping_config($shop_id);
            $this->check_token($shop_id);
            
            $limit = $this->input->get('limit') ?? 5;
            $products = $this->Product_model->get_products_for_sync($this->mapping_config);
            $test_products = array_slice($products, 0, $limit);
            
            $test_results = [];
            foreach ($test_products as $product) {
                $test_result = [
                    'product_id' => $product['product_id'],
                    'product_name' => $product['product_name'],
                    'current_stock' => $product['current_stock'],
                    'shopee_item_id' => $product['shopee_item_id'],
                    'sku' => $product['sku']
                ];
                
                if (empty($product['shopee_item_id'])) {
                    $test_result['status'] = 'skip';
                    $test_result['reason'] = 'No Shopee item ID';
                } else {
                    $test_result['status'] = 'ready';
                    $test_result['action'] = 'Would update stock to ' . $product['current_stock'];
                }
                
                $test_results[] = $test_result;
            }
            
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => true,
                    'shop_id' => (int)$shop_id,
                    'test_mode' => true,
                    'total_products' => count($products),
                    'tested_products' => count($test_results),
                    'test_results' => $test_results,
                    'ready_to_sync' => count(array_filter($test_results, function($r) { return $r['status'] === 'ready'; })),
                    'next_step' => base_url("sync/run_sync/{$shop_id}"),
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Test sync failed',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Run actual sync
     * POST: sync/run_sync/37419605
     */
    public function run_sync($shop_id) {
        try {
            $this->load_mapping_config($shop_id);
            $this->check_token($shop_id);
            
            $input = json_decode($this->input->raw_input_stream, true);
            $limit = $input['limit'] ?? 10;
            $delay_ms = $input['delay_ms'] ?? 500;
            
            $products = $this->Product_model->get_products_for_sync($this->mapping_config);
            $sync_products = array_slice($products, 0, $limit);
            
            $results = [
                'total_processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'skipped' => 0,
                'details' => []
            ];
            
            foreach ($sync_products as $product) {
                $results['total_processed']++;
                
                try {
                    if (empty($product['shopee_item_id'])) {
                        $results['skipped']++;
                        $results['details'][] = [
                            'product_id' => $product['product_id'],
                            'status' => 'skipped',
                            'reason' => 'No Shopee item ID'
                        ];
                        continue;
                    }
                    
                    $sync_result = $this->sync_single_product($shop_id, $product);
                    
                    if ($sync_result['success']) {
                        $results['successful']++;
                    } else {
                        $results['failed']++;
                    }
                    
                    $results['details'][] = $sync_result;
                    
                    // Add delay between requests
                    if ($delay_ms > 0) {
                        usleep($delay_ms * 1000);
                    }
                    
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['details'][] = [
                        'product_id' => $product['product_id'],
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => true,
                    'shop_id' => (int)$shop_id,
                    'sync_completed' => true,
                    'summary' => $results,
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Sync failed',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Sync single product by ID
     * POST: sync/sync_product/37419605/PRODUCT_ID
     */
    public function sync_product($shop_id, $product_id) {
        try {
            $this->load_mapping_config($shop_id);
            $this->check_token($shop_id);
            
            $product = $this->Product_model->get_product_by_id($product_id, $this->mapping_config);
            
            if (!$product) {
                throw new Exception("Product {$product_id} not found");
            }
            
            if (empty($product['shopee_item_id'])) {
                throw new Exception("Product {$product_id} has no Shopee item ID");
            }
            
            $result = $this->sync_single_product($shop_id, $product);
            
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => $result['success'],
                    'shop_id' => (int)$shop_id,
                    'product_id' => $product_id,
                    'sync_result' => $result,
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Single product sync failed',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * CRON endpoint for automated sync
     * POST: sync/cron/37419605
     */
    public function cron($shop_id) {
        try {
            // Log cron run
            log_message('info', "CRON: Starting sync for shop {$shop_id}");
            
            $this->load_mapping_config($shop_id);
            
            // Check if token exists (skip if no token)
            if (!$this->has_valid_token($shop_id)) {
                log_message('warning', "CRON: No valid token for shop {$shop_id}, skipping");
                
                $this->output
                    ->set_content_type('application/json')
                    ->set_output(json_encode([
                        'success' => false,
                        'shop_id' => (int)$shop_id,
                        'message' => 'No valid token, sync skipped',
                        'timestamp' => date('c')
                    ]));
                return;
            }
            
            $input = json_decode($this->input->raw_input_stream, true);
            $max_products = $input['max_products'] ?? 20;
            
            $products = $this->Product_model->get_products_for_sync($this->mapping_config);
            $sync_products = array_slice($products, 0, $max_products);
            
            $summary = [
                'processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'skipped' => 0
            ];
            
            foreach ($sync_products as $product) {
                $summary['processed']++;
                
                try {
                    if (empty($product['shopee_item_id'])) {
                        $summary['skipped']++;
                        continue;
                    }
                    
                    $result = $this->sync_single_product($shop_id, $product);
                    
                    if ($result['success']) {
                        $summary['successful']++;
                    } else {
                        $summary['failed']++;
                    }
                    
                    // Rate limiting
                    usleep(200000); // 200ms delay
                    
                } catch (Exception $e) {
                    $summary['failed']++;
                    log_message('error', "CRON: Failed to sync product {$product['product_id']}: " . $e->getMessage());
                }
            }
            
            log_message('info', "CRON: Completed sync for shop {$shop_id}: " . json_encode($summary));
            
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => true,
                    'shop_id' => (int)$shop_id,
                    'cron_summary' => $summary,
                    'next_run_recommendation' => count($products) > $max_products ? 'immediate' : 'next_scheduled',
                    'timestamp' => date('c')
                ]));
                
        } catch (Exception $e) {
            log_message('error', "CRON: Error for shop {$shop_id}: " . $e->getMessage());
            
            $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => false,
                    'shop_id' => (int)$shop_id,
                    'error' => 'CRON sync failed',
                    'message' => $e->getMessage(),
                    'timestamp' => date('c')
                ]));
        }
    }
    
    /**
     * Sync single product to Shopee
     */
    private function sync_single_product($shop_id, $product) {
        $start_time = microtime(true);
        
        try {
            // Get current Shopee stock first
            $path = '/api/v2/product/get_item_base_info';
            $params = ['item_id_list' => $product['shopee_item_id']];
            
            $shopee_info = $this->shopee_signer->signed_get($path, $shop_id, $params);
            
            if (isset($shopee_info['error']) && !empty($shopee_info['error'])) {
                throw new Exception('Failed to get Shopee item info: ' . $shopee_info['message']);
            }
            
            $item_data = $shopee_info['response']['item_list'][0] ?? null;
            if (!$item_data) {
                throw new Exception('Item not found in Shopee');
            }
            
            // Extract location info
            $seller_stock = $item_data['stock_info_v2']['seller_stock'] ?? [];
            if (empty($seller_stock)) {
                throw new Exception('No stock locations found');
            }
            
            // Build stock update payload
            $stock_list = [];
            foreach ($seller_stock as $seller) {
                if (isset($seller['stock']) && is_array($seller['stock'])) {
                    $seller_stock_data = [];
                    foreach ($seller['stock'] as $location_stock) {
                        $seller_stock_data[] = [
                            'location_id' => $location_stock['location_id'],
                            'stock' => (int)$product['current_stock']
                        ];
                    }
                    
                    $stock_list[] = [
                        'model_id' => 0,
                        'seller_stock' => $seller_stock_data
                    ];
                }
            }
            
            if (empty($stock_list)) {
                throw new Exception('Could not build stock update payload');
            }
            
            // Update stock in Shopee
            $update_path = '/api/v2/product/update_stock';
            $payload = [
                'item_id' => (int)$product['shopee_item_id'],
                'stock_list' => $stock_list
            ];
            
            $update_response = $this->shopee_signer->signed_post($update_path, $payload, $shop_id);
            
            if (isset($update_response['error']) && !empty($update_response['error'])) {
                throw new Exception('Shopee update failed: ' . $update_response['message']);
            }
            
            // Update local database
            $this->Product_model->update_stock_after_sync(
                $product['product_id'], 
                $product['current_stock'], 
                $this->mapping_config
            );
            
            // Log success
            $this->Product_model->log_sync([
                'shop_id' => $shop_id,
                'product_id' => $product['product_id'],
                'shopee_item_id' => $product['shopee_item_id'],
                'action' => 'sync_stock',
                'new_stock' => $product['current_stock'],
                'success' => true
            ]);
            
            $duration = round((microtime(true) - $start_time) * 1000, 2);
            
            return [
                'success' => true,
                'product_id' => $product['product_id'],
                'shopee_item_id' => $product['shopee_item_id'],
                'new_stock' => $product['current_stock'],
                'duration_ms' => $duration
            ];
            
        } catch (Exception $e) {
            // Log failure
            $this->Product_model->log_sync([
                'shop_id' => $shop_id,
                'product_id' => $product['product_id'],
                'shopee_item_id' => $product['shopee_item_id'],
                'action' => 'sync_stock',
                'success' => false,
                'error_message' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'product_id' => $product['product_id'],
                'shopee_item_id' => $product['shopee_item_id'],
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $start_time) * 1000, 2)
            ];
        }
    }
    
    /**
     * Load mapping configuration for shop
     */
    private function load_mapping_config($shop_id) {
        $config_file = APPPATH . "config/sync/mapping_{$shop_id}.json";
        
        if (!file_exists($config_file)) {
            throw new Exception("Mapping configuration not found for shop {$shop_id}. Please setup mapping first via " . base_url('sync/setup_mapping'));
        }
        
        $config_content = file_get_contents($config_file);
        $this->mapping_config = json_decode($config_content, true);
        
        if (!$this->mapping_config) {
            throw new Exception("Invalid mapping configuration for shop {$shop_id}");
        }
    }
    
    /**
     * Check if token exists and valid
     */
    private function check_token($shop_id) {
        if (!$this->has_valid_token($shop_id)) {
            throw new Exception("No valid token for shop {$shop_id}. Please authorize first via " . base_url('shopee/auth'));
        }
    }
    
    /**
     * Check if has valid token (without throwing exception)
     */
    private function has_valid_token($shop_id) {
        $token = $this->session->userdata("shopee_token_{$shop_id}");
        if (!$token) {
            return false;
        }
        
        $expires = $this->session->userdata("shopee_expires_{$shop_id}");
        if ($expires && $expires < time()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get token status
     */
    private function get_token_status($shop_id) {
        $token = $this->session->userdata("shopee_token_{$shop_id}");
        $expires = $this->session->userdata("shopee_expires_{$shop_id}");
        
        return [
            'has_access_token' => !empty($token),
            'expires_at' => $expires ? date('c', $expires) : null,
            'is_valid' => $this->has_valid_token($shop_id),
            'needs_refresh' => $expires ? ($expires < time() + 300) : true
        ];
    }
}
