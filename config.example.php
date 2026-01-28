<?php
/**
 * Configuration Example for Smoobu to The Keys Webhook
 * Copy this file to config.php and fill in your credentials
 */

return [
    // Database configuration
    'database' => [
        'host' => 'localhost',
        'database' => 'thekeys',
        'username' => 'your_db_username',
        'password' => 'your_db_password',
        'charset' => 'utf8mb4'
    ],
    
    // The Keys API credentials
    'thekeys' => [
        'username' => 'your_thekeys_username',
        'password' => 'your_thekeys_password'
    ],
    
    // Smoobu API credentials
    'smoobu' => [
        'api_key' => 'your_smoobu_api_key'
    ],
    
    // SMSFactor API configuration (optional - for SMS notifications)
    'smsfactor' => [
        'api_token' => 'your_smsfactor_api_token',
        'recipients' => [
            '+48123456789',  // Phone number(s) to receive SMS notifications
            // '+33987654321',  // Add more recipients as needed
        ]
    ],
    
    // Mapping: Smoobu Apartment ID -> The Keys Lock ID
    'apartment_locks' => [
        '123456' => 3733,  // Replace with your apartment ID -> Lock ID
        '123457' => 3723
    ],
    
    // Mapping: The Keys Lock ID -> Accessoire ID (STRING)
    'lock_accessoires' => [
        3733 => 'OXe37UIa',  // Replace with your Lock ID -> Accessoire ID
        3723 => 'SLORUV6s'
    ],
    
    // PIN code prefixes for each lock (2 digits)
    'digicode_prefixes' => [
        3733 => '28',  // Replace with your prefixes
        3723 => '18'
    ],
    
    // Default check-in/check-out times
    'default_times' => [
        'check_in_hour' => '15',
        'check_in_minute' => '0',
        'check_out_hour' => '12',
        'check_out_minute' => '0'
    ],
    
    // PIN code settings
    'code_settings' => [
        'length' => 4  // 4-digit PIN codes
    ],
    
    // Webhook security (optional)
    'webhook' => [
        'secret' => '',  // Optional: Smoobu webhook secret for signature validation
        'ip_whitelist' => []  // Optional: ['1.2.3.4', '5.6.7.8']
    ],
    
    // Logging
    'logging' => [
        'enabled' => true,
        'file' => __DIR__ . '/logs/webhook.log'
    ]
];
