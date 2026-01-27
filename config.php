<?php
/**
 * Configuration for Smoobu to The Keys Integration
 */

return [
    // The Keys credentials
    'thekeys' => [
        'username' => '+33650868488',
        'password' => 'aprilia131',
    ],
    
    // Smoobu webhook secret (optional)
    'smoobu_secret' => '',
    
    // Apartment to Lock mapping
    'apartment_locks' => [
        // Smoobu Apartment ID => The Keys Lock ID
        505200 => 3718,   // Studio 1A
        505203 => 3728,   // Studio 1B
        505206 => 19649,  // Studio 1C
        505209 => 3735,   // Studio 1D
    ],
    
    // Lock to Digicode (Keypad/Accessoire) mapping
    'lock_accessoires' => [
        // Active Production Locks
        3718 => 4413,    // Digicode 1A
        3728 => 4383,    // Digicode 1B
        19649 => 4344,   // Digicode 1C
        3735 => 4375,    // Digicode 1D
    ],
    
    // Check-in/out times
    'default_times' => [
        'check_in' => '15:00',
        'check_out' => '12:00',
    ],
    
    // Logging
    'log_file' => __DIR__ . '/logs/webhook.log',
    
    /*
     * BACKUP LOCKS (Available Spares):
     * Lock ID 7540 - "fixed"
     * Lock ID 3726 - "broken charge"
     * 
     * TO REPLACE A BROKEN LOCK:
     * Use: php emergency_swap.php [studio] [backup_lock_id]
     * Example: php emergency_swap.php 1A 7540
     */
];