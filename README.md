# The Keys + Smoobu Integration

Automatic smart lock access code management for Smoobu bookings using The Keys API.

## 🎯 Overview

This integration automatically creates, updates, and deletes keypad access codes on The Keys smart locks when bookings are created, modified, or cancelled in Smoobu. Perfect for short-term rental automation.

### Features

- ✅ **Automatic Code Generation**: Creates unique PIN codes for each booking
- ✅ **Lifecycle Management**: Handles booking creation, modification, and cancellation
- ✅ **Multi-Property Support**: Manages multiple apartments/locks
- ✅ **Emergency Lock Swap**: Quick replacement tool for broken locks
- ✅ **Visual Testing Interface**: Easy-to-use web-based tester
- ✅ **Detailed Logging**: Track all operations for debugging
- ✅ **Duplicate Prevention**: Checks for existing codes before creating

## 📋 Requirements

- **PHP 7.4+** with cURL extension
- **The Keys account** with smart locks
- **Smoobu account** with webhook support
- Web server (Apache/Nginx) or PHP built-in server for webhooks

## 🚀 Installation

### 1. Clone the Repository

```bash
git clone https://github.com/mmomasters/thekeys.git
cd thekeys
```

### 2. Configure the Integration

```bash
# Copy the example config
cp config.example.php config.php

# Edit config.php with your credentials and IDs
# (Never commit config.php to version control!)
```

### 3. Find Your IDs

#### The Keys Lock ID:
1. Login to [The Keys App](https://app.the-keys.fr)
2. Go to your locks list
3. Click on a lock
4. Check URL: `/compte/serrure/{LOCK_ID}/view_partage`

#### The Keys Accessoire (Keypad) ID:
1. Open a lock's page
2. View access codes section
3. Create a temporary code
4. Inspect the form/URL for the accessoire ID

#### Smoobu Apartment ID:
1. Login to Smoobu
2. Go to apartments
3. Click on an apartment
4. Check the URL or apartment details

### 4. Update config.php

```php
return [
    'thekeys' => [
        'username' => '+33650868488',  // Your phone number
        'password' => 'your_password',
    ],
    'apartment_locks' => [
        505200 => 3718,   // Smoobu ID => Lock ID
    ],
    'lock_accessoires' => [
        3718 => 4413,     // Lock ID => Keypad ID
    ],
    'smoobu_secret' => 'generate_random_string',
];
```

### 5. Create Logs Directory

```bash
mkdir logs
chmod 755 logs
```

### 6. Test the Integration

```bash
# Start PHP development server
php -S localhost:8000

# Open browser
# http://localhost:8000/test_webhook.php
```

## 🔗 Smoobu Webhook Setup

1. Login to Smoobu
2. Go to Settings → Webhooks
3. Create a new webhook:
   - **URL**: `https://yourdomain.com/smoobu_webhook.php`
   - **Events**: Booking Created, Booking Cancelled, Booking Modified
   - **Secret**: (Use the same value as in config.php)
4. Test the webhook

## 📖 Usage

### Automatic Mode (Production)

Once configured, the webhook will automatically:
- **Create PIN codes** when bookings are created
- **Delete codes** when bookings are cancelled
- **Update dates** when bookings are modified

### Manual Testing

Use the visual testing interface:

```bash
php -S localhost:8000
# Open: http://localhost:8000/test_webhook.php
```

Features:
- Select which studio to test
- Create test bookings
- Cancel bookings
- Modify booking dates
- List all codes on a lock

### Emergency Lock Replacement

If a lock breaks, use the emergency swap tool:

```bash
php emergency_swap.php 1A 7540
```

This will:
1. Copy all codes from the broken lock to the backup lock
2. Update config.php automatically
3. Create a backup of the config file

## 📁 Project Structure

```
thekeys/
├── config.php              # Main configuration (DO NOT COMMIT!)
├── config.example.php      # Configuration template
├── TheKeysAPI.php         # The Keys API wrapper class
├── smoobu_webhook.php     # Webhook endpoint
├── test_webhook.php       # Visual testing interface
├── emergency_swap.php     # Emergency lock replacement tool
├── logs/                  # Log files directory
│   └── webhook.log        # Webhook activity log
├── .gitignore            # Git ignore rules
└── README.md             # This file
```

## 🔐 Security Best Practices

### ✅ DO:
- Use the `.gitignore` file (already configured)
- Set a strong `smoobu_secret` webhook signature
- Keep `config.php` private and never commit it
- Use HTTPS for webhook endpoint
- Regularly rotate credentials
- Monitor logs for suspicious activity

### ❌ DON'T:
- Commit `config.php` to version control
- Share your credentials
- Use the same PIN for all guests
- Expose the webhook endpoint without signature verification

## 🐛 Troubleshooting

### Problem: Webhook returns 401 Unauthorized

**Solution**: Check that your The Keys credentials are correct in `config.php`

### Problem: Codes not being created

**Solutions**:
1. Check `logs/webhook.log` for error messages
2. Verify apartment_locks mapping is correct
3. Verify lock_accessoires mapping is correct
4. Test manually with `test_webhook.php`

### Problem: "No accessoire mapping found"

**Solution**: Add the lock→keypad mapping in `lock_accessoires` section of config.php

### Problem: Duplicate codes

**Solution**: The system checks for duplicates automatically. If you see this, a code already exists for that guest.

### Check Logs

```bash
# View recent webhook activity
tail -f logs/webhook.log

# View all logs
cat logs/webhook.log
```

## 📊 API Methods

### TheKeysAPI Class

```php
$api = new TheKeysAPI($username, $password);
$api->setAccessoireMapping($lockId, $accessoireId);
$api->login();

// Create code
$api->createCode($lockId, [
    'guestName' => 'John Doe',
    'startDate' => '2024-01-15',
    'endDate' => '2024-01-20',
    'code' => '1234'  // Optional, auto-generated if empty
]);

// List all codes
$codes = $api->getAllCodes($lockId);

// Find code by guest name
$code = $api->findCodeByGuestName($lockId, 'John Doe');

// Delete code
$api->deleteCode($codeId);

// Get code details
$details = $api->getCodeDetails($codeId);

// Copy code to another lock
$api->copyCode($sourceCodeId, $targetLockId);
```

### SmoobuWebhook Class

```php
$config = require 'config.php';
$webhook = new SmoobuWebhook($config);

// Process webhook payload
$result = $webhook->process($payload);
```

## 🔄 Webhook Events

The integration handles these Smoobu events:

| Event | Action |
|-------|--------|
| `booking.created` / `booking.new` | Creates new access code |
| `booking.cancelled` / `booking.canceled` | Deletes access code |
| `booking.modified` / `booking.updated` | Deletes old + creates new code |

## 📝 Configuration Reference

```php
return [
    // The Keys login credentials
    'thekeys' => [
        'username' => '',  // Phone number
        'password' => '',
    ],
    
    // Webhook security (recommended)
    'smoobu_secret' => '',
    
    // Map Smoobu apartments to locks
    'apartment_locks' => [
        // Smoobu Apartment ID => Lock ID
    ],
    
    // Map locks to keypads
    'lock_accessoires' => [
        // Lock ID => Keypad/Accessoire ID
    ],
    
    // Default check-in/out times
    'default_times' => [
        'check_in' => '15:00',
        'check_out' => '12:00',
    ],
    
    // Log file location
    'log_file' => __DIR__ . '/logs/webhook.log',
];
```

## 🤝 Contributing

Contributions are welcome! Please:
1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## 📄 License

This project is provided as-is for personal and commercial use.

## 🆘 Support

For issues or questions:
1. Check the troubleshooting section above
2. Review `logs/webhook.log`
3. Test with `test_webhook.php`
4. Open an issue on GitHub

## ⚠️ Important Notes

- **Session cookies** are stored in system temp directory
- **Logs** can contain sensitive information - protect them
- **Test thoroughly** before production use
- **Backup locks** should be configured in advance
- The Keys API has **no official documentation** - this wrapper is based on reverse engineering

## 🎯 Roadmap

Future improvements:
- [ ] Email notifications for errors
- [ ] Database storage for audit trail
- [ ] Retry logic for failed API calls
- [ ] Rate limiting protection
- [ ] Multi-language support
- [ ] Admin dashboard
- [ ] Mobile app integration

---

**Made with ❤️ for vacation rental automation**
