<?php
/**
 * Fix Product Variations Script
 * 
 * A simple script to fix the specific problem product with ghost variations.
 * This is a lightweight alternative to the full cleanup script.
 * 
 * Usage: php fix-problem-product.php
 */

// Include required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/WooCommerce.php';
require_once __DIR__ . '/../includes/functions.php';

// The specific problem product ID
$problem_product_id = 2268; // Κουδούνια αιγοπροβάτων απο το νούμερο 1 έως 16

// The expected SKUs that should be published
$expected_skus = [
    '106018', '106017', '106016', '106015', '106010', '106011', '106012',
    '106013', '106014', '106019', '106020', '106021', '106022'
];

// The unexpected SKUs that should be deleted
$unexpected_skus = [
    '204047', '204056', '204057', '204058', '204059', '204048', '204049',
    '204050', '204051', '204052'
];

echo "Starting to fix problem product ID: $problem_product_id\n";

// Initialize WooCommerce
$woocommerce = new WooCommerce();

// Test connection
echo "Testing WooCommerce connection...\n";
$connection = $woocommerce->testConnection();
if (!$connection['success']) {
    die("Failed to connect to WooCommerce API: " . $connection['message'] . "\n");
}
echo "Connection successful!\n";

// Get the product
echo "Fetching product information...\n";
$product = $woocommerce->getProduct($problem_product_id);

if (!$product || !isset($product->id)) {
    die("Product not found or invalid response.\n");
}

echo "Found product: {$product->name}\n";

// Get all variations for this product
echo "Fetching variations...\n";
$variations = $woocommerce->getProductVariations($problem_product_id);

if (empty($variations)) {
    die("No variations found for this product.\n");
}

echo "Found " . count($variations) . " variations\n";

// Analyze variations
$found_expected = [];
$found_unexpected = [];
$other_variations = [];

foreach ($variations as $variation) {
    $sku = isset($variation->sku) ? $variation->sku : "";
    $status = isset($variation->status) ? $variation->status : "unknown";
    $id = $variation->id;
    
    echo "Variation ID: $id, SKU: $sku, Status: $status\n";
    
    if (in_array($sku, $expected_skus)) {
        $found_expected[$sku] = [
            'id' => $id,
            'status' => $status
        ];
    } else if (in_array($sku, $unexpected_skus)) {
        $found_unexpected[$sku] = [
            'id' => $id,
            'status' => $status
        ];
    } else {
        $other_variations[] = [
            'id' => $id,
            'sku' => $sku,
            'status' => $status
        ];
    }
}

// Report on findings
echo "\nAnalysis Results:\n";
echo "----------------\n";

echo "Expected SKUs found: " . count($found_expected) . " of " . count($expected_skus) . "\n";
$missing_expected = array_diff($expected_skus, array_keys($found_expected));
if (!empty($missing_expected)) {
    echo "Missing expected SKUs: " . implode(", ", $missing_expected) . "\n";
}

echo "Unexpected SKUs found: " . count($found_unexpected) . " of " . count($unexpected_skus) . "\n";
$missing_unexpected = array_diff($unexpected_skus, array_keys($found_unexpected));
if (!empty($missing_unexpected)) {
    echo "Missing unexpected SKUs: " . implode(", ", $missing_unexpected) . "\n";
}

echo "Other variations found: " . count($other_variations) . "\n";

// Instructions for manual fix
echo "\nManual Fix Instructions:\n";
echo "----------------------\n";
echo "To fix this product manually in the WooCommerce admin:\n\n";

echo "1. Go to WooCommerce > Products\n";
echo "2. Edit product 'Κουδούνια αιγοπροβάτων απο το νούμερο 1 έως 16' (ID: $problem_product_id)\n";
echo "3. Go to the Variations tab\n";
echo "4. Look for these SKUs: " . implode(", ", array_keys($found_unexpected)) . "\n";
echo "5. Delete these variations by clicking the trash icon next to each one\n";
echo "6. Save the product\n";

// API Fix Instructions
echo "\nAPI Fix Instructions:\n";
echo "-------------------\n";
echo "To fix this product via the WooCommerce API:\n\n";

echo "1. Add the following methods to your WooCommerce.php class:\n\n";
echo "/**
 * Update a variation's status
 * 
 * @param int \$product_id The parent product ID
 * @param int \$variation_id The variation ID
 * @param string \$status The new status (publish, draft, pending, or trash)
 * @param bool \$visible Whether the variation should be visible
 * @return object|bool The result or false on failure
 */
public function updateVariationStatus(\$product_id, \$variation_id, \$status = \"publish\", \$visible = true) {
    try {
        \$endpoint = \"products/{\$product_id}/variations/{\$variation_id}\";
        \$params = [
            \"status\" => \$status,
            \"visible\" => \$visible
        ];
        
        return \$this->makeRequest(\$endpoint, \"PUT\", \$params);
    } catch (Exception \$e) {
        error_log(\"Error updating variation status: \" . \$e->getMessage());
        return false;
    }
}

/**
 * Delete a variation
 * 
 * @param int \$product_id The parent product ID
 * @param int \$variation_id The variation ID
 * @param bool \$force Whether to permanently delete (true) or move to trash (false)
 * @return object|bool The result or false on failure
 */
public function deleteVariation(\$product_id, \$variation_id, \$force = true) {
    try {
        \$endpoint = \"products/{\$product_id}/variations/{\$variation_id}\";
        \$params = [
            \"force\" => \$force
        ];
        
        return \$this->makeRequest(\$endpoint, \"DELETE\", \$params);
    } catch (Exception \$e) {
        error_log(\"Error deleting variation: \" . \$e->getMessage());
        return false;
    }
}
";

echo "\n2. Create a script that calls these methods for the problematic variations:\n\n";
echo "<?php
// Fix the problematic variations
\$woocommerce = new WooCommerce();\n";

foreach ($found_unexpected as $sku => $info) {
    echo "\$woocommerce->deleteVariation($problem_product_id, {$info['id']}, true); // Delete SKU: $sku\n";
}

foreach ($found_expected as $sku => $info) {
    if ($info['status'] !== 'publish') {
        echo "\$woocommerce->updateVariationStatus($problem_product_id, {$info['id']}, 'publish', true); // Publish SKU: $sku\n";
    }
}

echo "\necho \"Fixes applied successfully.\\n\";\n";

echo "\nAfter fixing the variations, you should delete all variable products from your local database:\n";
echo "php cron/delete-variable-products.php\n\n";
echo "Then re-import using a status-aware import script. This will ensure you only import published variations.\n";