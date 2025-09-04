<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Shopee extends CI_Controller {
    
    public function __construct() {
        parent::__construct();
        $this->load->library('shopee_signer');
        $this->load->helper('url');
        $this->load->library('session');
        
        // Set JSON response header
        $this->output->set_content_type('application/json');
    }
    
    /**
     * Home - Display available endpoints
     * GET: /shopee atau /shopee/index
     */
    public function index() {
        $data = [
            'message' => 'Shopee API Integration - CodeIgniter 3',
            'version' => '1.0.0',
            'status' => 'running',
            'endpoints' => [
                'auth' => base_url('shopee/auth'),
                'callback' => base_url('shopee/callback'),
                'status' => base_url('shopee/status'),
                'item_list' => base_url('shopee/item_list/SHOP_ID'),
                'base_info' => base_url('shopee/base_info/SHOP_ID?item_ids=ITEM_IDS'),
                'update_stock' => base_url('shopee/update_stock/SHOP_ID'),
                'stock_helper' => base_url('shopee/stock_helper/SHOP_ID/ITEM_ID'),
                'shop_info' => base_url('shopee/shop_info/SHOP_ID'),
            ],
            'test_flow' => [
                '1. Authorize: ' . base_url('shopee/auth'),
                '2. Check status: ' . base_url('shopee/status'),
                '3. Test API: ' . base_url('shopee/item_list/37419605'),
            ]
        ];
        
        $this->output->set_output(json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Step 1: Redirect to Shopee authorization
     * GET: /shopee/auth
     */
    public function auth() {
        try {
            $path = '/api/v2/shop/auth_partner';
            $timestamp = $this->shopee_signer->now_sec();
            $signature = $this->shopee_signer->sign($path, $timestamp);
            
            $params = [
                'partner_id' => $this->config->item('shopee_partner_id'),
                'timestamp' => $timestamp,
                'sign' => $signature,
                'redirect' => $this->config->item('shopee_redirect_uri')
            ];
            
            $auth_url = $this->shopee_signer->build_url($path, $params);
            
            log_message('info', 'Shopee Auth URL: ' . $auth_url);
            
            redirect($auth_url);
            
        } catch (Exception $e) {
            $this->output
                ->set_status_header(500)
                ->set_output(json_encode([
                    'error' => 'Failed to generate auth URL',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Step 2: Handle callback from Shopee
     * GET: /shopee/callback?code=xxx&shop_id=xxx
     */
    public function callback() {
        try {
            $code = $this->input->get('code');
            $shop_id = $this->input->get('shop_id');
            
            if (!$code || !$shop_id) {
                throw new Exception('Missing code or shop_id parameter');
            }
            
            log_message('info', "Processing callback for shop_id: {$shop_id}");
            
            // Exchange code for tokens
            $path = '/api/v2/auth/token/get';
            $timestamp = $this->shopee_signer->now_sec();
            $signature = $this->shopee_signer->sign($path, $timestamp);
            
            $params = [
                'partner_id' => $this->config->item('shopee_partner_id'),
                'timestamp' => $timestamp,
                'sign' => $signature
            ];
            
            $url = $this->shopee_signer->build_url($path, $params);
            
            $payload = [
                'code' => $code,
                'partner_id' => (int)$this->config->item('shopee_partner_id'),
                'shop_id' => (int)$shop_id
            ];
            
            $response = $this->make_post_request($url, $payload);
            
            if (isset($response['error']) && !empty($response['error'])) {
                throw new Exception('Token exchange failed: ' . $response['message']);
            }
            
            if (empty($response['access_token'])) {
                throw new Exception('No access token received');
            }
            
            // Store tokens in session
            $this->session->set_userdata([
                "shopee_token_{$shop_id}" => $response['access_token'],
                "shopee_refresh_{$shop_id}" => $response['refresh_token'],
                "shopee_expires_{$shop_id}" => time() + ($response['expire_in'] ?? 14400)
            ]);
            
            log_message('info', "Token stored successfully for shop {$shop_id}");
            
            $this->output->set_output(json_encode([
                'success' => true,
                'message' => '🎉 Authorization successful! You can now use API endpoints.',
                'shop_id' => (int)$shop_id,
                'token_info' => [
                    'expires_in' => $response['expire_in'] ?? 14400,
                    'token_type' => 'Bearer',
                    'access_token_preview' => substr($response['access_token'], 0, 10) . '...',
                    'refresh_token_preview' => substr($response['refresh_token'], 0, 10) . '...'
                ],
                'available_endpoints' => [
                    'item_list' => base_url("shopee/item_list/{$shop_id}"),
                    'base_info' => base_url("shopee/base_info/{$shop_id}?item_ids=ITEM_IDS"),
                    'update_stock' => base_url("shopee/update_stock/{$shop_id}"),
                    'shop_info' => base_url("shopee/shop_info/{$shop_id}"),
                    'stock_helper' => base_url("shopee/stock_helper/{$shop_id}/ITEM_ID")
                ],
                'next_steps' => [
                    "1. Try: " . base_url("shopee/item_list/{$shop_id}"),
                    "2. Try: " . base_url("shopee/shop_info/{$shop_id}"),
                    "3. Get item details: " . base_url("shopee/base_info/{$shop_id}?item_ids=18329949601")
                ],
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            log_message('error', 'Callback error: ' . $e->getMessage());
            
            $this->output
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Callback processing failed',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Check authorization status
     * GET: /shopee/status
     */
    public function status() {
        $tokens = [];
        $session_data = $this->session->all_userdata();
        
        foreach ($session_data as $key => $value) {
            if (strpos($key, 'shopee_token_') === 0) {
                $shop_id = str_replace('shopee_token_', '', $key);
                $expires_key = "shopee_expires_{$shop_id}";
                $expires_at = $this->session->userdata($expires_key);
                
                $tokens[$shop_id] = [
                    'has_access_token' => !empty($value),
                    'has_refresh_token' => !empty($this->session->userdata("shopee_refresh_{$shop_id}")),
                    'expires_at' => $expires_at ? date('c', $expires_at) : null,
                    'expires_in_seconds' => $expires_at ? max(0, $expires_at - time()) : 0,
                    'needs_refresh' => $expires_at ? ($expires_at < time() + 300) : true
                ];
            }
        }
        
        $this->output->set_output(json_encode([
            'success' => true,
            'message' => 'Authorization status for all shops',
            'shops' => $tokens,
            'total_authorized_shops' => count($tokens),
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT));
    }
    
    /**
     * Get item list for a shop
     * GET: /shopee/item_list/37419605?page_size=10&item_status=NORMAL
     */
    public function item_list($shop_id) {
        try {
            $this->check_token($shop_id);
            
            $offset = $this->input->get('offset') ?? 0;
            $page_size = $this->input->get('page_size') ?? 20;
            $item_status = $this->input->get('item_status') ?? 'NORMAL';
            
            $path = '/api/v2/product/get_item_list';
            $params = [
                'offset' => (int)$offset,
                'page_size' => (int)$page_size,
                'item_status' => $item_status
            ];
            
            $response = $this->shopee_signer->signed_get($path, $shop_id, $params);
            
            if (isset($response['error']) && !empty($response['error'])) {
                throw new Exception('Shopee API error: ' . $response['message']);
            }
            
            $this->output->set_output(json_encode([
                'success' => true,
                'shop_id' => (int)$shop_id,
                'data' => $response,
                'total_items' => $response['response']['total_count'] ?? 0,
                'params_used' => $params,
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Failed to get item list',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Get item list with full details (item list + base info for each item)
     * GET: /shopee/item_list_with_details/37419605?page_size=5&item_status=NORMAL
     */
    public function item_list_with_details($shop_id) {
        try {
            $this->check_token($shop_id);
            
            $offset = $this->input->get('offset') ?? 0;
            $page_size = $this->input->get('page_size') ?? 5; // Default smaller for details
            $item_status = $this->input->get('item_status') ?? 'NORMAL';
            
            // Step 1: Get item list
            $path = '/api/v2/product/get_item_list';
            $params = [
                'offset' => (int)$offset,
                'page_size' => (int)$page_size,
                'item_status' => $item_status
            ];
            
            $list_response = $this->shopee_signer->signed_get($path, $shop_id, $params);
            
            if (isset($list_response['error']) && !empty($list_response['error'])) {
                throw new Exception('Shopee API error: ' . $list_response['message']);
            }
            
            $items = $list_response['response']['item'] ?? [];
            $items_with_details = [];
            
            if (!empty($items)) {
                // Step 2: Get base info for all items in batch
                $item_ids = array_column($items, 'item_id');
                $item_ids_string = implode(',', $item_ids);
                
                $detail_path = '/api/v2/product/get_item_base_info';
                $detail_params = [
                    'item_id_list' => $item_ids_string
                ];
                
                $detail_response = $this->shopee_signer->signed_get($detail_path, $shop_id, $detail_params);
                
                if (isset($detail_response['error']) && !empty($detail_response['error'])) {
                    // If details fail, return items without details
                    $items_with_details = array_map(function($item) {
                        return array_merge($item, ['detail_error' => 'Failed to fetch details']);
                    }, $items);
                } else {
                    // Merge item list with details
                    $details_by_id = [];
                    foreach ($detail_response['response']['item_list'] ?? [] as $detail) {
                        $details_by_id[$detail['item_id']] = $detail;
                    }
                    
                    foreach ($items as $item) {
                        $item_detail = $details_by_id[$item['item_id']] ?? null;
                        $items_with_details[] = array_merge($item, [
                            'details' => $item_detail,
                            'has_details' => !is_null($item_detail)
                        ]);
                    }
                }
            }
            
            $this->output->set_output(json_encode([
                'success' => true,
                'shop_id' => (int)$shop_id,
                'total_items' => $list_response['response']['total_count'] ?? 0,
                'returned_items' => count($items_with_details),
                'items_with_details' => $items_with_details,
                'pagination' => [
                    'offset' => (int)$offset,
                    'page_size' => (int)$page_size,
                    'has_more' => ($list_response['response']['has_next_page'] ?? false)
                ],
                'params_used' => $params,
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Failed to get item list with details',
                    'message' => $e->getMessage()
                ]));
        }
    }

    /**
     * Get item base info
     * GET: /shopee/base_info/37419605?item_ids=18329949601,18329949602
     */
    public function base_info($shop_id) {
        try {
            $this->check_token($shop_id);
            
            $item_ids = $this->input->get('item_ids');
            if (!$item_ids) {
                throw new Exception('Missing item_ids parameter. Example: ?item_ids=123,456,789');
            }
            
            $path = '/api/v2/product/get_item_base_info';
            $params = [
                'item_id_list' => $item_ids
            ];
            
            $response = $this->shopee_signer->signed_get($path, $shop_id, $params);
            
            if (isset($response['error']) && !empty($response['error'])) {
                throw new Exception('Shopee API error: ' . $response['message']);
            }
            
            $this->output->set_output(json_encode([
                'success' => true,
                'shop_id' => (int)$shop_id,
                'requested_items' => explode(',', $item_ids),
                'data' => $response,
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Failed to get item base info',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Get stock helper information
     * GET: /shopee/stock_helper/37419605/18329949601
     */
    public function stock_helper($shop_id, $item_id) {
        try {
            $this->check_token($shop_id);
            
            if (!$item_id) {
                throw new Exception('Missing item_id in URL');
            }
            
            $path = '/api/v2/product/get_item_base_info';
            $params = [
                'item_id_list' => $item_id
            ];
            
            $response = $this->shopee_signer->signed_get($path, $shop_id, $params);
            
            if (isset($response['error']) && !empty($response['error'])) {
                throw new Exception('Shopee API error: ' . $response['message']);
            }
            
            $item_data = $response['response']['item_list'][0] ?? null;
            if (!$item_data) {
                throw new Exception("Item {$item_id} not found in shop {$shop_id}");
            }
            
            // Extract stock information
            $current_stock = $item_data['stock_info_v2']['summary_info'] ?? [];
            $seller_stock = $item_data['stock_info_v2']['seller_stock'] ?? [];
            
            // Generate update payload template
            $update_template = [
                'item_id' => (int)$item_id,
                'stock_list' => []
            ];
            
            foreach ($seller_stock as $seller) {
                if (isset($seller['stock']) && is_array($seller['stock'])) {
                    $seller_stock_data = [];
                    foreach ($seller['stock'] as $location_stock) {
                        $seller_stock_data[] = [
                            'location_id' => $location_stock['location_id'],
                            'stock' => $location_stock['stock'] // Current stock, change this value
                        ];
                    }
                    
                    $update_template['stock_list'][] = [
                        'model_id' => 0, // Use 0 for normal item (non-variation)
                        'seller_stock' => $seller_stock_data
                    ];
                }
            }
            
            $this->output->set_output(json_encode([
                'success' => true,
                'message' => 'Stock helper information',
                'shop_id' => (int)$shop_id,
                'item_id' => (int)$item_id,
                'current_stock_summary' => [
                    'total_reserved_stock' => $current_stock['total_reserved_stock'] ?? 0,
                    'total_available_stock' => $current_stock['total_available_stock'] ?? 0
                ],
                'current_stock_detail' => $seller_stock,
                'update_stock_endpoint' => base_url("shopee/update_stock/{$shop_id}"),
                'update_payload_template' => $update_template,
                'example_usage' => [
                    'description' => 'Copy the update_payload_template above and modify the stock values, then POST to update_stock endpoint',
                    'curl_example' => "curl -X POST \"" . base_url("shopee/update_stock/{$shop_id}") . "\" -H \"Content-Type: application/json\" -d '" . json_encode($update_template) . "'"
                ],
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Failed to get stock helper info',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Update product stock
     * POST: /shopee/update_stock/37419605
     * Body: {"item_id": 123, "stock_list": [...]}
     */
    public function update_stock($shop_id) {
        try {
            $this->check_token($shop_id);
            
            // Get POST data
            $input = json_decode($this->input->raw_input_stream, true);
            
            if (!$input) {
                throw new Exception('Invalid JSON input or missing request body');
            }
            
            if (!isset($input['item_id']) || !isset($input['stock_list'])) {
                throw new Exception('Missing item_id or stock_list in request body');
            }
            
            if (!is_array($input['stock_list'])) {
                throw new Exception('stock_list must be an array');
            }
            
            $path = '/api/v2/product/update_stock';
            $payload = [
                'item_id' => (int)$input['item_id'],
                'stock_list' => $input['stock_list']
            ];
            
            $response = $this->shopee_signer->signed_post($path, $payload, $shop_id);
            
            if (isset($response['error']) && !empty($response['error'])) {
                throw new Exception('Shopee API error: ' . $response['message']);
            }
            
            log_message('info', "Stock updated for item {$input['item_id']} in shop {$shop_id}");
            
            $this->output->set_output(json_encode([
                'success' => true,
                'message' => "Stock updated successfully for item {$input['item_id']}",
                'shop_id' => (int)$shop_id,
                'item_id' => (int)$input['item_id'],
                'updated_stock' => $input['stock_list'],
                'shopee_response' => $response,
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            log_message('error', 'Update stock error: ' . $e->getMessage());
            
            $this->output
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Failed to update stock',
                    'message' => $e->getMessage(),
                    'example_payload' => [
                        'item_id' => 18329949601,
                        'stock_list' => [
                            [
                                'model_id' => 0,
                                'seller_stock' => [
                                    [
                                        'location_id' => 'ID@2AAIZ',
                                        'stock' => 100
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]));
        }
    }
    
    /**
     * Get shop information
     * GET: /shopee/shop_info/37419605
     */
    public function shop_info($shop_id) {
        try {
            $this->check_token($shop_id);
            
            $path = '/api/v2/shop/get_shop_info';
            $response = $this->shopee_signer->signed_get($path, $shop_id);
            
            if (isset($response['error']) && !empty($response['error'])) {
                throw new Exception('Shopee API error: ' . $response['message']);
            }
            
            $this->output->set_output(json_encode([
                'success' => true,
                'shop_id' => (int)$shop_id,
                'data' => $response,
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Failed to get shop info',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Test endpoint untuk debugging
     * GET: /shopee/test
     */
    public function test() {
        $this->output->set_output(json_encode([
            'message' => 'Shopee CI3 Test Endpoint',
            'config_loaded' => [
                'partner_id' => $this->config->item('shopee_partner_id'),
                'host' => $this->config->item('shopee_host'),
                'redirect_uri' => $this->config->item('shopee_redirect_uri')
            ],
            'session_data' => $this->session->all_userdata(),
            'libraries_loaded' => class_exists('Shopee_signer'),
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT));
    }
    
    // ========== PRIVATE METHODS ==========
    
    /**
     * Check if token exists and valid for shop
     */
    private function check_token($shop_id) {
        $token = $this->session->userdata("shopee_token_{$shop_id}");
        if (!$token) {
            throw new Exception("No authorization found for shop {$shop_id}. Please authorize first via " . base_url('shopee/auth'));
        }
        
        $expires = $this->session->userdata("shopee_expires_{$shop_id}");
        if ($expires && $expires < time()) {
            // TODO: Implement token refresh
            throw new Exception("Token expired for shop {$shop_id}. Please re-authorize.");
        }
    }
    
    /**
     * Make POST request using cURL
     */
    private function make_post_request($url, $data) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen(json_encode($data))
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }
        
        if ($http_code >= 400) {
            throw new Exception("HTTP Error {$http_code}: " . $response);
        }
        
        return json_decode($response, true);
    }
}
