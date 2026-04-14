# The Keys Cloud - Smoobu Integration Webhook System

Real-time PHP Webhook System - Automatic keypad code management via Smoobu webhooks! 
Provides integration between Smoobu (rental booking platform) and The Keys Cloud (smart lock management). When guests book via Smoobu, the system automatically generates PIN codes for keypad locks, sends SMS/email notifications, and manages code lifecycle (create on booking, update on change, delete on cancellation).

## 🎉 Features

- ✅ **Real-time sync** - No polling, instant updates via webhooks
- ✅ **Event-driven** - Only processes what changes (Reservation Created, Updated, Cancelled)
- ✅ **Idempotent** - Prevents duplicate processing (5-minute window)
- ✅ **Smart Matching** - Matches manual codes by guest name (prefix-aware)
- ✅ **SMS notifications** - Guest receives PIN via SMS + admin notifications (SerwerSMS or BudgetSMS)
- ✅ **Email messages** - Multilingual messages sent to guests (EN/DE/PL/RU/UA)
- ✅ **ElevenLabs AI Agent Integration** - Forwards conversation summaries to Pushover
- ✅ **Manual Sync Tool** - Recovery tool with dry-run and smart linking
- ✅ **Database logging** - Complete audit trail of all operations
- ✅ **PIN preservation** - Never changes existing codes
- ✅ **Apartment changes** - Handles booking moves between apartments
- ✅ **Auto cleanup** - Cancelled bookings removed immediately after checkout

## 📋 Requirements

- PHP 7.4+ with PDO, cURL, JSON extensions
- MySQL 5.7+ database
- Public HTTPS URL for webhook
- Smoobu API key
- The Keys API credentials
- SMS Provider credentials (SerwerSMS or BudgetSMS)

## 🚀 Quick Start & Installation

### 1. Database Setup

Database and tables:
- Database: `thekeys`

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

### 2. Configuration

Copy `config.example.php` to `config.php` and fill in your credentials:

```bash
cp config.example.php config.php
```

Edit `config.php` with your real credentials:

```php
return [
    'database' => [
        'host' => 'localhost',
        'database' => 'thekeys',
        'username' => 'your_db_username',
        'password' => 'your_db_password',
        'charset' => 'utf8mb4'
    ],
    'thekeys' => [
        'username' => 'your_thekeys_username',
        'password' => 'your_thekeys_password'
    ],
    'smoobu' => [
        'api_key' => 'your_smoobu_api_key'
    ],
    // SMS Provider Selection (default: 'serwersms' or 'budgetsms')
    'serwersms' => [
        'api_token' => 'your_serwersms_api_token',
    ],
    'apartment_locks' => [
        '123456' => 3733,  // Smoobu Apartment ID => Lock ID
    ],
    'lock_accessoires' => [
        3733 => 'OXe37UIa',  // Lock ID => STRING accessoire ID
    ],
    'digicode_prefixes' => [
        3733 => '28',  // Lock ID => 2-digit PIN prefix
    ],
    'webhook' => [
        'secret' => '',         // Optional: Smoobu webhook secret
        'ip_whitelist' => []    // Optional: ['1.2.3.4']
    ]
];
```

### 3. Upload Files & Set Permissions

Upload these files to your server at `public_html/thekeys/`:
- `webhook.php` - Main webhook endpoint
- `SmoobuWebhook.php` - Event handler
- `TheKeysAPI.php` - API client
- `config.php` - Your configuration
- `pushover.php` - ElevenLabs (HMAC) to Pushover webhook
- `manual_sync.php`
- `lock_migration.php`
- `pipe.php`
- `languages/`

Set permissions:
```bash
chmod 755 webhook.php
chmod 644 config.php
chmod 755 logs/
```

### 4. Configure Smoobu Webhook

1. Login to Smoobu → Settings → API & Webhooks
2. Add new webhook:
   - URL: `https://your-domain.com/thekeys/webhook.php`
   - Events: Select Reservation Created, Updated, Cancelled
3. Save webhook

## 🏛 Architecture & Project Structure

No build system. Plain PHP project deployed by uploading files to a web server.

### Request Flow

```
Smoobu POST → webhook.php → SmoobuWebhook → TheKeysAPI (create/update/delete PIN)
                                          → SMS provider (SerwerSMS or BudgetSMS)
                                          → Smoobu API (message to guest)
                                          → MySQL (audit log)

ElevenLabs POST → pushover.php → Pushover API (conversation summary)
```

### Core Files

- **`webhook.php`** — HTTP entry point for Smoobu. Validates JSON payload, optionally checks IP whitelist and HMAC signature, delegates to `SmoobuWebhook`.
- **`pushover.php`** — Webhook for ElevenLabs. Validates HMAC, extracts caller ID and summary, then sends Pushover notification with agent name and Chrome deep link.
- **`SmoobuWebhook.php`** — Main business logic for bookings. Routes events (`reservation.new`, `reservation.updated`, `reservation.cancelled`), checks idempotency (5-minute window), manages PIN lifecycle, dispatches notifications.
- **`TheKeysAPI.php`** — API client for The Keys Cloud. JWT auth, CRUD on lock codes via form-encoded POST requests. PIN stored in code description as `Smoobu#{bookingId}`.
- **`config.php`** — Runtime config (gitignored; copy from `config.example.php`).
- **`languages/{en,de,pl,ru,ua}.php`** — Localized message templates.

### Admin Tools

- **`manual_sync.php`** — Web UI to recover missed bookings from Smoobu API. Supports dry-run mode, name-based matching for existing codes (with prefix handling), and links `Smoobu#ID` to manual codes. Sends guest notifications for new codes and date updates.
- **`lock_migration.php`** — Lock hardware replacement tool; migrates codes and notifies guests.
- **`pipe.php`** — Email logging endpoint for IFTTT triggers.

## 🔧 How It Works

### Event Handling Flow

```
New Booking in Smoobu → Webhook to your URL → webhook.php → SmoobuWebhook.php
```

1. Log to database
2. Check idempotency (prevent duplicates)
3. Process based on event type:
   - **`reservation.new`**: Generates random 4-digit PIN (prepends prefix), creates code in The Keys, sends multilingual message and SMS to guest, logs operation.
   - **`reservation.updated`**: Finds existing code (by `Smoobu#ID` or Guest Name fallback), updates dates/times if changed. Preserves existing PIN. Dispatches notifications for date changes or new creations.
   - **`reservation.cancelled`**: Finds existing code and deletes only if checkout date has passed. Prevents deletions during active stays.

### SMS Notifications
Automatic SMS sent via SerwerSMS or BudgetSMS to the guest's phone with PIN code and check-in/out details.
SerwerSMS uses Bearer token auth + `utf=true` for Cyrillic. BudgetSMS uses `username`/`userid`/`handle` query params and auto-detects Unicode.

### Email Messages
Multilingual email sent to guest with building/lobby/apartment door codes, check-in/out times, parking info, and contact phone.

## 🤖 ElevenLabs AI Agent Integration

`pushover.php` is a webhook endpoint to receive conversation summaries from ElevenLabs AI Agents and forward them as Pushover notifications.

### Setup:
1. **Configure ElevenLabs:** Set the **Post-call Webhook URL** to `https://your-domain.com/thekeys/pushover.php`. Copy the **Webhook Secret**.
2. **Configure `config.php`:**
   ```php
   'elevenlabs' => [
       'webhook_secret' => 'your_elevenlabs_secret'
   ],
   'pushover' => [
       'user_key' => 'your_pushover_user_key',
       'api_token' => 'your_pushover_app_token'
   ]
   ```

## ⚙️ Configuration Details

### Key Concepts

- **`apartment_locks`** — Maps Smoobu apartment ID → The Keys lock ID.
- **`lock_accessoires`** — Maps lock ID → accessoire string ID (e.g., `"OXe37UIa"`). ⚠️ **IMPORTANT:** You **MUST** use the string ID from the API response field `accessoire.id_accessoire`, not a numeric ID.
- **`digicode_prefixes`** — Maps lock ID → 2-digit PIN prefix. The full PIN is prefix + 4-digit random code.

### Finding Your IDs
- **Lock ID:** URL shows `/compte/serrure/{LOCK_ID}/view_partage` on app.the-keys.fr.
- **Accessoire STRING ID:** Use TheKeysAPI to list codes, check `accessoire.id_accessoire` field.

## 🌐 External APIs Used

| API | Auth | Format |
|-----|------|--------|
| The Keys Cloud | JWT (login → token) | Form-encoded POST |
| Smoobu | `Api-Key` header | JSON |
| ElevenLabs | HMAC Signature | JSON |
| SerwerSMS | Bearer token | Form-encoded POST with `utf=true` |
| BudgetSMS | `username`+`userid`+`handle` params | GET query string |
| Pushover | `token` + `user` keys | Form-encoded POST |

## 🛠 Development Commands & Testing

**Validate PHP syntax:**
```bash
php -l webhook.php
php -l SmoobuWebhook.php
php -l TheKeysAPI.php
```

**Test webhook locally (simulate Smoobu event):**
```bash
curl -X POST http://localhost/webhook.php \
  -H "Content-Type: application/json" \
  -d '{"action":"reservation.new","reservation":{"id":12345, "guest-name": "Test Guest", "arrival": "2026-02-01", "departure": "2026-02-03", "apartment": {"id": 123456}, "language": "en"}}'
```

**Tail logs:**
```bash
tail -f logs/webhook.log
```

## 📊 Database Monitoring

```sql
-- Check Recent Webhooks
SELECT * FROM webhook_logs ORDER BY created_at DESC LIMIT 10;

-- Check Sync Operations
SELECT * FROM sync_history ORDER BY created_at DESC LIMIT 10;

-- Count Operations by Type
SELECT operation, COUNT(*) as count FROM sync_history GROUP BY operation;
```

## 🔒 Security & Logging

- `config.php` is gitignored (contains credentials).
- Optional **IP Whitelist** (`webhook.ip_whitelist`) and **Webhook Secret** (`webhook.secret`).
- All webhook activity is logged to `logs/webhook.log` and the `webhook_logs` database table.

## 🆘 Troubleshooting

**Webhook Not Working / Receiving Events:**
1. Check Smoobu webhook configuration and verify URL is publicly accessible.
2. Check `logs/webhook.log` and `webhook_logs` table.
3. Test manually with curl.

**Codes Not Creating:**
1. Check The Keys credentials in `config.php`.
2. Verify apartment/lock mappings and ensure accessoire IDs are STRINGS.
3. Check `sync_history` table for errors.

**Messages Not Sending:**
1. Verify Smoobu / SerwerSMS API tokens.
2. Check if arrival date is in future (messages only sent to future guests).
3. Check logs for HTTP status codes.

## 📄 License & Support

MIT License - see repository for details.
Check logs and database for detailed information: `logs/webhook.log`, `webhook_logs`, and `sync_history` tables.

---
*Note for AI Assistants (Claude, Gemini, etc.): This repository contains webhook integrations. Follow the architectural constraints outlined above. Do not use a build system.*