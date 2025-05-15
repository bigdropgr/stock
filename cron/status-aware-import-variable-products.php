<?php
/**
 * Status-Aware Ultra-Strict Variable Products Import Script
 * 
 * This enhanced version adds strict filtering based on product status.
 * It will only import variations that are actually published and visible
 * in your WooCommerce store, ignoring draft/trash/hidden variations.
 * 
 * Usage: php status-aware-import-variable-products.php [--output=file.txt] [--debug] [--dry-run]
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
$options = getopt('', ['output:', 'debug', 'dry-run', 'skip-status-check']);
$output_file = isset($options['output']) ? $options['output'] : 'status-aware-variable-products-import.log';
$debug_mode = isset($options['debug']);
$dry_run = isset($options['dry-run']);
$skip_status_check = isset($options['skip-status-check']);

echo "Starting status-aware variable products import...\n";
echo "Debug mode: " . ($debug_mode ? "ON" : "OFF") . "\n";
echo "Dry run mode: " . ($dry_run ? "ON" : "OFF") . "\n";
echo "Skip status check: " . ($skip_status_check ? "ON" : "OFF") . "\n";

// Open log file
$log_file = fopen($output_file, 'w');
fwrite($log_file, "# Status-Aware Variable Products Import Log\n");
fwrite($log_file, "# Generated on: " . date('Y-m-d H:i:s') . "\n");
fwrite($log_file, "# Debug mode: " . ($debug_mode ? "ON" : "OFF") . "\n");
fwrite($log_file, "# Dry run mode: " . ($dry_run ? "ON" : "OFF") . "\n");
fwrite($log_file, "# Skip status check: " . ($skip_status_check ? "ON" : "OFF") . "\n\n");

// Initialize classes
$db = Database::getInstance();
$woocommerce = new WooCommerce();
$product_manager = new Product();

// Create helper function to log messages both to console and log file
function log_message($message, $log_file, $console_output = true) {
    fwrite($log_file, $message . "\n");
    if ($console_output) {
        echo $message . "\n";
    }
}

// Test WooCommerce connection
log_message("Testing WooCommerce connection...", $log_file);
$connection_test = $woocommerce->testConnection();
if (!$connection_test['success']) {
    log_message("WooCommerce API connection failed: " . $connection_test['message'], $log_file);
    fclose($log_file);
    die();
}
log_message("Connection successful!", $log_file);

// Get all variation IDs already in the database
log_message("Getting all existing variation IDs from the database...", $log_file);
$sql = "SELECT product_id FROM physical_inventory WHERE notes LIKE '%Variation of product%'";
$result = $db->query($sql);
$existing_variation_ids = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_object()) {
        $existing_variation_ids[$row->product_id] = true;
    }
}

log_message("Found " . count($existing_variation_ids) . " existing variations in the database.", $log_file);

// Track variations globally by ID
$all_variation_ids_global = [];
$variation_metadata = [];

// Track parent products
$all_parent_products = [];

// Step 1: Get all variable products first
log_message("\nSTEP 1: Fetching all variable products...", $log_file);
$per_page = 50; // Use smaller page size for more reliable results
$page = 1;
$variable_products_count = 0;

do {
    log_message("  Fetching page $page...", $log_file);
    
    try {
        // Only get published products
        $params = [
            'per_page' => $per_page,
            'page' => $page,
            'status' => 'publish'
        ];
        $wc_products = $woocommerce->getProductsWithParams($per_page, $page, $params);
        
        if (empty($wc_products)) {
            log_message("  No products found on page $page.", $log_file);
            break;
        }
        
        log_message("  Found " . count($wc_products) . " products on page $page", $log_file);
        
        foreach ($wc_products as $product) {
            if (isset($product->type) && $product->type === 'variable') {
                $variable_products_count++;
                $all_parent_products[$product->id] = $product;
                
                if ($debug_mode) {
                    log_message("    Variable product: " . $product->name . " (ID: " . $product->id . ")", $log_file);
                }
            }
        }
        
        $page++;
        
    } catch (Exception $e) {
        log_message("  ERROR on page $page: " . $e->getMessage(), $log_file);
        // Continue to next page despite errors
        $page++;
    }
    
} while (!empty($wc_products) && count($wc_products) == $per_page);

log_message("Found " . count($all_parent_products) . " variable products across $page pages.", $log_file);

// Step 2: Build a complete and verified variations list
log_message("\nSTEP 2: Building verified variations list...", $log_file);
$total_api_variations = 0;
$unique_variations_count = 0;
$filtered_variations_count = 0;
$duplicates_found = 0;
$skus_found = [];
$skus_details = [];

// Helper function to get all variations for a product with retries, validation and status filtering
function get_all_verified_variations($product_id, $product_name, $woocommerce, $debug_mode, $log_file, $skip_status_check) {
    $verified_variations = [];
    $seen_variation_ids = [];
    $duplicates_found = 0;
    $skus_found = [];
    $max_retries = 3;
    
    // First attempt - standard call
    log_message("  Fetching variations for product $product_id: $product_name...", $log_file);
    
    for ($retry = 0; $retry < $max_retries; $retry++) {
        try {
            // Get variations with status filter if not skipping
            $params = ['per_page' => 100];
            if (!$skip_status_check) {
                $params['status'] = 'publish';
            }
            
            $variations = $woocommerce->getProductVariationsWithParams($product_id, $params);
            
            if (empty($variations)) {
                log_message("    No variations found on attempt " . ($retry + 1), $log_file);
                continue;
            }
            
            log_message("    Found " . count($variations) . " variations on attempt " . ($retry + 1), $log_file);
            
            // Process variations
            foreach ($variations as $variation) {
                if (!isset($variation->id)) {
                    if ($debug_mode) {
                        log_message("    WARNING: Variation without ID found, skipping", $log_file);
                    }
                    continue;
                }
                
                // Check status and visibility (if not skipping status check)
                if (!$skip_status_check) {
                    if (isset($variation->status) && $variation->status !== 'publish') {
                        if ($debug_mode) {
                            log_message("    SKIPPING: Variation ID {$variation->id} has status '{$variation->status}'", $log_file);
                        }
                        continue;
                    }
                    
                    if (isset($variation->visible) && !$variation->visible) {
                        if ($debug_mode) {
                            log_message("    SKIPPING: Variation ID {$variation->id} is not visible", $log_file);
                        }
                        continue;
                    }
                }
                
                // Store SKU information
                $sku = isset($variation->sku) ? $variation->sku : "";
                if (!empty($sku)) {
                    $skus_found[$sku] = $variation->id;
                    if ($debug_mode) {
                        log_message("    Found variation with SKU: $sku (ID: {$variation->id})", $log_file);
                    }
                }
                
                if (!isset($seen_variation_ids[$variation->id])) {
                    $seen_variation_ids[$variation->id] = true;
                    $verified_variations[] = $variation;
                } else {
                    $duplicates_found++;
                    if ($debug_mode) {
                        log_message("    DUPLICATE DETECTED: Variation ID {$variation->id}", $log_file);
                    }
                }
            }
            
            // Break if we found some variations
            if (!empty($verified_variations)) {
                break;
            }
            
        } catch (Exception $e) {
            log_message("    ERROR fetching variations on attempt " . ($retry + 1) . ": " . $e->getMessage(), $log_file);
        }
    }
    
    // Return the verified variations
    return [
        'variations' => $verified_variations,
        'duplicates' => $duplicates_found,
        'skus' => $skus_found
    ];
}

// Process each variable product to get complete variation data
foreach ($all_parent_products as $parent_id => $parent_product) {
    $result = get_all_verified_variations($parent_id, $parent_product->name, $woocommerce, $debug_mode, $log_file, $skip_status_check);
    $verified_variations = $result['variations'];
    $duplicates_found += $result['duplicates'];
    
    // Track all SKUs for this parent
    foreach ($result['skus'] as $sku => $variation_id) {
        $skus_details[$sku] = [
            'parent_id' => $parent_id,
            'parent_name' => $parent_product->name,
            'variation_id' => $variation_id
        ];
    }
    
    $total_api_variations += count($verified_variations);
    
    // Create metadata for each variation and check for global duplicates
    foreach ($verified_variations as $variation) {
        // Skip if no ID
        if (!isset($variation->id)) continue;
        
        // Skip if we've seen this variation ID globally already
        if (isset($all_variation_ids_global[$variation->id])) {
            log_message("    GLOBAL DUPLICATE DETECTED: Variation ID {$variation->id} appears in multiple parent products!", $log_file);
            $duplicates_found++;
            continue;
        }
        
        // Add to global tracking
        $all_variation_ids_global[$variation->id] = $parent_id;
        $unique_variations_count++;
        
        // Create variation metadata for later use
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
            }
        }
        
        // Store metadata
        $variation_metadata[$variation->id] = [
            'parent_id' => $parent_id,
            'parent_name' => $parent_product->name,
            'variation_id' => $variation->id,
            'attributes' => $attributes_desc,
            'sku' => isset($variation->sku) ? $variation->sku : '',
            'price' => isset($variation->price) ? $variation->price : 0,
            'image_url' => !empty($variation->image) && isset($variation->image->src) ? $variation->image->src : 
                        (!empty($parent_product->images) && isset($parent_product->images[0]->src) ? $parent_product->images[0]->src : ''),
            'status' => isset($variation->status) ? $variation->status : 'unknown',
            'visible' => isset($variation->visible) ? ($variation->visible ? 'yes' : 'no') : 'unknown'
        ];
    }
}

log_message("Completed variations verification:", $log_file);
log_message("  - Total parent variable products: " . count($all_parent_products), $log_file);
log_message("  - Total API variations returned: $total_api_variations", $log_file);
log_message("  - Unique variations after deduplication: $unique_variations_count", $log_file);
log_message("  - Duplicates found and filtered out: $duplicates_found", $log_file);

// Step 2b: Show detailed SKU report for problematic products
log_message("\nSTEP 2b: Detailed SKU report for problematic products...", $log_file);

// Focus on Κουδούνια αιγοπροβάτων απο το νούμερο 1 έως 16
$problem_product_id = 2268;
$problem_skus = [
    '106018', '106017', '106016', '106015', '106010', '106011', '106012',
    '106013', '106014', '106019', '106020', '106021', '106022',
    '204047', '204056', '204057', '204058', '204059', '204048', '204049',
    '204050', '204051', '204052'
];

log_message("Analyzing problem product ID: $problem_product_id", $log_file);
log_message("Checking for SKUs: " . implode(', ', $problem_skus), $log_file);

foreach ($problem_skus as $sku) {
    if (isset($skus_details[$sku])) {
        $details = $skus_details[$sku];
        log_message("  SKU $sku FOUND in product ID: {$details['parent_id']} - {$details['parent_name']} (Variation ID: {$details['variation_id']})", $log_file);
    } else {
        log_message("  SKU $sku NOT FOUND in any product", $log_file);
    }
}

// For the specific product in the image
$image_product_id = 37550;
log_message("\nAnalyzing specific product ID: $image_product_id", $log_file);

$variations_for_product = [];
foreach ($all_variation_ids_global as $var_id => $parent_id) {
    if ($parent_id == $image_product_id) {
        $variations_for_product[] = $var_id;
    }
}

log_message("Found " . count($variations_for_product) . " variations for product ID $image_product_id", $log_file);
foreach ($variations_for_product as $var_id) {
    $metadata = $variation_metadata[$var_id];
    log_message("  Variation ID: $var_id, Attributes: {$metadata['attributes']}, SKU: {$metadata['sku']}, Status: {$metadata['status']}, Visible: {$metadata['visible']}", $log_file);
}

// Step 3: Compare with database to find truly missing variations
log_message("\nSTEP 3: Comparing with database to identify truly missing variations...", $log_file);

// Get all variation product_ids from database
$sql = "SELECT product_id FROM physical_inventory WHERE notes LIKE '%Variation of product%'";
$result = $db->query($sql);
$db_variation_ids = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_object()) {
        $db_variation_ids[$row->product_id] = true;
    }
}

// Get parent products in database
$sql = "SELECT product_id FROM physical_inventory WHERE notes LIKE '%Variable product%'";
$result = $db->query($sql);
$db_parent_ids = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_object()) {
        $db_parent_ids[$row->product_id] = true;
    }
}

// Find variations to add (those in our verified list but not in the database)
$variations_to_add = [];
foreach ($all_variation_ids_global as $variation_id => $parent_id) {
    if (!isset($db_variation_ids[$variation_id])) {
        $variations_to_add[$variation_id] = $parent_id;
    }
}

// Find parents to add (those in our verified list but not in the database)
$parents_to_add = [];
foreach ($all_parent_products as $parent_id => $parent_product) {
    if (!isset($db_parent_ids[$parent_id])) {
        $parents_to_add[$parent_id] = $parent_product;
    }
}

log_message("Comparison complete:", $log_file);
log_message("  - Variable parents in database: " . count($db_parent_ids), $log_file);
log_message("  - Variable parents to add: " . count($parents_to_add), $log_file);
log_message("  - Variations in database: " . count($db_variation_ids), $log_file);
log_message("  - Variations to add: " . count($variations_to_add), $log_file);

// Early exit if dry run
if ($dry_run) {
    log_message("\nDRY RUN MODE - No changes will be made to the database", $log_file);
    log_message("Dry run complete! Here's what would happen:", $log_file);
    log_message("  - Would add " . count($parents_to_add) . " parent products", $log_file);
    log_message("  - Would add " . count($variations_to_add) . " variation products", $log_file);
    
    // Write summary to log
    fwrite($log_file, "\n# Summary (DRY RUN)\n");
    fwrite($log_file, "Parent variable products found: " . count($all_parent_products) . "\n");
    fwrite($log_file, "Unique variations found: $unique_variations_count\n");
    fwrite($log_file, "Duplicates filtered out: $duplicates_found\n");
    fwrite($log_file, "Parents that would be added: " . count($parents_to_add) . "\n");
    fwrite($log_file, "Variations that would be added: " . count($variations_to_add) . "\n");
    fclose($log_file);
    
    exit("Dry run completed. Results saved to: $output_file\n");
}

// Step 4: Import the missing parents and variations
log_message("\nSTEP 4: Importing missing parents and variations...", $log_file);

$parents_imported = 0;
$variations_imported = 0;
$start_time = microtime(true);

// First import missing parent products
foreach ($parents_to_add as $parent_id => $parent_product) {
    log_message("Importing parent product: " . $parent_product->name . " (ID: $parent_id)", $log_file);
    
    // Prepare product data for parent
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
    
    // Triple-check it doesn't exist
    $existing = $product_manager->getByProductId($parent_id);
    if ($existing) {
        log_message("  - Parent already exists in database, skipping", $log_file);
        continue;
    }
    
    // Add parent product
    $parent_db_id = $product_manager->add($product_data);
    
    if ($parent_db_id) {
        $parents_imported++;
        log_message("  - Successfully imported parent", $log_file);
        fwrite($log_file, "IMPORTED PARENT: " . $parent_product->name . " (ID: " . $parent_id . ")\n");
    } else {
        log_message("  - Failed to import parent", $log_file);
        fwrite($log_file, "FAILED PARENT: " . $parent_product->name . " (ID: " . $parent_id . ")\n");
    }
}

// Now import missing variations
foreach ($variations_to_add as $variation_id => $parent_id) {
    // Skip if we don't have metadata for this variation (shouldn't happen)
    if (!isset($variation_metadata[$variation_id])) {
        log_message("WARNING: Missing metadata for variation ID $variation_id, skipping", $log_file);
        continue;
    }
    
    $metadata = $variation_metadata[$variation_id];
    $parent_name = $metadata['parent_name'];
    $attributes = $metadata['attributes'];
    
    log_message("Importing variation: $variation_id (Parent: $parent_id - $parent_name)", $log_file);
    
    // Triple-check it doesn't exist
    $existing = $product_manager->getByProductId($variation_id);
    if ($existing) {
        log_message("  - Variation already exists in database, skipping", $log_file);
        continue;
    }
    
    // Create a title for the variation
    $variation_title = $parent_name;
    if (!empty($attributes)) {
        $variation_title .= ' - ' . $attributes;
    }
    
    // Prepare variation data
    $variation_data = [
        'product_id' => $variation_id,
        'title' => $variation_title,
        'sku' => $metadata['sku'],
        'category' => isset($all_parent_products[$parent_id]->categories[0]->name) ? 
                     $all_parent_products[$parent_id]->categories[0]->name : '',
        'price' => $metadata['price'],
        'image_url' => $metadata['image_url'],
        'stock' => 0,
        'low_stock_threshold' => DEFAULT_LOW_STOCK_THRESHOLD,
        'notes' => 'Variation of product ID: ' . $parent_id . ' | ' . $attributes
    ];
    
    // Add variation
    if ($product_manager->add($variation_data)) {
        $variations_imported++;
        log_message("  - Successfully imported variation", $log_file);
        fwrite($log_file, "  IMPORTED VARIATION: " . $variation_title . " (ID: " . $variation_id . ")\n");
    } else {
        log_message("  - Failed to import variation", $log_file);
        fwrite($log_file, "  FAILED VARIATION: " . $variation_title . " (ID: " . $variation_id . ")\n");
    }
}

// Calculate total time
$total_time = microtime(true) - $start_time;

// Final validation check
log_message("\nPerforming final validation...", $log_file);
$sql = "SELECT COUNT(*) as count FROM physical_inventory WHERE notes LIKE '%Variation of product%'";
$result = $db->query($sql);
$final_count = ($result && $result->num_rows > 0) ? $result->fetch_object()->count : 0;

$sql = "SELECT COUNT(*) as count FROM physical_inventory WHERE notes LIKE '%Variable product%'";
$result = $db->query($sql);
$final_parent_count = ($result && $result->num_rows > 0) ? $result->fetch_object()->count : 0;

log_message("Final database variation count: $final_count (Expected: $unique_variations_count)", $log_file);
log_message("Final database parent count: $final_parent_count (Expected: " . count($all_parent_products) . ")", $log_file);

// Check if the numbers match expectations
if ($final_count != $unique_variations_count) {
    log_message("WARNING: Final count doesn't match expected count!", $log_file);
    
    if ($final_count > $unique_variations_count) {
        log_message("There are MORE variations in the database than expected. Possible issues:", $log_file);
        log_message("1. Some variations existed before this import run", $log_file);
        log_message("2. There might still be duplicates", $log_file);
        
        // Find exact duplicates by product_id
        $sql = "SELECT product_id, COUNT(*) as count FROM physical_inventory 
                WHERE notes LIKE '%Variation of product%' 
                GROUP BY product_id HAVING count > 1";
        $result = $db->query($sql);
        
        if ($result && $result->num_rows > 0) {
            log_message("Found duplicate variation IDs in database:", $log_file);
            
            while ($row = $result->fetch_object()) {
                log_message("Variation ID {$row->product_id} appears {$row->count} times", $log_file);
            }
        } else {
            log_message("No duplicate variation IDs found in database.", $log_file);
            log_message("The extra variations likely existed before this import run.", $log_file);
        }
    } else {
        log_message("There are FEWER variations in the database than expected. Possible issues:", $log_file);
        log_message("1. Some variations failed to import", $log_file);
        log_message("2. Database constraints prevented some imports", $log_file);
    }
}

// Log the import to the database
$now = date('Y-m-d H:i:s');
$details = "Imported with status-aware validation";
$sql = "INSERT INTO sync_log 
        (sync_date, products_added, products_updated, status, details) 
        VALUES 
        ('$now', " . ($parents_imported + $variations_imported) . ", 0, 'success', '$details')";
$db->query($sql);

// Write summary to log
fwrite($log_file, "\n# Summary\n");
fwrite($log_file, "Parent variable products found: " . count($all_parent_products) . "\n");
fwrite($log_file, "Unique variations found: $unique_variations_count\n");
fwrite($log_file, "Duplicates filtered out: $duplicates_found\n");
fwrite($log_file, "Parents imported: $parents_imported\n");
fwrite($log_file, "Variations imported: $variations_imported\n");
fwrite($log_file, "Total import time: " . gmdate("H:i:s", $total_time) . "\n");
fclose($log_file);

log_message("\nImport completed!", $log_file, true);
log_message("Parents imported: $parents_imported", $log_file, true);
log_message("Variations imported: $variations_imported", $log_file, true);
log_message("Duplicates filtered out: $duplicates_found", $log_file, true);
log_message("Total time: " . gmdate("H:i:s", $total_time), $log_file, true);
log_message("Results saved to: $output_file", $log_file, true);