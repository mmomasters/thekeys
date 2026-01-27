<?php
/**
 * View raw HTML from code detail page
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

$codeId = 683237;

// Make a direct request to get the detail page HTML
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.the-keys.fr/en/compte/partage/accessoire/$codeId/get");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, sys_get_temp_dir() . '/thekeys_session_' . md5($config['thekeys']['username']) . '.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, sys_get_temp_dir() . '/thekeys_session_' . md5($config['thekeys']['username']) . '.txt');
$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: text/plain; charset=utf-8');

echo "=== Code Detail Page HTML for ID: $codeId ===\n\n";
echo "HTTP Code: $code\n\n";
echo "First 10000 characters:\n\n";
echo substr($html, 0, 10000);
