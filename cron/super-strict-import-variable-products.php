<?php
/**
 * Super Strict Variable Products Import Script
 * 
 * This script uses multiple validation layers to ensure no duplicate variations
 * are imported, addressing the issue where 634 actual variations end up as 785 imports.
 * 
 * Usage: php super-strict-import-variable-products.php [--output=file.txt]
 */

// Set unlimited execution time
set_time_limit(0);

// Include required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Product.php';
require_once __DIR__ . '/../includes/WooCommerce.php';
require_once __DIR__ . '/../includes/functions.php';

// Parse command line arguments
$options = getopt('', ['output:', 'debug']);
$output_file = isset($options['output']) ? $options['output'] : 'variable-products-import.log';
$debug_mode = isset($options['debug']);

echo "Starting super strict variable products import...\n";
echo "Debug mode: " . ($debug_mode ? "ON" : "OFF") . "\n";

// Open log file
$log_file = fopen($output_file, 'w');
fwrite($log_file, "# Variable Products Import Log\n");
fwrite($log_file, "# Generated on: " . date('Y-m-d H:i:s') . "\n\n");

// Initialize classes
$db = Database::getInstance();
$woocommerce = new WooCommerce();
$product_manager = new Product();

// Test WooCommerce connection
echo "Testing WooCommerce connection...\n";
$connection_test = $woocommerce->testConnection();
if (!$connection_test['success']) {
    die("WooCommerce API connection failed: " . $connection_test['message'] . "\n");
}
echo "Connection successful!\n";

// Get all variation IDs already in the database
echo "Getting all existing variation IDs from the database...\n";
$sql = "SELECT product_id FROM physical_inventory WHERE notes LIKE '%Variation of product%'";
$result = $db->query($sql);
$existing_variation_ids = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_object()) {
        $existing_variation_ids[$row->product_id] = true;
    }
}

echo "Found " . count($existing_variation_ids) . " existing variations in the database.\n";

// Master lists for tracking what we've already processed
$processed_parent_ids = [];
$processed_variation_ids = [];

// Get all variable products first to establish complete record
echo "Fetching all variable products first...\n";
$all_variable_products = [];
$per_page = 100;
$page = 1;

do {
    echo "Fetching variable products page $page...\n";
    
    $wc_products = $woocommerce->getProducts($per_page, $page);
    
    if (empty($wc_products)) {
        break;
    }
    
    foreach ($wc_products as $product) {
        if (isset($product->type) && $product->type === 'variable') {
            $all_variable_products[$product->id] = $product;
        }
    }
    
    $page++;
    
} while (count($wc_products) === $per_page);

echo "Found " . count($all_variable_products) . " variable products.\n";
fwrite($log_file, "Found " . count($all_variable_products) . " variable products.\n\n");

// Pre-fetch all variations to create a complete reference
echo "Pre-fetching all variations...\n";
$all_variations = [];
$all_variation_count = 0;

foreach ($all_variable_products as $parent_id => $parent_product) {
    echo "Fetching variations for product $parent_id: {$parent_product->name}...\n";
    $variations = $woocommerce->getProductVariations($parent_id);
    
    if (!empty($variations)) {
        // Create a de-duplicated set of variations for this product
        $deduplicated_variations = [];
        $seen_variation_ids = [];
        
        foreach ($variations as $variation) {
            if (!isset($seen_variation_ids[$variation->id])) {
                $deduplicated_variations[] = $variation;
                $seen_variation_ids[$variation->id] = true;
            } else if ($debug_mode) {
                echo "DUPLICATE DETECTED in API response: Variation ID {$variation->id}\n";
                fwrite($log_file, "DUPLICATE DETECTED in API response: Variation ID {$variation->id} for product {$parent_id}\n");
            }
        }
        
        $all_variations[$parent_id] = $deduplicated_variations;
        $all_variation_count += count($deduplicated_variations);
        
        echo "  Found " . count($variations) . " variations, " . count($deduplicated_variations) . " unique.\n";
    } else {
        echo "  No variations found for this product.\n";
    }
}

echo "Completed pre-fetch. Found $all_variation_count total unique variations across all products.\n";
fwrite($log_file, "Total unique variations found across all products: $all_variation_count\n\n");

// Now start the actual import process using our pre-fetched and de-duplicated data
echo "\nStarting the import process...\n";

$variable_products_imported = 0;
$variations_imported = 0;
$start_time = microtime(true);

// Process each variable product
foreach ($all_variable_products as $parent_id => $parent_product) {
    // Skip if we've already processed this parent
    if (isset($processed_parent_ids[$parent_id])) {
        echo "Skipping already processed parent product ID $parent_id\n";
        continue;
    }
    
    // Mark this parent as processed
    $processed_parent_ids[$parent_id] = true;
    
    echo "Processing variable product: {$parent_product->name} (ID: $parent_id)\n";
    
    // Check if product already exists
    $existing_product = $product_manager->getByProductId($parent_id);
    
    if ($existing_product) {
        echo "  - Already imported, skipping\n";
        fwrite($log_file, "SKIPPED: {$parent_product->name} (ID: $parent_id) - Already exists\n");
        continue;
    }
    
    // Prepare product data for parent product
    $product_data = [
        'product_id' => $parent_id,
        'title' => $parent_product->name,
        'sku' => isset($parent_product->sku) ? $parent_product->sku : '',
        'category' => isset($parent_product->categories[0]->name) ? $parent_product->categories[0]->name : '',
        'price' => isset($parent_product->price) ? $parent_product->price : 0,
        'image_url' => !empty($parent_product->images) && isset($parent_product->images[0]->src) ? 
                       $parent_product->images[0]->src : '',
        'stock' => 0,
        'low_stock_threshold' => DEFAULT_LOW_STOCK_THRESHOLD,
        'notes' => 'Variable product'
    ];
    
    // Add parent product
    $parent_db_id = $product_manager->add($product_data);
    
    if ($parent_db_id) {
        $variable_products_imported++;
        echo "  - Imported variable product\n";
        fwrite($log_file, "IMPORTED PARENT: {$parent_product->name} (ID: $parent_id)\n");
        
        // Check if we have variations for this product
        if (isset($all_variations[$parent_id]) && !empty($all_variations[$parent_id])) {
            $product_variations = $all_variations[$parent_id];
            echo "  - Processing " . count($product_variations) . " variations\n";
            
            foreach ($product_variations as $variation) {
                $variation_id = $variation->id;
                
                // Triple-layer duplicate check
                if (isset($processed_variation_ids[$variation_id])) {
                    echo "    - Skipping already processed variation ID: $variation_id\n";
                    continue;
                }
                
                if (isset($existing_variation_ids[$variation_id])) {
                    echo "    - Skipping existing variation ID: $variation_id\n";
                    continue;
                }
                
                // Check database again just to be sure
                $existing_variation = $product_manager->getByProductId($variation_id);
                if ($existing_variation) {
                    echo "    - Variation already exists in database, skipping ID: $variation_id\n";
                    continue;
                }
                
                // Mark this variation as processed
                $processed_variation_ids[$variation_id] = true;
                
                // Create a title for the variation
                $variation_title = $parent_product->name;
                
                // Add attributes to the title
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
                
                // Log the variation details for debugging
                if ($debug_mode) {
                    $debug_info = "    - Variation ID: $variation_id, Title: " . substr($variation_title, 0, 50) . "...\n";
                    echo $debug_info;
                    fwrite($log_file, $debug_info);
                }
                
                // Prepare variation data
                $variation_data = [
                    'product_id' => $variation_id,
                    'title' => $variation_title,
                    'sku' => isset($variation->sku) ? $variation->sku : '',
                    'category' => isset($parent_product->categories[0]->name) ? $parent_product->categories[0]->name : '',
                    'price' => isset($variation->price) ? $variation->price : 0,
                    'image_url' => !empty($variation->image) && isset($variation->image->src) ? $variation->image->src : 
                              (!empty($parent_product->images) && isset($parent_product->images[0]->src) ? $parent_product->images[0]->src : ''),
                    'stock' => 0,
                    'low_stock_threshold' => DEFAULT_LOW_STOCK_THRESHOLD,
                    'notes' => 'Variation of product ID: ' . $parent_id . ' | ' . $attributes_desc
                ];
                
                // Add variation
                if ($product_manager->add($variation_data)) {
                    $variations_imported++;
                    echo "    - Imported variation: " . substr($variation_title, 0, 50) . "...\n";
                    fwrite($log_file, "  IMPORTED VARIATION: " . $variation_title . " (ID: " . $variation_id . ")\n");
                } else {
                    echo "    - Failed to import variation: " . substr($variation_title, 0, 50) . "...\n";
                    fwrite($log_file, "  FAILED VARIATION: " . $variation_title . " (ID: " . $variation_id . ")\n");
                }
            }
        } else {
            echo "  - No variations found for this product\n";
            fwrite($log_file, "  NO VARIATIONS FOUND for " . $parent_product->name . "\n");
        }
    } else {
        echo "  - Failed to import variable product\n";
        fwrite($log_file, "FAILED: " . $parent_product->name . " (ID: " . $parent_id . ")\n");
    }
    
    // Show progress after each product
    $elapsed = microtime(true) - $start_time;
    $percent_complete = round((count($processed_parent_ids) / count($all_variable_products)) * 100);
    echo "Progress: " . $percent_complete . "% - Imported $variable_products_imported parent products and $variations_imported variations\n";
    echo "Elapsed time: " . gmdate("H:i:s", $elapsed) . "\n\n";
}

// Calculate total time
$total_time = microtime(true) - $start_time;

// Final validation check
echo "\nPerforming final validation...\n";
$sql = "SELECT COUNT(*) as count FROM physical_inventory WHERE notes LIKE '%Variation of product%'";
$result = $db->query($sql);
$final_count = ($result && $result->num_rows > 0) ? $result->fetch_object()->count : 0;

echo "Final database variation count: $final_count (Expected: $all_variation_count)\n";
fwrite($log_file, "\nFinal database variation count: $final_count (Expected: $all_variation_count)\n");

if ($final_count > $all_variation_count) {
    echo "WARNING: More variations in database than expected! There might still be duplicates.\n";
    fwrite($log_file, "WARNING: More variations in database than expected! There might still be duplicates.\n");
    
    // Find exact duplicates by product_id
    $sql = "SELECT product_id, COUNT(*) as count FROM physical_inventory 
            WHERE notes LIKE '%Variation of product%' 
            GROUP BY product_id HAVING count > 1";
    $result = $db->query($sql);
    
    if ($result && $result->num_rows > 0) {
        echo "Found duplicate variation IDs in database:\n";
        fwrite($log_file, "\nFound duplicate variation IDs in database:\n");
        
        while ($row = $result->fetch_object()) {
            echo "Variation ID {$row->product_id} appears {$row->count} times\n";
            fwrite($log_file, "Variation ID {$row->product_id} appears {$row->count} times\n");
        }
    }
}

// Write summary to log
fwrite($log_file, "\n# Summary\n");
fwrite($log_file, "Total variable products found: " . count($all_variable_products) . "\n");
fwrite($log_file, "Total variations found: $all_variation_count\n");
fwrite($log_file, "Variable products imported: $variable_products_imported\n");
fwrite($log_file, "Variations imported: $variations_imported\n");
fwrite($log_file, "Total time: " . gmdate("H:i:s", $total_time) . "\n");
fclose($log_file);

// Log the import to the database
$now = date('Y-m-d H:i:s');
$details = "Imported with super-strict validation";
$sql = "INSERT INTO sync_log 
        (sync_date, products_added, products_updated, status, details) 
        VALUES 
        ('$now', " . ($variable_products_imported + $variations_imported) . ", 0, 'success', '$details')";
$db->query($sql);

echo "\n\nImport completed!\n";
echo "Variable products imported: $variable_products_imported\n";
echo "Variations imported: $variations_imported\n";
echo "Total time: " . gmdate("H:i:s", $total_time) . "\n";
echo "Results saved to: $output_file\n";