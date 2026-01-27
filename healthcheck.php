<?php
/**
 * Health Check Endpoint
 * 
 * Use this endpoint to monitor the integration's health status.
 * Returns JSON with system status, dependencies, and last activity.
 * 
 * Usage: GET /healthcheck.php
 */

header('Content-Type: application/json');

$status = [
    'status' => 'healthy',
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => []
];

// Check 1: Config file exists
$status['checks']['config_exists'] = [
    'status' => file_exists(__DIR__ . '/config.php') ? 'ok' : 'error',
    'message' => file_exists(__DIR__ . '/config.php') 
        ? 'Configuration file found' 
        : 'Configuration file missing - copy config.example.php to config.php'
];

// Check 2: PHP version
$requiredPhpVersion = '7.4.0';
$currentPhpVersion = phpversion();
$status['checks']['php_version'] = [
    'status' => version_compare($currentPhpVersion, $requiredPhpVersion, '>=') ? 'ok' : 'error',
    'value' => $currentPhpVersion,
    'required' => $requiredPhpVersion,
    'message' => version_compare($currentPhpVersion, $requiredPhpVersion, '>=')
        ? 'PHP version is compatible'
        : "PHP version {$requiredPhpVersion} or higher required"
];

// Check 3: cURL extension
$status['checks']['curl_extension'] = [
    'status' => extension_loaded('curl') ? 'ok' : 'error',
    'message' => extension_loaded('curl') 
        ? 'cURL extension is loaded' 
        : 'cURL extension is required but not loaded'
];

// Check 4: Required files
$requiredFiles = [
    'TheKeysAPI.php',
    'smoobu_webhook.php',
    'test_webhook.php',
    'emergency_swap.php'
];

$missingFiles = [];
foreach ($requiredFiles as $file) {
    if (!file_exists(__DIR__ . '/' . $file)) {
        $missingFiles[] = $file;
    }
}

$status['checks']['required_files'] = [
    'status' => empty($missingFiles) ? 'ok' : 'error',
    'message' => empty($missingFiles) 
        ? 'All required files present' 
        : 'Missing files: ' . implode(', ', $missingFiles)
];

// Check 5: Logs directory
$logsDir = __DIR__ . '/logs';
$logsDirExists = is_dir($logsDir);
$logsDirWritable = $logsDirExists && is_writable($logsDir);

$status['checks']['logs_directory'] = [
    'status' => $logsDirWritable ? 'ok' : 'warning',
    'exists' => $logsDirExists,
    'writable' => $logsDirWritable,
    'message' => $logsDirWritable 
        ? 'Logs directory exists and is writable' 
        : ($logsDirExists 
            ? 'Logs directory exists but is not writable' 
            : 'Logs directory does not exist (will be created on first use)')
];

// Check 6: Configuration validation (if config exists)
if (file_exists(__DIR__ . '/config.php')) {
    try {
        $config = require __DIR__ . '/config.php';
        
        $configIssues = [];
        
        // Check thekeys credentials
        if (empty($config['thekeys']['username'])) {
            $configIssues[] = 'The Keys username not configured';
        }
        if (empty($config['thekeys']['password'])) {
            $configIssues[] = 'The Keys password not configured';
        }
        
        // Check apartment mappings
        if (empty($config['apartment_locks'])) {
            $configIssues[] = 'No apartment locks configured';
        }
        
        // Check lock accessoires
        if (empty($config['lock_accessoires'])) {
            $configIssues[] = 'No lock accessoires configured';
        }
        
        // Check webhook secret
        if (empty($config['smoobu_secret'])) {
            $configIssues[] = 'Smoobu webhook secret not set (recommended for security)';
        }
        
        $status['checks']['configuration'] = [
            'status' => empty($configIssues) ? 'ok' : 'warning',
            'issues' => $configIssues,
            'apartments_configured' => count($config['apartment_locks'] ?? []),
            'locks_configured' => count($config['lock_accessoires'] ?? []),
            'message' => empty($configIssues) 
                ? 'Configuration is complete' 
                : 'Configuration has issues: ' . implode(', ', $configIssues)
        ];
        
    } catch (Exception $e) {
        $status['checks']['configuration'] = [
            'status' => 'error',
            'message' => 'Error loading config: ' . $e->getMessage()
        ];
    }
}

// Check 7: Last webhook activity (if log exists)
if (file_exists(__DIR__ . '/logs/webhook.log')) {
    $logContent = @file_get_contents(__DIR__ . '/logs/webhook.log');
    if ($logContent !== false) {
        $lines = array_filter(explode("\n", $logContent));
        $lastLine = end($lines);
        
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $lastLine, $match)) {
            $lastActivity = $match[1];
            $lastActivityTime = strtotime($lastActivity);
            $hoursSinceActivity = (time() - $lastActivityTime) / 3600;
            
            $status['checks']['last_activity'] = [
                'status' => 'info',
                'last_activity' => $lastActivity,
                'hours_ago' => round($hoursSinceActivity, 1),
                'message' => "Last activity: {$lastActivity} (" . round($hoursSinceActivity, 1) . " hours ago)"
            ];
        }
        
        // Count total log lines
        $status['checks']['log_file'] = [
            'status' => 'info',
            'total_entries' => count($lines),
            'size_kb' => round(filesize(__DIR__ . '/logs/webhook.log') / 1024, 2),
            'message' => count($lines) . ' log entries, ' . round(filesize(__DIR__ . '/logs/webhook.log') / 1024, 2) . ' KB'
        ];
    }
}

// Overall status determination
$hasError = false;
foreach ($status['checks'] as $check) {
    if ($check['status'] === 'error') {
        $hasError = true;
        break;
    }
}

$status['status'] = $hasError ? 'unhealthy' : 'healthy';

// HTTP status code
http_response_code($hasError ? 503 : 200);

// Output
echo json_encode($status, JSON_PRETTY_PRINT);
