<?php
/**
 * Smoobu Webhook Receiver
 * Handles booking events: create, cancel, modify
 */

require_once 'TheKeysAPI.php';

class SmoobuWebhook {
    
    private $config;
    private $api;
    
    public function __construct($config) {
        $this->config = $config;
        $this->initAPI();
    }
    
    /**
     * Initialize The Keys API
     */
    private function initAPI() {
        $this->api = new TheKeysAPI(
            $this->config['thekeys']['username'],
            $this->config['thekeys']['password']
        );
        
        // Set accessoire mappings
        foreach ($this->config['lock_accessoires'] as $lockId => $accessoireId) {
            $this->api->setAccessoireMapping($lockId, $accessoireId);
        }
    }
    
    /**
     * Log message
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        
        // Create log directory if needed
        $logDir = dirname($this->config['log_file']);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        // Write to log file
        @file_put_contents($this->config['log_file'], $logMessage, FILE_APPEND);
    }
    
    /**
     * Generate random PIN code
     */
    private function generatePIN() {
        return str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Get lock ID for apartment
     */
    private function getLockId($apartmentId) {
        return $this->config['apartment_locks'][$apartmentId] ?? null;
    }
    
    /**
     * Extract guest name from booking data
     */
    private function getGuestName($data) {
        $firstName = $data['firstName'] ?? '';
        $lastName = $data['lastName'] ?? '';
        $guestName = trim("$firstName $lastName");
        
        return empty($guestName) || $guestName === ' ' ? 'Guest' : $guestName;
    }
    
    /**
     * Create new access code
     */
    public function createCode($data) {
        $this->log("=== CREATE CODE ===");
        
        // Extract data
        $apartmentId = $data['apartment']['id'] ?? null;
        $guestName = $this->getGuestName($data);
        $checkIn = $data['arrival'] ?? null;
        $checkOut = $data['departure'] ?? null;
        
        // Validate
        if (!$apartmentId) {
            throw new Exception("Missing apartment ID");
        }
        if (!$checkIn || !$checkOut) {
            throw new Exception("Missing check-in/out dates");
        }
        
        // Get lock ID
        $lockId = $this->getLockId($apartmentId);
        if (!$lockId) {
            $this->log("⚠️  No lock mapped for apartment $apartmentId");
            return [
                'skipped' => true, 
                'reason' => 'No lock mapping for apartment ' . $apartmentId
            ];
        }
        
        // Check if code already exists
        $existingCode = $this->api->findCodeByGuestName($lockId, $guestName);
        if ($existingCode) {
            $this->log("⚠️  Code already exists for $guestName (ID: {$existingCode['id']})");
            return [
                'already_exists' => true,
                'guest' => $guestName,
                'code_id' => $existingCode['id']
            ];
        }
        
        // Generate PIN
        $pinCode = $this->generatePIN();
        
        // Create code
        $this->log("Creating: $guestName | $checkIn to $checkOut | PIN: $pinCode | Lock: $lockId");
        
        $result = $this->api->createCode($lockId, [
            'guestName' => $guestName,
            'startDate' => $checkIn,
            'endDate' => $checkOut,
            'startTime' => $this->config['default_times']['check_in'],
            'endTime' => $this->config['default_times']['check_out'],
            'code' => $pinCode,
            'description' => 'Smoobu booking'
        ]);
        
        $this->log("✅ Code created! PIN: $pinCode");
        
        return [
            'success' => true,
            'action' => 'created',
            'pin' => $pinCode,
            'guest' => $guestName,
            'apartment_id' => $apartmentId,
            'lock_id' => $lockId,
            'check_in' => $checkIn,
            'check_out' => $checkOut
        ];
    }
    
    /**
     * Delete access code
     */
    public function deleteCode($data) {
        $this->log("=== DELETE CODE ===");
        
        // Extract data
        $apartmentId = $data['apartment']['id'] ?? null;
        $guestName = $this->getGuestName($data);
        
        if (!$apartmentId) {
            throw new Exception("Missing apartment ID");
        }
        
        // Get lock ID
        $lockId = $this->getLockId($apartmentId);
        if (!$lockId) {
            $this->log("⚠️  No lock mapped for apartment $apartmentId");
            return ['skipped' => true];
        }
        
        // Find the code
        $this->log("Searching for code: $guestName");
        $code = $this->api->findCodeByGuestName($lockId, $guestName);
        
        if (!$code) {
            $this->log("⚠️  No code found for $guestName");
            return [
                'not_found' => true,
                'guest' => $guestName
            ];
        }
        
        // Delete
        $this->log("Deleting code ID: {$code['id']}");
        $this->api->deleteCode($code['id']);
        
        $this->log("✅ Code deleted!");
        
        return [
            'success' => true,
            'action' => 'deleted',
            'guest' => $guestName,
            'code_id' => $code['id'],
            'apartment_id' => $apartmentId,
            'lock_id' => $lockId
        ];
    }
    
    /**
     * Update access code (delete old + create new)
     */
    public function updateCode($data) {
        $this->log("=== UPDATE CODE ===");
        
        $guestName = $this->getGuestName($data);
        $this->log("Guest: $guestName");
        
        // Delete old code
        try {
            $deleteResult = $this->deleteCode($data);
        } catch (Exception $e) {
            $this->log("Warning: Could not delete old code - " . $e->getMessage());
            $deleteResult = ['error' => $e->getMessage()];
        }
        
        // Create new code with updated dates
        $createResult = $this->createCode($data);
        
        return [
            'success' => true,
            'action' => 'updated',
            'deleted' => $deleteResult,
            'created' => $createResult
        ];
    }
    
    /**
     * Process webhook event
     */
    public function process($payload) {
        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? $payload;
        
        $this->log("\n" . str_repeat("=", 60));
        $this->log("Webhook Event: $event");
        $this->log(str_repeat("=", 60));
        
        try {
            switch ($event) {
                case 'booking.created':
                case 'booking.new':
                    return $this->createCode($data);
                    
                case 'booking.cancelled':
                case 'booking.canceled':
                    return $this->deleteCode($data);
                    
                case 'booking.modified':
                case 'booking.updated':
                    return $this->updateCode($data);
                    
                default:
                    $this->log("⚠️  Unknown event: $event");
                    return ['ignored' => true, 'event' => $event];
            }
            
        } catch (Exception $e) {
            $this->log("❌ ERROR: " . $e->getMessage());
            throw $e;
        }
    }
}

// ========================================
// WEBHOOK ENDPOINT
// ========================================

// Only process if called directly, not when included
if (php_sapi_name() !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    
    // Load config
    $config = require_once 'config.php';
    
    // Get webhook payload
    $rawPayload = file_get_contents('php://input');
    $payload = json_decode($rawPayload, true);
    
    // Verify payload
    if (!$payload) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }
    
    // Optional: Verify webhook signature
    if (!empty($config['smoobu_secret'])) {
        $signature = $_SERVER['HTTP_X_SMOOBU_SIGNATURE'] ?? '';
        $expectedSignature = hash_hmac('sha256', $rawPayload, $config['smoobu_secret']);
        
        if (!hash_equals($expectedSignature, $signature)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
    }
    
    // Process webhook
    try {
        $webhook = new SmoobuWebhook($config);
        $result = $webhook->process($payload);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'result' => $result
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage()
        ]);
    }
}