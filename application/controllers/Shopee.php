<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Shopee extends CI_Controller {
    
    public function __construct() {
        parent::__construct();
        $this->load->library('shopee_signer');
        $this->load->helper('url');
        $this->load->library('session');
    }
    
    /**
     * Display available endpoints
     */
    public function index() {
        $data = [
            'message' => 'Shopee API Integration - CodeIgniter 3',
            'version' => '1.0.0',
            'endpoints' => [
                'auth' => base_url('shopee/auth'),
                'callback' => base_url('shopee/callback'),
                'item_list' => base_url('shopee/item_list/SHOP_ID'),
                'base_info' => base_url('shopee/base_info/SHOP_ID/ITEM_IDS'),
                'update_stock' => base_url('shopee/update_stock/SHOP_ID'),
                'shop_info' => base_url('shopee/shop_info/SHOP_ID'),
                'status' => base_url('shopee/status')
            ]
        ];
        
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Step 1: Redirect to Shopee authorization
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
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Failed to generate auth URL',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Step 2: Handle callback from Shopee
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
            
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => true,
                    'message' => 'Authorization successful!',
                    'shop_id' => (int)$shop_id,
                    'token_info' => [
                        'expires_in' => $response['expire_in'] ?? 14400,
                        'token_type' => 'Bearer'
                    ],
                    'available_endpoints' => [
                        'item_list' => base_url("shopee/item_list/{$shop_id}"),
                        'base_info' => base_url("shopee/base_info/{$shop_id}/ITEM_IDS"),
                        'update_stock' => base_url("shopee/update_stock/{$shop_id}"),
                        'shop_info' => base_url("shopee/shop_info/{$shop_id}")
                    ],
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            log_message('error', 'Callback error: ' . $e->getMessage());
            
            $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Callback processing failed',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Get item list for a shop
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
            
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => true,
                    'shop_id' => (int)$shop_id,
                    'data' => $response,
                    'params_used' => $params,
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Failed to get item list',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Get item base info
     * URL: shopee/base_info/37419605/18329949601,18329949602
     */
    public function base_info($shop_id, $item_ids) {
        try {
            $this->check_token($shop_id);
            
            $path = '/api/v2/product/get_item_base_info';
            $params = [
                'item_id_list' => $item_ids
            ];
            
            $response = $this->shopee_signer->signed_get($path, $shop_id, $params);
            
            if (isset($response['error']) && !empty($response['error'])) {
                throw new Exception('Shopee API error: ' . $response['message']);
            }
            
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => true,
                    'shop_id' => (int)$shop_id,
                    'requested_items' => explode(',', $item_ids),
                    'data' => $response,
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Failed to get item base info',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Update product stock
     * POST data: {"item_id": 123, "stock_list": [...]}
     */
    public function update_stock($shop_id) {
        try {
            $this->check_token($shop_id);
            
            $input = json_decode($this->input->raw_input_stream, true);
            
            if (!isset($input['item_id']) || !isset($input['stock_list'])) {
                throw new Exception('Missing item_id or stock_list in request body');
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
            
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => true,
                    'message' => "Stock updated successfully for item {$input['item_id']}",
                    'shop_id' => (int)$shop_id,
                    'item_id' => (int)$input['item_id'],
                    'shopee_response' => $response,
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            log_message('error', 'Update stock error: ' . $e->getMessage());
            
            $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Failed to update stock',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Get shop information
     */
    public function shop_info($shop_id) {
        try {
            $this->check_token($shop_id);
            
            $path = '/api/v2/shop/get_shop_info';
            $response = $this->shopee_signer->signed_get($path, $shop_id);
            
            if (isset($response['error']) && !empty($response['error'])) {
                throw new Exception('Shopee API error: ' . $response['message']);
            }
            
            $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'success' => true,
                    'shop_id' => (int)$shop_id,
                    'data' => $response,
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT));
                
        } catch (Exception $e) {
            $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'Failed to get shop info',
                    'message' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Check authorization status
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
                    'needs_refresh' => $expires_at ? ($expires_at < time() + 300) : true
                ];
            }
        }
        
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'success' => true,
                'message' => 'Authorization status for all shops',
                'shops' => $tokens,
                'total_authorized_shops' => count($tokens),
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT));
    }
    
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
            throw new Exception("HTTP Error {$http_code}");
        }
        
        return json_decode($response, true);
    }
}
