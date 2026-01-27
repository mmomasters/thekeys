<?php
/**
 * Check details of a specific code to see date format
 */

require_once 'TheKeysAPI.php';
$config = require 'config.php';

$api = new TheKeysAPI(
    $config['thekeys']['username'],
    $config['thekeys']['password']
);

foreach ($config['lock_accessoires'] as $lockId => $accessoireId) {
    $api->setAccessoireMapping($lockId, $accessoireId);
}

$api->login();

header('Content-Type: text/plain; charset=utf-8');

// Check one of the smoobu codes - 683237
$codeId = 683237;

echo "=== Checking Code Details for ID: $codeId ===\n\n";

try {
    $details = $api->getCodeDetails($codeId);
    
    echo "Full Details:\n";
    print_r($details);
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
