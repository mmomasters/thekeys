<?php
/**
 * Debug script to troubleshoot getAllCodes() issue
 */

require_once 'TheKeysAPI.php';
$config = require 'config.php';

$api = new TheKeysAPI(
    $config['thekeys']['username'],
    $config['thekeys']['password']
);

// Set accessoire mappings
foreach ($config['lock_accessoires'] as $lockId => $accessoireId) {
    $api->setAccessoireMapping($lockId, $accessoireId);
}

$api->login();

// Pick one of your locks to test (e.g., 3718 for Studio 1A)
$lockId = 3718;

echo "<h1>Debug Information for Lock $lockId</h1>";
echo "<pre>";

echo "=== DEBUG MODE ===\n\n";
$debug = $api->getAllCodes($lockId, true);
print_r($debug);

echo "\n\n=== NORMAL MODE ===\n\n";
$codes = $api->getAllCodes($lockId, false);
echo "Number of codes found: " . count($codes) . "\n\n";
print_r($codes);

echo "</pre>";
