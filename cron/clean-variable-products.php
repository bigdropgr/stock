<?php
/**
 * Clean and Reset Variable Products Script
 * 
 * This script completely removes all variable products and their variations,
 * then fixes any duplicate method definitions in your code.
 * 
 * Usage: php clean-variable-products.php
 */

// Set unlimited execution time
set_time_limit(0);

// Include required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "Starting cleanup of variable products...\n";

// Initialize database
$db = Database::getInstance();

// Step 1: Delete variable products from database
echo "\nStep 1: Deleting all variable products and variations from the database...\n";

// Delete all products that are variations or variable products
$sql = "DELETE FROM physical_inventory WHERE notes LIKE '%Variable product%' OR notes LIKE '%Variation of product%'";
$result = $db->query($sql);

if ($result) {
    $affected_rows = $db->getConnection()->affected_rows;
    echo "Successfully deleted $affected_rows variable products and variations.\n";
} else {
    echo "Error deleting variable products: " . $db->getError() . "\n";
}

// Step 2: Fix the WooCommerce.php file
echo "\nStep 2: Checking and fixing WooCommerce.php...\n";

$woocommerce_file = __DIR__ . '/../includes/WooCommerce.php';
$woocommerce_content = file_get_contents($woocommerce_file);

if ($woocommerce_content === false) {
    echo "ERROR: Could not read WooCommerce.php file.\n";
} else {
    // Check if there are duplicate methods
    $pattern = '/public\s+function\s+getProductVariations\s*\([^)]*\)\s*{[^}]*}/si';
    $count = preg_match_all($pattern, $woocommerce_content, $matches);
    
    if ($count > 1) {
        echo "Found $count implementations of getProductVariations method!\n";
        echo "Keeping only the first implementation...\n";
        
        // Keep only the first implementation
        $first_implementation = $matches[0][0];
        $fixed_content = preg_replace($pattern, '', $woocommerce_content);
        
        // Add the first implementation back at the end of the class
        $fixed_content = str_replace('}</function>', "}\n\n    " . $first_implementation . "\n}</function>", $fixed_content);
        
        // If the class doesn't end with }</function>, attempt another way to add it back
        if (strpos($fixed_content, '}</function>') === false) {
            $fixed_content = preg_replace('/}(\s*)$/', "}\n\n    " . $first_implementation . "\n}", $fixed_content, 1);
        }
        
        // Backup the original file
        $backup_file = $woocommerce_file . '.bak';
        file_put_contents($backup_file, $woocommerce_content);
        echo "Original file backed up to: $backup_file\n";
        
        // Write the fixed file
        file_put_contents($woocommerce_file, $fixed_content);
        echo "WooCommerce.php has been fixed to have only one getProductVariations method.\n";
    } else if ($count === 1) {
        echo "WooCommerce.php has only one getProductVariations method. No fix needed.\n";
    } else {
        echo "No getProductVariations method found in WooCommerce.php.\n";
        echo "Adding the method to the file...\n";
        
        // Add the method to the file
        $method_to_add = "\n    /**
     * Get product variations
     * 
     * @param int \$product_id Product ID
     * @return array List of variations
     */
    public function getProductVariations(\$product_id) {
        try {
            \$endpoint = \"products/{\$product_id}/variations\";
            \$params = [
                'per_page' => 100
            ];
            
            return \$this->makeRequest(\$endpoint, 'GET', \$params);
        } catch (Exception \$e) {
            error_log(\"Error getting product variations: \" . \$e->getMessage());
            return [];
        }
    }\n";
        
        // Find the end of the class
        $fixed_content = preg_replace('/}(\s*)$/', $method_to_add . "}\n", $woocommerce_content, 1);
        
        // Backup the original file
        $backup_file = $woocommerce_file . '.bak';
        file_put_contents($backup_file, $woocommerce_content);
        echo "Original file backed up to: $backup_file\n";
        
        // Write the fixed file
        file_put_contents($woocommerce_file, $fixed_content);
        echo "Added getProductVariations method to WooCommerce.php.\n";
    }
}

// Step 3: Check and fix import-variable-products.php
echo "\nStep 3: Checking import-variable-products.php for duplicate method definitions...\n";

$import_file = __DIR__ . '/import-variable-products.php';
$import_content = file_get_contents($import_file);

if ($import_content === false) {
    echo "ERROR: Could not read import-variable-products.php file.\n";
} else {
    // Check for function definition at the end
    $pattern = '/function\s+getProductVariations\s*\([^)]*\)\s*{[^}]*}/si';
    $count = preg_match_all($pattern, $import_content, $matches);
    
    if ($count > 0) {
        echo "Found getProductVariations function in import-variable-products.php. Removing it...\n";
        
        // Remove the function
        $fixed_content = preg_replace($pattern, '', $import_content);
        
        // Backup the original file
        $backup_file = $import_file . '.bak';
        file_put_contents($backup_file, $import_content);
        echo "Original file backed up to: $backup_file\n";
        
        // Write the fixed file
        file_put_contents($import_file, $fixed_content);
        echo "Removed getProductVariations function from import-variable-products.php.\n";
    } else {
        echo "No duplicate getProductVariations function found in import-variable-products.php.\n";
    }
}

echo "\nCleanup completed! You can now run the updated import-variable-products.php script.\n";
echo "Make sure to use the fixed-import-variable-products.php script for the import.\n";