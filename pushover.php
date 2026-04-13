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

// Log raw request for debugging (moved up for earlier logging)
if (isset($config['logging']['enabled']) && $config['logging']['enabled']) {
    $logFile = $config['logging']['file'];
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $allHeaders = getallheaders();
    $logEntry = "\n[{$timestamp}] ELEVENLABS WEBHOOK REQUEST\n";
    $logEntry .= "Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
    $logEntry .= "Headers: " . json_encode($allHeaders) . "\n";
    $logEntry .= "Payload: " . $rawPayload . "\n";
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Validate ElevenLabs signature if configured
if (!empty($config['elevenlabs']['webhook_secret'])) {
    // Check both standard and possible Variations of the signature header
    $signatureHeader = $_SERVER['HTTP_X_ELEVENLABS_SIGNATURE'] 
                    ?? $_SERVER['HTTP_ELEVENLABS_SIGNATURE'] 
                    ?? $allHeaders['X-ElevenLabs-Signature'] 
                    ?? $allHeaders['ElevenLabs-Signature'] 
                    ?? '';
    
    if (isset($config['logging']['enabled']) && $config['logging']['enabled']) {
        file_put_contents($logFile, "Signature Header Found: " . $signatureHeader . "\n", FILE_APPEND);
    }

    // Parse signature header (format: t=timestamp,v1=signature)
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
        if (isset($config['logging']['enabled']) && $config['logging']['enabled']) {
            file_put_contents($logFile, "ERROR: Missing signature components (t or v1)\n", FILE_APPEND);
        }
        http_response_code(401);
        echo json_encode(['error' => 'Missing signature components']);
        exit;
    }
    
    // Construct signed payload (timestamp + payload)
    $signedPayload = $timestamp . $rawPayload;
    $expectedSignature = hash_hmac('sha256', $signedPayload, $config['elevenlabs']['webhook_secret']);
    
    if (!hash_equals($expectedSignature, $signature)) {
        if (isset($config['logging']['enabled']) && $config['logging']['enabled']) {
            file_put_contents($logFile, "ERROR: Signature mismatch. Expected: $expectedSignature, Received: $signature\n", FILE_APPEND);
        }
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }

    if (isset($config['logging']['enabled']) && $config['logging']['enabled']) {
        file_put_contents($logFile, "SUCCESS: Signature validated\n", FILE_APPEND);
    }
}

// Rest of the logic...
if (isset($config['logging']['enabled']) && $config['logging']['enabled']) {
    file_put_contents($config['logging']['file'], str_repeat('-', 80) . "\n", FILE_APPEND);
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Validate payload
if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

// Extract event type
$type = $payload['type'] ?? '';

// We only care about post_call_transcription which contains the summary
if ($type !== 'post_call_transcription') {
    http_response_code(200);
    echo json_encode(['success' => true, 'result' => 'ignored_event_type', 'type' => $type]);
    exit;
}

// Extract summary from data.analysis.summary
$summary = $payload['data']['analysis']['summary'] ?? '';
$conversationId = $payload['data']['conversation_id'] ?? 'unknown';
$agentId = $payload['data']['agent_id'] ?? 'unknown';

if (empty($summary)) {
    // If summary is not in the analysis, maybe it's a different event structure or no summary was generated
    http_response_code(200);
    echo json_encode(['success' => true, 'result' => 'no_summary_available', 'conversation_id' => $conversationId]);
    exit;
}

// Prepare Pushover notification
if (!isset($config['pushover'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Pushover configuration missing']);
    exit;
}

$pushoverConfig = $config['pushover'];
$pushoverUrl = 'https://api.pushover.net/1/messages.json';

// Build the message
$message = "Conversation Summary:\n" . $summary;
$message .= "\n\nID: " . $conversationId;

$postData = [
    'token'   => $pushoverConfig['api_token'],
    'user'    => $pushoverConfig['user_key'],
    'message' => $message,
    'title'   => 'ElevenLabs AI Agent',
    'url'     => "https://elevenlabs.io/app/conversational-ai/{$agentId}/conversations/{$conversationId}",
    'url_title' => 'View Conversation'
];

// Send to Pushover via cURL
$ch = curl_init($pushoverUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Log Pushover result
if (isset($config['logging']['enabled']) && $config['logging']['enabled']) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "\n[{$timestamp}] PUSHOVER NOTIFICATION SENT\n";
    $logEntry .= "Conversation ID: {$conversationId}\n";
    $logEntry .= "HTTP Code: {$httpCode}\n";
    $logEntry .= "Response: {$response}\n";
    if ($error) {
        $logEntry .= "cURL Error: {$error}\n";
    }
    $logEntry .= str_repeat('-', 80) . "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Return success to ElevenLabs
http_response_code(200);
echo json_encode([
    'success' => true, 
    'pushover_status' => $httpCode,
    'conversation_id' => $conversationId
]);
