<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Shopee_signer {
    
    private $CI;
    private $partner_id;
    private $partner_key;
    
    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->config->load('shopee');
        
        $this->partner_id = $this->CI->config->item('shopee_partner_id');
        $this->partner_key = $this->CI->config->item('shopee_partner_key');
    }
    
    /**
     * Generate HMAC-SHA256 signature for Shopee API
     */
    public function sign($path, $timestamp, $access_token = '', $shop_id = '') {
        // Build string to sign: partner_id + path + timestamp + access_token + shop_id
        $string_to_sign = $this->partner_id . $path . $timestamp . $access_token . $shop_id;
        
        // Generate HMAC-SHA256 signature
        $signature = hash_hmac('sha256', $string_to_sign, $this->partner_key);
        
        return $signature;
    }
    
    /**
     * Get current timestamp
     */
    public function now_sec() {
        return time();
    }
    
    /**
     * Build full URL with query parameters
     */
    public function build_url($path, $params = []) {
        $host = $this->CI->config->item('shopee_host');
        $url = $host . $path;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        return $url;
    }
    
    /**
     * Make signed GET request
     */
    public function signed_get($path, $shop_id = null, $extra_params = []) {
        $timestamp = $this->now_sec();
        $access_token = '';
        
        // Get access token if shop_id provided
        if ($shop_id) {
            $access_token = $this->CI->session->userdata("shopee_token_{$shop_id}");
            if (!$access_token) {
                throw new Exception("No access token found for shop {$shop_id}");
            }
        }
        
        $signature = $this->sign($path, $timestamp, $access_token, $shop_id);
        
        $params = array_merge([
            'partner_id' => $this->partner_id,
            'timestamp' => $timestamp,
            'sign' => $signature
        ], $extra_params);
        
        if ($access_token) {
            $params['access_token'] = $access_token;
            $params['shop_id'] = $shop_id;
        }
        
        $url = $this->build_url($path, $params);
        
        return $this->make_request($url, 'GET');
    }
    
    /**
     * Make signed POST request
     */
    public function signed_post($path, $data = [], $shop_id = null) {
        $timestamp = $this->now_sec();
        $access_token = '';
        
        // Get access token if shop_id provided
        if ($shop_id) {
            $access_token = $this->CI->session->userdata("shopee_token_{$shop_id}");
            if (!$access_token) {
                throw new Exception("No access token found for shop {$shop_id}");
            }
        }
        
        $signature = $this->sign($path, $timestamp, $access_token, $shop_id);
        
        $params = [
            'partner_id' => $this->partner_id,
            'timestamp' => $timestamp,
            'sign' => $signature
        ];
        
        if ($access_token) {
            $params['access_token'] = $access_token;
            $params['shop_id'] = $shop_id;
        }
        
        $url = $this->build_url($path, $params);
        
        return $this->make_request($url, 'POST', $data);
    }
    
    /**
     * Make HTTP request using cURL
     */
    private function make_request($url, $method = 'GET', $data = null) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'CodeIgniter Shopee Client/1.0'
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen(json_encode($data))
                ]);
            }
        }
        
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
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response: " . $response);
        }
        
        return $decoded;
    }
}
