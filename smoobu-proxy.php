<?php
/**
 * Smoobu availability endpoint for the ElevenLabs agent's booking check.
 *
 *   POST /thekeys/smoobu-proxy.php/availability
 *   X-Proxy-Token: <config['smoobu']['proxy_token']>
 *   {"arrival":"2026-10-30","departure":"2026-10-31","guests":1}
 *
 * (The same fields also work as GET query params.) Returns only which
 * apartments are free, with price — never booking or guest data, so the
 * agent cannot read other guests' details to a caller. There is deliberately
 * no raw /api/... passthrough.
 *
 * Signing mirrors SmoobuWebhook::smoobuSign()/uuidV4() — keep them in sync.
 * Not built on SmoobuWebhook because its constructor opens MySQL and TheKeysAPI.
 */

$config = require __DIR__ . '/config.php';
$smoobu = $config['smoobu'] ?? [];
$apiKey = $smoobu['api_key'] ?? '';
$apiSecret = $smoobu['api_secret'] ?? '';
$proxyToken = $smoobu['proxy_token'] ?? '';

const APARTMENTS_CACHE_TTL = 86400;

header('Content-Type: application/json');

function fail($code, $error, $received = null) {
    http_response_code($code);
    $out = ['error' => $error];
    if ($received !== null) {
        $out['received'] = $received; // echoes the agent's own input so a bad tool config is diagnosable
    }
    echo json_encode($out);
    exit;
}

// Strict Y-m-d, tolerating a trailing time part ("2026-10-30T00:00:00").
function parseDate($value, DateTimeZone $tz) {
    if (!is_string($value) || !preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $m)) {
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $m[1], $tz);
    return $date && $date->format('Y-m-d') === $m[1] ? $date : null;
}

// Signed request against the Smoobu API. Returns [$httpCode, $decodedJson|null].
function smoobuRequest($method, $path, array $query = [], $bodyString = '') {
    global $apiKey, $apiSecret;

    ksort($query);
    $queryString = http_build_query($query);

    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10
    $nonce = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

    $canonical = implode("\n", [$method, $path, $queryString, $timestamp, $nonce, hash('sha256', $bodyString), $apiKey]);
    $signature = base64_encode(hash_hmac('sha256', $canonical, $apiSecret, true));

    $headers = [
        'X-API-Key: ' . $apiKey,
        'X-Timestamp: ' . $timestamp,
        'X-Nonce: ' . $nonce,
        'X-Signature: ' . $signature,
        'Accept: application/json',
    ];

    $ch = curl_init('https://login.smoobu.com' . $path . ($queryString !== '' ? '?' . $queryString : ''));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($bodyString !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyString);
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$httpCode, $response === false ? null : json_decode($response, true)];
}

// Customer id + apartment id=>name map. Rarely changes, so cached for a day
// to keep the voice agent's call down to a single Smoobu request.
function loadAccount() {
    $cacheFile = sys_get_temp_dir() . '/smoobu-proxy-apartments.json';
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - APARTMENTS_CACHE_TTL) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (!empty($cached['customerId']) && !empty($cached['apartments'])) {
            return $cached;
        }
    }

    list($meCode, $me) = smoobuRequest('GET', '/api/me');
    list($aptCode, $apts) = smoobuRequest('GET', '/api/apartments');
    if ($meCode !== 200 || empty($me['id']) || $aptCode !== 200 || empty($apts['apartments'])) {
        return null;
    }

    $account = ['customerId' => $me['id'], 'apartments' => []];
    foreach ($apts['apartments'] as $apt) {
        $account['apartments'][(string)$apt['id']] = $apt['name'];
    }
    @file_put_contents($cacheFile, json_encode($account));
    return $account;
}

// An empty configured token would make hash_equals('', '') pass for a missing header.
if (strlen($proxyToken) < 32 || $apiKey === '' || $apiSecret === '') {
    fail(500, 'proxy not configured');
}
if (!hash_equals($proxyToken, $_SERVER['HTTP_X_PROXY_TOKEN'] ?? '')) {
    fail(403, 'forbidden');
}
if (($_SERVER['PATH_INFO'] ?? '') !== '/availability') {
    fail(404, 'not found');
}
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    fail(405, 'method not allowed');
}

// JSON body wins; form-encoded body and query params are fallbacks.
$rawBody = file_get_contents('php://input');
$json = json_decode($rawBody, true);
$input = array_merge($_GET, is_array($json) ? $json : $_POST);
$received = $input ?: ($rawBody !== '' ? substr($rawBody, 0, 500) : 'empty request (is the tool set to POST with a body?)');

$tz = new DateTimeZone('Europe/Warsaw');
$arrival = parseDate($input['arrival'] ?? $input['arrivalDate'] ?? null, $tz);
$departure = parseDate($input['departure'] ?? $input['departureDate'] ?? null, $tz);
if (!$arrival) {
    fail(400, 'arrival must be a date in YYYY-MM-DD format', $received);
}
if (!$departure) {
    fail(400, 'departure must be a date in YYYY-MM-DD format', $received);
}
if ($departure <= $arrival) {
    fail(400, 'departure must be after arrival');
}
if ($arrival < new DateTime('today', $tz)) {
    fail(400, 'arrival is in the past');
}
$guests = filter_var($input['guests'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
if ($guests === false) {
    fail(400, 'guests must be a number between 1 and 20', $received);
}

$account = loadAccount();
if ($account === null) {
    fail(502, 'availability check failed');
}

list($code, $result) = smoobuRequest('POST', '/booking/checkApartmentAvailability', [], json_encode([
    'arrivalDate' => $arrival->format('Y-m-d'),
    'departureDate' => $departure->format('Y-m-d'),
    'apartments' => array_map('intval', array_keys($account['apartments'])),
    'customerId' => $account['customerId'],
    'guests' => $guests,
]));
if ($code !== 200 || !is_array($result) || !isset($result['availableApartments'])) {
    fail(502, 'availability check failed');
}

// Smoobu lists free apartments in availableApartments, explains rule failures
// (too many guests, minimum stay, ...) in errorMessages, and omits booked ones.
$freeIds = array_map('strval', $result['availableApartments']);
$errors = is_array($result['errorMessages'] ?? null) ? $result['errorMessages'] : [];
$available = [];
$unavailable = [];
foreach ($account['apartments'] as $id => $name) {
    $id = (string)$id; // PHP turns numeric-string array keys back into ints
    if (in_array($id, $freeIds, true)) {
        $price = $result['prices'][$id] ?? [];
        $available[] = ['apartment' => $name, 'price' => $price['price'] ?? null, 'currency' => $price['currency'] ?? null];
    } else {
        $unavailable[] = ['apartment' => $name, 'reason' => $errors[$id]['message'] ?? 'booked'];
    }
}

echo json_encode([
    'arrival' => $arrival->format('Y-m-d'),
    'departure' => $departure->format('Y-m-d'),
    'nights' => $arrival->diff($departure)->days,
    'guests' => $guests,
    'available' => $available,
    'unavailable' => $unavailable,
]);
