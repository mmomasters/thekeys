<?php
/**
 * Manual Sync Script
 * Run this after server downtime to sync missed bookings
 * 
 * Usage: php manual_sync.php
 */

require_once 'config.php';
require_once 'TheKeysAPI.php';
require_once 'SmoobuWebhook.php';

$config = require 'config.php';

echo "============================================\n";
echo "Manual Booking Sync - Recovery Tool\n";
echo "============================================\n\n";

// Initialize APIs
$keysApi = new TheKeysAPI(
    $config['thekeys']['username'],
    $config['thekeys']['password']
);

// Login to The Keys
echo "[1/5] Logging into The Keys API...\n";
if (!$keysApi->login()) {
    die("ERROR: Failed to login to The Keys API\n");
}
echo "✓ Logged in successfully\n\n";

// Get Smoobu bookings
echo "[2/5] Fetching bookings from Smoobu...\n";
$smoobuApiKey = $config['smoobu']['api_key'];
$startDate = date('Y-m-d');
$endDate = date('Y-m-d', strtotime('+90 days'));

$url = "https://login.smoobu.com/api/reservations?arrivalFrom={$startDate}&arrivalTo={$endDate}";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Api-Key: ' . $smoobuApiKey,
    'Cache-Control: no-cache'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("ERROR: Failed to fetch Smoobu bookings (HTTP {$httpCode})\n");
}

$data = json_decode($response, true);
$bookings = $data['bookings'] ?? [];
echo "✓ Found " . count($bookings) . " bookings\n\n";

// Scan existing codes
echo "[3/5] Scanning existing codes in The Keys...\n";
$existingCodes = [];
foreach ($config['lock_accessoires'] as $lockId => $accessoireId) {
    $codes = $keysApi->listCodes($lockId);
    foreach ($codes as $code) {
        $desc = $code['description'] ?? '';
        if (preg_match('/Smoobu#(\d+)/', $desc, $matches)) {
            $bookingId = $matches[1];
            $existingCodes[$bookingId] = [
                'lock_id' => $lockId,
                'code_id' => $code['id'],
                'code' => $code['code'],
                'name' => $code['nom'],
                'start' => $code['date_debut'],
                'end' => $code['date_fin']
            ];
        }
    }
}
echo "✓ Found " . count($existingCodes) . " existing Smoobu codes\n\n";

// Analyze and sync
echo "[4/5] Analyzing bookings...\n";
$stats = [
    'ok' => 0,
    'created' => 0,
    'updated' => 0,
    'errors' => 0,
    'skipped' => 0
];

foreach ($bookings as $booking) {
    $bookingId = $booking['id'];
    $guestName = $booking['guest-name'] ?? 'Guest';
    $arrival = $booking['arrival'] ?? null;
    $departure = $booking['departure'] ?? null;
    $apartmentId = (string)($booking['apartment']['id'] ?? '');
    
    if (!$arrival || !$departure) {
        echo "  ⚠ Booking {$bookingId}: Missing dates, skipping\n";
        $stats['skipped']++;
        continue;
    }
    
    // Get lock mapping
    $lockId = $config['apartment_locks'][$apartmentId] ?? null;
    if (!$lockId) {
        echo "  ⚠ Booking {$bookingId}: No lock mapping for apartment {$apartmentId}, skipping\n";
        $stats['skipped']++;
        continue;
    }
    
    $idAccessoire = $config['lock_accessoires'][$lockId] ?? null;
    if (!$idAccessoire) {
        echo "  ⚠ Booking {$bookingId}: No accessoire for lock {$lockId}, skipping\n";
        $stats['skipped']++;
        continue;
    }
    
    // Check if code exists
    if (isset($existingCodes[$bookingId])) {
        $existing = $existingCodes[$bookingId];
        
        // Check if code is on correct lock
        if ($existing['lock_id'] != $lockId) {
            echo "  → Booking {$bookingId} ({$guestName}): Moved locks, recreating...\n";
            
            // Delete old code
            $keysApi->deleteCode($existing['code_id']);
            
            // Create new code
            $pinCode = generatePIN($config['code_settings']['length'] ?? 4);
            $times = $config['default_times'];
            $result = $keysApi->createCode(
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
                echo "    ✓ Created new code on lock {$lockId}\n";
                $stats['created']++;
            } else {
                echo "    ✗ Failed to create code\n";
                $stats['errors']++;
            }
        } elseif ($existing['start'] != $arrival || $existing['end'] != $departure) {
            // Dates changed, update
            echo "  → Booking {$bookingId} ({$guestName}): Dates changed, updating...\n";
            
            $times = $config['default_times'];
            $success = $keysApi->updateCode(
                $existing['code_id'],
                $guestName,
                $existing['code'],
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
                echo "    ✓ Updated\n";
                $stats['updated']++;
            } else {
                echo "    ✗ Failed to update\n";
                $stats['errors']++;
            }
        } else {
            // All good
            $stats['ok']++;
        }
    } else {
        // Code doesn't exist, create it
        echo "  → Booking {$bookingId} ({$guestName}): Creating new code...\n";
        
        $pinCode = generatePIN($config['code_settings']['length'] ?? 4);
        $times = $config['default_times'];
        $result = $keysApi->createCode(
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
            $prefix = $config['digicode_prefixes'][$lockId] ?? '';
            echo "    ✓ Created code {$prefix}{$pinCode}\n";
            $stats['created']++;
        } else {
            echo "    ✗ Failed to create code\n";
            $stats['errors']++;
        }
    }
}

echo "\n[5/5] Sync Summary:\n";
echo "  ✓ Already synced: {$stats['ok']}\n";
echo "  + Created: {$stats['created']}\n";
echo "  ⟳ Updated: {$stats['updated']}\n";
echo "  ⚠ Skipped: {$stats['skipped']}\n";
echo "  ✗ Errors: {$stats['errors']}\n";

echo "\n============================================\n";
echo "Manual sync complete!\n";
echo "============================================\n";

/**
 * Generate random PIN
 */
function generatePIN($length = 4) {
    $pin = '';
    for ($i = 0; $i < $length; $i++) {
        $pin .= rand(0, 9);
    }
    return $pin;
}
