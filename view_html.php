<?php
/**
 * View raw HTML from The Keys to debug parsing
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

$lockId = 3723;

// Get debug info
$debug = $api->getAllCodes($lockId, true);

header('Content-Type: text/plain; charset=utf-8');

echo "=== RAW HTML (first 10000 chars) ===\n\n";
echo substr($debug['html_snippet'], 0, 10000);

echo "\n\n=== FULL HTML LENGTH: " . $debug['html_length'] . " ===\n";
echo "\n\nLook for table rows with code information...\n";

// Try to find table content
if (preg_match('/<table[^>]*>(.*?)<\/table>/is', $debug['html_snippet'], $table)) {
    echo "\n=== FOUND TABLE ===\n";
    echo substr($table[0], 0, 3000);
}
