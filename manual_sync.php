<?php
/**
 * Manual Sync Script - Web Version
 * Run this after server downtime to sync missed bookings
 * 
 * Usage: https://your-domain.com/thekeys/manual_sync.php?apply=1
 */

// IP Protection - Only allow access from authorized IP
$allowedDomain = 'mmo.gleeze.com';
$allowedIPs = gethostbynamel($allowedDomain);
$visitorIP = $_SERVER['REMOTE_ADDR'];

if (!$allowedIPs || !in_array($visitorIP, $allowedIPs)) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><title>Access Denied</title><style>body{font-family:Arial;text-align:center;padding:50px;background:#f44336;color:white;}h1{font-size:48px;}</style></head><body><h1>🔒 Access Denied</h1><p>This page is restricted to authorized users only.</p><p>Your IP: ' . htmlspecialchars($visitorIP) . '</p></body></html>');
}

require_once 'config.php';
require_once 'TheKeysAPI.php';

$config = require 'config.php';

// Check if apply flag is provided
$apply = ($_GET['apply'] ?? false) == '1';

// Start HTML output
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Booking Sync - Recovery Tool</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 32px; margin-bottom: 10px; }
        .header p { font-size: 16px; opacity: 0.9; }
        .content { padding: 30px; }
        .mode-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .mode-dry-run {
            background: #e3f2fd;
            color: #1976d2;
        }
        .mode-apply {
            background: #e8f5e9;
            color: #388e3c;
        }
        .step {
            background: #f5f5f5;
            padding: 15px 20px;
            margin: 15px 0;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        .step-title { font-weight: bold; color: #667eea; margin-bottom: 10px; }
        .step-content { color: #666; }
        .booking {
            background: white;
            border: 1px solid #e0e0e0;
            padding: 15px;
            margin: 10px 0;
            border-radius: 6px;
        }
        .booking-action { color: #ff9800; font-weight: bold; }
        .booking-detail { color: #666; margin-left: 20px; font-size: 14px; }
        .summary {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .summary-item {
            display: inline-block;
            margin: 10px 20px 10px 0;
            padding: 10px 15px;
            background: white;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .summary-number { font-size: 24px; font-weight: bold; }
        .summary-label { font-size: 12px; color: #666; text-transform: uppercase; }
        .ok { color: #4caf50; }
        .created { color: #2196f3; }
        .updated { color: #ff9800; }
        .error { color: #f44336; }
        .skipped { color: #9e9e9e; }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            transition: transform 0.2s;
            margin: 10px 10px 10px 0;
        }
        .button:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
        .button-success { background: linear-gradient(135deg, #4caf50 0%, #45a049 100%); }
        .button-success:hover { box-shadow: 0 4px 12px rgba(76,175,80,0.4); }
        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin: 20px 0;
        }
        .alert-info { background: #e3f2fd; color: #1976d2; border-left: 4px solid #2196f3; }
        .alert-success { background: #e8f5e9; color: #388e3c; border-left: 4px solid #4caf50; }
        .alert-warning { background: #fff3e0; color: #f57c00; border-left: 4px solid #ff9800; }
        .loading { text-align: center; padding: 40px; }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🔄 Manual Booking Sync</h1>
        <p>Recovery Tool for Missed Bookings</p>
    </div>
    <div class="content">
        <?php if (!$apply): ?>
            <span class="mode-badge mode-dry-run">🔍 DRY RUN MODE</span>
            <div class="alert alert-info">
                <strong>Preview Mode</strong><br>
                This will show you what changes need to be made without actually applying them.
                Review the changes below, then click "Apply Changes" to execute.
            </div>
        <?php else: ?>
            <span class="mode-badge mode-apply">✅ APPLY MODE</span>
            <div class="alert alert-success">
                <strong>Applying Changes</strong><br>
                Changes are being applied and notifications will be sent to guests.
            </div>
        <?php endif; ?>

        <?php
        // Initialize APIs
        $keysApi = new TheKeysAPI(
            $config['thekeys']['username'],
            $config['thekeys']['password']
        );

        // Step 1: Login
        echo '<div class="step">';
        echo '<div class="step-title">[1/5] Logging into The Keys API...</div>';
        if (!$keysApi->login()) {
            echo '<div class="step-content error">❌ ERROR: Failed to login to The Keys API</div>';
            echo '</div></div></body></html>';
            exit;
        }
        echo '<div class="step-content ok">✓ Logged in successfully</div>';
        echo '</div>';

        // Step 2: Get Smoobu bookings
        echo '<div class="step">';
        echo '<div class="step-title">[2/5] Fetching bookings from Smoobu...</div>';
        
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
            echo '<div class="step-content error">❌ ERROR: Failed to fetch Smoobu bookings (HTTP ' . $httpCode . ')</div>';
            echo '</div></div></body></html>';
            exit;
        }

        $data = json_decode($response, true);
        $bookings = $data['bookings'] ?? [];
        echo '<div class="step-content ok">✓ Found ' . count($bookings) . ' bookings</div>';
        echo '</div>';

        // Step 3: Scan existing codes
        echo '<div class="step">';
        echo '<div class="step-title">[3/5] Scanning existing codes in The Keys...</div>';
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
        echo '<div class="step-content ok">✓ Found ' . count($existingCodes) . ' existing Smoobu codes</div>';
        echo '</div>';

        // Step 4: Analyze and sync
        echo '<div class="step">';
        echo '<div class="step-title">[4/5] Analyzing bookings...</div>';
        
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
                $stats['skipped']++;
                continue;
            }
            
            $lockId = $config['apartment_locks'][$apartmentId] ?? null;
            if (!$lockId) {
                $stats['skipped']++;
                continue;
            }
            
            $idAccessoire = $config['lock_accessoires'][$lockId] ?? null;
            if (!$idAccessoire) {
                $stats['skipped']++;
                continue;
            }
            
            if (isset($existingCodes[$bookingId])) {
                $existing = $existingCodes[$bookingId];
                
                if ($existing['lock_id'] != $lockId) {
                    echo '<div class="booking">';
                    echo '<div class="booking-action">→ Booking #' . $bookingId . ' (' . htmlspecialchars($guestName) . ')</div>';
                    echo '<div class="booking-detail">Apartment moved (Lock ' . $existing['lock_id'] . ' → ' . $lockId . ')</div>';
                    
                    if ($apply) {
                        $keysApi->deleteCode($existing['code_id']);
                        $pinCode = generatePIN($config['code_settings']['length'] ?? 4);
                        $times = $config['default_times'];
                        $result = $keysApi->createCode(
                            $lockId, $idAccessoire, $guestName, $pinCode,
                            $arrival, $departure,
                            $times['check_in_hour'], $times['check_in_minute'],
                            $times['check_out_hour'], $times['check_out_minute'],
                            "Smoobu#{$bookingId}"
                        );
                        
                        if ($result) {
                            echo '<div class="booking-detail ok">✓ Created new code on lock ' . $lockId . '</div>';
                            $stats['created']++;
                        } else {
                            echo '<div class="booking-detail error">✗ Failed to create code</div>';
                            $stats['errors']++;
                        }
                    } else {
                        echo '<div class="booking-detail">[DRY RUN] Would delete from lock ' . $existing['lock_id'] . ' and create on lock ' . $lockId . '</div>';
                        $stats['created']++;
                    }
                    echo '</div>';
                    
                } elseif ($existing['start'] != $arrival || $existing['end'] != $departure) {
                    echo '<div class="booking">';
                    echo '<div class="booking-action">→ Booking #' . $bookingId . ' (' . htmlspecialchars($guestName) . ')</div>';
                    echo '<div class="booking-detail">Dates changed (' . $existing['start'] . '-' . $existing['end'] . ' → ' . $arrival . '-' . $departure . ')</div>';
                    
                    if ($apply) {
                        $times = $config['default_times'];
                        $success = $keysApi->updateCode(
                            $existing['code_id'], $guestName, $existing['code'],
                            $arrival, $departure,
                            $times['check_in_hour'], $times['check_in_minute'],
                            $times['check_out_hour'], $times['check_out_minute'],
                            true, "Smoobu#{$bookingId}"
                        );
                        
                        if ($success) {
                            echo '<div class="booking-detail ok">✓ Updated</div>';
                            $stats['updated']++;
                        } else {
                            echo '<div class="booking-detail error">✗ Failed to update</div>';
                            $stats['errors']++;
                        }
                    } else {
                        echo '<div class="booking-detail">[DRY RUN] Would update dates</div>';
                        $stats['updated']++;
                    }
                    echo '</div>';
                } else {
                    $stats['ok']++;
                }
            } else {
                echo '<div class="booking">';
                echo '<div class="booking-action">→ Booking #' . $bookingId . ' (' . htmlspecialchars($guestName) . ')</div>';
                echo '<div class="booking-detail">Missing code (arrive: ' . $arrival . ')</div>';
                
                if ($apply) {
                    $pinCode = generatePIN($config['code_settings']['length'] ?? 4);
                    $times = $config['default_times'];
                    $result = $keysApi->createCode(
                        $lockId, $idAccessoire, $guestName, $pinCode,
                        $arrival, $departure,
                        $times['check_in_hour'], $times['check_in_minute'],
                        $times['check_out_hour'], $times['check_out_minute'],
                        "Smoobu#{$bookingId}"
                    );
                    
                    if ($result) {
                        $prefix = $config['digicode_prefixes'][$lockId] ?? '';
                        echo '<div class="booking-detail ok">✓ Created code ' . $prefix . $pinCode . '</div>';
                        $stats['created']++;
                    } else {
                        echo '<div class="booking-detail error">✗ Failed to create code</div>';
                        $stats['errors']++;
                    }
                } else {
                    echo '<div class="booking-detail">[DRY RUN] Would create new code</div>';
                    $stats['created']++;
                }
                echo '</div>';
            }
        }
        echo '</div>';

        // Step 5: Summary
        echo '<div class="step">';
        echo '<div class="step-title">[5/5] Sync Summary</div>';
        echo '<div class="summary">';
        echo '<div class="summary-item"><div class="summary-number ok">' . $stats['ok'] . '</div><div class="summary-label">Already Synced</div></div>';
        echo '<div class="summary-item"><div class="summary-number created">' . $stats['created'] . '</div><div class="summary-label">To Create</div></div>';
        echo '<div class="summary-item"><div class="summary-number updated">' . $stats['updated'] . '</div><div class="summary-label">To Update</div></div>';
        echo '<div class="summary-item"><div class="summary-number skipped">' . $stats['skipped'] . '</div><div class="summary-label">Skipped</div></div>';
        echo '<div class="summary-item"><div class="summary-number error">' . $stats['errors'] . '</div><div class="summary-label">Errors</div></div>';
        echo '</div>';
        echo '</div>';

        // Final message
        if (!$apply) {
            if ($stats['created'] > 0 || $stats['updated'] > 0) {
                echo '<div class="alert alert-warning">';
                echo '<strong>Ready to apply changes?</strong><br>';
                echo 'Click the button below to create missing codes and send notifications to guests.';
                echo '</div>';
                echo '<a href="?apply=1" class="button button-success">✅ Apply Changes</a>';
                echo '<a href="?" class="button">🔄 Refresh Preview</a>';
            } else {
                echo '<div class="alert alert-success">';
                echo '<strong>✓ All bookings are already synced!</strong><br>';
                echo 'No changes needed. Everything is up to date.';
                echo '</div>';
                echo '<a href="?" class="button">🔄 Refresh</a>';
            }
        } else {
            echo '<div class="alert alert-success">';
            echo '<strong>✅ Sync Complete!</strong><br>';
            if ($stats['created'] > 0 || $stats['updated'] > 0) {
                echo 'Changes have been applied. SMS and email notifications were sent to affected guests.';
            } else {
                echo 'All bookings were already in sync.';
            }
            echo '</div>';
            echo '<a href="?" class="button">🔄 Run Again</a>';
        }
        ?>
    </div>
</div>
</body>
</html>

<?php
function generatePIN($length = 4) {
    $pin = '';
    for ($i = 0; $i < $length; $i++) {
        $pin .= rand(0, 9);
    }
    return $pin;
}
?>
