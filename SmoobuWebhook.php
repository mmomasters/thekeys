<?php
/**
 * Smoobu Webhook Handler
 * Processes webhook events from Smoobu
 */

require_once 'TheKeysAPI.php';

class SmoobuWebhook {
    private $config;
    private $db;
    private $keysApi;
    
    public function __construct($config) {
        $this->config = $config;
        $this->connectDatabase();
        
        // Initialize The Keys API
        $this->keysApi = new TheKeysAPI(
            $config['thekeys']['username'],
            $config['thekeys']['password']
        );
    }
    
    private function connectDatabase() {
        $dbConfig = $this->config['database'];
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
        
        try {
            $this->db = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            $this->log("Database connection failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    private function log($message, $level = 'INFO') {
        if (!$this->config['logging']['enabled']) return;
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
        
        $logFile = $this->config['logging']['file'];
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
    
    /**
     * Process incoming webhook
     */
    public function processWebhook($payload, $eventType) {
        $this->log("Received webhook: {$eventType}");
        
        // Log to database
        $bookingId = $payload['id'] ?? null;
        $webhookLogId = $this->logWebhook($eventType, $bookingId, $payload);
        
        // Check idempotency - prevent duplicate processing
        if ($this->wasRecentlyProcessed($bookingId, $eventType)) {
            $this->log("Webhook already processed recently (idempotency), skipping: {$bookingId}");
            return ['status' => 'skipped', 'message' => 'Already processed'];
        }
        
        // Process based on event type
        try {
            switch ($eventType) {
                case 'reservation.new':
                case 'reservation.created':
                    $result = $this->handleNewReservation($payload);
                    break;
                    
                case 'reservation.updated':
                case 'reservation.modified':
                    $result = $this->handleUpdatedReservation($payload);
                    break;
                    
                case 'reservation.cancelled':
                case 'reservation.deleted':
                    $result = $this->handleCancelledReservation($payload);
                    break;
                    
                default:
                    $this->log("Unknown event type: {$eventType}", 'WARNING');
                    $result = ['status' => 'ignored', 'message' => 'Unknown event type'];
            }
            
            // Mark webhook as processed
            $this->markWebhookProcessed($webhookLogId);
            
            return $result;
            
        } catch (Exception $e) {
            $this->log("Error processing webhook: " . $e->getMessage(), 'ERROR');
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    private function handleNewReservation($booking) {
        $this->log("Processing new reservation: " . $booking['id']);
        
        // Login to The Keys API
        if (!$this->keysApi->login()) {
            throw new Exception("Failed to login to The Keys API");
        }
        
        $apartmentId = (string)($booking['apartment']['id'] ?? '');
        $lockId = $this->config['apartment_locks'][$apartmentId] ?? null;
        
        if (!$lockId) {
            $this->log("No lock mapping for apartment {$apartmentId}", 'WARNING');
            return ['status' => 'skipped', 'message' => 'No lock mapping'];
        }
        
        $idAccessoire = $this->config['lock_accessoires'][$lockId] ?? null;
        if (!$idAccessoire) {
            $this->log("No accessoire mapping for lock {$lockId}", 'WARNING');
            return ['status' => 'skipped', 'message' => 'No accessoire mapping'];
        }
        
        // Check if code already exists
        $bookingId = $booking['id'];
        $existingCode = $this->findExistingCode($lockId, $bookingId);
        
        if ($existingCode) {
            $this->log("Code already exists for booking {$bookingId}, skipping creation");
            return ['status' => 'exists', 'message' => 'Code already exists'];
        }
        
        // Generate PIN
        $pinCode = $this->generatePIN();
        $prefix = $this->config['digicode_prefixes'][$lockId] ?? '';
        $fullPin = $prefix . $pinCode;
        
        // Create code
        $guestName = $booking['guest-name'] ?? 'Guest';
        $arrival = $booking['arrival'] ?? null;
        $departure = $booking['departure'] ?? null;
        
        if (!$arrival || !$departure) {
            throw new Exception("Missing arrival or departure dates");
        }
        
        $times = $this->config['default_times'];
        $result = $this->keysApi->createCode(
            $lockId,
            $idAccessoire,
            $guestName,
            $pinCode,
            $arrival,
            $departure,
            $times['check_in_hour'],
            $times['check_in_minute'],
            $times['check_out_hour'],
            $times['check_out_minute'],
            "Smoobu#{$bookingId}"
        );
        
        if ($result) {
            $codeId = $result['id'] ?? null;
            $this->log("Created code {$pinCode} for {$guestName} (Code ID: {$codeId})");
            
            // Log to database
            $this->logSyncOperation($bookingId, $codeId, 'create', true);
            
            $apartmentName = $booking['apartment']['name'] ?? 'your apartment';
            
            // Send SMS notification for new booking
            $this->sendSMSNotification($booking, $fullPin, $apartmentName, 'new');
            
            // Send message to guest
            if (date('Y-m-d') <= $arrival) {
                $this->sendPINToGuest($booking, $fullPin, $apartmentName);
            }
            
            return ['status' => 'created', 'code_id' => $codeId, 'pin' => $pinCode];
        }
        
        throw new Exception("Failed to create code");
    }
    
    private function handleUpdatedReservation($booking) {
        $this->log("Processing updated reservation: " . $booking['id']);
        
        // Login to The Keys API
        if (!$this->keysApi->login()) {
            throw new Exception("Failed to login to The Keys API");
        }
        
        $apartmentId = (string)($booking['apartment']['id'] ?? '');
        $lockId = $this->config['apartment_locks'][$apartmentId] ?? null;
        
        if (!$lockId) {
            return ['status' => 'skipped', 'message' => 'No lock mapping'];
        }
        
        $bookingId = $booking['id'];
        
        // Search for existing code across ALL locks (apartment might have changed)
        $existingCode = null;
        $existingLockId = null;
        
        foreach ($this->config['lock_accessoires'] as $searchLockId => $accessoire) {
            $code = $this->findExistingCode($searchLockId, $bookingId);
            if ($code) {
                $existingCode = $code;
                $existingLockId = $searchLockId;
                break;
            }
        }
        
        // If code exists but on WRONG lock (apartment changed), delete old and create new
        if ($existingCode && $existingLockId != $lockId) {
            $this->log("Booking {$bookingId} moved from lock {$existingLockId} to lock {$lockId}, deleting old code");
            $this->keysApi->deleteCode($existingCode['id']);
            $this->logSyncOperation($bookingId, $existingCode['id'], 'delete', true);
            
            // Create new code on correct lock
            return $this->handleNewReservation($booking);
        }
        
        // Code not found anywhere? Create new
        if (!$existingCode) {
            $this->log("Code not found for booking {$bookingId}, creating new one");
            return $this->handleNewReservation($booking);
        }
        
        // Update existing code
        $guestName = $booking['guest-name'] ?? 'Guest';
        $arrival = $booking['arrival'] ?? null;
        $departure = $booking['departure'] ?? null;
        $existingPin = $existingCode['code'];
        
        $times = $this->config['default_times'];
        $success = $this->keysApi->updateCode(
            $existingCode['id'],
            $guestName,
            $existingPin,  // Keep existing PIN
            $arrival,
            $departure,
            $times['check_in_hour'],
            $times['check_in_minute'],
            $times['check_out_hour'],
            $times['check_out_minute'],
            true,
            "Smoobu#{$bookingId}"
        );
        
        if ($success) {
            $this->log("Updated code for {$guestName} (Code ID: {$existingCode['id']})");
            $this->logSyncOperation($bookingId, $existingCode['id'], 'update', true);
            
            // Send SMS notification for updated booking
            $apartmentName = $booking['apartment']['name'] ?? 'your apartment';
            $prefix = $this->config['digicode_prefixes'][$lockId] ?? '';
            $fullPin = $prefix . $existingPin;
            $this->sendSMSNotification($booking, $fullPin, $apartmentName, 'update');
            
            return ['status' => 'updated', 'code_id' => $existingCode['id']];
        }
        
        throw new Exception("Failed to update code");
    }
    
    private function handleCancelledReservation($booking) {
        $this->log("Processing cancelled reservation: " . $booking['id']);
        
        // Login to The Keys API
        if (!$this->keysApi->login()) {
            throw new Exception("Failed to login to The Keys API");
        }
        
        $bookingId = $booking['id'];
        
        // Search for existing code across ALL locks (might have moved apartments)
        $existingCode = null;
        $existingLockId = null;
        
        foreach ($this->config['lock_accessoires'] as $searchLockId => $accessoire) {
            $code = $this->findExistingCode($searchLockId, $bookingId);
            if ($code) {
                $existingCode = $code;
                $existingLockId = $searchLockId;
                break;
            }
        }
        
        if (!$existingCode) {
            $this->log("Code not found for booking {$bookingId}, nothing to delete");
            return ['status' => 'not_found', 'message' => 'Code not found'];
        }
        
        // CANCELLED bookings: delete immediately regardless of checkout date
        // Guest won't be coming, so code should be removed right away
        $this->log("Deleting code for cancelled booking {$bookingId} from lock {$existingLockId} (Code ID: {$existingCode['id']})");
        $success = $this->keysApi->deleteCode($existingCode['id']);
        
        if ($success) {
            $this->log("Deleted code for cancelled booking {$bookingId} (Code ID: {$existingCode['id']})");
            $this->logSyncOperation($bookingId, $existingCode['id'], 'delete', true);
            
            // No SMS for cancellations (guest already knows, save costs)
            
            return ['status' => 'deleted', 'code_id' => $existingCode['id']];
        }
        
        throw new Exception("Failed to delete code");
    }
    
    private function findExistingCode($lockId, $bookingId) {
        $codes = $this->keysApi->listCodes($lockId);
        
        foreach ($codes as $code) {
            $description = $code['description'] ?? '';
            if (strpos($description, "Smoobu#{$bookingId}") !== false) {
                return $code;
            }
        }
        
        return null;
    }
    
    private function generatePIN() {
        $length = $this->config['code_settings']['length'] ?? 4;
        $pin = '';
        for ($i = 0; $i < $length; $i++) {
            $pin .= rand(0, 9);
        }
        return $pin;
    }
    
    private function sendSMSNotification($booking, $fullPin, $apartmentName, $action = 'new') {
        $smsfactorConfig = $this->config['smsfactor'] ?? [];
        $apiToken = $smsfactorConfig['api_token'] ?? '';
        $adminRecipients = $smsfactorConfig['recipients'] ?? [];
        
        if (empty($apiToken)) {
            $this->log("SMS notifications disabled (no token)", 'DEBUG');
            return false;
        }
        
        $bookingId = $booking['id'] ?? '';
        $guestName = $booking['guest-name'] ?? 'Guest';
        $arrival = $booking['arrival'] ?? '';
        $departure = $booking['departure'] ?? '';
        
        // Get guest phone number from booking and clean it
        $guestPhone = $booking['phone'] ?? '';
        if ($guestPhone) {
            // Clean phone number: remove spaces, parentheses, dashes
            $guestPhone = str_replace([' ', '(', ')', '-'], '', $guestPhone);
        }
        
        // Only send SMS to guest (not admin to save costs)
        $recipients = [];
        if (!empty($guestPhone)) {
            $recipients[] = $guestPhone;
        }
        
        // If no guest phone, don't send any SMS
        if (empty($recipients)) {
            $this->log("No guest phone number, skipping SMS", 'DEBUG');
            return false;
        }
        
        // Get language for multilingual message
        $language = strtolower($booking['language'] ?? 'en');
        
        // Create SMS message based on action - same as email format
        if ($action == 'cancel') {
            $message = "CANCELLED: Kolna Apartments reservation {$apartmentName} ({$arrival} to {$departure}) has been cancelled.";
        } else {
            // Same message format as email
            $messages = [
                'en' => "Dear {$guestName},\n\n- Main building \"Jana z Kolna 19\" code is 1 + KEY + 5687\n- Lobby door code is 3256 + ENTER\n- Apartment {$apartmentName} door code is {$fullPin} + BLUE BUTTON\n\nYour apartment code will ONLY work between the check in and check out date and time.\nYour check in: {$arrival} from 15.00\nYour check out: {$departure} until 12.00\n\nPARKING : Parking is free from 5pm to 8am and during weekends and holidays.\n\nIn case of any issue, please call us +48 91 819 99 65\n\nKolna Apartments",
                
                'de' => "Lieber, Herr {$guestName},\n\n- Hauptgebäude \"Jana z Kolna 19\" Code ist 1 + SCHLÜSSEL + 5687\n- Lobby-Türcode ist 3256 + ENTER\n- Der Türcode für das Apartment {$apartmentName} lautet {$fullPin} + BLAUE TASTE\n\nIhr Apartmentcode funktioniert NUR zwischen Check-in- und Check-out-Datum und -Uhrzeit.\nIhr Check-in: {$arrival} ab 15.00 Uhr.\nIhr Check-out: {$departure} bis 12.00 Uhr.\n\nPARKING : Das Parken ist von 17:00 bis 08:00 Uhr sowie an Wochenenden und Feiertagen kostenlos.\n\nBei Problemen rufen Sie uns unter +48 91 819 99 65 an.\n\nKolna Apartments",
                
                'pl' => "Pan, Pani {$guestName},\n\n- Kod budynku głównego \"Jana z Kolna 19\" to 1 + KLUCZ + 5687\n- Kod do recepcji to 3256 + ENTER\n- Kod apartamentu {$apartmentName} to {$fullPin} + NIEBIESKI PRZYCISK\n\nTwój kod apartamentu będzie działał TYLKO pomiędzy datą i godziną zameldowania i wymeldowania.\nTwoje zameldowanie: {$arrival} od 15.00\nTwoje wymeldowanie: {$departure} do 12.00\n\nPARKING : Parking jest bezpłatny od 17:00 do 8:00 oraz w weekendy i święta.\n\nW przypadku problemów prosimy o kontakt +48 91 819 99 65\n\nŻyczymy miłego pobytu,\nKolna Apartments"
            ];
            
            $message = $messages[$language] ?? $messages['en'];
        }
        
        $successCount = 0;
        
        foreach ($recipients as $recipient) {
            // SMSFactor uses GET with query parameters
            $params = [
                'to' => $recipient,
                'text' => $message,
                'sender' => 'KOLNA'
            ];
            
            $url = "https://api.smsfactor.com/send?" . http_build_query($params);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $apiToken,
                'Accept: application/json'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responseData = json_decode($response, true);
            curl_close($ch);
            
            if ($httpCode === 200 && isset($responseData['status']) && $responseData['status'] == 1) {
                $this->log("Sent SMS to {$recipient} for booking {$bookingId} (ticket: {$responseData['ticket']})");
                $successCount++;
            } else {
                $errorMsg = $responseData['message'] ?? 'Unknown error';
                $this->log("Failed to send SMS to {$recipient}: HTTP {$httpCode} - {$errorMsg} - {$response}", 'WARNING');
            }
        }
        
        return $successCount > 0;
    }
    
    private function sendPINToGuest($booking, $fullPin, $apartmentName) {
        $bookingId = $booking['id'];
        $guestName = $booking['guest-name'] ?? 'Guest';
        $arrival = $booking['arrival'] ?? '';
        $departure = $booking['departure'] ?? '';
        $language = strtolower($booking['language'] ?? 'en');
        
        // Multilingual message templates
        $messages = [
            'en' => "Dear {$guestName},\n\n- Main building \"Jana z Kolna 19\" code is 1 + KEY + 5687\n- Lobby door code is 3256 + ENTER\n- Apartment {$apartmentName} door code is {$fullPin} + BLUE BUTTON\n\nYour apartment code will ONLY work between the check in and check out date and time.\nYour check in: {$arrival} from 15.00\nYour check out: {$departure} until 12.00\n\nPARKING : A lot of parking spaces are located on the street near Kolna Apartments. Parking is free from 5pm to 8am and during weekends and holidays, pricing: https://spp.szczecin.pl/informacja/paid-parking-zone-pricing\n\nIn case of any issue, please feel free to call us +48 91 819 99 65\n\nWe wish you a very pleasant stay,\nKolna Apartments",
            
            'de' => "Lieber, Herr {$guestName},\n\n- Hauptgebäude \"Jana z Kolna 19\" Code ist 1 + SCHLÜSSEL + 5687\n- Lobby-Türcode ist 3256 + ENTER\n- Der Türcode für das Apartment {$apartmentName} lautet {$fullPin} + BLAUE TASTE\n\nIhr Apartmentcode funktioniert NUR zwischen Check-in- und Check-out-Datum und -Uhrzeit.\nIhr Check-in: {$arrival} ab 15.00 Uhr.\nIhr Check-out: {$departure} bis 12.00 Uhr.\n\nPARKING : Viele Parkplätze befinden sich auf der Straße in der Nähe der Kolna Apartments. Das Parken ist von 17:00 bis 08:00 Uhr sowie an Wochenenden und Feiertagen kostenlos, Preisliste: https://spp.szczecin.pl/informacja/SPP-Preisliste\n\nBei Problemen können Sie uns gerne unter +48 91 819 99 65 anrufen.\n\nWir wünschen Ihnen einen sehr angenehmen Aufenthalt,\nKolna Apartments",
            
            'pl' => "Pan, Pani {$guestName},\n\n- Kod budynku głównego \"Jana z Kolna 19\" to 1 + KLUCZ + 5687\n- Kod do recepcji to 3256 + ENTER\n- Kod apartamentu {$apartmentName} to {$fullPin} + NIEBIESKI PRZYCISK\n\nTwój kod apartamentu będzie działał TYLKO pomiędzy datą i godziną zameldowania i wymeldowania.\nTwoje zameldowanie: {$arrival} od 15.00\nTwoje wymeldowanie: {$departure} do 12.00\n\nPARKING : Dużo miejsc parkingowych znajduje się przy ulicy pod Kolna Apartments. Parking jest bezpłatny od 17:00 do 8:00 oraz w weekendy i święta, cennik: https://spp.szczecin.pl/informacja/cennik-strefy-platnego-parkowania\n\nW przypadku jakichkolwiek problemów prosimy o kontakt telefoniczny +48 91 819 99 65\n\nŻyczymy miłego pobytu,\nKolna Apartments"
        ];
        
        $subjects = [
            'en' => "Kolna Apartments access codes and information",
            'de' => "Zugangscodes für die Kolna Apartments",
            'pl' => "Kody dostępu do Kolna Apartments"
        ];
        
        $message = $messages[$language] ?? $messages['en'];
        $subject = $subjects[$language] ?? $subjects['en'];
        
        // Send via Smoobu API
        $url = "https://login.smoobu.com/api/reservations/{$bookingId}/messages/send-message-to-guest";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'subject' => $subject,
            'messageBody' => $message
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Api-Key: ' . $this->config['smoobu']['api_key'],
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 201 || $httpCode === 200) {
            $this->log("Sent PIN message to {$guestName} ({$language})");
            return true;
        } else {
            $this->log("Failed to send message: HTTP {$httpCode}", 'WARNING');
            return false;
        }
    }
    
    private function logWebhook($eventType, $bookingId, $payload) {
        $stmt = $this->db->prepare("
            INSERT INTO webhook_logs (event_type, booking_id, payload, processed)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $eventType,
            $bookingId,
            json_encode($payload),
            0  // Use 0 instead of false for MySQL compatibility
        ]);
        
        return $this->db->lastInsertId();
    }
    
    private function markWebhookProcessed($webhookLogId) {
        $stmt = $this->db->prepare("UPDATE webhook_logs SET processed = TRUE WHERE id = ?");
        $stmt->execute([$webhookLogId]);
    }
    
    private function wasRecentlyProcessed($bookingId, $eventType, $minutes = 5) {
        if (!$bookingId) return false;
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM webhook_logs
            WHERE booking_id = ? 
            AND event_type = ?
            AND processed = TRUE
            AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        
        $stmt->execute([$bookingId, $eventType, $minutes]);
        return $stmt->fetchColumn() > 0;
    }
    
    private function logSyncOperation($bookingId, $codeId, $operation, $success, $errorMessage = null) {
        $stmt = $this->db->prepare("
            INSERT INTO sync_history (booking_id, code_id, operation, success, error_message)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $bookingId,
            $codeId,
            $operation,
            $success,
            $errorMessage
        ]);
    }
}
