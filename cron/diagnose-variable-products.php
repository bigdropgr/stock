<?php
/**
 * Diagnose Variable Products Script
 * 
 * This script analyzes variable products and their variations from the WooCommerce API
 * to identify the source of duplicate variations.
 * 
 * Usage: php diagnose-variable-products.php
 */

// Set unlimited execution time
set_time_limit(0);

// Include required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/WooCommerce.php';
require_once __DIR__ . '/../includes/functions.php';

echo "Starting diagnosis of variable products...\n";

// Initialize WooCommerce API class
$woocommerce = new WooCommerce();

// Open log file
$log_file = 'variable-products-diagnosis.log';
$log = fopen($log_file, 'w');
fwrite($log, "# Variable Products Diagnosis Log\n");
fwrite($log, "# Generated on: " . date('Y-m-d H:i:s') . "\n\n");

// Test WooCommerce connection
echo "Testing WooCommerce connection...\n";
$connection_test = $woocommerce->testConnection();
if (!$connection_test['success']) {
    die("WooCommerce API connection failed: " . $connection_test['message'] . "\n");
}
echo "Connection successful!\n";

// Function to examine and print the variations for a product
function examineVariations($woocommerce, $product_id, $product_name, $log) {
    echo "\nExamining variations for: $product_name (ID: $product_id)\n";
    fwrite($log, "\n## Product: $product_name (ID: $product_id)\n");
    
    // Get variations - first call
    echo "First API call for variations...\n";
    $variations1 = $woocommerce->getProductVariations($product_id);
    
    if (empty($variations1)) {
        echo "No variations found in first call.\n";
        fwrite($log, "No variations found in first call.\n");
        return;
    }
    
    echo "Found " . count($variations1) . " variations in first call.\n";
    fwrite($log, "Found " . count($variations1) . " variations in first call.\n");
    
    // Print info about each variation
    $variation_ids1 = [];
    fwrite($log, "\nVariations from first call:\n");
    foreach ($variations1 as $index => $variation) {
        $variation_ids1[] = $variation->id;
        $attributes = [];
        if (!empty($variation->attributes)) {
            foreach ($variation->attributes as $attr) {
                if (isset($attr->option)) {
                    $attributes[] = $attr->name . ': ' . $attr->option;
                }
            }
        }
        
        $variation_info = "  [$index] ID: {$variation->id}, SKU: " . (isset($variation->sku) ? $variation->sku : 'N/A');
        $variation_info .= ", Attributes: " . (!empty($attributes) ? implode(', ', $attributes) : 'None');
        
        echo $variation_info . "\n";
        fwrite($log, $variation_info . "\n");
    }
    
    // Make a second API call to check for consistency
    echo "Second API call for variations...\n";
    $variations2 = $woocommerce->getProductVariations($product_id);
    
    if (empty($variations2)) {
        echo "No variations found in second call.\n";
        fwrite($log, "\nNo variations found in second call.\n");
        return;
    }
    
    echo "Found " . count($variations2) . " variations in second call.\n";
    fwrite($log, "\nFound " . count($variations2) . " variations in second call.\n");
    
    // Print info about each variation from second call
    $variation_ids2 = [];
    fwrite($log, "\nVariations from second call:\n");
    foreach ($variations2 as $index => $variation) {
        $variation_ids2[] = $variation->id;
        $attributes = [];
        if (!empty($variation->attributes)) {
            foreach ($variation->attributes as $attr) {
                if (isset($attr->option)) {
                    $attributes[] = $attr->name . ': ' . $attr->option;
                }
            }
        }
        
        $variation_info = "  [$index] ID: {$variation->id}, SKU: " . (isset($variation->sku) ? $variation->sku : 'N/A');
        $variation_info .= ", Attributes: " . (!empty($attributes) ? implode(', ', $attributes) : 'None');
        
        echo $variation_info . "\n";
        fwrite($log, $variation_info . "\n");
    }
    
    // Compare the two calls
    $same_count = count($variations1) === count($variations2);
    $same_ids = $variation_ids1 === $variation_ids2;
    
    echo "\nComparison: " . ($same_count ? "Same count" : "DIFFERENT COUNT") . ", " . 
         ($same_ids ? "Same IDs" : "DIFFERENT IDs") . "\n";
    fwrite($log, "\nComparison: " . ($same_count ? "Same count" : "DIFFERENT COUNT") . ", " . 
           ($same_ids ? "Same IDs" : "DIFFERENT IDs") . "\n");
    
    // Find duplicates within each call
    $duplicates1 = array_count_values($variation_ids1);
    $duplicates2 = array_count_values($variation_ids2);
    
    $has_duplicates1 = false;
    foreach ($duplicates1 as $id => $count) {
        if ($count > 1) {
            $has_duplicates1 = true;
            echo "DUPLICATE in first call: ID $id appears $count times\n";
            fwrite($log, "DUPLICATE in first call: ID $id appears $count times\n");
        }
    }
    
    $has_duplicates2 = false;
    foreach ($duplicates2 as $id => $count) {
        if ($count > 1) {
            $has_duplicates2 = true;
            echo "DUPLICATE in second call: ID $id appears $count times\n";
            fwrite($log, "DUPLICATE in second call: ID $id appears $count times\n");
        }
    }
    
    if (!$has_duplicates1 && !$has_duplicates2) {
        echo "No duplicates found within API responses.\n";
        fwrite($log, "No duplicates found within API responses.\n");
    }
    
    // See if any variations are missing between calls
    $missing_in_2 = array_diff($variation_ids1, $variation_ids2);
    $missing_in_1 = array_diff($variation_ids2, $variation_ids1);
    
    if (!empty($missing_in_2)) {
        echo "IDs present in first call but missing in second: " . implode(', ', $missing_in_2) . "\n";
        fwrite($log, "IDs present in first call but missing in second: " . implode(', ', $missing_in_2) . "\n");
    }
    
    if (!empty($missing_in_1)) {
        echo "IDs present in second call but missing in first: " . implode(', ', $missing_in_1) . "\n";
        fwrite($log, "IDs present in second call but missing in first: " . implode(', ', $missing_in_1) . "\n");
    }
    
    // Check for different pagination behavior
    if (count($variations1) === 100 || count($variations2) === 100) {
        echo "WARNING: Reached the maximum of 100 variations - pagination might be needed!\n";
        fwrite($log, "WARNING: Reached the maximum of 100 variations - pagination might be needed!\n");
        
        // Try with a smaller per_page setting
        echo "Testing with pagination (smaller per_page value)...\n";
        fwrite($log, "\nTesting with pagination (smaller per_page value)...\n");
        
        // Manually construct a paginated request
        $all_paginated_variations = [];
        $paginated_page = 1;
        $per_page = 20;
        
        do {
            fwrite($log, "Fetching page $paginated_page with $per_page per page...\n");
            
            // Manually construct the request
            $endpoint = "products/{$product_id}/variations";
            $params = [
                'per_page' => $per_page,
                'page' => $paginated_page
            ];
            
            $paginated_variations = $woocommerce->makeRequest($endpoint, 'GET', $params);
            
            if (empty($paginated_variations)) {
                fwrite($log, "No more variations found on page $paginated_page.\n");
                break;
            }
            
            $count_on_page = count($paginated_variations);
            fwrite($log, "Found $count_on_page variations on page $paginated_page.\n");
            
            foreach ($paginated_variations as $var) {
                $all_paginated_variations[] = $var->id;
            }
            
            $paginated_page++;
            
        } while (count($paginated_variations) === $per_page);
        
        fwrite($log, "Total variations with pagination: " . count($all_paginated_variations) . "\n");
        fwrite($log, "Unique variations with pagination: " . count(array_unique($all_paginated_variations)) . "\n");
        
        // Check for duplicates in paginated results
        $paginated_duplicates = array_count_values($all_paginated_variations);
        $has_paginated_duplicates = false;
        
        foreach ($paginated_duplicates as $id => $count) {
            if ($count > 1) {
                $has_paginated_duplicates = true;
                fwrite($log, "DUPLICATE in paginated results: ID $id appears $count times\n");
            }
        }
        
        if (!$has_paginated_duplicates) {
            fwrite($log, "No duplicates found in paginated results.\n");
        }
    }
}

// Find and examine variable products
$per_page = 20;
$page = 1;
$examined_products = 0;
$max_products_to_examine = 5; // Set a limit to avoid excessive API calls

echo "\nLooking for variable products to examine...\n";

do {
    echo "Fetching products page $page...\n";
    
    // Get products from WooCommerce
    $wc_products = $woocommerce->getProducts($per_page, $page);
    
    if (empty($wc_products)) {
        echo "No products found on page $page. Stopping.\n";
        break;
    }
    
    foreach ($wc_products as $wc_product) {
        // Only process variable products
        if (isset($wc_product->type) && $wc_product->type === 'variable') {
            echo "\nFound variable product: " . $wc_product->name . " (ID: " . $wc_product->id . ")\n";
            
            // Examine this product's variations
            examineVariations($woocommerce, $wc_product->id, $wc_product->name, $log);
            
            $examined_products++;
            
            // Stop after examining enough products
            if ($examined_products >= $max_products_to_examine) {
                echo "\nReached limit of $max_products_to_examine products. Stopping.\n";
                break 2; // Break out of both loops
            }
        }
    }
    
    $page++;
    
} while (count($wc_products) === $per_page);

// Examine API method implementation
echo "\nExamining getProductVariations method implementation...\n";
fwrite($log, "\n## WooCommerce API Method Analysis\n");

// Get WooCommerce.php file contents
$woocommerce_file = file_get_contents(__DIR__ . '/../includes/WooCommerce.php');

if ($woocommerce_file === false) {
    echo "Could not read WooCommerce.php file.\n";
    fwrite($log, "Could not read WooCommerce.php file.\n");
} else {
    // Extract the getProductVariations method using regex
    $pattern = '/public\s+function\s+getProductVariations\s*\([^)]*\)\s*{[^}]*}/si';
    preg_match($pattern, $woocommerce_file, $matches);
    
    if (empty($matches)) {
        echo "Could not find getProductVariations method in WooCommerce.php\n";
        fwrite($log, "Could not find getProductVariations method in WooCommerce.php\n");
    } else {
        echo "Found getProductVariations method:\n" . $matches[0] . "\n";
        fwrite($log, "Found getProductVariations method:\n" . $matches[0] . "\n");
    }
    
    // Check for duplicate methods
    $count = preg_match_all($pattern, $woocommerce_file, $all_matches);
    if ($count > 1) {
        echo "WARNING: Found $count implementations of getProductVariations method!\n";
        fwrite($log, "WARNING: Found $count implementations of getProductVariations method!\n");
        
        foreach ($all_matches[0] as $index => $match) {
            fwrite($log, "\nImplementation #" . ($index + 1) . ":\n" . $match . "\n");
        }
    }
    
    // Check for the method in import-variable-products.php
    $import_file = file_get_contents(__DIR__ . '/import-variable-products.php');
    if ($import_file === false) {
        echo "Could not read import-variable-products.php file.\n";
        fwrite($log, "Could not read import-variable-products.php file.\n");
    } else {
        preg_match($pattern, $import_file, $import_matches);
        
        if (!empty($import_matches)) {
            echo "WARNING: Found getProductVariations method in import-variable-products.php!\n";
            fwrite($log, "WARNING: Found getProductVariations method in import-variable-products.php!\n");
            fwrite($log, "Method in import file:\n" . $import_matches[0] . "\n");
        } else {
            echo "No duplicate getProductVariations method found in import-variable-products.php\n";
            fwrite($log, "No duplicate getProductVariations method found in import-variable-products.php\n");
        }
    }
}

echo "\nDiagnosis completed. Results saved to: $log_file\n";
fclose($log);