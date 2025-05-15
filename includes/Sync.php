<?php
/**
 * Sync Class
 * 
 * Handles synchronization between WooCommerce and physical inventory
 * with features to prevent timeouts and resume interrupted syncs
 */

// Use require_once only for dependency classes
require_once __DIR__ . '/WooCommerce.php';
require_once __DIR__ . '/Product.php';

class Sync {
    // Sync state constants
    const SYNC_STATE_IDLE = 'idle';
    const SYNC_STATE_IN_PROGRESS = 'in_progress';
    const SYNC_STATE_COMPLETED = 'completed';
    const SYNC_STATE_ERROR = 'error';
    
    private $db;
    private $woocommerce;
    private $product;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->woocommerce = new WooCommerce();
        $this->product = new Product();
    }
    
    /**
     * Start or continue a sync operation
     * 
     * @param bool $full_sync Whether to perform a full sync (all products) or just new products
     * @return array Results of the sync operation
     */
    public function syncProducts($full_sync = false) {
        // Get current sync state
        $state = $this->getSyncState();
        
        // If a sync is already in progress, continue it
        if ($state['status'] === self::SYNC_STATE_IN_PROGRESS) {
            return $this->continueSyncProducts($state, $full_sync);
        }
        
        // Otherwise, start a new sync
        return $this->startSyncProducts($full_sync);
    }
    
    /**
     * Start a new sync operation
     * 
     * @param bool $full_sync Whether to perform a full sync (all products) or just new products
     * @return array Results of the sync operation
     */
    private function startSyncProducts($full_sync = false) {
        $results = [
            'products_added' => 0,
            'products_updated' => 0,
            'errors' => [],
            'start_time' => microtime(true),
            'end_time' => null,
            'status' => 'success',
            'is_complete' => false,
            'continuation_token' => null,
            'total_products' => 0,
            'processed_products' => 0
        ];
        
        try {
            // Test WooCommerce API connection
            $connection_test = $this->woocommerce->testConnection();
            if (!$connection_test['success']) {
                throw new Exception("WooCommerce API connection failed: " . $connection_test['message']);
            }
            
            // Initialize sync state
            $state = [
                'status' => self::SYNC_STATE_IN_PROGRESS,
                'page' => 1,
                'per_page' => 20, // Process fewer products per batch to avoid timeouts
                'products_added' => 0,
                'products_updated' => 0,
                'errors' => [],
                'start_time' => microtime(true),
                'full_sync' => $full_sync,
                'total_products' => 0,
                'processed_products' => 0,
                'estimated_total' => 5000, // Set an initial estimate for total products
                'last_count' => 0
            ];
            
            // Save initial state
            $this->saveSyncState($state);
            
            // Continue with first batch
            return $this->continueSyncProducts($state, $full_sync);
            
        } catch (Exception $e) {
            $results['status'] = 'error';
            $results['errors'][] = $e->getMessage();
            
            // Log detailed error
            error_log("Sync Error: " . $e->getMessage());
            
            // Log the error
            $this->logSync(0, 0, 'error', $e->getMessage());
            
            // Reset sync state
            $this->resetSyncState();
            
            return $results;
        }
    }
    
    /**
     * Continue a sync operation from where it left off
     * 
     * @param array $state Current sync state
     * @param bool $full_sync Whether to perform a full sync
     * @return array Results of the sync operation
     */
    private function continueSyncProducts($state, $full_sync) {
        $results = [
            'products_added' => $state['products_added'],
            'products_updated' => $state['products_updated'],
            'errors' => $state['errors'],
            'start_time' => $state['start_time'],
            'end_time' => null,
            'status' => 'success',
            'is_complete' => false,
            'continuation_token' => null,
            'total_products' => $state['total_products'],
            'processed_products' => $state['processed_products'],
            'progress_percent' => 0
        ];
        
        try {
            $page = $state['page'];
            $per_page = $state['per_page'];
            $full_sync = $state['full_sync']; // Use the same sync mode as originally started
            
            // Get products from WooCommerce for this batch
            $wc_products = $this->woocommerce->getProducts($per_page, $page);
            
            // Check if we got a valid response
            if (empty($wc_products)) {
                // If first page returns empty, something is wrong
                if ($page === 1) {
                    throw new Exception("Failed to retrieve products from WooCommerce API");
                }
                
                // No more products, sync is complete
                $this->completeSyncProcess($results);
                return $results;
            }
            
            // Log progress in development mode
            if (defined('APP_ENV') && APP_ENV === 'development') {
                error_log("Processing page {$page} with " . count($wc_products) . " products");
            }
            
            // Update our estimate of total products if this is a later page
            if ($page > 1 && count($wc_products) === $per_page) {
                // We're still getting full pages, so there are likely more products
                $state['estimated_total'] = max($state['estimated_total'], $page * $per_page * 1.2); // Add 20% buffer
            } else if (count($wc_products) < $per_page) {
                // We got a partial page, so we can calculate the exact total
                $state['estimated_total'] = (($page - 1) * $per_page) + count($wc_products);
            }
            
            // Store current count for tracking progress
            $state['last_count'] = count($wc_products);
            
            // Process this batch of products
            foreach ($wc_products as $wc_product) {
                // Skip variable products (get variations separately)
                if (isset($wc_product->type) && $wc_product->type === 'variable') {
                    continue;
                }
                
                // Make sure required fields are present
                if (!isset($wc_product->id) || !isset($wc_product->name)) {
                    $results['errors'][] = "Invalid product data received";
                    continue;
                }
                
                // Check if product already exists in our database
                $existing_product = $this->product->getByProductId($wc_product->id);
                
                // Prepare product data
                $product_data = [
                    'product_id' => $wc_product->id,
                    'title' => $wc_product->name,
                    'sku' => isset($wc_product->sku) ? $wc_product->sku : '',
                    'category' => $this->getCategoryName($wc_product),
                    'price' => isset($wc_product->price) ? $wc_product->price : 0,
                    'image_url' => $this->getProductImage($wc_product),
                ];
                
                if ($existing_product) {
                    if ($full_sync) {
                        // Only update specific fields during sync
                        // Don't overwrite stock if already set in physical inventory
                        $update_data = [
                            'title' => $product_data['title'],
                            'sku' => $product_data['sku'],
                            'category' => $product_data['category'],
                            'price' => $product_data['price'],
                            'image_url' => $product_data['image_url']
                        ];
                        
                        if ($this->product->update($existing_product->id, $update_data)) {
                            $results['products_updated']++;
                            $state['products_updated']++;
                        }
                    }
                } else {
                    // New product, add it
                    // Set default stock to 0 for new products
                    $product_data['stock'] = 0;
                    $product_data['low_stock_threshold'] = DEFAULT_LOW_STOCK_THRESHOLD;
                    
                    if ($this->product->add($product_data)) {
                        $results['products_added']++;
                        $state['products_added']++;
                    }
                }
                
                $results['processed_products']++;
                $state['processed_products']++;
            }
            
            $results['total_products'] += count($wc_products);
            $state['total_products'] += count($wc_products);
            
            // Determine if we should continue
            $should_continue = count($wc_products) === $per_page;
            
            // If we've processed 10 batches without adding or updating any products,
            // and we're over 90% of our estimated total, assume we're done
            if ($page > 10 && $results['products_added'] + $results['products_updated'] == 0 && 
                $state['processed_products'] > ($state['estimated_total'] * 0.9)) {
                $should_continue = false;
            }
            
            if ($should_continue) {
                // More products to process, save state and return continuation token
                $page++;
                $state['page'] = $page;
                $state['per_page'] = $per_page;
                $state['products_added'] = $results['products_added'];
                $state['products_updated'] = $results['products_updated'];
                $state['errors'] = $results['errors'];
                $state['total_products'] = $results['total_products'];
                $state['processed_products'] = $results['processed_products'];
                
                $this->saveSyncState($state);
                
                $results['is_complete'] = false;
                $results['continuation_token'] = base64_encode(json_encode(['page' => $page]));
                
                // Calculate progress percentage based on estimated total
                if ($state['estimated_total'] > 0) {
                    $results['progress_percent'] = min(99, round(($state['processed_products'] / $state['estimated_total']) * 100));
                }
                
                return $results;
            } else {
                // No more products or we've determined we're likely done, mark sync as complete
                $this->completeSyncProcess($results);
                return $results;
            }
            
        } catch (Exception $e) {
            $results['status'] = 'error';
            $results['errors'][] = $e->getMessage();
            
            // Log detailed error
            error_log("Sync Error: " . $e->getMessage());
            
            // Log the error
            $this->logSync(0, 0, 'error', $e->getMessage());
            
            // Reset sync state
            $this->resetSyncState();
            
            return $results;
        }
    }
    
    /**
     * Complete the sync process
     * 
     * @param array $results Sync results
     */
    private function completeSyncProcess(&$results) {
        // Update results
        $results['status'] = 'success';
        $results['is_complete'] = true;
        $results['end_time'] = microtime(true);
        $results['duration'] = $results['end_time'] - $results['start_time'];
        $results['progress_percent'] = 100;
        
        // Log successful sync
        $this->logSync(
            $results['products_added'], 
            $results['products_updated'], 
            'success',
            "Total products: {$results['total_products']}, Processed: {$results['processed_products']}"
        );
        
        // Reset sync state
        $this->resetSyncState();
    }
    

    /**
     * Get the current sync state
     * 
     * @return array Sync state
     */
    public function getSyncState() {
        $default_state = [
            'status' => self::SYNC_STATE_IDLE,
            'page' => 1,
            'per_page' => 20,
            'products_added' => 0,
            'products_updated' => 0,
            'errors' => [],
            'start_time' => microtime(true),
            'full_sync' => false,
            'total_products' => 0,
            'processed_products' => 0,
            'estimated_total' => 5000,
            'last_count' => 0
        ];
        
        if (!isset($_SESSION['sync_state'])) {
            return $default_state;
        }
        
        $state = $_SESSION['sync_state'];
        
        // Make sure all expected keys exist, use defaults if missing
        foreach ($default_state as $key => $default_value) {
            if (!isset($state[$key])) {
                $state[$key] = $default_value;
            }
        }
        
        // Check if the sync has timed out (more than 60 minutes)
        if ($state['status'] === self::SYNC_STATE_IN_PROGRESS) {
            $timeout = 60 * 60; // 60 minutes
            if (microtime(true) - $state['start_time'] > $timeout) {
                // If timed out, reset state and return default
                $this->resetSyncState();
                return $default_state;
            }
        }
        
        return $state;
    }
    
    /**
     * Save the current sync state
     * 
     * @param array $state Sync state
     */
    private function saveSyncState($state) {
        $_SESSION['sync_state'] = $state;
    }
    
    /**
     * Reset the sync state
     */
    public function resetSyncState() {
        if (isset($_SESSION['sync_state'])) {
            unset($_SESSION['sync_state']);
        }
    }
    
    /**
     * Get the primary category name for a product
     * 
     * @param object $product WooCommerce product object
     * @return string
     */
    private function getCategoryName($product) {
        if (!empty($product->categories) && is_array($product->categories) && isset($product->categories[0]->name)) {
            return $product->categories[0]->name;
        }
        
        return '';
    }
    
    /**
     * Get the featured image URL for a product
     * 
     * @param object $product WooCommerce product object
     * @return string
     */
    private function getProductImage($product) {
        if (!empty($product->images) && is_array($product->images) && isset($product->images[0]->src)) {
            return $product->images[0]->src;
        }
        
        return '';
    }
    
    /**
     * Log a sync operation
     * 
     * @param int $products_added
     * @param int $products_updated
     * @param string $status
     * @param string $details
     * @return bool
     */
    private function logSync($products_added, $products_updated, $status, $details = '') {
        $now = date('Y-m-d H:i:s');
        $products_added = (int)$products_added;
        $products_updated = (int)$products_updated;
        $status = $this->db->escapeString($status);
        $details = $this->db->escapeString($details);
        
        $sql = "INSERT INTO sync_log 
                (sync_date, products_added, products_updated, status, details) 
                VALUES 
                ('$now', $products_added, $products_updated, '$status', '$details')";
        
        return $this->db->query($sql);
    }
    
    /**
     * Get the last sync log
     * 
     * @return object|null
     */
    public function getLastSync() {
        $sql = "SELECT * FROM sync_log ORDER BY sync_date DESC LIMIT 1";
        $result = $this->db->query($sql);
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_object();
        }
        
        return null;
    }
    
    /**
     * Get all sync logs
     * 
     * @param int $limit
     * @return array
     */
    public function getSyncLogs($limit = 10) {
        $limit = (int)$limit;
        $sql = "SELECT * FROM sync_log ORDER BY sync_date DESC LIMIT $limit";
        $result = $this->db->query($sql);
        $logs = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_object()) {
                $logs[] = $row;
            }
        }
        
        return $logs;
    }
    
    /**
     * Get current sync progress
     * 
     * @return array Progress information
     */
    public function getSyncProgress() {
        $state = $this->getSyncState();
        
        if ($state['status'] !== self::SYNC_STATE_IN_PROGRESS) {
            return [
                'in_progress' => false,
                'percent' => 0,
                'products_added' => 0,
                'products_updated' => 0,
                'processed' => 0,
                'total' => 0,
                'page' => 1,
                'last_count' => 0
            ];
        }
        
        // Calculate percent based on estimated total
        $percent = 0;
        if ($state['estimated_total'] > 0) {
            $percent = min(99, round(($state['processed_products'] / $state['estimated_total']) * 100));
        }
        
        return [
            'in_progress' => true,
            'percent' => $percent,
            'products_added' => isset($state['products_added']) ? $state['products_added'] : 0,
            'products_updated' => isset($state['products_updated']) ? $state['products_updated'] : 0,
            'processed' => isset($state['processed_products']) ? $state['processed_products'] : 0,
            'total' => isset($state['estimated_total']) ? $state['estimated_total'] : 0,
            'page' => isset($state['page']) ? $state['page'] : 1,
            'last_count' => isset($state['last_count']) ? $state['last_count'] : 0
        ];
    }
}