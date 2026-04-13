<?php
/**
 * ElevenLabs to Pushover Webhook Endpoint
 * Forwards conversation summaries from ElevenLabs AI Agent to Pushover.
 */

header('Content-Type: application/json');

// Load configuration
if (!file_exists('config.php')) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration file missing']);
    exit;
}
$config = require 'config.php';

// Get raw webhook payload
$rawPayload = file_get_contents('php://input');
$payload = json_decode($rawPayload, true);

// Get all headers once
$allHeaders = getallheaders();

// Log raw request if debugging is enabled in config
$debugMode = isset($config['logging']['enabled']) && $config['logging']['enabled'];
if ($debugMode) {
    $logFile = $config['logging']['file'];
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "\n[{$timestamp}] ELEVENLABS WEBHOOK REQUEST\n";
    $logEntry .= "Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
    $logEntry .= "Headers: " . json_encode($allHeaders) . "\n";
    $logEntry .= "Payload: " . $rawPayload . "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Validate ElevenLabs signature if configured
if (!empty($config['elevenlabs']['webhook_secret'])) {
    $signatureHeader = $_SERVER['HTTP_X_ELEVENLABS_SIGNATURE'] 
                    ?? $_SERVER['HTTP_ELEVENLABS_SIGNATURE'] 
                    ?? $allHeaders['X-ElevenLabs-Signature'] 
                    ?? $allHeaders['ElevenLabs-Signature'] 
                    ?? '';
    
    // Parse signature header (format: t=timestamp,v0=signature)
    $parts = explode(',', $signatureHeader);
    $timestamp = '';
    $signature = '';
    
    foreach ($parts as $part) {
        $kv = explode('=', $part);
        if (count($kv) === 2) {
            $key = trim($kv[0]);
            $val = trim($kv[1]);
            if ($key === 't') $timestamp = $val;
            if ($key === 'v1' || $key === 'v0') $signature = $val;
        }
    }
    
    if (!$timestamp || !$signature) {
        if ($debugMode) file_put_contents($logFile, "ERROR: Missing signature components\n", FILE_APPEND);
        http_response_code(401);
        echo json_encode(['error' => 'Missing signature components']);
        exit;
    }
    
    // Construct signed payload (timestamp.payload)
    $signedPayload = $timestamp . '.' . $rawPayload;
    $expectedSignature = hash_hmac('sha256', $signedPayload, $config['elevenlabs']['webhook_secret']);
    
    if (!hash_equals($expectedSignature, $signature)) {
        if ($debugMode) file_put_contents($logFile, "ERROR: Signature mismatch\n", FILE_APPEND);
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

// Validate request method and payload
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

// Extract event type
$type = $payload['type'] ?? '';

// We only care about post_call_transcription
if ($type !== 'post_call_transcription') {
    http_response_code(200);
    echo json_encode(['success' => true, 'result' => 'ignored']);
    exit;
}

// Extract data
$analysis = $payload['data']['analysis'] ?? [];
$summary = $analysis['transcript_summary'] ?? $analysis['summary'] ?? '';
$conversationId = $payload['data']['conversation_id'] ?? 'unknown';
$agentId = $payload['data']['agent_id'] ?? 'unknown';
$agentName = $payload['data']['agent_name'] ?? 'ElevenLabs AI Agent';

// Extract caller ID (phone number)
$callerId = $payload['data']['metadata']['phone_call']['external_number'] 
         ?? $payload['data']['conversation_initiation_client_data']['dynamic_variables']['system__caller_id']
         ?? 'Unknown';

if (empty($summary)) {
    http_response_code(200);
    echo json_encode(['success' => true, 'result' => 'no_summary']);
    exit;
}

// Prepare Pushover notification
if (!isset($config['pushover'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Pushover config missing']);
    exit;
}

$pushoverConfig = $config['pushover'];
$pushoverUrl = 'https://api.pushover.net/1/messages.json';

// Build the message
$message = "Caller: " . $callerId . "\n\n";
$message .= "Summary:\n" . $summary;

$postData = [
    'token'   => $pushoverConfig['api_token'],
    'user'    => $pushoverConfig['user_key'],
    'message' => $message,
    'title'   => $agentName,
    'url'     => "googlechrome://elevenlabs.io/app/agents/agents/{$agentId}?tab=analysis",
    'url_title' => 'Open in Chrome'
];

// Send to Pushover via cURL
$ch = curl_init($pushoverUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($debugMode) {
    file_put_contents($logFile, "Pushover Response Code: $httpCode\n", FILE_APPEND);
    file_put_contents($logFile, str_repeat('=', 80) . "\n", FILE_APPEND);
}

// Return success to ElevenLabs
http_response_code(200);
echo json_encode(['success' => true]);
