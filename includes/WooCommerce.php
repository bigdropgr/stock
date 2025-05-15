<?php
/**
 * WooCommerce API Integration Class
 * 
 * Handles all WooCommerce API operations
 */

class WooCommerce {
    private $consumer_key;
    private $consumer_secret;
    private $store_url;
    private $api_version;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->consumer_key = WC_CONSUMER_KEY;
        $this->consumer_secret = WC_CONSUMER_SECRET;
        $this->store_url = WC_STORE_URL;
        $this->api_version = WC_API_VERSION;
    }
    
    /**
     * Get the total number of products in WooCommerce
     * 
     * @return array With count and success status
     */
    public function getProductCount() {
        try {
            $endpoint = "products";
            $params = [
                'per_page' => 1,
                'status' => 'publish'
            ];
            
            // Initialize cURL
            $url = $this->store_url . '/wp-json/' . $this->api_version . '/' . $endpoint . '?' . http_build_query($params);
            $url .= '&consumer_key=' . $this->consumer_key . '&consumer_secret=' . $this->consumer_secret;
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            
            curl_setopt($ch, CURLOPT_HEADER, true);
            $response = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($response, 0, $headerSize);
            
            // Check for X-WP-Total header
            preg_match('/X-WP-Total: (\d+)/', $headers, $matches);
            $total = isset($matches[1]) ? intval($matches[1]) : 0;
            
            curl_close($ch);
            
            return [
                'success' => true,
                'count' => $total
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'count' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get the range of product IDs in WooCommerce
     * 
     * @return array With min and max IDs
     */
    public function getProductIdRange() {
        try {
            // Get first product
            $first_page = $this->getProducts(1, 1);
            $min_id = isset($first_page[0]->id) ? $first_page[0]->id : 1;
            
            // Get total count
            $count_result = $this->getProductCount();
            $total = $count_result['count'];
            
            // Get last page
            $per_page = 1;
            $last_page = ceil($total / $per_page);
            $last_products = $this->getProducts($per_page, $last_page);
            $max_id = isset($last_products[0]->id) ? $last_products[0]->id : 100000;
            
            return [
                'min' => $min_id,
                'max' => $max_id
            ];
        } catch (Exception $e) {
            return [
                'min' => 1,
                'max' => 100000  // Default to a large range
            ];
        }
    }
    
    /**
     * Get all products from WooCommerce
     * 
     * @param int $per_page Number of products per page
     * @param int $page Page number
     * @return array
     */
    public function getProducts($per_page = 100, $page = 1) {
        $endpoint = "products";
        $params = [
            'per_page' => $per_page,
            'page' => $page,
            'status' => 'publish'
        ];
        
        return $this->makeRequest($endpoint, 'GET', $params);
    }
    
    /**
     * Get a single product by ID
     * 
     * @param int $id Product ID
     * @return array
     */
    public function getProduct($id) {
        $endpoint = "products/{$id}";
        return $this->makeRequest($endpoint, 'GET');
    }
    
    /**
     * Search products
     * 
     * @param string $term Search term
     * @param int $per_page Number of products per page
     * @return array
     */
    public function searchProducts($term, $per_page = 20) {
        $endpoint = "products";
        $params = [
            'search' => $term,
            'per_page' => $per_page,
            'status' => 'publish'
        ];
        
        return $this->makeRequest($endpoint, 'GET', $params);
    }
    
    /**
     * Get products by SKU
     * 
     * @param string $sku Product SKU
     * @return array
     */
    public function getProductBySku($sku) {
        $endpoint = "products";
        $params = [
            'sku' => $sku
        ];
        
        return $this->makeRequest($endpoint, 'GET', $params);
    }
    
    /**
     * Get product categories
     * 
     * @param int $per_page Number of categories per page
     * @return array
     */
    public function getCategories($per_page = 100) {
        $endpoint = "products/categories";
        $params = [
            'per_page' => $per_page
        ];
        
        return $this->makeRequest($endpoint, 'GET', $params);
    }
    
    /**
     * Get top selling products
     * 
     * @param int $limit Number of products to retrieve
     * @return array
     */
    public function getTopSellingProducts($limit = 10) {
        try {
            // We'll get all products and sort by total sales
            $endpoint = "products";
            $params = [
                'per_page' => 100,
                'orderby' => 'popularity',
                'order' => 'desc',
                'status' => 'publish'
            ];
            
            $products = $this->makeRequest($endpoint, 'GET', $params);
            
            // If an error occurred, return empty array
            if (empty($products) || !is_array($products)) {
                return [];
            }
            
            // Sort by total_sales and take top $limit
            usort($products, function($a, $b) {
                return isset($b->total_sales) && isset($a->total_sales) 
                    ? $b->total_sales - $a->total_sales 
                    : 0;
            });
            
            return array_slice($products, 0, $limit);
        } catch (Exception $e) {
            error_log("Error getting top selling products: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get products with low stock in WooCommerce
     * 
     * @param int $threshold Low stock threshold
     * @param int $limit Number of products to retrieve
     * @return array
     */
    public function getLowStockProducts($threshold = 5, $limit = 20) {
        try {
            $endpoint = "products";
            $params = [
                'per_page' => 100,
                'status' => 'publish',
                'stock_status' => 'instock'
            ];
            
            $products = $this->makeRequest($endpoint, 'GET', $params);
            
            // If an error occurred, return empty array
            if (empty($products) || !is_array($products)) {
                return [];
            }
            
            // Filter products with stock <= threshold
            $low_stock = array_filter($products, function($product) use ($threshold) {
                return isset($product->stock_quantity) && 
                       $product->stock_quantity <= $threshold && 
                       $product->stock_quantity > 0;
            });
            
            // Sort by stock quantity (ascending)
            usort($low_stock, function($a, $b) {
                return isset($a->stock_quantity) && isset($b->stock_quantity)
                    ? $a->stock_quantity - $b->stock_quantity
                    : 0;
            });
            
            return array_slice($low_stock, 0, $limit);
        } catch (Exception $e) {
            error_log("Error getting low stock products: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recently added products
     * 
     * @param int $days Number of days to look back
     * @param int $limit Number of products to retrieve
     * @return array
     */
    public function getRecentlyAddedProducts($days = 7, $limit = 10) {
        try {
            $endpoint = "products";
            $date = date('Y-m-d', strtotime("-{$days} days"));
            
            $params = [
                'per_page' => 100,
                'status' => 'publish',
                'after' => $date
            ];
            
            $products = $this->makeRequest($endpoint, 'GET', $params);
            
            // If an error occurred, return empty array
            if (empty($products) || !is_array($products)) {
                return [];
            }
            
            // Sort by date created (newest first)
            usort($products, function($a, $b) {
                return isset($a->date_created) && isset($b->date_created)
                    ? strtotime($b->date_created) - strtotime($a->date_created)
                    : 0;
            });
            
            return array_slice($products, 0, $limit);
        } catch (Exception $e) {
            error_log("Error getting recently added products: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Test the connection to WooCommerce API
     * 
     * @return array Connection status and message
     */
    public function testConnection() {
        try {
            // Make sure cURL is available
            if (!function_exists('curl_init')) {
                return [
                    'success' => false,
                    'message' => "cURL is not available on this server."
                ];
            }
            
            // Make a simple request to check if the API is accessible
            $url = $this->store_url . '/wp-json/';
            
            // Initialize cURL
            $ch = curl_init();
            
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification for testing
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Disable SSL host verification for testing
            
            // Execute cURL request
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            curl_close($ch);
            
            // Check for errors
            if ($error) {
                return [
                    'success' => false,
                    'message' => "cURL Error: " . $error
                ];
            }
            
            if ($httpCode >= 400) {
                return [
                    'success' => false,
                    'message' => "HTTP Error {$httpCode}: " . $response
                ];
            }
            
            // Now test with authentication
            $endpoint = 'wp-json/' . $this->api_version . '/products';
            $auth_url = $this->store_url . '/' . $endpoint . '?consumer_key=' . $this->consumer_key . '&consumer_secret=' . $this->consumer_secret . '&per_page=1';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $auth_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $auth_response = curl_exec($ch);
            $auth_error = curl_error($ch);
            $auth_httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            curl_close($ch);
            
            if ($auth_error) {
                return [
                    'success' => false,
                    'message' => "API Authentication Error: " . $auth_error
                ];
            }
            
            if ($auth_httpCode >= 400) {
                return [
                    'success' => false,
                    'message' => "API Authentication Failed (HTTP {$auth_httpCode}): " . $auth_response
                ];
            }
            
            return [
                'success' => true,
                'message' => "Connection successful"
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Exception: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Make a request to the WooCommerce API
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param array $params Query parameters
     * @return array|object Response data
     */
    private function makeRequest($endpoint, $method = 'GET', $params = []) {
        try {
            // Check if cURL is available
            if (!function_exists('curl_init')) {
                error_log("cURL is not available. Cannot make API requests.");
                return [];
            }
            
            $url = $this->store_url . '/wp-json/' . $this->api_version . '/' . $endpoint;
            
            // Add authentication
            $params['consumer_key'] = $this->consumer_key;
            $params['consumer_secret'] = $this->consumer_secret;
            
            // Build query string
            if (!empty($params) && $method === 'GET') {
                $url .= '?' . http_build_query($params);
            }
            
            // Log the request in development mode
            if (defined('APP_ENV') && APP_ENV === 'development') {
                error_log("WooCommerce API Request: " . $url);
            }
            
            // Initialize cURL
            $ch = curl_init();
            
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification for testing
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Disable SSL host verification for testing
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            
            // Set request method
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            } elseif ($method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            } elseif ($method === 'DELETE') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }
            
            // Execute cURL request
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            curl_close($ch);
            
            // Handle errors
            if ($error) {
                error_log("WooCommerce API Error: " . $error);
                return [];
            }
            
            if ($httpCode >= 400) {
                error_log("WooCommerce API Error (HTTP {$httpCode}): " . $response);
                return [];
            }
            
            // Decode response
            $result = json_decode($response);
            
            // Check if response is valid JSON
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("WooCommerce API Error: Invalid JSON response - " . $response);
                return [];
            }
            
            return $result;
        } catch (Exception $e) {
            error_log("WooCommerce API Exception: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get product variations
     * 
     * @param int $product_id Product ID
     * @return array List of variations
     */
    public function getProductVariations($product_id) {
        try {
            $endpoint = "products/{$product_id}/variations";
            $params = [
                'per_page' => 100
            ];
            
            return $this->makeRequest($endpoint, 'GET', $params);
        } catch (Exception $e) {
            error_log("Error getting product variations: " . $e->getMessage());
            return [];
        }
    }

    /**
     * WooCommerce API Helper Extensions
     * 
     * Add these methods to your WooCommerce.php class to enable status filtering and advanced parameters
     */

    /**
     * Get products with custom parameters
     * 
     * @param int $per_page Number of products per page
     * @param int $page Page number
     * @param array $custom_params Additional parameters to pass to the API
     * @return array
     */
    public function getProductsWithParams($per_page = 100, $page = 1, $custom_params = []) {
        $endpoint = "products";
        $params = array_merge([
            'per_page' => $per_page,
            'page' => $page
        ], $custom_params);
        
        return $this->makeRequest($endpoint, 'GET', $params);
    }

    /**
     * Get product variations with custom parameters
     * 
     * @param int $product_id Product ID
     * @param array $custom_params Additional parameters to pass to the API
     * @return array
     */
    public function getProductVariationsWithParams($product_id, $custom_params = []) {
        try {
            $endpoint = "products/{$product_id}/variations";
            $params = array_merge([
                'per_page' => 100
            ], $custom_params);
            
            return $this->makeRequest($endpoint, 'GET', $params);
        } catch (Exception $e) {
            error_log("Error getting product variations: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Directly fetch variation by ID with custom parameters
     * 
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @param array $custom_params Additional parameters
     * @return object|null
     */
    public function getVariationById($product_id, $variation_id, $custom_params = []) {
        try {
            $endpoint = "products/{$product_id}/variations/{$variation_id}";
            return $this->makeRequest($endpoint, 'GET', $custom_params);
        } catch (Exception $e) {
            error_log("Error getting variation by ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update a variation's status
     * 
     * @param int $product_id The parent product ID
     * @param int $variation_id The variation ID
     * @param string $status The new status (publish, draft, pending, or trash)
     * @param bool $visible Whether the variation should be visible
     * @return object|bool The result or false on failure
     */
    public function updateVariationStatus($product_id, $variation_id, $status = "publish", $visible = true) {
        try {
            $endpoint = "products/{$product_id}/variations/{$variation_id}";
            $params = [
                "status" => $status,
                "visible" => $visible
            ];
            
            return $this->makeRequest($endpoint, "PUT", $params);
        } catch (Exception $e) {
            error_log("Error updating variation status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a variation
     * 
     * @param int $product_id The parent product ID
     * @param int $variation_id The variation ID
     * @param bool $force Whether to permanently delete (true) or move to trash (false)
     * @return object|bool The result or false on failure
     */
    public function deleteVariation($product_id, $variation_id, $force = true) {
        try {
            $endpoint = "products/{$product_id}/variations/{$variation_id}";
            $params = [
                "force" => $force
            ];
            
            return $this->makeRequest($endpoint, "DELETE", $params);
        }
        catch (Exception $e) {
            error_log("Error deleting variation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get only published variations for a product
     * 
     * @param int $product_id Product ID
     * @return array List of published variations
     */
    public function getPublishedProductVariations($product_id) {
        $all_variations = $this->getProductVariations($product_id);
        $published_variations = [];
        
        foreach ($all_variations as $variation) {
            if (isset($variation->status) && $variation->status === 'publish') {
                $published_variations[] = $variation;
            }
        }
        
        return $published_variations;
    }

    /**
     * Import a product with only published variations
     * 
     * @param object $product_data The WooCommerce product object
     * @param object $product_manager The Product manager object
     * @return int|bool The inserted ID or false on failure
     */
    public function importPublishedVariationsOnly($product_data, $product_manager) {
        // Skip if not a variable product
        if (!isset($product_data->type) || $product_data->type !== 'variable') {
            return false;
        }
        
        // Create the parent product
        $parent_data = [
            'product_id' => $product_data->id,
            'title' => $product_data->name,
            'sku' => isset($product_data->sku) ? $product_data->sku : '',
            'category' => isset($product_data->categories[0]->name) ? $product_data->categories[0]->name : '',
            'price' => isset($product_data->price) ? $product_data->price : 0,
            'image_url' => !empty($product_data->images) && isset($product_data->images[0]->src) ? 
                        $product_data->images[0]->src : '',
            'stock' => 0,
            'low_stock_threshold' => defined('DEFAULT_LOW_STOCK_THRESHOLD') ? DEFAULT_LOW_STOCK_THRESHOLD : 5,
            'notes' => 'Variable product'
        ];
        
        // Add parent product
        $parent_id = $product_manager->add($parent_data);
        
        if (!$parent_id) {
            return false;
        }
        
        // Get only published variations
        $variations = $this->getPublishedProductVariations($product_data->id);
        $imported_variations = 0;
        
        // Add each published variation
        foreach ($variations as $variation) {
            // Create variation title
            $variation_title = $product_data->name;
            $attributes_desc = "";
            
            if (!empty($variation->attributes)) {
                $attributes = [];
                foreach ($variation->attributes as $attr) {
                    if (isset($attr->option)) {
                        $attributes[] = $attr->option;
                    }
                }
                if (!empty($attributes)) {
                    $attributes_desc = implode(', ', $attributes);
                    $variation_title .= ' - ' . $attributes_desc;
                }
            }
            
            // Prepare variation data
            $variation_data = [
                'product_id' => $variation->id,
                'title' => $variation_title,
                'sku' => isset($variation->sku) ? $variation->sku : '',
                'category' => isset($product_data->categories[0]->name) ? $product_data->categories[0]->name : '',
                'price' => isset($variation->price) ? $variation->price : 0,
                'image_url' => !empty($variation->image) && isset($variation->image->src) ? $variation->image->src : 
                            (!empty($product_data->images) && isset($product_data->images[0]->src) ? $product_data->images[0]->src : ''),
                'stock' => 0,
                'low_stock_threshold' => defined('DEFAULT_LOW_STOCK_THRESHOLD') ? DEFAULT_LOW_STOCK_THRESHOLD : 5,
                'notes' => 'Variation of product ID: ' . $product_data->id . ' | ' . $attributes_desc
            ];
            
            // Add variation
            if ($product_manager->add($variation_data)) {
                $imported_variations++;
            }
        }
        
        return $parent_id;
    }
}