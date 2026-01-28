<?php
/**
 * Lock Migration Tool
 * Copy all bookings from a broken lock to a spare lock
 * and resend notifications to guests
 * 
 * Usage: https://your-domain.com/thekeys/lock_migration.php
 */

// IP Protection - Only allow access from authorized IP
$allowedDomain = 'mmo.gleeze.com';
$allowedIPs = gethostbynamel($allowedDomain);

// Get real IP (Cloudflare passes real IP in CF-Connecting-IP header)
$visitorIP = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];

if (!$allowedIPs || !in_array($visitorIP, $allowedIPs)) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><title>Access Denied</title><style>body{font-family:Arial;text-align:center;padding:50px;background:#f44336;color:white;}h1{font-size:48px;}</style></head><body><h1>🔒 Access Denied</h1><p>This page is restricted to authorized users only.</p><p>Your IP: ' . htmlspecialchars($visitorIP) . '</p><p>Allowed: ' . htmlspecialchars(implode(', ', $allowedIPs ?: [])) . '</p></body></html>');
}

require_once 'config.php';
require_once 'TheKeysAPI.php';
require_once 'SmoobuWebhook.php';

$config = require 'config.php';

// Get parameters
$fromLock = $_GET['from'] ?? null;
$toLock = $_GET['to'] ?? null;
$apply = ($_GET['apply'] ?? false) == '1';

// Get all locks including spares
$allLocks = array_merge(
    array_values($config['lock_accessoires']),
    [11503, 3726] // Spare locks
);
$allLocks = array_unique(array_map('intval', array_keys($config['lock_accessoires'])));
$allLocks[] = 11503;
$allLocks[] = 3726;
sort($allLocks);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lock Migration Tool</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
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
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 32px; margin-bottom: 10px; }
        .header p { font-size: 16px; opacity: 0.9; }
        .content { padding: 30px; }
        .selection-form {
            background: #f5f5f5;
            padding: 25px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .form-group { margin: 15px 0; }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
            color: #333;
        }
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            background: white;
        }
        .form-group select:focus {
            outline: none;
            border-color: #f5576c;
        }
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
            border-left: 4px solid #f5576c;
        }
        .step-title { font-weight: bold; color: #f5576c; margin-bottom: 10px; }
        .step-content { color: #666; }
        .code-item {
            background: white;
            border: 1px solid #e0e0e0;
            padding: 15px;
            margin: 10px 0;
            border-radius: 6px;
        }
        .code-name { font-weight: bold; color: #333; }
        .code-detail { color: #666; font-size: 14px; margin-left: 20px; }
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
        .summary-number { font-size: 24px; font-weight: bold; color: #f5576c; }
        .summary-label { font-size: 12px; color: #666; text-transform: uppercase; }
        .ok { color: #4caf50; }
        .warning { color: #ff9800; }
        .error { color: #f44336; }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.2s;
            margin: 10px 10px 10px 0;
        }
        .button:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(245,87,108,0.4); }
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
        .alert-danger { background: #ffebee; color: #c62828; border-left: 4px solid #f44336; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🔧 Lock Migration Tool</h1>
        <p>Copy bookings from broken lock to spare lock</p>
    </div>
    <div class="content">
        <?php if (!$fromLock || !$toLock): ?>
            <!-- Selection Form -->
            <div class="alert alert-info">
                <strong>Lock Replacement Wizard</strong><br>
                When a lock breaks, select which lock to replace and which spare lock to use.
                The system will copy all active bookings and resend notifications to guests.
            </div>
            
            <form method="GET" class="selection-form">
                <div class="form-group">
                    <label>🔴 From Lock (Broken/Old):</label>
                    <select name="from" required>
                        <option value="">-- Select broken lock --</option>
                        <?php foreach ($allLocks as $lockId): ?>
                            <option value="<?= $lockId ?>"><?= $lockId ?> <?= in_array($lockId, [11503, 3726]) ? '(Spare)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>🟢 To Lock (New/Spare):</label>
                    <select name="to" required>
                        <option value="">-- Select replacement lock --</option>
                        <?php foreach ($allLocks as $lockId): ?>
                            <option value="<?= $lockId ?>"><?= $lockId ?> <?= in_array($lockId, [11503, 3726]) ? '(Spare)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="button">🔍 Preview Migration</button>
            </form>
            
        <?php else: 
            // Validation
            if ($fromLock == $toLock) {
                echo '<div class="alert alert-danger"><strong>Error!</strong><br>Source and destination locks must be different.</div>';
                echo '<a href="?" class="button">← Back</a>';
                exit;
            }
            
            // Show mode badge
            if (!$apply): ?>
                <span class="mode-badge mode-dry-run">🔍 DRY RUN MODE</span>
                <div class="alert alert-info">
                    <strong>Preview Mode</strong><br>
                    Review the codes that will be migrated and guests who will be notified.
                    Click "Apply Migration" to proceed.
                </div>
            <?php else: ?>
                <span class="mode-badge mode-apply">✅ APPLY MODE</span>
                <div class="alert alert-success">
                    <strong>Migrating Codes</strong><br>
                    Copying codes and sending notifications...
                </div>
            <?php endif;
            
            // Display selection
            echo '<div class="summary">';
            echo '<strong>Migration:</strong> Lock ' . htmlspecialchars($fromLock) . ' → Lock ' . htmlspecialchars($toLock);
            echo '</div>';
            
            // Initialize API
            $keysApi = new TheKeysAPI(
                $config['thekeys']['username'],
                $config['thekeys']['password']
            );
            
            // Step 1: Login
            echo '<div class="step">';
            echo '<div class="step-title">[1/4] Logging into The Keys API...</div>';
            if (!$keysApi->login()) {
                echo '<div class="step-content error">❌ ERROR: Failed to login</div>';
                echo '</div></div></body></html>';
                exit;
            }
            echo '<div class="step-content ok">✓ Logged in successfully</div>';
            echo '</div>';
            
            // Step 2: Get codes from source lock
            echo '<div class="step">';
            echo '<div class="step-title">[2/4] Fetching codes from source lock ' . htmlspecialchars($fromLock) . '...</div>';
            
            $sourceCodes = $keysApi->listCodes($fromLock);
            
            // Filter active codes (not expired)
            $today = date('Y-m-d');
            $activeCodes = array_filter($sourceCodes, function($code) use ($today) {
                $endDate = $code['date_fin'] ?? '';
                return $endDate >= $today;
            });
            
            echo '<div class="step-content ok">✓ Found ' . count($sourceCodes) . ' total codes (' . count($activeCodes) . ' active)</div>';
            echo '</div>';
            
            // Step 3: Preview/Copy codes
            echo '<div class="step">';
            echo '<div class="step-title">[3/4] ' . ($apply ? 'Copying' : 'Preview of') . ' codes...</div>';
            
            $stats = [
                'copied' => 0,
                'skipped' => 0,
                'sms_sent' => 0,
                'email_sent' => 0,
                'errors' => 0
            ];
            
            $toAccessoire = $config['lock_accessoires'][$toLock] ?? null;
            
            if (!$toAccessoire && $apply) {
                echo '<div class="step-content error">❌ ERROR: No accessoire mapping for lock ' . $toLock . '</div>';
                echo '</div></div></body></html>';
                exit;
            }
            
            foreach ($activeCodes as $code) {
                $codeName = $code['nom'] ?? 'Unknown';
                $codePin = $code['code'] ?? '';
                $startDate = $code['date_debut'] ?? '';
                $endDate = $code['date_fin'] ?? '';
                $description = $code['description'] ?? '';
                
                echo '<div class="code-item">';
                echo '<div class="code-name">📌 ' . htmlspecialchars($codeName) . '</div>';
                echo '<div class="code-detail">Code: ' . htmlspecialchars($codePin) . ' | ' . htmlspecialchars($startDate) . ' to ' . htmlspecialchars($endDate) . '</div>';
                
                if ($apply) {
                    // Get times from original code
                    $timeStart = $code['heure_debut'] ?? [];
                    $timeEnd = $code['heure_fin'] ?? [];
                    
                    $times = $config['default_times'];
                    $startHour = $timeStart['hour'] ?? $times['check_in_hour'];
                    $startMin = $timeStart['minute'] ?? $times['check_in_minute'];
                    $endHour = $timeEnd['hour'] ?? $times['check_out_hour'];
                    $endMin = $timeEnd['minute'] ?? $times['check_out_minute'];
                    
                    // Create code on new lock
                    $result = $keysApi->createCode(
                        $toLock,
                        $toAccessoire,
                        $codeName,
                        $codePin,
                        $startDate,
                        $endDate,
                        $startHour,
                        $startMin,
                        $endHour,
                        $endMin,
                        $description . ' [Migrated from lock ' . $fromLock . ']'
                    );
                    
                    if ($result) {
                        echo '<div class="code-detail ok">✓ Copied to new lock</div>';
                        $stats['copied']++;
                        
                        // Try to extract booking info and resend notifications
                        preg_match('/Smoobu#(\d+)/', $description, $matches);
                        if ($matches) {
                            $bookingId = $matches[1];
                            
                            // Fetch booking from Smoobu
                            $smoobuApiKey = $config['smoobu']['api_key'];
                            $url = "https://login.smoobu.com/api/reservations/{$bookingId}";
                            $ch = curl_init($url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Api-Key: ' . $smoobuApiKey]);
                            $response = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            
                            if ($httpCode == 200) {
                                $booking = json_decode($response, true);
                                if ($booking) {
                                    $apartmentName = $booking['apartment']['name'] ?? 'your apartment';
                                    $prefix = $config['digicode_prefixes'][$toLock] ?? '';
                                    $fullPin = $prefix . $codePin;
                                    
                                    // Initialize webhook handler for notifications
                                    $webhook = new SmoobuWebhook($config);
                                    
                                    // Send SMS (uses private method, so we'll inline it)
                                    // Send email via Smoobu
                                    $language = strtolower($booking['language'] ?? 'en');
                                    $langFile = __DIR__ . "/languages/{$language}.php";
                                    if (!file_exists($langFile)) {
                                        $langFile = __DIR__ . "/languages/en.php";
                                    }
                                    $lang = require $langFile;
                                    
                                    $replacements = [
                                        '{guest_name}' => $booking['guest-name'] ?? 'Guest',
                                        '{apartment_name}' => $apartmentName,
                                        '{full_pin}' => $fullPin,
                                        '{arrival}' => $booking['arrival'] ?? '',
                                        '{departure}' => $booking['departure'] ?? ''
                                    ];
                                    
                                    $message = str_replace(array_keys($replacements), array_values($replacements), $lang['message']);
                                    $subject = $lang['subject'];
                                    
                                    // Send email
                                    $emailUrl = "https://login.smoobu.com/api/reservations/{$bookingId}/messages/send-message-to-guest";
                                    $ch = curl_init($emailUrl);
                                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                    curl_setopt($ch, CURLOPT_POST, true);
                                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['subject' => $subject, 'messageBody' => $message]));
                                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Api-Key: ' . $smoobuApiKey, 'Content-Type: application/json']);
                                    $emailResponse = curl_exec($ch);
                                    $emailCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                    curl_close($ch);
                                    
                                    if ($emailCode == 201 || $emailCode == 200) {
                                        echo '<div class="code-detail ok">✓ Email sent</div>';
                                        $stats['email_sent']++;
                                    }
                                    
                                    // Send SMS
                                    $guestPhone = $booking['phone'] ?? '';
                                    if ($guestPhone) {
                                        $guestPhone = str_replace([' ', '(', ')', '-'], '', $guestPhone);
                                        $smsToken = $config['smsfactor']['api_token'] ?? '';
                                        
                                        if ($smsToken) {
                                            $smsUrl = "https://api.smsfactor.com/send?" . http_build_query([
                                                'to' => $guestPhone,
                                                'text' => $message,
                                                'sender' => 'KOLNA'
                                            ]);
                                            
                                            $ch = curl_init($smsUrl);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $smsToken, 'Accept: application/json']);
                                            $smsResponse = curl_exec($ch);
                                            $smsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                            $smsData = json_decode($smsResponse, true);
                                            curl_close($ch);
                                            
                                            if ($smsCode == 200 && isset($smsData['status']) && $smsData['status'] == 1) {
                                                echo '<div class="code-detail ok">✓ SMS sent</div>';
                                                $stats['sms_sent']++;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        echo '<div class="code-detail error">✗ Failed to copy</div>';
                        $stats['errors']++;
                    }
                } else {
                    echo '<div class="code-detail">[DRY RUN] Will copy and notify guest</div>';
                    $stats['copied']++;
                }
                
                echo '</div>';
            }
            
            if (empty($activeCodes)) {
                echo '<div class="code-detail">No active codes to migrate</div>';
            }
            
            echo '</div>';
            
            // Step 4: Summary
            echo '<div class="step">';
            echo '<div class="step-title">[4/4] Migration Summary</div>';
            echo '<div class="summary">';
            echo '<div class="summary-item"><div class="summary-number">' . $stats['copied'] . '</div><div class="summary-label">Codes ' . ($apply ? 'Copied' : 'To Copy') . '</div></div>';
            if ($apply) {
                echo '<div class="summary-item"><div class="summary-number ok">' . $stats['email_sent'] . '</div><div class="summary-label">Emails Sent</div></div>';
                echo '<div class="summary-item"><div class="summary-number ok">' . $stats['sms_sent'] . '</div><div class="summary-label">SMS Sent</div></div>';
                if ($stats['errors'] > 0) {
                    echo '<div class="summary-item"><div class="summary-number error">' . $stats['errors'] . '</div><div class="summary-label">Errors</div></div>';
                }
            }
            echo '</div>';
            echo '</div>';
            
            // Final actions
            if (!$apply && $stats['copied'] > 0) {
                echo '<div class="alert alert-warning">';
                echo '<strong>Ready to migrate?</strong><br>';
                echo 'This will copy ' . $stats['copied'] . ' code(s) to the new lock and resend notifications to all guests.';
                echo '</div>';
                echo '<a href="?from=' . urlencode($fromLock) . '&to=' . urlencode($toLock) . '&apply=1" class="button button-success">✅ Apply Migration</a>';
                echo '<a href="?from=' . urlencode($fromLock) . '&to=' . urlencode($toLock) . '" class="button">🔄 Refresh</a>';
                echo '<a href="?" class="button">← Back</a>';
            } elseif ($apply) {
                echo '<div class="alert alert-success">';
                echo '<strong>✅ Migration Complete!</strong><br>';
                echo 'Successfully migrated ' . $stats['copied'] . ' codes. Guests have been notified of the change.';
                echo '</div>';
                echo '<a href="?" class="button">🔧 Migrate Another Lock</a>';
            } else {
                echo '<div class="alert alert-info">';
                echo '<strong>No active codes to migrate</strong><br>';
                echo 'The source lock has no active bookings.';
                echo '</div>';
                echo '<a href="?" class="button">← Back</a>';
            }
            
        endif; ?>
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
