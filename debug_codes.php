<?php
/**
 * Debug script to troubleshoot getAllCodes() issue
 */

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Debug Information</h1>";
echo "<pre>";

try {
    echo "Step 1: Loading files...\n";
    require_once 'TheKeysAPI.php';
    echo "✓ TheKeysAPI.php loaded\n\n";
    
    $config = require 'config.php';
    echo "✓ config.php loaded\n\n";
    
    echo "Step 2: Initializing API...\n";
    $api = new TheKeysAPI(
        $config['thekeys']['username'],
        $config['thekeys']['password']
    );
    echo "✓ API initialized\n\n";
    
    echo "Step 3: Setting accessoire mappings...\n";
    foreach ($config['lock_accessoires'] as $lockId => $accessoireId) {
        $api->setAccessoireMapping($lockId, $accessoireId);
        echo "  Lock $lockId => Accessoire $accessoireId\n";
    }
    echo "✓ Mappings set\n\n";
    
    echo "Step 4: Logging in...\n";
    $api->login();
    echo "✓ Logged in successfully\n\n";
    
    // Pick one of your locks to test (use 3723 as confirmed by user)
    $lockId = 3723;
    
    echo "Step 5: Getting codes for Lock $lockId (DEBUG MODE)...\n\n";
    echo "=== DEBUG MODE ===\n";
    $debug = $api->getAllCodes($lockId, true);
    print_r($debug);
    
    echo "\n\nStep 6: Getting codes for Lock $lockId (NORMAL MODE)...\n\n";
    echo "=== NORMAL MODE ===\n";
    $codes = $api->getAllCodes($lockId, false);
    echo "Number of codes found: " . count($codes) . "\n\n";
    print_r($codes);
    
} catch (Exception $e) {
    echo "\n\n❌ ERROR:\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString();
}

echo "</pre>";
