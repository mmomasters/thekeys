<?php
require_once 'TheKeysAPI.php';
require_once 'smoobu_webhook.php';

$config = require 'config.php';

// Your actual studios
$studios = [
    '1A' => 505200,
    '1B' => 505203,
    '1C' => 505206,
    '1D' => 505209,
];

// Test dates
$checkIn = date('Y-m-d', strtotime('+3 days'));
$checkOut = date('Y-m-d', strtotime('+5 days'));
$checkOutUpdated = date('Y-m-d', strtotime('+6 days'));

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Smoobu Webhook Tester</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f5f7fa; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #2c3e50; margin-bottom: 30px; font-size: 28px; }
        h2 { color: #34495e; margin: 30px 0 15px; font-size: 20px; }
        h3 { color: #7f8c8d; margin: 20px 0 10px; font-size: 16px; }
        
        .menu { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .menu-item { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; transition: transform 0.2s; }
        .menu-item:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
        .menu-item a { text-decoration: none; color: inherit; display: block; }
        .menu-item .icon { font-size: 32px; margin-bottom: 10px; }
        .menu-item .title { font-weight: 600; color: #2c3e50; margin-bottom: 5px; }
        .menu-item .desc { font-size: 13px; color: #7f8c8d; }
        
        .studio-select { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .studio-button { display: inline-block; padding: 10px 20px; margin: 5px; background: #3498db; color: white; border-radius: 5px; text-decoration: none; font-weight: 600; }
        .studio-button:hover { background: #2980b9; }
        
        .result { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .success { border-left: 4px solid #27ae60; background: #d4edda; }
        .error { border-left: 4px solid #e74c3c; background: #f8d7da; }
        .warning { border-left: 4px solid #f39c12; background: #fff3cd; }
        
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 13px; line-height: 1.5; border: 1px solid #e9ecef; }
        
        table { width: 100%; border-collapse: collapse; background: white; }
        th { background: #34495e; color: white; padding: 12px; text-align: left; font-weight: 600; }
        td { padding: 10px 12px; border-bottom: 1px solid #ecf0f1; }
        tr:hover { background: #f8f9fa; }
        
        .badge { display: inline-block; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #27ae60; color: white; }
        .badge-info { background: #3498db; color: white; }
        .badge-warning { background: #f39c12; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Smoobu Webhook Tester</h1>
        
        <?php if (!isset($_GET['test'])): ?>
            
            <div class="studio-select">
                <h2>🏠 Select Studio to Test</h2>
                <p style="margin: 10px 0; color: #7f8c8d;">Choose which apartment to test with:</p>
                <?php foreach ($studios as $name => $smoobuId): ?>
                    <a href="?studio=<?php echo $name; ?>" class="studio-button">
                        Studio <?php echo $name; ?> (ID: <?php echo $smoobuId; ?>)
                    </a>
                <?php endforeach; ?>
            </div>
            
            <?php if (isset($_GET['studio']) && isset($studios[$_GET['studio']])): ?>
                <?php 
                $selectedStudio = $_GET['studio'];
                $selectedId = $studios[$selectedStudio];
                ?>
                
                <div class="result">
                    <h2>Testing Studio <?php echo $selectedStudio; ?> (Smoobu ID: <?php echo $selectedId; ?>)</h2>
                </div>
                
                <div class="menu">
                    <div class="menu-item">
                        <a href="?test=list&studio=<?php echo $selectedStudio; ?>">
                            <div class="icon">📋</div>
                            <div class="title">List All Codes</div>
                            <div class="desc">View codes on this lock</div>
                        </a>
                    </div>
                    
                    <div class="menu-item">
                        <a href="?test=create&studio=<?php echo $selectedStudio; ?>">
                            <div class="icon">➕</div>
                            <div class="title">Create Code</div>
                            <div class="desc">Test new booking</div>
                        </a>
                    </div>
                    
                    <div class="menu-item">
                        <a href="?test=cancel&studio=<?php echo $selectedStudio; ?>">
                            <div class="icon">❌</div>
                            <div class="title">Cancel Booking</div>
                            <div class="desc">Test cancellation</div>
                        </a>
                    </div>
                    
                    <div class="menu-item">
                        <a href="?test=modify&studio=<?php echo $selectedStudio; ?>">
                            <div class="icon">✏️</div>
                            <div class="title">Modify Dates</div>
                            <div class="desc">Test date change</div>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="result">
                <h3>📖 Instructions</h3>
                <ol style="margin-left: 20px; line-height: 1.8;">
                    <li>Select a studio above</li>
                    <li>Run tests for that specific apartment</li>
                    <li>Check your The Keys app to verify codes were created</li>
                </ol>
            </div>
            
        <?php else: ?>
            
            <?php
            $selectedStudio = $_GET['studio'] ?? '1A';
            $selectedId = $studios[$selectedStudio] ?? $studios['1A'];
            $lockId = $config['apartment_locks'][$selectedId] ?? null;
            
            ob_start();
            
            try {
                $webhook = new SmoobuWebhook($config);
                
                switch ($_GET['test']) {
                    
                    case 'list':
                        echo "<div class='result'>";
                        echo "<h2>📋 All Codes on Studio $selectedStudio (Lock $lockId)</h2>";
                        
                        $api = new TheKeysAPI($config['thekeys']['username'], $config['thekeys']['password']);
                        foreach ($config['lock_accessoires'] as $lid => $accessoireId) {
                            $api->setAccessoireMapping($lid, $accessoireId);
                        }
                        $api->login();
                        
                        if ($lockId) {
                            $codes = $api->getAllCodes($lockId);
                            
                            if (empty($codes)) {
                                echo "<p style='padding: 20px; text-align: center; color: #7f8c8d;'>No codes found</p>";
                            } else {
                                echo "<table>";
                                echo "<tr><th>Guest Name</th><th>Dates</th><th>Code ID</th></tr>";
                                foreach ($codes as $code) {
                                    echo "<tr>";
                                    echo "<td><strong>{$code['name']}</strong></td>";
                                    echo "<td>";
                                    if ($code['start_date'] && $code['end_date']) {
                                        echo "{$code['start_date']} → {$code['end_date']}";
                                    } else {
                                        echo "-";
                                    }
                                    echo "</td>";
                                    echo "<td><span class='badge badge-info'>{$code['id']}</span></td>";
                                    echo "</tr>";
                                }
                                echo "</table>";
                            }
                        } else {
                            echo "<p class='error'>No lock mapped for this studio</p>";
                        }
                        echo "</div>";
                        break;
                        
                    case 'create':
                        echo "<div class='result'>";
                        echo "<h2>➕ Create New Code - Studio $selectedStudio</h2>";
                        
                        $payload = [
                            'event' => 'booking.created',
                            'data' => [
                                'apartment' => ['id' => $selectedId],
                                'firstName' => 'Test',
                                'lastName' => 'Guest',
                                'arrival' => $checkIn,
                                'departure' => $checkOut,
                            ]
                        ];
                        
                        echo "<h3>Request Payload:</h3>";
                        echo "<pre>" . json_encode($payload, JSON_PRETTY_PRINT) . "</pre>";
                        
                        $result = $webhook->process($payload);
                        
                        echo "<div class='result success'>";
                        echo "<h3>✅ Success!</h3>";
                        echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
                        if (isset($result['pin'])) {
                            echo "<p style='margin-top: 15px; font-size: 18px;'><strong>PIN Code:</strong> <span style='background: #27ae60; color: white; padding: 5px 15px; border-radius: 5px; font-family: monospace;'>{$result['pin']}</span></p>";
                        }
                        echo "</div>";
                        echo "</div>";
                        break;
                        
                    case 'cancel':
                        echo "<div class='result'>";
                        echo "<h2>❌ Cancel Booking - Studio $selectedStudio</h2>";
                        
                        $payload = [
                            'event' => 'booking.cancelled',
                            'data' => [
                                'apartment' => ['id' => $selectedId],
                                'firstName' => 'Test',
                                'lastName' => 'Guest',
                            ]
                        ];
                        
                        echo "<h3>Request Payload:</h3>";
                        echo "<pre>" . json_encode($payload, JSON_PRETTY_PRINT) . "</pre>";
                        
                        $result = $webhook->process($payload);
                        
                        if (isset($result['success']) && $result['success']) {
                            echo "<div class='result success'>";
                            echo "<h3>✅ Code Deleted!</h3>";
                        } elseif (isset($result['not_found'])) {
                            echo "<div class='result warning'>";
                            echo "<h3>⚠️ No Code Found</h3>";
                        } else {
                            echo "<div class='result'>";
                        }
                        echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
                        echo "</div>";
                        echo "</div>";
                        break;
                        
                    case 'modify':
                        echo "<div class='result'>";
                        echo "<h2>✏️ Modify Booking Dates - Studio $selectedStudio</h2>";
                        
                        $payload = [
                            'event' => 'booking.modified',
                            'data' => [
                                'apartment' => ['id' => $selectedId],
                                'firstName' => 'Test',
                                'lastName' => 'Guest',
                                'arrival' => $checkIn,
                                'departure' => $checkOutUpdated,
                            ]
                        ];
                        
                        echo "<h3>Request Payload:</h3>";
                        echo "<pre>" . json_encode($payload, JSON_PRETTY_PRINT) . "</pre>";
                        
                        $result = $webhook->process($payload);
                        
                        echo "<div class='result success'>";
                        echo "<h3>✅ Booking Updated!</h3>";
                        echo "<p>Old code deleted, new code created with updated dates.</p>";
                        echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
                        echo "</div>";
                        echo "</div>";
                        break;
                }
                
            } catch (Exception $e) {
                echo "<div class='result error'>";
                echo "<h3>❌ Error</h3>";
                echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
                echo "</div>";
            }
            
            $output = ob_get_clean();
            echo $output;
            ?>
            
            <?php if (file_exists($config['log_file'])): ?>
                <div class="result">
                    <h2>📋 Log Output</h2>
                    <pre><?php echo htmlspecialchars(file_get_contents($config['log_file'])); ?></pre>
                </div>
            <?php endif; ?>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="?studio=<?php echo $selectedStudio; ?>" style="display: inline-block; background: #3498db; color: white; padding: 12px 24px; border-radius: 5px; text-decoration: none; font-weight: 600;">← Back to Tests</a>
                <a href="?" style="display: inline-block; background: #6c757d; color: white; padding: 12px 24px; border-radius: 5px; text-decoration: none; font-weight: 600; margin-left: 10px;">🏠 Studio Selection</a>
            </div>
            
        <?php endif; ?>
        
    </div>
</body>
</html>