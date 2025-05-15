<?php
/**
 * Product Class
 * 
 * Handles operations for physical inventory products
 */

class Product {
    private $db;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Get a product by ID
     * 
     * @param int $id Product ID in the physical inventory
     * @return object|null
     */
    public function getById($id) {
        $id = $this->db->escapeString($id);
        $sql = "SELECT * FROM physical_inventory WHERE id = '$id'";
        $result = $this->db->query($sql);
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_object();
        }
        
        return null;
    }
    
    /**
     * Get a product by WooCommerce product ID
     * 
     * @param int $product_id WooCommerce product ID
     * @return object|null
     */
    public function getByProductId($product_id) {
        $product_id = $this->db->escapeString($product_id);
        $sql = "SELECT * FROM physical_inventory WHERE product_id = '$product_id'";
        $result = $this->db->query($sql);
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_object();
        }
        
        return null;
    }
    
    /**
     * Get a product by SKU
     * 
     * @param string $sku Product SKU
     * @return object|null
     */
    public function getBySku($sku) {
        $sku = $this->db->escapeString($sku);
        $sql = "SELECT * FROM physical_inventory WHERE sku = '$sku'";
        $result = $this->db->query($sql);
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_object();
        }
        
        return null;
    }
    
    /**
     * Search products
     * 
     * @param string $term Search term (title or SKU)
     * @param int $limit Number of results to return
     * @return array
     */
    public function search($term, $limit = 20) {
        $term = $this->db->escapeString($term);
        $limit = (int)$limit;
        
        $sql = "SELECT * FROM physical_inventory 
                WHERE title LIKE '%$term%' OR sku LIKE '%$term%' 
                ORDER BY title ASC 
                LIMIT $limit";
        
        $result = $this->db->query($sql);
        $products = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_object()) {
                $products[] = $row;
            }
        }
        
        return $products;
    }
    
    /**
     * Get all products
     * 
     * @param int $limit Number of results to return
     * @param int $offset Offset for pagination
     * @return array
     */
    public function getAll($limit = 50, $offset = 0) {
        $limit = (int)$limit;
        $offset = (int)$offset;
        
        $sql = "SELECT * FROM physical_inventory 
                ORDER BY title ASC 
                LIMIT $limit OFFSET $offset";
        
        $result = $this->db->query($sql);
        $products = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_object()) {
                $products[] = $row;
            }
        }
        
        return $products;
    }
    
    /**
     * Count total products
     * 
     * @return int
     */
    public function countAll() {
        $sql = "SELECT COUNT(*) as total FROM physical_inventory";
        $result = $this->db->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_object();
            return (int)$row->total;
        }
        
        return 0;
    }
    
    /**
     * Add a new product or update if exists
     * 
     * @param array $data Product data
     * @return int|false
     */
    public function addOrUpdate($data) {
        // Check if product already exists by product_id
        $existing = $this->getByProductId($data['product_id']);
        
        if ($existing) {
            return $this->update($existing->id, $data);
        } else {
            return $this->add($data);
        }
    }
    
    /**
     * Add a new product
     * 
     * @param array $data Product data
     * @return int|false New ID or false on failure
     */
    public function add($data) {
        $product_id = $this->db->escapeString($data['product_id']);
        $title = $this->db->escapeString($data['title']);
        $sku = $this->db->escapeString($data['sku']);
        $category = $this->db->escapeString($data['category']);
        $price = (float)$data['price'];
        $stock = isset($data['stock']) ? (int)$data['stock'] : 0;
        $image_url = $this->db->escapeString($data['image_url']);
        $now = date('Y-m-d H:i:s');
        $is_low_stock = isset($data['is_low_stock']) ? (int)$data['is_low_stock'] : 0;
        $low_stock_threshold = isset($data['low_stock_threshold']) ? (int)$data['low_stock_threshold'] : DEFAULT_LOW_STOCK_THRESHOLD;
        $notes = isset($data['notes']) ? $this->db->escapeString($data['notes']) : '';
        
        $sql = "INSERT INTO physical_inventory 
                (product_id, title, sku, category, price, stock, image_url, last_updated, created_at, is_low_stock, low_stock_threshold, notes) 
                VALUES 
                ('$product_id', '$title', '$sku', '$category', $price, $stock, '$image_url', '$now', '$now', $is_low_stock, $low_stock_threshold, '$notes')";
        
        if ($this->db->query($sql)) {
            return $this->db->lastInsertId();
        }
        
        return false;
    }
    
    /**
     * Update an existing product
     * 
     * @param int $id Product ID in the physical inventory
     * @param array $data Product data
     * @return bool
     */
    public function update($id, $data) {
        $id = (int)$id;
        $updates = [];
        
        // Only update fields that are provided
        if (isset($data['title'])) {
            $title = $this->db->escapeString($data['title']);
            $updates[] = "title = '$title'";
        }
        
        if (isset($data['sku'])) {
            $sku = $this->db->escapeString($data['sku']);
            $updates[] = "sku = '$sku'";
        }
        
        if (isset($data['category'])) {
            $category = $this->db->escapeString($data['category']);
            $updates[] = "category = '$category'";
        }
        
        if (isset($data['price'])) {
            $price = (float)$data['price'];
            $updates[] = "price = $price";
        }
        
        if (isset($data['stock'])) {
            $stock = (int)$data['stock'];
            $updates[] = "stock = $stock";
            
            // Auto-update low stock flag
            if (isset($data['low_stock_threshold'])) {
                $low_stock_threshold = (int)$data['low_stock_threshold'];
            } else {
                // Get current threshold
                $product = $this->getById($id);
                $low_stock_threshold = $product ? $product->low_stock_threshold : DEFAULT_LOW_STOCK_THRESHOLD;
            }
            
            $is_low_stock = ($stock <= $low_stock_threshold) ? 1 : 0;
            $updates[] = "is_low_stock = $is_low_stock";
        }
        
        if (isset($data['image_url'])) {
            $image_url = $this->db->escapeString($data['image_url']);
            $updates[] = "image_url = '$image_url'";
        }
        
        if (isset($data['is_low_stock'])) {
            $is_low_stock = (int)$data['is_low_stock'];
            $updates[] = "is_low_stock = $is_low_stock";
        }
        
        if (isset($data['low_stock_threshold'])) {
            $low_stock_threshold = (int)$data['low_stock_threshold'];
            $updates[] = "low_stock_threshold = $low_stock_threshold";
            
            // Re-evaluate low stock status if we have stock data
            if (isset($data['stock'])) {
                $stock = (int)$data['stock'];
                $is_low_stock = ($stock <= $low_stock_threshold) ? 1 : 0;
                $updates[] = "is_low_stock = $is_low_stock";
            } else {
                // Get current stock
                $product = $this->getById($id);
                if ($product) {
                    $is_low_stock = ($product->stock <= $low_stock_threshold) ? 1 : 0;
                    $updates[] = "is_low_stock = $is_low_stock";
                }
            }
        }
        
        if (isset($data['notes'])) {
            $notes = $this->db->escapeString($data['notes']);
            $updates[] = "notes = '$notes'";
        }
        
        // Always update the last_updated timestamp
        $now = date('Y-m-d H:i:s');
        $updates[] = "last_updated = '$now'";
        
        if (empty($updates)) {
            return false;
        }
        
        $sql = "UPDATE physical_inventory SET " . implode(', ', $updates) . " WHERE id = $id";
        
        return $this->db->query($sql);
    }
    
    /**
     * Update product stock
     * 
     * @param int $id Product ID in the physical inventory
     * @param int $stock New stock quantity
     * @return bool
     */
    public function updateStock($id, $stock) {
        $product = $this->getById($id);
        
        if (!$product) {
            return false;
        }
        
        $data = [
            'stock' => (int)$stock
        ];
        
        return $this->update($id, $data);
    }
    
    /**
     * Get recently updated products
     * 
     * @param int $limit Number of products to retrieve
     * @return array
     */
    public function getRecentlyUpdated($limit = 10) {
        $limit = (int)$limit;
        
        $sql = "SELECT * FROM physical_inventory 
                ORDER BY last_updated DESC 
                LIMIT $limit";
        
        $result = $this->db->query($sql);
        $products = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_object()) {
                $products[] = $row;
            }
        }
        
        return $products;
    }
    
    /**
     * Get products with low stock in physical store
     * 
     * @param int $limit Number of products to retrieve
     * @return array
     */
    public function getLowStock($limit = 10) {
        $limit = (int)$limit;
        
        $sql = "SELECT * FROM physical_inventory 
                WHERE is_low_stock = 1 AND stock > 0 
                ORDER BY stock ASC 
                LIMIT $limit";
        
        $result = $this->db->query($sql);
        $products = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_object()) {
                $products[] = $row;
            }
        }
        
        return $products;
    }
    
    /**
     * Get total inventory value
     * 
     * @return float
     */
    public function getTotalValue() {
        $sql = "SELECT SUM(price * stock) as total_value FROM physical_inventory";
        $result = $this->db->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_object();
            return (float)$row->total_value;
        }
        
        return 0.0;
    }
    
    /**
     * Delete a product
     * 
     * @param int $id Product ID in the physical inventory
     * @return bool
     */
    public function delete($id) {
        $id = (int)$id;
        $sql = "DELETE FROM physical_inventory WHERE id = $id";
        
        return $this->db->query($sql);
    }
}