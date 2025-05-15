<?php
/**
 * Products API
 * 
 * Handles AJAX requests for product operations
 */

// Include required files
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/Database.php';
require_once '../includes/Auth.php';
require_once '../includes/Product.php';
require_once '../includes/functions.php';

// Initialize classes
$auth = new Auth();
$product = new Product();

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

// Handle request
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

switch ($action) {
    case 'search':
        // Search products
        $term = isset($_GET['term']) ? $_GET['term'] : '';
        
        if (empty($term)) {
            json_response(['success' => false, 'message' => 'Search term is required']);
        }
        
        $results = $product->search($term);
        json_response($results);
        break;
        
    case 'get':
        // Get a single product
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid product ID']);
        }
        
        $product_data = $product->getById($id);
        
        if (!$product_data) {
            json_response(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        json_response(['success' => true, 'product' => $product_data]);
        break;
        
    case 'update_stock':
        // Update product stock
        $id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $stock = isset($_POST['stock']) ? intval($_POST['stock']) : 0;
        
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid product ID']);
        }
        
        if ($stock < 0) {
            json_response(['success' => false, 'message' => 'Stock must be a positive number']);
        }
        
        $product_data = $product->getById($id);
        
        if (!$product_data) {
            json_response(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        $result = $product->updateStock($id, $stock);
        
        if ($result) {
            json_response(['success' => true, 'message' => 'Stock updated successfully', 'stock' => $stock]);
        } else {
            json_response(['success' => false, 'message' => 'Failed to update stock']);
        }
        break;
        
    case 'update':
        // Update product details
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid product ID']);
        }
        
        $product_data = $product->getById($id);
        
        if (!$product_data) {
            json_response(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        // Build update data
        $update_data = [];
        
        if (isset($_POST['stock'])) {
            $update_data['stock'] = intval($_POST['stock']);
        }
        
        if (isset($_POST['low_stock_threshold'])) {
            $update_data['low_stock_threshold'] = intval($_POST['low_stock_threshold']);
        }
        
        if (isset($_POST['notes'])) {
            $update_data['notes'] = $_POST['notes'];
        }
        
        if (empty($update_data)) {
            json_response(['success' => false, 'message' => 'No data to update']);
        }
        
        $result = $product->update($id, $update_data);
        
        if ($result) {
            // Get updated product
            $updated_product = $product->getById($id);
            json_response(['success' => true, 'message' => 'Product updated successfully', 'product' => $updated_product]);
        } else {
            json_response(['success' => false, 'message' => 'Failed to update product']);
        }
        break;
        
    case 'list':
        // List products (paginated)
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;
        
        if ($page < 1) {
            $page = 1;
        }
        
        if ($per_page < 1 || $per_page > 100) {
            $per_page = 20;
        }
        
        $offset = ($page - 1) * $per_page;
        $products = $product->getAll($per_page, $offset);
        $total = $product->countAll();
        
        json_response([
            'success' => true,
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        ]);
        break;
        
    case 'low_stock':
        // Get products with low stock
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        
        if ($limit < 1 || $limit > 100) {
            $limit = 20;
        }
        
        $low_stock = $product->getLowStock($limit);
        
        json_response([
            'success' => true,
            'products' => $low_stock,
            'count' => count($low_stock)
        ]);
        break;
        
    case 'recently_updated':
        // Get recently updated products
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        
        if ($limit < 1 || $limit > 100) {
            $limit = 20;
        }
        
        $recently_updated = $product->getRecentlyUpdated($limit);
        
        json_response([
            'success' => true,
            'products' => $recently_updated,
            'count' => count($recently_updated)
        ]);
        break;
        
    case 'stats':
        // Get product statistics
        $total_products = $product->countAll();
        $total_value = $product->getTotalValue();
        $low_stock_count = count($product->getLowStock(1000)); // Count all low stock products
        
        json_response([
            'success' => true,
            'stats' => [
                'total_products' => $total_products,
                'total_value' => $total_value,
                'low_stock_count' => $low_stock_count
            ]
        ]);
        break;
        
    default:
        json_response(['success' => false, 'message' => 'Invalid action'], 400);
        break;
}