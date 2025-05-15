<?php
/**
 * Import Variable Products Script
 * 
 * This script imports variable products and their variations that were skipped
 * during the normal import process
 * 
 * Usage: php import-variable-products.php [--output=file.txt]
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
$options = getopt('', ['output:']);
$output_file = isset($options['output']) ? $options['output'] : 'variable-products-import.log';

echo "Importing variable products...\n";

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

// Process all products in batches to find variable products
$per_page = 100;
$page = 1;
$processed = 0;
$variable_products_imported = 0;
$variations_imported = 0;
$start_time = microtime(true);

echo "\nScanning for variable products...\n";

do {
    echo "Processing page $page...\n";
    
    // Get products from WooCommerce
    $wc_products = $woocommerce->getProducts($per_page, $page);
    
    if (empty($wc_products)) {
        echo "No products found on page $page. Stopping.\n";
        break;
    }
    
    foreach ($wc_products as $wc_product) {
        // Only process variable products
        if (isset($wc_product->type) && $wc_product->type === 'variable') {
            echo "Found variable product: " . $wc_product->name . " (ID: " . $wc_product->id . ")\n";
            
            // Check if product already exists
            $existing_product = $product_manager->getByProductId($wc_product->id);
            
            if ($existing_product) {
                echo "  - Already imported, skipping\n";
                fwrite($log_file, "SKIPPED: " . $wc_product->name . " (ID: " . $wc_product->id . ") - Already exists\n");
                continue;
            }
            
            // Prepare product data for parent product
            $product_data = [
                'product_id' => $wc_product->id,
                'title' => $wc_product->name,
                'sku' => isset($wc_product->sku) ? $wc_product->sku : '',
                'category' => isset($wc_product->categories[0]->name) ? $wc_product->categories[0]->name : '',
                'price' => isset($wc_product->price) ? $wc_product->price : 0,
                'image_url' => !empty($wc_product->images) && isset($wc_product->images[0]->src) ? $wc_product->images[0]->src : '',
                'stock' => 0, // For variable products, we'll set stock on variations
                'low_stock_threshold' => DEFAULT_LOW_STOCK_THRESHOLD,
                'notes' => 'Variable product'
            ];
            
            // Add parent product first
            $parent_id = $product_manager->add($product_data);
            
            if ($parent_id) {
                $variable_products_imported++;
                echo "  - Imported variable product\n";
                fwrite($log_file, "IMPORTED PARENT: " . $wc_product->name . " (ID: " . $wc_product->id . ")\n");
                
                // Now get and process variations
                $variations = $woocommerce->getProductVariations($wc_product->id);

                if (!empty($variations)) {
                    echo "  - Found " . count($variations) . " variations\n";
                    
                    // Track processed variation IDs to avoid duplicates
                    $processed_variation_ids = [];
                    
                    foreach ($variations as $variation) {
                        // Skip if we already processed this variation
                        if (in_array($variation->id, $processed_variation_ids)) {
                            echo "    - Skipping duplicate variation ID: " . $variation->id . "\n";
                            continue;
                        }
                        
                        // Add to processed list
                        $processed_variation_ids[] = $variation->id;
                        
                        // Create a title for the variation
                        $variation_title = $wc_product->name;
                        
                        // Add attributes to the title
                        if (!empty($variation->attributes)) {
                            $attributes = [];
                            foreach ($variation->attributes as $attr) {
                                if (isset($attr->option)) {
                                    $attributes[] = $attr->option;
                                }
                            }
                            if (!empty($attributes)) {
                                $variation_title .= ' - ' . implode(', ', $attributes);
                            }
                        }
                        
                        // Also check if variation already exists in the database
                        $existing_variation = $product_manager->getByProductId($variation->id);
                        if ($existing_variation) {
                            echo "    - Variation already exists in database, skipping: " . $variation_title . "\n";
                            continue;
                        }
                        
                        // Prepare variation data
                        $variation_data = [
                            'product_id' => $variation->id,
                            'title' => $variation_title,
                            'sku' => isset($variation->sku) ? $variation->sku : '',
                            'category' => isset($wc_product->categories[0]->name) ? $wc_product->categories[0]->name : '',
                            'price' => isset($variation->price) ? $variation->price : 0,
                            'image_url' => !empty($variation->image) && isset($variation->image->src) ? $variation->image->src : 
                                        (!empty($wc_product->images) && isset($wc_product->images[0]->src) ? $wc_product->images[0]->src : ''),
                            'stock' => 0,
                            'low_stock_threshold' => DEFAULT_LOW_STOCK_THRESHOLD,
                            'notes' => 'Variation of product ID: ' . $wc_product->id
                        ];
                        
                        // Add variation
                        if ($product_manager->add($variation_data)) {
                            $variations_imported++;
                            echo "    - Imported variation: " . $variation_title . "\n";
                            fwrite($log_file, "  IMPORTED VARIATION: " . $variation_title . " (ID: " . $variation->id . ")\n");
                        } else {
                            echo "    - Failed to import variation: " . $variation_title . "\n";
                            fwrite($log_file, "  FAILED VARIATION: " . $variation_title . " (ID: " . $variation->id . ")\n");
                        }
                    }
                } else {
                    echo "  - No variations found\n";
                    fwrite($log_file, "  NO VARIATIONS FOUND for " . $wc_product->name . "\n");
                }
        }
        
        $processed++;
    }
    
    $page++;
    
    // Show progress
    $elapsed = microtime(true) - $start_time;
    echo "\nProgress: Processed $processed products, Imported $variable_products_imported variable products and $variations_imported variations\n";
    echo "Elapsed time: " . gmdate("H:i:s", $elapsed) . "\n\n";
    
} while (count($wc_products) === $per_page);

// Calculate total time
$total_time = microtime(true) - $start_time;

// Write summary to log
fwrite($log_file, "\n# Summary\n");
fwrite($log_file, "Total products processed: $processed\n");
fwrite($log_file, "Variable products imported: $variable_products_imported\n");
fwrite($log_file, "Variations imported: $variations_imported\n");
fwrite($log_file, "Total time: " . gmdate("H:i:s", $total_time) . "\n");
fclose($log_file);

// Log the import to the database
$now = date('Y-m-d H:i:s');
$sql = "INSERT INTO sync_log 
        (sync_date, products_added, products_updated, status, details) 
        VALUES 
        ('$now', " . ($variable_products_imported + $variations_imported) . ", 0, 'success', 'Imported variable products and variations')";
$db->query($sql);

echo "\n\nImport completed!\n";
echo "Variable products imported: $variable_products_imported\n";
echo "Variations imported: $variations_imported\n";
echo "Total time: " . gmdate("H:i:s", $total_time) . "\n";
echo "Results saved to: $output_file\n";

/**
 * Get product variations
 * 
 * @param int $product_id Product ID
 * @return array List of variations
 */
public /variations";
        $params = [
            'per_page' => 100
        ];
        
        return $this->makeRequest($endpoint, 'GET', $params);
    } catch (Exception $e) {
        error_log("Error getting product variations: " . $e->getMessage());
        return [];
    }
}