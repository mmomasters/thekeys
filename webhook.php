<?php
/**
 * Smoobu Webhook Endpoint
 * URL: https://mmo-masters.com/thekeys/webhook.php
 */

header('Content-Type: application/json');

require_once 'SmoobuWebhook.php';

// Load configuration
$config = require 'config.php';

// Get raw webhook payload
$rawPayload = file_get_contents('php://input');
$payload = json_decode($rawPayload, true);

// Log raw request for debugging
if ($config['logging']['enabled']) {
    $logFile = $config['logging']['file'];
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "\n[{$timestamp}] RAW WEBHOOK REQUEST\n";
    $logEntry .= "Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
    $logEntry .= "Headers: " . json_encode(getallheaders()) . "\n";
    $logEntry .= "Payload: " . $rawPayload . "\n";
    $logEntry .= str_repeat('-', 80) . "\n";
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
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

// Extract event type and data
// Smoobu sends action field (newReservation, cancelReservation, updateReservation)
$action = $payload['action'] ?? '';
$bookingData = $payload['data'] ?? $payload['booking'] ?? $payload;

// Map Smoobu actions to our event types
$eventTypeMap = [
    'newReservation' => 'reservation.new',
    'cancelReservation' => 'reservation.cancelled',
    'updateReservation' => 'reservation.updated',
];

$eventType = $eventTypeMap[$action] ?? 'reservation.updated';

// For cancelled bookings, check the type field too
if (isset($bookingData['type']) && $bookingData['type'] === 'cancellation') {
    $eventType = 'reservation.cancelled';
}

// Security checks (optional but recommended)
if (!empty($config['webhook']['ip_whitelist'])) {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($clientIp, $config['webhook']['ip_whitelist'])) {
        http_response_code(403);
        echo json_encode(['error' => 'IP not whitelisted']);
        exit;
    }
}

// Validate webhook secret if configured
if (!empty($config['webhook']['secret'])) {
    $signature = $_SERVER['HTTP_X_SMOOBU_SIGNATURE'] ?? '';
    $expectedSignature = hash_hmac('sha256', $rawPayload, $config['webhook']['secret']);
    
    if (!hash_equals($expectedSignature, $signature)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

try {
    // Process webhook
    $webhook = new SmoobuWebhook($config);
    $result = $webhook->processWebhook($bookingData, $eventType);
    
    // Return success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'result' => $result,
        'event' => $eventType,
        'booking_id' => $bookingData['id'] ?? null
    ]);
    
} catch (Exception $e) {
    // Log error
    if ($config['logging']['enabled']) {
        $logFile = $config['logging']['file'];
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [ERROR] " . $e->getMessage() . "\n";
        $logEntry .= $e->getTraceAsString() . "\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    // Return error response (but still 200 to prevent Smoobu retries)
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
