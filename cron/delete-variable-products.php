<?php
/**
 * Delete Variable Products Script
 * 
 * This script removes all variable products and their variations 
 * that were imported by the import-variable-products.php script.
 * 
 * Usage: php delete-variable-products.php
 */

// Set unlimited execution time
set_time_limit(0);

// Include required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Product.php';
require_once __DIR__ . '/../includes/functions.php';

echo "Starting deletion of variable products and their variations...\n";

// Initialize classes
$db = Database::getInstance();
$product_manager = new Product();

// Open log file for results
$log_file = 'deleted-variable-products.log';
$log = fopen($log_file, 'w');
fwrite($log, "# Variable Products Deletion Log\n");
fwrite($log, "# Generated on: " . date('Y-m-d H:i:s') . "\n\n");

// Find products with "Variable product" or "Variation of product" in notes
$sql = "SELECT * FROM physical_inventory WHERE notes LIKE '%Variable product%' OR notes LIKE '%Variation of product%'";
$result = $db->query($sql);

$deleted_count = 0;
$parent_products = [];
$variation_products = [];

if ($result && $result->num_rows > 0) {
    echo "Found " . $result->num_rows . " variable products and variations to delete.\n";
    
    while ($product = $result->fetch_object()) {
        if (strpos($product->notes, 'Variable product') !== false) {
            $parent_products[] = $product;
        } else {
            $variation_products[] = $product;
        }
    }
    
    echo "Parent variable products: " . count($parent_products) . "\n";
    echo "Variation products: " . count($variation_products) . "\n";
    
    // Delete variations first
    echo "Deleting variations...\n";
    foreach ($variation_products as $product) {
        echo "Deleting variation: " . $product->title . " (ID: " . $product->id . ")\n";
        fwrite($log, "DELETED VARIATION: " . $product->title . " (ID: " . $product->id . ", WC_ID: " . $product->product_id . ")\n");
        
        if ($product_manager->delete($product->id)) {
            $deleted_count++;
        } else {
            fwrite($log, "  ERROR: Failed to delete variation ID " . $product->id . "\n");
            echo "  ERROR: Failed to delete variation ID " . $product->id . "\n";
        }
    }
    
    // Then delete parent products
    echo "Deleting parent products...\n";
    foreach ($parent_products as $product) {
        echo "Deleting parent product: " . $product->title . " (ID: " . $product->id . ")\n";
        fwrite($log, "DELETED PARENT: " . $product->title . " (ID: " . $product->id . ", WC_ID: " . $product->product_id . ")\n");
        
        if ($product_manager->delete($product->id)) {
            $deleted_count++;
        } else {
            fwrite($log, "  ERROR: Failed to delete parent ID " . $product->id . "\n");
            echo "  ERROR: Failed to delete parent ID " . $product->id . "\n";
        }
    }
    
} else {
    echo "No variable products or variations found in the database.\n";
}

// Write summary to log
fwrite($log, "\n# Summary\n");
fwrite($log, "Total products deleted: " . $deleted_count . "\n");
fwrite($log, "Parent products deleted: " . count($parent_products) . "\n");
fwrite($log, "Variations deleted: " . count($variation_products) . "\n");
fclose($log);

echo "\nDeletion completed!\n";
echo "Total products deleted: " . $deleted_count . "\n";
echo "Results saved to: " . $log_file . "\n";
echo "\nTo reimport variable products correctly, fix the WooCommerce.php class first, then run the updated import-variable-products.php script.\n";