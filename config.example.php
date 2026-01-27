<?php
/**
 * Configuration for Smoobu to The Keys Integration
 * 
 * SETUP INSTRUCTIONS:
 * 1. Copy this file to config.php
 * 2. Fill in your actual credentials and IDs
 * 3. Never commit config.php to version control
 */

return [
    // The Keys credentials
    'thekeys' => [
        'username' => '',  // Your The Keys phone number (e.g., +33650868488)
        'password' => '',  // Your The Keys password
    ],
    
    // Smoobu webhook secret (optional but recommended for security)
    // Generate a random string and configure it in Smoobu webhook settings
    'smoobu_secret' => '',
    
    // Apartment to Lock mapping
    // Map your Smoobu Apartment IDs to The Keys Lock IDs
    'apartment_locks' => [
        // Smoobu Apartment ID => The Keys Lock ID
        // 505200 => 3718,   // Studio 1A (example)
        // 505203 => 3728,   // Studio 1B (example)
    ],
    
    // Lock to Digicode (Keypad/Accessoire) mapping
    // Each lock needs to be mapped to its keypad accessoire ID
    'lock_accessoires' => [
        // The Keys Lock ID => Digicode/Keypad ID
        // 3718 => 4413,    // Digicode 1A (example)
        // 3728 => 4383,    // Digicode 1B (example)
    ],
    
    // Check-in/out times
    'default_times' => [
        'check_in' => '15:00',   // Default check-in time
        'check_out' => '12:00',  // Default check-out time
    ],
    
    // Logging
    'log_file' => __DIR__ . '/logs/webhook.log',
    
    /*
     * FINDING YOUR IDs:
     * 
     * The Keys Lock ID:
     * - Login to https://app.the-keys.fr
     * - Go to your locks list
     * - Click on a lock
     * - Check the URL: /compte/serrure/{LOCK_ID}/view_partage
     * 
     * The Keys Accessoire (Keypad) ID:
     * - Open a lock's page
     * - Go to "Partage" or access codes section
     * - Create a temporary code
     * - Check the form or URL for accessoire ID
     * - Or use the getAllCodes() API method to inspect
     * 
     * Smoobu Apartment ID:
     * - Login to Smoobu
     * - Go to your apartments
     * - Click on an apartment
     * - Check the URL or apartment details
     * 
     * BACKUP LOCKS (Available Spares):
     * Keep track of spare/backup locks here for emergency swaps
     * Example:
     * Lock ID 7540 - "fixed"
     * Lock ID 3726 - "broken charge"
     * 
     * TO REPLACE A BROKEN LOCK:
     * Use: php emergency_swap.php [studio] [backup_lock_id]
     * Example: php emergency_swap.php 1A 7540
     */
];
