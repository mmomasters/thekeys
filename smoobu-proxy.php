<?php
/**
 * Smoobu read-only signing proxy for the ElevenLabs agent's booking check.
 *
 * The agent sends X-Proxy-Token and calls e.g.
 *   GET /thekeys/smoobu-proxy.php/api/reservations?arrivalFrom=...
 * and this script forwards it to Smoobu with HMAC auth, so the agent never
 * holds the Smoobu key/secret. GET only: a leaked proxy token can read
 * bookings but cannot send messages or modify reservations.
 *
 * Signing mirrors SmoobuWebhook::smoobuSign()/uuidV4() — keep them in sync.
 * Not built on SmoobuWebhook because its constructor opens MySQL and TheKeysAPI.
 */

$config = require __DIR__ . '/config.php';
$smoobu = $config['smoobu'] ?? [];
$apiKey = $smoobu['api_key'] ?? '';
$apiSecret = $smoobu['api_secret'] ?? '';
$proxyToken = $smoobu['proxy_token'] ?? '';

header('Content-Type: application/json');

function fail($code, $error) {
    http_response_code($code);
    echo json_encode(['error' => $error]);
    exit;
}

// An empty configured token would make hash_equals('', '') pass for a missing header.
if (strlen($proxyToken) < 32 || $apiKey === '' || $apiSecret === '') {
    fail(500, 'proxy not configured');
}
if (!hash_equals($proxyToken, $_SERVER['HTTP_X_PROXY_TOKEN'] ?? '')) {
    fail(403, 'forbidden');
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    fail(405, 'method not allowed');
}

$path = $_SERVER['PATH_INFO'] ?? '';
if (!preg_match('#^/api/[A-Za-z0-9/_-]+$#', $path)) {
    fail(400, 'bad path');
}

// Same encoding as SmoobuWebhook::smoobuApiRequest(); signed string == sent string.
$query = $_GET;
ksort($query);
$queryString = http_build_query($query);
$body = '';

$timestamp = gmdate('Y-m-d\TH:i:s\Z');
$bytes = random_bytes(16);
$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10
$nonce = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

$canonical = implode("\n", [$method, $path, $queryString, $timestamp, $nonce, hash('sha256', $body), $apiKey]);
$signature = base64_encode(hash_hmac('sha256', $canonical, $apiSecret, true));

$ch = curl_init('https://login.smoobu.com' . $path . ($queryString !== '' ? '?' . $queryString : ''));
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => [
        'X-API-Key: ' . $apiKey,
        'X-Timestamp: ' . $timestamp,
        'X-Nonce: ' . $nonce,
        'X-Signature: ' . $signature,
        'Accept: application/json',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    fail(502, $curlError);
}
http_response_code($httpCode ?: 502);
echo $response;
