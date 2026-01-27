<?php
/**
 * Emergency Lock Swap Utility
 * 
 * When a lock breaks, use this script to quickly swap to a backup lock
 * and copy all existing codes to the new lock.
 * 
 * Usage: php emergency_swap.php [studio] [backup_lock_id]
 * Example: php emergency_swap.php 1A 7540
 */

require_once 'TheKeysAPI.php';

// CLI only
if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

// Load config
$config = require 'config.php';

// Parse arguments
$studio = $argv[1] ?? null;
$backupLockId = $argv[2] ?? null;

if (!$studio || !$backupLockId) {
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║           EMERGENCY LOCK SWAP UTILITY                      ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "Usage: php emergency_swap.php [studio] [backup_lock_id]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php emergency_swap.php 1A 7540\n";
    echo "  php emergency_swap.php 1B 3726\n";
    echo "\n";
    echo "This will:\n";
    echo "  1. Find all active codes on the broken lock\n";
    echo "  2. Copy them to the backup lock\n";
    echo "  3. Update config.php to use the new lock\n";
    echo "\n";
    exit(1);
}

// Find studio in config
$studioMap = [
    '1A' => 505200,
    '1B' => 505203,
    '1C' => 505206,
    '1D' => 505209,
];

$apartmentId = $studioMap[$studio] ?? null;
if (!$apartmentId) {
    echo "❌ ERROR: Unknown studio '$studio'\n";
    echo "Available studios: " . implode(', ', array_keys($studioMap)) . "\n";
    exit(1);
}

// Get current lock
$currentLockId = $config['apartment_locks'][$apartmentId] ?? null;
if (!$currentLockId) {
    echo "❌ ERROR: No lock mapped for studio $studio (Apartment ID: $apartmentId)\n";
    exit(1);
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║           EMERGENCY LOCK SWAP - Studio $studio              ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Current Lock:  $currentLockId (broken)\n";
echo "Backup Lock:   $backupLockId (replacement)\n";
echo "Apartment ID:  $apartmentId\n";
echo "\n";
echo "⚠️  WARNING: This will copy ALL codes to the new lock!\n";
echo "\n";
echo "Continue? (yes/no): ";

$handle = fopen("php://stdin", "r");
$confirm = trim(fgets($handle));
fclose($handle);

if (strtolower($confirm) !== 'yes') {
    echo "\n❌ Cancelled by user\n\n";
    exit(0);
}

echo "\n";
echo "Starting swap process...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

try {
    // Initialize API
    $api = new TheKeysAPI(
        $config['thekeys']['username'],
        $config['thekeys']['password']
    );
    
    // Set accessoire mappings
    foreach ($config['lock_accessoires'] as $lockId => $accessoireId) {
        $api->setAccessoireMapping($lockId, $accessoireId);
    }
    
    // Login
    echo "🔐 Logging in to The Keys...\n";
    $api->login();
    echo "✅ Logged in successfully\n\n";
    
    // Get all codes from broken lock
    echo "📋 Fetching codes from broken lock ($currentLockId)...\n";
    $codes = $api->getAllCodes($currentLockId);
    
    if (empty($codes)) {
        echo "ℹ️  No codes found on broken lock\n";
        echo "   Nothing to copy.\n\n";
    } else {
        echo "✅ Found " . count($codes) . " code(s) to copy\n\n";
        
        // Display codes
        echo "Codes to copy:\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        foreach ($codes as $code) {
            echo sprintf(
                "  • %s (ID: %s) - %s → %s\n",
                $code['name'],
                $code['id'],
                $code['start_date'] ?? '?',
                $code['end_date'] ?? '?'
            );
        }
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        // Copy each code
        echo "📝 Copying codes to backup lock ($backupLockId)...\n";
        $successCount = 0;
        $failCount = 0;
        
        foreach ($codes as $code) {
            try {
                echo "   Copying: {$code['name']}... ";
                $result = $api->copyCode($code['id'], $backupLockId);
                echo "✅\n";
                $successCount++;
            } catch (Exception $e) {
                echo "❌ {$e->getMessage()}\n";
                $failCount++;
            }
        }
        
        echo "\n";
        echo "Results: $successCount copied, $failCount failed\n\n";
    }
    
    // Update config.php
    echo "📝 Updating config.php...\n";
    
    $configPath = __DIR__ . '/config.php';
    $configContent = file_get_contents($configPath);
    
    // Backup original config
    $backupPath = __DIR__ . '/config.backup.' . date('YmdHis') . '.php';
    file_put_contents($backupPath, $configContent);
    echo "   ✅ Backup created: " . basename($backupPath) . "\n";
    
    // Replace lock ID in apartment_locks mapping
    $pattern = "/(\d+)\s*=>\s*" . preg_quote($currentLockId, '/') . "\s*,\s*\/\/\s*Studio\s+" . preg_quote($studio, '/') . "/";
    $replacement = "$1 => $backupLockId,   // Studio $studio (SWAPPED from $currentLockId)";
    $configContent = preg_replace($pattern, $replacement, $configContent);
    
    // Write updated config
    file_put_contents($configPath, $configContent);
    echo "   ✅ Config updated: Lock $currentLockId → $backupLockId\n\n";
    
    // Check if we need to add accessoire mapping for backup lock
    echo "⚠️  IMPORTANT: You may need to update lock_accessoires mapping!\n";
    echo "   For lock $backupLockId, find and add the accessoire ID to config.php\n\n";
    
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║                   SWAP COMPLETED                           ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "✅ Studio $studio now uses Lock $backupLockId\n";
    echo "📋 Config backup saved to: " . basename($backupPath) . "\n";
    echo "\n";
    echo "Next steps:\n";
    echo "  1. Verify codes in The Keys app on lock $backupLockId\n";
    echo "  2. Add accessoire mapping for lock $backupLockId in config.php\n";
    echo "  3. Test the webhook with test_webhook.php\n";
    echo "  4. Remove/retire the broken lock $currentLockId\n";
    echo "\n";
    
} catch (Exception $e) {
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║                     ERROR                                  ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "❌ " . $e->getMessage() . "\n";
    echo "\n";
    exit(1);
}
