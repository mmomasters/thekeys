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

// Get the full HTML
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $debug['url']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, sys_get_temp_dir() . '/thekeys_session_' . md5($config['thekeys']['username']) . '.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, sys_get_temp_dir() . '/thekeys_session_' . md5($config['thekeys']['username']) . '.txt');
$fullHtml = curl_exec($ch);
curl_close($ch);

echo "=== FULL HTML LENGTH: " . strlen($fullHtml) . " ===\n\n";

// Try to find table content
if (preg_match_all('/<table[^>]*>(.*?)<\/table>/is', $fullHtml, $tables)) {
    echo "=== FOUND " . count($tables[0]) . " TABLE(S) ===\n\n";
    foreach ($tables[0] as $i => $table) {
        echo "--- TABLE " . ($i+1) . " ---\n";
        echo substr($table, 0, 5000) . "\n\n";
    }
} else {
    echo "No tables found. Looking for 'partage' or 'code' keywords...\n\n";
    
    // Search for relevant sections
    if (preg_match('/partage.*?<\/div>/is', $fullHtml, $m)) {
        echo "Found 'partage' section:\n";
        echo substr($m[0], 0, 2000) . "\n\n";
    }
}
