# The Keys Cloud - Smoobu Integration

**Real-time PHP Webhook System** - Automatic keypad code management via Smoobu webhooks!

## 🎉 Features

- ✅ **Real-time sync** - Webhooks trigger instant code creation/update/deletion
- ✅ **SMS notifications** - Guest receives PIN via SMS + admin notifications
- ✅ **Email messages** - Multilingual messages sent to guests (EN/DE/PL)
- ✅ **Database logging** - Complete audit trail of all operations
- ✅ **Idempotency** - Prevents duplicate processing
- ✅ **Apartment changes** - Handles booking moves between apartments
- ✅ **Auto cleanup** - Cancelled bookings removed immediately

## Quick Start

### 1. Database Setup

Create MySQL database and tables:

```sql
CREATE DATABASE thekeys;

CREATE TABLE webhook_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50),
    booking_id INT,
    payload JSON,
    processed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sync_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT,
    code_id INT,
    operation VARCHAR(20),
    success BOOLEAN,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 2. Configure

Copy the example config and fill in your details:

```bash
cp config.example.php config.php
```

Edit `config.php` with your credentials:

```php
return [
    'database' => [
        'host' => 'localhost',
        'database' => 'thekeys',
        'username' => 'your_db_user',
        'password' => 'your_db_password'
    ],
    'thekeys' => [
        'username' => '+33650868488',
        'password' => 'your_password'
    ],
    'smoobu' => [
        'api_key' => 'your_smoobu_api_key'
    ],
    'smsfactor' => [
        'api_token' => 'your_sms_token',
        'recipients' => ['+48503165434']
    ],
    'apartment_locks' => [
        '505200' => 3723   // Smoobu Apartment ID => Lock ID
    ],
    'lock_accessoires' => [
        3723 => 'OXe37UIa'   // Lock ID => STRING accessoire ID
    ]
];
```

### 3. Upload Files

Upload to your web server:
- `webhook.php` - Main webhook endpoint
- `SmoobuWebhook.php` - Event handler
- `TheKeysAPI.php` - API client
- `config.php` - Your configuration

### 4. Configure Smoobu Webhook

1. Login to Smoobu
2. Go to Settings → API & Webhooks
3. Add new webhook:
   - URL: `https://your-domain.com/thekeys/webhook.php`
   - Events: Reservation Created, Updated, Cancelled
4. Save

## How It Works

### Webhook Flow

```
Smoobu Event → webhook.php
    ↓
1. Validate request
2. Log to database
3. Check idempotency
4. Process event:
   - New → Create code + Send SMS + Send email
   - Update → Update code + Send SMS
   - Cancel → Delete code + Send SMS
5. Return 200 OK
```

### SMS Notifications

Automatic SMS sent via SMSFactor to:
- **Guest's phone** (from booking) - Receives PIN code
- **Admin phone(s)** (from config) - Receives all notifications

**Example SMS:**
```
🔑 NEW BOOKING #125712
John Doe
Studio 1A
2026-01-29 → 2026-01-30
PIN: 184717
```

### Email Messages

Multilingual email sent to guest with:
- Building entrance code
- Lobby door code
- Apartment door code (with PIN)
- Check-in/check-out times
- Parking information
- Contact phone

Languages supported: English, German, Polish

## API Endpoints Used

### The Keys Cloud API

**Authentication:**
```
POST /api/login_check
```

**List Codes:**
```
GET /fr/api/v2/partage/all/serrure/{lock_id}?_format=json
```

**Create Code:**
```
POST /fr/api/v2/partage/create/{lock_id}/accessoire/{id_accessoire}
```

**Update Code:**
```
POST /fr/api/v2/partage/accessoire/update/{code_id}
```

**Delete Code:**
```
POST /fr/api/v2/partage/accessoire/delete/{code_id}
```

### Smoobu API

**Send Message to Guest:**
```
POST /api/reservations/{booking_id}/messages/send-message-to-guest
```

### SMSFactor API

**Send SMS:**
```
POST /send
Authorization: Bearer {token}
```

## Critical: STRING Accessoire IDs

⚠️ **IMPORTANT:** You **MUST** use STRING `id_accessoire` (NOT numeric `id`)!

**Correct:**
- `"OXe37UIa"` ✅
- `"f4H7DpX0"` ✅
- `"FBptKZHE"` ✅

**Wrong:**
- `4413` ❌
- `4383` ❌

Find STRING IDs by calling the LIST endpoint.

## Configuration Details

### Finding Your IDs

**Lock ID:**
1. Login to https://app.the-keys.fr
2. Go to locks list
3. Click a lock
4. URL shows: `/compte/serrure/{LOCK_ID}/view_partage`

**Accessoire STRING ID:**
1. Use TheKeysAPI to list codes
2. Check `accessoire.id_accessoire` field
3. Use that STRING value

**Smoobu Apartment ID:**
1. Login to Smoobu
2. Go to apartments
3. Click apartment
4. Check URL or details

**Smoobu API Key:**
1. Smoobu Settings → API
2. Generate/copy key

**SMSFactor API Token:**
1. Login to SMSFactor
2. Go to API section
3. Generate/copy token

## Database Monitoring

### Check Recent Webhooks
```sql
SELECT * FROM webhook_logs 
ORDER BY created_at DESC 
LIMIT 10;
```

### Check Sync Operations
```sql
SELECT * FROM sync_history 
ORDER BY created_at DESC 
LIMIT 10;
```

### Count Operations by Type
```sql
SELECT operation, COUNT(*) as count 
FROM sync_history 
GROUP BY operation;
```

## Project Structure

```
thekeys/
├── webhook.php            # Main webhook endpoint
├── SmoobuWebhook.php     # Event handler
├── TheKeysAPI.php        # The Keys API client
├── config.php            # Configuration (gitignored)
├── config.example.php    # Configuration template
├── README.md            # This file
├── README_WEBHOOK.md    # Webhook documentation
├── SECURITY.md          # Security guidelines
├── .gitignore           # Git ignore rules
└── logs/
    └── webhook.log      # Webhook logs
```

## Security

- `config.php` is gitignored (contains credentials)
- Database logs all webhook requests (audit trail)
- Idempotency prevents duplicate processing
- Optional IP whitelist and webhook secret validation

See [SECURITY.md](SECURITY.md) for details.

## Troubleshooting

### Webhook Not Working

1. Check `logs/webhook.log`
2. Query `webhook_logs` table
3. Verify URL in Smoobu is correct
4. Test with manual POST request

### SMS Not Sending

1. Verify SMSFactor token in config
2. Check logs for HTTP status codes
3. Verify phone numbers are in international format

### Codes Not Creating

1. Check The Keys credentials
2. Verify apartment/lock mappings
3. Ensure accessoire IDs are STRINGS
4. Check `sync_history` table for errors

## License

MIT

## Support

Check logs and database for detailed information:
- File: `logs/webhook.log`
- Database: `webhook_logs` and `sync_history` tables

---

**Real-time webhook system with SMS notifications! 🎉**
