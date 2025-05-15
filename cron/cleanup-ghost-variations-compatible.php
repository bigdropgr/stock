<?php
/**
 * WooCommerce Variations Cleanup Script - Compatible Version
 * 
 * This version is compatible with WooCommerce classes where makeRequest() is private
 * 
 * Usage: php cleanup-ghost-variations-compatible.php [--debug] [--apply]
 */

// Set unlimited execution time
set_time_limit(0);

// Include required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/WooCommerce.php';
require_once __DIR__ . '/../includes/functions.php';

// Parse command line arguments
$options = getopt('', ['debug', 'apply', 'product-id:']);
$debug_mode = isset($options['debug']);
$apply_changes = isset($options['apply']);
$specific_product_id = isset($options['product-id']) ? (int)$options['product-id'] : 0;

echo "Starting WooCommerce Variations Cleanup...\n";
echo "Debug mode: " . ($debug_mode ? "ON" : "OFF") . "\n";
echo "Apply changes: " . ($apply_changes ? "ON" : "OFF") . "\n";
if ($specific_product_id) {
    echo "Processing only product ID: $specific_product_id\n";
}

// Open log file
$log_file = fopen('ghost-variations-cleanup.log', 'w');
fwrite($log_file, "# WooCommerce Ghost Variations Cleanup Log\n");
fwrite($log_file, "# Generated on: " . date('Y-m-d H:i:s') . "\n");
fwrite($log_file, "# Debug mode: " . ($debug_mode ? "ON" : "OFF") . "\n");
fwrite($log_file, "# Apply changes: " . ($apply_changes ? "ON" : "OFF") . "\n");
if ($specific_product_id) {
    fwrite($log_file, "# Processing only product ID: $specific_product_id\n");
}
fwrite($log_file, "\n");

// Create helper function to log messages both to console and log file
function log_message($message, $log_file, $console_output = true) {
    fwrite($log_file, $message . "\n");
    if ($console_output) {
        echo $message . "\n";
    }
}

// Initialize WooCommerce API
$woocommerce = new WooCommerce();

// Test WooCommerce connection
log_message("Testing WooCommerce connection...", $log_file);
$connection_test = $woocommerce->testConnection();
if (!$connection_test['success']) {
    log_message("WooCommerce API connection failed: " . $connection_test['message'], $log_file);
    fclose($log_file);
    die();
}
log_message("Connection successful!", $log_file);

// Step 1: Find all variable products
log_message("\nStep 1: Finding variable products...", $log_file);

// Find variable products
$all_variable_products = [];
$per_page = 50;
$page = 1;

// Only get a specific product if requested
if ($specific_product_id) {
    log_message("Fetching specific product ID: $specific_product_id", $log_file);
    $product = $woocommerce->getProduct($specific_product_id);
    
    if ($product && isset($product->type) && $product->type === 'variable') {
        $all_variable_products[$product->id] = $product;
        log_message("Found variable product: {$product->name} (ID: {$product->id})", $log_file);
    } else {
        log_message("Error: Product ID $specific_product_id not found or not a variable product", $log_file);
        fclose($log_file);
        exit();
    }
} else {
    // Get all variable products
    do {
        log_message("  Fetching page $page...", $log_file);
        
        // Get products from WooCommerce - using getProducts() instead of makeRequest()
        $products = $woocommerce->getProducts($per_page, $page);
        
        if (empty($products)) {
            break;
        }
        
        foreach ($products as $product) {
            if (isset($product->type) && $product->type === 'variable') {
                $all_variable_products[$product->id] = $product;
                log_message("Found variable product: {$product->name} (ID: {$product->id})", $log_file);
            }
        }
        
        $page++;
    } while (!empty($products));
}

log_message("Found " . count($all_variable_products) . " variable products", $log_file);

// Step 2: Analyze variations for each product
log_message("\nStep 2: Analyzing variations for each product...", $log_file);

$problem_products = [];
$ghost_variations = [];
$total_ghost_variations = 0;

// Focus on the specific problematic product with expected SKUs
$problem_skus = [
    '106018', '106017', '106016', '106015', '106010', '106011', '106012', 
    '106013', '106014', '106019', '106020', '106021', '106022'
];

$unexpected_skus = [
    '204047', '204056', '204057', '204058', '204059', '204048', '204049', 
    '204050', '204051', '204052'
];

foreach ($all_variable_products as $product_id => $product) {
    log_message("\nAnalyzing product: {$product->name} (ID: {$product_id})", $log_file);
    
    // Get all variations - using the existing method
    $all_variations = $woocommerce->getProductVariations($product_id);
    
    // Track which variations have published status vs other statuses
    $published_variations = [];
    $non_published_variations = [];
    
    foreach ($all_variations as $variation) {
        // Check if this variation has a status and it's published
        if (isset($variation->status) && $variation->status === 'publish') {
            $published_variations[] = $variation;
        } else {
            $non_published_variations[] = $variation;
        }
    }
    
    // If there are non-published variations, log them as ghost variations
    if (!empty($non_published_variations)) {
        $problem_products[$product_id] = $product->name;
        log_message("  Found " . count($non_published_variations) . " ghost variations", $log_file);
        
        // Track total
        $total_ghost_variations += count($non_published_variations);
        
        // Get details on each ghost variation
        foreach ($non_published_variations as $variation) {
            $ghost_variations[$variation->id] = [
                'product_id' => $product_id,
                'product_name' => $product->name,
                'variation_id' => $variation->id,
                'sku' => isset($variation->sku) ? $variation->sku : 'no-sku',
                'status' => isset($variation->status) ? $variation->status : 'unknown',
                'attributes' => isset($variation->attributes) ? $variation->attributes : []
            ];
            
            $attrs_text = '';
            if (!empty($variation->attributes)) {
                $attrs = [];
                foreach ($variation->attributes as $attr) {
                    if (isset($attr->name) && isset($attr->option)) {
                        $attrs[] = "{$attr->name}: {$attr->option}";
                    }
                }
                $attrs_text = implode(', ', $attrs);
            }
            
            log_message("    Ghost Variation ID: {$variation->id}, SKU: " . 
                       (isset($variation->sku) ? $variation->sku : 'no-sku') . 
                       ", Status: " . (isset($variation->status) ? $variation->status : 'unknown') . 
                       ", Attributes: " . $attrs_text, 
                       $log_file);
            
            // Specially log if this is one of our problem SKUs
            if (isset($variation->sku)) {
                $sku = $variation->sku;
                
                if (in_array($sku, $problem_skus)) {
                    log_message("    WARNING: Ghost variation has a VALID SKU ($sku) that should be visible!", $log_file);
                }
                
                if (in_array($sku, $unexpected_skus)) {
                    log_message("    ISSUE DETECTED: Found an UNEXPECTED SKU ($sku) that should not exist!", $log_file);
                }
            }
        }
    } else {
        log_message("  No ghost variations found for this product", $log_file);
    }
    
    // Additional checks for the specific problem product: Κουδούνια αιγοπροβάτων απο το νούμερο 1 έως 16
    if ($product_id == 2268 || 
        (strtolower($product->name) == "κουδούνια αιγοπροβάτων απο το νούμερο 1 έως 16") || 
        (strtolower($product->name) == "κουδουνια αιγοπροβατων απο το νουμερο 1 εως 16")) {
        
        log_message("\n  SPECIAL ANALYSIS for problem product ID: {$product_id}", $log_file);
        log_message("  Product Name: {$product->name}", $log_file);
        log_message("  Expected SKUs: " . implode(", ", $problem_skus), $log_file);
        log_message("  Unexpected but found SKUs: " . implode(", ", $unexpected_skus), $log_file);
        
        log_message("\n  Published variations:", $log_file);
        foreach ($published_variations as $i => $var) {
            $sku = isset($var->sku) ? $var->sku : "no-sku";
            $status = isset($var->status) ? $var->status : "unknown";
            log_message("    [{$i}] ID: {$var->id}, SKU: {$sku}, Status: {$status}", $log_file);
        }
        
        // Check for the specific unexpected SKUs to see if they exist in the variations
        log_message("\n  Checking for unexpected SKUs in all variations:", $log_file);
        $found_unexpected = []; 
        foreach ($unexpected_skus as $unexpected_sku) {
            $found = false;
            foreach ($all_variations as $var) {
                if (isset($var->sku) && $var->sku === $unexpected_sku) {
                    $found = true;
                    $found_unexpected[] = [
                        'sku' => $unexpected_sku,
                        'id' => $var->id,
                        'status' => isset($var->status) ? $var->status : 'unknown'
                    ];
                    break;
                }
            }
            log_message("    SKU {$unexpected_sku}: " . ($found ? "FOUND" : "NOT FOUND"), $log_file);
        }
        
        if (!empty($found_unexpected)) {
            log_message("\n  Details of unexpected SKUs:", $log_file);
            foreach ($found_unexpected as $item) {
                log_message("    SKU: {$item['sku']}, ID: {$item['id']}, Status: {$item['status']}", $log_file);
            }
        }
        
        // Check for missing expected SKUs
        log_message("\n  Checking for expected SKUs in all variations:", $log_file);
        $missing_expected = [];
        foreach ($problem_skus as $expected_sku) {
            $found = false;
            foreach ($all_variations as $var) {
                if (isset($var->sku) && $var->sku === $expected_sku) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $missing_expected[] = $expected_sku;
            }
            
            log_message("    SKU {$expected_sku}: " . ($found ? "FOUND" : "MISSING"), $log_file);
        }
        
        if (!empty($missing_expected)) {
            log_message("\n  WARNING: Missing expected SKUs: " . implode(", ", $missing_expected), $log_file);
        }
    }
}

log_message("\nFound " . count($problem_products) . " products with ghost variations", $log_file);
log_message("Total ghost variations found: " . $total_ghost_variations, $log_file);

// Step 3: Apply changes if requested
if ($apply_changes && !empty($ghost_variations)) {
    log_message("\nStep 3: Applying changes to ghost variations...", $log_file);
    log_message("WARNING: Cannot apply changes directly because makeRequest() is private", $log_file);
    log_message("Please add the following method to your WooCommerce.php class:", $log_file);
    
    $method_code = '
/**
 * Update a variation\'s status
 * 
 * @param int $product_id The parent product ID
 * @param int $variation_id The variation ID
 * @param string $status The new status (publish, draft, pending, or trash)
 * @param bool $visible Whether the variation should be visible
 * @return object|bool The result or false on failure
 */
public function updateVariationStatus($product_id, $variation_id, $status = "publish", $visible = true) {
    try {
        $endpoint = "products/' . $product_id . '/variations/' . $variation_id . '";
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
        $endpoint = "products/' . $product_id . '/variations/' . $variation_id . '";
        $params = [
            "force" => $force
        ];
        
        return $this->makeRequest($endpoint, "DELETE", $params);
    } catch (Exception $e) {
        error_log("Error deleting variation: " . $e->getMessage());
        return false;
    }
}';
    
    log_message($method_code, $log_file);
    log_message("\nPlease add these methods to your WooCommerce.php class and run this script again.", $log_file);
    
    // Output instructions for manually fixing problematic product
    if (count($ghost_variations) > 0) {
        log_message("\nTo manually fix your problematic product, follow these steps:", $log_file);
        log_message("1. Go to WooCommerce > Products in your WordPress admin", $log_file);
        log_message("2. Find and edit the product 'Κουδούνια αιγοπροβάτων απο το νούμερο 1 έως 16'", $log_file);
        log_message("3. Go to the 'Variations' tab", $log_file);
        log_message("4. For variations with these SKUs, make sure they are published:", $log_file);
        foreach ($problem_skus as $sku) {
            log_message("   - $sku", $log_file);
        }
        log_message("5. For variations with these SKUs, they should be deleted:", $log_file);
        foreach ($unexpected_skus as $sku) {
            log_message("   - $sku", $log_file);
        }
    }
} else if ($apply_changes) {
    log_message("\nStep 3: No ghost variations found, nothing to fix", $log_file);
} else {
    log_message("\nStep 3: Skipped (apply changes not enabled)", $log_file);
    log_message("To apply changes, run with --apply flag", $log_file);
}

// Summary
log_message("\nCleanup process complete!", $log_file);
log_message("Problem products found: " . count($problem_products), $log_file);
log_message("Ghost variations found: " . $total_ghost_variations, $log_file);

if ($apply_changes) {
    log_message("Please follow the instructions above to fix the ghost variations.", $log_file);
} else {
    log_message("No changes applied. Run with --apply to get instructions for fixing ghost variations.", $log_file);
}

fclose($log_file);