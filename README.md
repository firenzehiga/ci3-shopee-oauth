# Shopee API Integration - CodeIgniter 3 Version

## 🚀 Setup & Installation

### 1. Copy Files ke Project CI3 Anda

```bash
# Copy semua file dari ci3-version/ ke aplikasi CI3 Anda
cp -r ci3-version/application/* /path/to/your/ci3/application/
```

### 2. Configuration

Edit `application/config/shopee.php`:
```php
$config['shopee_partner_id'] = '2012584'; // Partner ID Anda
$config['shopee_partner_key'] = 'your_partner_key'; // Partner Key Anda
$config['shopee_redirect_uri'] = base_url('shopee/callback');
```

### 3. Setup Database

Pastikan database CI3 sudah terkonfigurasi di `application/config/database.php`

### 4. URL Routing (Optional)

Tambahkan ke `application/config/routes.php`:
```php
$route['shopee/auth'] = 'shopee/auth';
$route['shopee/callback'] = 'shopee/callback';
$route['sync/(:any)'] = 'sync/$1';
```

## 📋 Penggunaan

### 1. OAuth Authorization

```bash
# Buka di browser untuk authorize
http://your-domain.com/shopee/auth

# Setelah authorize, check status
http://your-domain.com/shopee/status
```

### 2. Setup Database Mapping

```bash
# Analisis database existing
curl "http://your-domain.com/sync/analyze_database"

# Setup mapping (POST JSON)
curl -X POST "http://your-domain.com/sync/setup_mapping" \
  -H "Content-Type: application/json" \
  -d '{
    "table_name": "products",
    "shop_id": 37419605,
    "column_mappings": {
      "product_id": "id",
      "product_name": "name",
      "stock_quantity": "stock",
      "shopee_item_id": "shopee_id",
      "sku": "product_code",
      "last_updated": "updated_at"
    },
    "where_condition": "WHERE status = 1 AND shopee_id IS NOT NULL"
  }'
```

### 3. Test & Run Sync

```bash
# Test sync (dry run)
curl "http://your-domain.com/sync/test_sync/37419605?limit=5"

# Run actual sync
curl -X POST "http://your-domain.com/sync/run_sync/37419605" \
  -H "Content-Type: application/json" \
  -d '{"limit": 10, "delay_ms": 500}'

# Sync single product
curl -X POST "http://your-domain.com/sync/sync_product/37419605/PRODUCT_ID"
```

### 4. Check Status

```bash
# Overall sync status
curl "http://your-domain.com/sync/status/37419605"
```

### 5. CRON Setup

Tambahkan ke crontab server:
```bash
# Sync every 10 minutes
*/10 * * * * curl -X POST "http://your-domain.com/sync/cron/37419605" -H "Content-Type: application/json" -d '{"max_products":20}' >> /var/log/shopee-sync.log 2>&1
```

## 🎯 Integrasi dengan Aplikasi CI3 Existing

### Cara 1: Load Library di Controller Existing

```php
class Your_controller extends CI_Controller {
    
    public function __construct() {
        parent::__construct();
        $this->load->library('shopee_signer');
        $this->load->model('Product_model');
    }
    
    public function update_stock_to_shopee($product_id) {
        // Get product dari database Anda
        $product = $this->Your_model->get_product($product_id);
        
        // Update stock ke Shopee
        try {
            $shop_id = 37419605; // Your shop ID
            
            $payload = [
                'item_id' => $product['shopee_item_id'],
                'stock_list' => [
                    [
                        'model_id' => 0,
                        'seller_stock' => [
                            [
                                'location_id' => 'ID@2AAIZ',
                                'stock' => $product['stock']
                            ]
                        ]
                    ]
                ]
            ];
            
            $response = $this->shopee_signer->signed_post(
                '/api/v2/product/update_stock', 
                $payload, 
                $shop_id
            );
            
            if (isset($response['error'])) {
                throw new Exception($response['message']);
            }
            
            echo "Stock updated successfully!";
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
        }
    }
}
```

### Cara 2: Hook di Model Existing

Tambahkan di model yang handle update stock:

```php
class Your_product_model extends CI_Model {
    
    public function update_stock($product_id, $new_stock) {
        // Update di database lokal
        $this->db->where('id', $product_id);
        $this->db->update('products', ['stock' => $new_stock]);
        
        // Auto-sync ke Shopee
        $this->sync_to_shopee($product_id, $new_stock);
    }
    
    private function sync_to_shopee($product_id, $new_stock) {
        try {
            $this->load->library('shopee_signer');
            
            // Get shopee_item_id
            $product = $this->db->where('id', $product_id)->get('products')->row_array();
            
            if (empty($product['shopee_id'])) {
                return; // Skip if no Shopee ID
            }
            
            // Update stock ke Shopee
            $payload = [
                'item_id' => $product['shopee_id'],
                'stock_list' => [
                    [
                        'model_id' => 0,
                        'seller_stock' => [
                            [
                                'location_id' => 'ID@2AAIZ', // Sesuaikan dengan location Anda
                                'stock' => $new_stock
                            ]
                        ]
                    ]
                ]
            ];
            
            $this->shopee_signer->signed_post(
                '/api/v2/product/update_stock', 
                $payload, 
                37419605 // Your shop ID
            );
            
            log_message('info', "Stock synced to Shopee for product {$product_id}");
            
        } catch (Exception $e) {
            log_message('error', "Failed to sync stock to Shopee: " . $e->getMessage());
        }
    }
}
```

### Cara 3: Database Trigger (Advanced)

Jika ingin full otomatis, buat trigger database:

```sql
DELIMITER $$
CREATE TRIGGER auto_sync_shopee 
AFTER UPDATE ON products 
FOR EACH ROW 
BEGIN
    IF NEW.stock != OLD.stock AND NEW.shopee_id IS NOT NULL THEN
        INSERT INTO sync_queue (product_id, action, created_at) 
        VALUES (NEW.id, 'update_stock', NOW());
    END IF;
END$$
DELIMITER ;
```

Kemudian buat CRON yang process queue:

```php
public function process_sync_queue() {
    $queue = $this->db->get('sync_queue')->result_array();
    
    foreach ($queue as $item) {
        try {
            $this->sync_product_to_shopee($item['product_id']);
            $this->db->where('id', $item['id'])->delete('sync_queue');
        } catch (Exception $e) {
            log_message('error', "Queue sync failed: " . $e->getMessage());
        }
    }
}
```

## 🔧 Customization

### Custom Field Mapping

Edit method `get_products_for_sync()` di `Product_model.php` sesuai struktur database Anda.

### Rate Limiting

Edit `delay_ms` di sync methods untuk mengatur kecepatan sync.

### Error Handling

Semua error disimpan di `sync_logs` table untuk monitoring.

## 🎉 Keunggulan Versi CI3

✅ **Familiar** - Menggunakan pattern CI3 yang sudah Anda kuasai  
✅ **Terintegrasi** - Mudah digabung dengan aplikasi existing  
✅ **Flexible** - Database mapping bisa disesuaikan dinamis  
✅ **Logging** - Built-in logging CI3 untuk debugging  
✅ **Session Based** - Token disimpan di session (bisa diubah ke database)  
✅ **Auto Sync** - Bisa dipicu otomatis dari model existing  

Version CI3 ini **100% compatible** dan lebih mudah untuk diintegrasikan dengan sistem yang sudah ada! 🚀
