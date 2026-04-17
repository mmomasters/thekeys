# The Keys Cloud - Smoobu Integration Webhook System

Automatic keypad code management via Smoobu webhooks. Integrates Smoobu (rental booking platform) with The Keys Cloud (smart lock management). When guests book via Smoobu, the system automatically generates PIN codes for keypad locks, sends SMS/email notifications, and manages code lifecycle (create on booking, update on change, delete on cancellation).

The webhook endpoints run on **Cloudflare Workers** (TypeScript, in `workers/`). Admin tools remain as PHP on a VPS.

## 🎉 Features

- ✅ **Real-time sync** - No polling, instant updates via webhooks
- ✅ **Event-driven** - Only processes what changes (Reservation Created, Updated, Cancelled)
- ✅ **SMS notifications** - Guest receives PIN via SMS (SerwerSMS or BudgetSMS)
- ✅ **Email messages** - Multilingual messages sent to guests (EN/DE/PL/RU/UA)
- ✅ **ElevenLabs AI Agent Integration** - Forwards conversation summaries to Pushover
- ✅ **Structured logging** - JSON logs with 92-day retention via Cloudflare Workers Logs
- ✅ **Manual Sync Tool** - Recovery tool with dry-run and smart linking (VPS)
- ✅ **PIN preservation** - Never changes existing codes
- ✅ **Apartment changes** - Handles booking moves between apartments
- ✅ **Auto cleanup** - Cancelled bookings removed immediately

## 📋 Requirements

**Cloudflare Workers (webhooks):**
- Cloudflare account with Workers enabled
- Node.js 18+ and npm (for local development)
- Wrangler CLI

**VPS (admin tools only):**
- PHP 7.4+ with PDO, cURL, JSON extensions
- MySQL 5.7+ database

**API credentials (both):**
- Smoobu API key
- The Keys API credentials
- SMS Provider credentials (SerwerSMS or BudgetSMS)
- ElevenLabs webhook secret (for Pushover integration)
- Pushover API token and user key

## 🚀 Quick Start & Installation

### 1. Cloudflare Workers Setup (Webhooks)

```bash
cd workers
npm install
```

**Configure `workers/wrangler.toml`** with your apartment/lock mappings:

```toml
[vars]
APARTMENT_LOCKS = '{"123456":3733,"123457":3723}'
LOCK_ACCESSOIRES = '{"3733":"OXe37UIa","3723":"SLORUV6s"}'
DIGICODE_PREFIXES = '{"3733":"28","3723":"18"}'
DEFAULT_TIMES = '{"check_in_hour":"15","check_in_minute":"0","check_out_hour":"12","check_out_minute":"0"}'
SMS_PROVIDER = "serwersms"
PIN_LENGTH = "4"
```

**Add secrets** in the Cloudflare dashboard (Workers & Pages > thekeys > Settings > Variables and Secrets):

| Secret | Description |
|--------|-------------|
| `THEKEYS_USERNAME` | The Keys Cloud login |
| `THEKEYS_PASSWORD` | The Keys Cloud password |
| `SMOOBU_API_KEY` | Smoobu API key |
| `ELEVENLABS_WEBHOOK_SECRET` | ElevenLabs webhook HMAC secret |
| `PUSHOVER_USER_KEY` | Pushover user key |
| `PUSHOVER_API_TOKEN` | Pushover app token |
| `SERWERSMS_API_TOKEN` | SerwerSMS Bearer token |
| `BUDGETSMS_USERNAME` | BudgetSMS username (if using BudgetSMS) |
| `BUDGETSMS_USERID` | BudgetSMS user ID (if using BudgetSMS) |
| `BUDGETSMS_HANDLE` | BudgetSMS handle (if using BudgetSMS) |
| `WEBHOOK_SECRET` | Optional: Smoobu HMAC secret |

**Deploy** by connecting the GitHub repo to Cloudflare. Set **Root directory** to `workers/` in the build configuration.

**Configure Smoobu webhook:**
1. Login to Smoobu → Settings → API & Webhooks
2. Add webhook URL: `https://thekeys.<your-subdomain>.workers.dev/webhook`
3. Events: Reservation Created, Updated, Cancelled

**Configure ElevenLabs webhook:**
1. Set Post-call Webhook URL: `https://thekeys.<your-subdomain>.workers.dev/pushover`

### 2. VPS Setup (Admin Tools Only)

The admin tools still need PHP and MySQL on a VPS.

**Database:**
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

**Configuration:** Copy `config.example.php` to `config.php` and fill in your credentials.

**Upload** to your server: `manual_sync.php`, `lock_migration.php`, `pipe.php`, `TheKeysAPI.php`, `SmoobuWebhook.php`, `config.php`, `languages/`.

## 🏛 Architecture & Project Structure

### Request Flow

```
Smoobu POST → Workers /webhook → smoobu.ts → thekeys.ts (create/update/delete PIN)
                                            → sms.ts (SerwerSMS or BudgetSMS)
                                            → Smoobu API (message to guest)

ElevenLabs POST → Workers /pushover → pushover.ts → Pushover API (notification)
```

### Cloudflare Workers (`workers/`)

```
workers/
  src/
    index.ts              # Router: /webhook and /pushover
    smoobu.ts             # Smoobu webhook handler (booking lifecycle)
    thekeys.ts            # The Keys Cloud API client (JWT auth, CRUD)
    pushover.ts           # ElevenLabs → Pushover forwarder
    sms.ts                # SerwerSMS + BudgetSMS dispatch
    types.ts              # TypeScript type definitions
    languages/            # Localized message templates (en/de/pl/ru/ua)
  test/                   # Vitest test suite
  wrangler.toml           # Cloudflare Workers config
```

- **`index.ts`** — Routes `POST /webhook` to the Smoobu handler and `POST /pushover` to the Pushover handler. Returns 200 on errors to prevent webhook retries.
- **`smoobu.ts`** — Main business logic. Routes events (`reservation.new`, `reservation.updated`, `reservation.cancelled`), manages PIN lifecycle, dispatches SMS and guest message notifications.
- **`thekeys.ts`** — API client for The Keys Cloud. JWT auth, CRUD on lock codes via form-encoded POST requests. PIN stored in code description as `Smoobu#{bookingId}`.
- **`pushover.ts`** — Validates ElevenLabs HMAC signature, extracts caller ID and transcript summary, forwards as Pushover notification.
- **`sms.ts`** — SMS dispatch via SerwerSMS (Bearer token, UTF for Cyrillic) or BudgetSMS (query params). Handles Polish diacritic transliteration.

### VPS (Admin Tools)

- **`manual_sync.php`** — Web UI to recover missed bookings from Smoobu API. Supports dry-run mode, name-based matching for existing codes (with prefix handling), and links `Smoobu#ID` to manual codes. Sends guest notifications for new codes and date updates.
- **`lock_migration.php`** — Lock hardware replacement tool; migrates codes and notifies guests.
- **`pipe.php`** — Email logging endpoint for IFTTT triggers.
- **`config.php`** — Runtime config for admin tools (gitignored; copy from `config.example.php`).

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

### Workers

```bash
cd workers

# Run tests
npm test

# Type check
npx tsc --noEmit

# Local dev server
npx wrangler dev

# Tail production logs
npx wrangler tail
```

**Test locally (simulate Smoobu event):**
```bash
curl -X POST http://localhost:8787/webhook \
  -H "Content-Type: application/json" \
  -d '{"action":"newReservation","data":{"id":12345,"guest-name":"Test Guest","arrival":"2026-05-01","departure":"2026-05-03","apartment":{"id":"123456"},"language":"en"}}'
```

**Test Pushover endpoint:**
```bash
curl -X POST http://localhost:8787/pushover \
  -H "Content-Type: application/json" \
  -d '{"type":"post_call_transcription","data":{"analysis":{"transcript_summary":"Test"},"agent_name":"Test Agent","agent_id":"abc","conversation_id":"123"}}'
```

### PHP (admin tools)

```bash
php -l manual_sync.php
php -l lock_migration.php
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

**Workers logs:** `npx wrangler tail` or Cloudflare dashboard.
**Admin tool logs:** `logs/webhook.log`, `webhook_logs`, and `sync_history` database tables.

---
*Note for AI Assistants (Claude, Gemini, etc.): This repository contains webhook integrations. Workers code is in `workers/` (TypeScript, Cloudflare Workers). Admin tools are plain PHP — do not add a build system to the PHP files.*