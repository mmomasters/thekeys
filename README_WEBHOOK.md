# Smoobu to The Keys Webhook System

Real-time synchronization between Smoobu bookings and The Keys access codes using webhooks.

## 🎯 Features

- ✅ **Real-time sync** - No polling, instant updates
- ✅ **Event-driven** - Only processes what changes
- ✅ **Idempotent** - Prevents duplicate processing
- ✅ **Database logging** - Complete audit trail
- ✅ **Multilingual messages** - EN/DE/PL support
- ✅ **PIN preservation** - Never changes existing codes
- ✅ **Safe deletions** - Only after checkout
- ✅ **Notifications disabled** - No spam to guests

## 📋 Requirements

- PHP 7.4+ with PDO, cURL, JSON extensions
- MySQL 5.7+ database
- Public HTTPS URL for webhook
- Smoobu API key
- The Keys API credentials

## 🚀 Installation

### 1. Database Setup

Database and tables are already created:
- Database: `thekeys`
- Tables: `webhook_logs`, `sync_history`

### 2. Upload Files

Upload these files to your server at `public_html/thekeys/`:

```
TheKeysAPI.php          - The Keys API client
SmoobuWebhook.php       - Webhook handler
webhook.php             - Main webhook endpoint
config.php              - Configuration (create from example)
```

### 3. Configuration

Copy `config.example.php` to `config.php` and fill in your credentials:

```php
return [
    'database' => [
        'host' => 'localhost',
        'database' => 'thekeys',
        'username' => 'your_db_user',
        'password' => 'your_db_password'
    ],
    'thekeys' => [
        'username' => 'your_thekeys_username',
        'password' => 'your_thekeys_password'
    ],
    'smoobu' => [
        'api_key' => 'your_smoobu_api_key'
    ],
    // ... map your apartments and locks
];
```

### 4. Set Permissions

```bash
chmod 755 webhook.php
chmod 644 config.php
chmod 755 logs/
```

### 5. Configure Smoobu Webhook

1. Go to Smoobu → Settings → API & Webhooks
2. Add new webhook:
   - **URL:** `https://mmo-masters.com/thekeys/webhook.php`
   - **Events:** Select:
     - ✅ Reservation Created
     - ✅ Reservation Updated  
     - ✅ Reservation Cancelled
3. Save webhook

## 🔧 How It Works

### Webhook Flow

```
New Booking in Smoobu
    ↓
Smoobu sends webhook to your URL
    ↓
webhook.php receives POST request
    ↓
SmoobuWebhook.php processes event
    ↓
1. Log to database
2. Check idempotency (prevent duplicates)
3. Process based on event type:
   - reservation.new → Create code + Send message
   - reservation.updated → Update code
   - reservation.cancelled → Delete code (after checkout)
4. Return 200 OK to Smoobu
```

### Event Handling

**New Reservation:**
- Generates random 4-digit PIN
- Creates code in The Keys
- Sends multilingual message to guest
- Logs operation to database

**Updated Reservation:**
- Finds existing code
- Updates dates/times
- Preserves existing PIN
- Updates description

**Cancelled Reservation:**
- Finds existing code
- Only deletes if checkout date has passed
- Prevents deletions during active stays

## 📊 Database Tables

### webhook_logs
Tracks every webhook received:
```sql
- id (auto increment)
- event_type (reservation.new, etc.)
- booking_id (Smoobu booking ID)
- payload (full JSON)
- processed (boolean)
- created_at (timestamp)
```

### sync_history
Tracks all sync operations:
```sql
- id (auto increment)
- booking_id (Smoobu booking ID)
- code_id (The Keys code ID)
- operation (create/update/delete)
- success (boolean)
- error_message (if failed)
- created_at (timestamp)
```

## 🐛 Testing

### Test Webhook Manually

```bash
curl -X POST https://mmo-masters.com/thekeys/webhook.php \
  -H "Content-Type: application/json" \
  -d '{
    "id": 12345,
    "guest-name": "Test Guest",
    "arrival": "2026-02-01",
    "departure": "2026-02-03",
    "apartment": {"id": 123456, "name": "Test Apartment"},
    "language": "en"
  }'
```

### Check Logs

View webhook processing:
```bash
tail -f logs/webhook.log
```

View database logs:
```sql
SELECT * FROM webhook_logs ORDER BY created_at DESC LIMIT 10;
SELECT * FROM sync_history ORDER BY created_at DESC LIMIT 10;
```

## 🔒 Security

### Optional Security Features

1. **IP Whitelist** (recommended):
```php
'webhook' => [
    'ip_whitelist' => ['Smoobu.IP.Address.Here']
]
```

2. **Webhook Secret** (if Smoobu supports):
```php
'webhook' => [
    'secret' => 'your_secret_key_here'
]
```

## 📝 Logging

All webhook activity is logged to:
- **File:** `logs/webhook.log`
- **Database:** `webhook_logs` table

Log format:
```
[2026-01-28 09:30:00] [INFO] Received webhook: reservation.new
[2026-01-28 09:30:01] [INFO] Created code 1234 for John Doe
[2026-01-28 09:30:02] [INFO] Sent PIN message to John Doe (en)
```

## 🔄 Migration from Python

If you were using the Python polling system (`smoobu_sync.py`):

1. **Stop scheduled task** - No more need for Task Scheduler
2. **Keep Python files** - Useful for manual operations
3. **Webhook handles real-time** - Events processed instantly
4. **Database tracks everything** - Better audit trail

## 🆘 Troubleshooting

### Webhook not receiving events

1. Check Smoobu webhook configuration
2. Verify URL is publicly accessible: `https://mmo-masters.com/thekeys/webhook.php`
3. Check server error logs
4. Test manually with curl

### Codes not creating

1. Check `logs/webhook.log` for errors
2. Verify The Keys credentials in config.php
3. Check apartment/lock mappings
4. Query `webhook_logs` table for payload details

### Messages not sending

1. Verify Smoobu API key
2. Check if arrival date is in future (messages only sent to future guests)
3. Review logs for HTTP status codes

## 📞 Support

- Check logs first: `logs/webhook.log`
- Review database: `webhook_logs` and `sync_history`
- GitHub: https://github.com/mmomasters/thekeys

## 📄 License

MIT License - see repository for details
