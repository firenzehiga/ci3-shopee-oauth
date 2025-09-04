<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Product_model extends CI_Model {
    
    public function __construct() {
        parent::__construct();
        $this->load->database();
    }
    
    /**
     * Get products that need sync based on custom mapping
     * 
     * @param array $mapping Configuration mapping
     * @return array Products that need sync
     */
    public function get_products_for_sync($mapping) {
        $table = $mapping['table_name'];
        $cols = $mapping['column_mappings'];
        $where = $mapping['where_condition'] ?? '';
        
        $sql = "
            SELECT 
                {$cols['product_id']} as product_id,
                {$cols['product_name']} as product_name,
                {$cols['stock_quantity']} as current_stock,
                {$cols['shopee_item_id']} as shopee_item_id,
                {$cols['sku']} as sku,
                {$cols['last_updated']} as last_updated
            FROM {$table}
            {$where}
            ORDER BY {$cols['last_updated']} DESC
        ";
        
        $query = $this->db->query($sql);
        return $query->result_array();
    }
    
    /**
     * Get single product by ID
     */
    public function get_product_by_id($product_id, $mapping) {
        $table = $mapping['table_name'];
        $cols = $mapping['column_mappings'];
        
        $sql = "
            SELECT 
                {$cols['product_id']} as product_id,
                {$cols['product_name']} as product_name,
                {$cols['stock_quantity']} as current_stock,
                {$cols['shopee_item_id']} as shopee_item_id,
                {$cols['sku']} as sku,
                {$cols['last_updated']} as last_updated
            FROM {$table}
            WHERE {$cols['product_id']} = ?
        ";
        
        $query = $this->db->query($sql, [$product_id]);
        return $query->row_array();
    }
    
    /**
     * Get product by Shopee item ID
     */
    public function get_product_by_shopee_id($shopee_item_id, $mapping) {
        $table = $mapping['table_name'];
        $cols = $mapping['column_mappings'];
        
        $sql = "
            SELECT 
                {$cols['product_id']} as product_id,
                {$cols['product_name']} as product_name,
                {$cols['stock_quantity']} as current_stock,
                {$cols['shopee_item_id']} as shopee_item_id,
                {$cols['sku']} as sku,
                {$cols['last_updated']} as last_updated
            FROM {$table}
            WHERE {$cols['shopee_item_id']} = ?
        ";
        
        $query = $this->db->query($sql, [$shopee_item_id]);
        return $query->row_array();
    }
    
    /**
     * Update stock in your database after successful Shopee sync
     */
    public function update_stock_after_sync($product_id, $new_stock, $mapping) {
        $table = $mapping['table_name'];
        $cols = $mapping['column_mappings'];
        
        $update_data = [
            $cols['stock_quantity'] => $new_stock,
            $cols['last_updated'] => date('Y-m-d H:i:s')
        ];
        
        $this->db->where($cols['product_id'], $product_id);
        return $this->db->update($table, $update_data);
    }
    
    /**
     * Get sync statistics
     */
    public function get_sync_stats($mapping) {
        $table = $mapping['table_name'];
        $cols = $mapping['column_mappings'];
        $where = $mapping['where_condition'] ?? '';
        
        $sql = "
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN {$cols['shopee_item_id']} IS NOT NULL THEN 1 END) as has_shopee_id,
                COUNT(CASE WHEN {$cols['shopee_item_id']} IS NOT NULL AND {$cols['stock_quantity']} > 0 THEN 1 END) as in_stock
            FROM {$table}
            {$where}
        ";
        
        $query = $this->db->query($sql);
        return $query->row_array();
    }
    
    /**
     * Create sync log entry
     */
    public function log_sync($data) {
        // Create sync_logs table if not exists
        if (!$this->db->table_exists('sync_logs')) {
            $this->create_sync_logs_table();
        }
        
        $log_data = [
            'shop_id' => $data['shop_id'],
            'product_id' => $data['product_id'],
            'shopee_item_id' => $data['shopee_item_id'],
            'action' => $data['action'],
            'old_stock' => $data['old_stock'] ?? null,
            'new_stock' => $data['new_stock'] ?? null,
            'success' => $data['success'] ? 1 : 0,
            'error_message' => $data['error_message'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return $this->db->insert('sync_logs', $log_data);
    }
    
    /**
     * Create sync logs table
     */
    private function create_sync_logs_table() {
        $sql = "
            CREATE TABLE IF NOT EXISTS sync_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                shop_id BIGINT NOT NULL,
                product_id VARCHAR(255),
                shopee_item_id BIGINT,
                action VARCHAR(50),
                old_stock INT,
                new_stock INT,
                success TINYINT(1) DEFAULT 0,
                error_message TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_shop_id (shop_id),
                INDEX idx_created_at (created_at)
            )
        ";
        
        $this->db->query($sql);
    }
    
    /**
     * Analyze existing database structure
     */
    public function analyze_database() {
        // Get all tables
        $tables = $this->db->list_tables();
        
        $analysis = [];
        foreach ($tables as $table) {
            // Look for tables that might contain products
            if (stripos($table, 'product') !== false || 
                stripos($table, 'item') !== false || 
                stripos($table, 'stock') !== false) {
                
                $fields = $this->db->field_data($table);
                $sample_data = $this->db->limit(3)->get($table)->result_array();
                
                $analysis[] = [
                    'table_name' => $table,
                    'fields' => $fields,
                    'sample_data' => $sample_data
                ];
            }
        }
        
        return $analysis;
    }
}
