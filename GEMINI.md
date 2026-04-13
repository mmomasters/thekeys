# GEMINI.md

This file provides guidance to Gemini CLI when working with code in this repository.

## Project Overview

PHP webhook integration between Smoobu (rental booking platform) and The Keys Cloud (smart lock management). When guests book via Smoobu, the system automatically generates PIN codes for keypad locks, sends SMS/email notifications, and manages code lifecycle (create on booking, update on change, delete on cancellation).

## Development Commands

No build system. This is a plain PHP project deployed by uploading files to a web server.

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
  -d '{"action":"reservation.new","reservation":{"id":12345,...}}'
```

**Tail logs:**
```bash
tail -f logs/webhook.log
```

**Configuration setup:**
```bash
cp config.example.php config.php
# Edit config.php with real credentials
```

## Architecture

### Request Flow

```
Smoobu POST → webhook.php → SmoobuWebhook → TheKeysAPI (create/update/delete PIN)
                                          → SMS provider (SerwerSMS or BudgetSMS)
                                          → Smoobu API (message to guest)
                                          → MySQL (audit log)

ElevenLabs POST → pushover.php → Pushover API (conversation summary)
```

### Core Files

- **webhook.php** — HTTP entry point for Smoobu. Validates JSON payload, optionally checks IP whitelist and HMAC signature, delegates to `SmoobuWebhook`.
- **pushover.php** — Webhook for ElevenLabs. Validates HMAC, extracts caller ID and summary, then sends Pushover notification with agent name and Chrome deep link.
- **SmoobuWebhook.php** — Main business logic for bookings. Routes events (`reservation.new`, `reservation.updated`, `reservation.cancelled`), checks idempotency (5-minute window), manages PIN lifecycle, dispatches notifications.
- **TheKeysAPI.php** — API client for The Keys Cloud. JWT auth, CRUD on lock codes via form-encoded POST requests. PIN stored in code description as `Smoobu#{bookingId}`.
- **config.php** — Runtime config (gitignored; copy from `config.example.php`).
- **languages/{en,de,pl,ru,ua}.php** — Localized message templates.

### Admin Tools

- **manual_sync.php** — Web UI to recover missed bookings from Smoobu API. Supports dry-run mode, name-based matching for existing codes (with prefix handling), and links `Smoobu#ID` to manual codes. Sends guest notifications for new codes and date updates.
- **lock_migration.php** — Lock hardware replacement tool; migrates codes and notifies guests.
- **pipe.php** — Email logging endpoint for IFTTT triggers.

## Key Configuration Concepts

**`apartment_locks`** — Maps Smoobu apartment ID → The Keys lock ID.

**`lock_accessoires`** — Maps lock ID → accessoire string ID (e.g., `"OXe37UIa"`). Must be the string ID from the API response field `accessoire.id_accessoire`, not a numeric ID.

**`digicode_prefixes`** — Maps lock ID → 2-digit PIN prefix. The full PIN is prefix + 4-digit random code.

## Database Tables

```sql
webhook_logs   -- Raw incoming events (idempotency checks use this)
sync_history   -- Outcome of each create/update/delete operation
```

## SMS Provider Selection

Set `sms_provider` in `config.php` to `'serwersms'` (default) or `'budgetsms'`. Each provider has its own credentials block in config.

SerwerSMS uses Bearer token auth + `utf=true` for Cyrillic. BudgetSMS uses `username`/`userid`/`handle` query params and auto-detects Unicode.

## External APIs

| API | Auth | Format |
|-----|------|--------|
| The Keys Cloud | JWT (login → token) | Form-encoded POST |
| Smoobu | `Api-Key` header | JSON |
| ElevenLabs | HMAC Signature | JSON |
| SerwerSMS | Bearer token | Form-encoded POST with `utf=true` |
| BudgetSMS | `username`+`userid`+`handle` params | GET query string |
| Pushover | `token` + `user` keys | Form-encoded POST |
