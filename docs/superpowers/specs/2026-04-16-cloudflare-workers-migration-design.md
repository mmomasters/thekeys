# Cloudflare Workers Migration — Webhook Endpoints

## Context

The Keys Cloud webhook system currently runs as plain PHP on a VPS. The two webhook endpoints (Smoobu booking handler and ElevenLabs-to-Pushover forwarder) are stateless request handlers that are a natural fit for Cloudflare Workers — removing VPS dependency for the critical booking path, improving latency, and eliminating server maintenance.

Admin tools (`manual_sync.php`, `lock_migration.php`, `pipe.php`) remain on the VPS unchanged.

## Scope

**In scope:**
- `webhook.php` + `SmoobuWebhook.php` + `TheKeysAPI.php` → Smoobu booking webhook
- `pushover.php` → ElevenLabs-to-Pushover forwarder
- Language templates (`languages/*.php`) → TypeScript modules

**Out of scope:**
- `manual_sync.php`, `lock_migration.php`, `pipe.php` (stay on VPS)
- Database (idempotency checks and audit logging are dropped)

## Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Database | None | Simplifies migration. Idempotency and audit logs dropped. |
| Language | TypeScript | Type safety, standard for Workers. |
| Deployment | Single Worker | Both endpoints are small; route-based dispatch avoids operational overhead. |
| Repo layout | `workers/` subdirectory | PHP files stay for VPS admin tools. Both coexist. |

## Project Structure

```
workers/
  src/
    index.ts          # Router: POST /webhook, POST /pushover
    smoobu.ts         # Smoobu webhook handler (booking lifecycle)
    thekeys.ts        # The Keys Cloud API client
    pushover.ts       # ElevenLabs → Pushover forwarder
    sms.ts            # SerwerSMS + BudgetSMS dispatch
    languages/
      index.ts        # loadLanguage() + placeholder replacement
      en.ts
      de.ts
      pl.ts
      ru.ts
      ua.ts
    types.ts          # Shared type definitions (Env, booking payloads, etc.)
  wrangler.toml
  package.json
  tsconfig.json
```

## Component Design

### Router (`index.ts`)

Worker `fetch` handler routes by pathname:

- `POST /webhook` → `handleSmoobuWebhook(request, env)`
- `POST /pushover` → `handlePushover(request, env)`
- All other methods/paths → 404

Top-level try/catch returns 200 on error (matching PHP behavior — Smoobu retries on non-200). Errors logged via `console.error()`.

### Pushover handler (`pushover.ts`)

Direct port of `pushover.php`:

1. Validate ElevenLabs HMAC signature using Web Crypto API (`crypto.subtle.importKey` + `crypto.subtle.sign`). Signature format: `t=timestamp,v1=hash`. Signed payload: `${timestamp}.${rawBody}`.
2. Ignore events where `type !== 'post_call_transcription'`.
3. Extract `transcript_summary` (fallback to `summary`), `agent_name`, `caller_id` from payload.
4. POST to `https://api.pushover.net/1/messages.json` via `fetch()` with form-encoded body.
5. Return 200.

### The Keys API client (`thekeys.ts`)

Class `TheKeysAPI` with JWT auth:

- `login()` — POST form-encoded to `/api/login_check`, stores Bearer token.
- `listCodes(lockId)` — GET `/fr/api/v2/partage/all/serrure/{lockId}?_format=json`.
- `createCode(lockId, idAccessoire, name, code, dateStart, dateEnd, times, description)` — POST form-encoded to `/fr/api/v2/partage/create/{lockId}/accessoire/{idAccessoire}`. Field names use `partage_accessoire[...]` format.
- `updateCode(codeId, ...)` — POST form-encoded to `/fr/api/v2/partage/accessoire/update/{codeId}`. Only sends non-null fields. Preserves existing PIN.
- `deleteCode(codeId)` — POST to `/fr/api/v2/partage/accessoire/delete/{codeId}`.

All methods use `fetch()` with `URLSearchParams` for form encoding. No SSL verification override (Workers handles TLS natively). Token is instance-scoped — new instance per request, same as PHP.

### Smoobu webhook handler (`smoobu.ts`)

Port of `webhook.php` entry validation + `SmoobuWebhook.php` business logic, minus all DB operations.

**Entry validation:**
- Parse JSON body.
- Map Smoobu `action` field: `newReservation` → `reservation.new`, `cancelReservation` → `reservation.cancelled`, `updateReservation` → `reservation.updated`.
- Silently ignore non-reservation actions (`newMessage`, `updateRates`, `newTimelineEvent`, `deleteTimelineEvent`) with 200.
- Optional IP whitelist check via `CF-Connecting-IP` header.
- Optional HMAC signature check (`X-Smoobu-Signature` header, SHA-256).

**Event handlers:**

`handleNewReservation(booking, env)`:
1. Login to The Keys API.
2. Look up lock ID from `apartment_locks[apartmentId]`, then accessoire from `lock_accessoires[lockId]`.
3. Check if code already exists (`findExistingCode` — iterates `listCodes()`, matches `Smoobu#{bookingId}` in description).
4. Generate PIN: random 4 digits, prepend `digicode_prefixes[lockId]`.
5. Create code via `TheKeysAPI.createCode()`.
6. Send SMS notification to guest phone (if available).
7. Send guest message via Smoobu API (if arrival is today or future).

`handleUpdatedReservation(booking, env)`:
1. Login, look up lock.
2. Search for existing code across ALL locks (apartment may have changed).
3. If found on wrong lock → delete old, call `handleNewReservation`.
4. If not found → call `handleNewReservation`.
5. If found on correct lock → update dates/times via `TheKeysAPI.updateCode()`, preserve existing PIN, send SMS + guest message.

`handleCancelledReservation(booking, env)`:
1. Login, search for code across all locks.
2. If found → delete via `TheKeysAPI.deleteCode()`.
3. No notifications sent for cancellations.

**Removed (DB-dependent):**
- `logWebhook()`, `markWebhookProcessed()`, `wasRecentlyProcessed()`, `logSyncOperation()`, `connectDatabase()`.

### SMS module (`sms.ts`)

Two provider functions:

`sendViaSerwersms(recipient, message, language, apiToken)`:
- POST form-encoded to `https://api2.serwersms.pl/messages/send_sms`.
- Bearer token auth. Sender: `KOLNA`.
- Sets `utf=true` for `ru`/`ua` languages.

`sendViaBudgetSMS(recipient, message, config)`:
- GET to `https://api.budgetsms.net/sendsms/` with query params (`username`, `userid`, `handle`, `msg`, `from`, `to`).
- Phone cleanup: strip leading `+` and `00` for E.164.

`sendSMSNotification(booking, fullPin, apartmentName, action, env)`:
- Selects provider from `env.SMS_PROVIDER`.
- Cleans guest phone number (strips spaces, parens, dashes).
- Loads language template, applies Polish diacritic transliteration for `pl`.
- Dispatches to the selected provider.

### Language templates (`languages/`)

Each file exports `{ subject, message, sms_message }` with `{placeholder}` tokens.

`loadLanguage(language, booking, fullPin, apartmentName)`:
- Imports the matching module (fallback to `en`).
- Replaces `{guest_name}`, `{apartment_name}`, `{full_pin}`, `{arrival}`, `{departure}`.
- Returns `{ subject, message, sms_message }`.

### Types (`types.ts`)

```typescript
interface Env {
  // Secrets
  THEKEYS_USERNAME: string;
  THEKEYS_PASSWORD: string;
  SMOOBU_API_KEY: string;
  ELEVENLABS_WEBHOOK_SECRET: string;
  PUSHOVER_USER_KEY: string;
  PUSHOVER_API_TOKEN: string;
  SERWERSMS_API_TOKEN: string;
  BUDGETSMS_USERNAME: string;
  BUDGETSMS_USERID: string;
  BUDGETSMS_HANDLE: string;
  WEBHOOK_SECRET?: string;

  // Vars (JSON strings, parsed at runtime)
  APARTMENT_LOCKS: string;    // '{"smoobuId": lockId}'
  LOCK_ACCESSOIRES: string;   // '{"lockId": "accessoireStringId"}'
  DIGICODE_PREFIXES: string;  // '{"lockId": "prefix"}'
  DEFAULT_TIMES: string;      // '{"check_in_hour":"15",...}'
  SMS_PROVIDER: string;       // 'serwersms' | 'budgetsms'
  IP_WHITELIST?: string;      // '["1.2.3.4"]'
}
```

## Configuration

**`wrangler.toml` vars:**
```toml
[vars]
APARTMENT_LOCKS = '{"123456": 3733}'
LOCK_ACCESSOIRES = '{"3733": "OXe37UIa"}'
DIGICODE_PREFIXES = '{"3733": "28"}'
DEFAULT_TIMES = '{"check_in_hour":"15","check_in_minute":"0","check_out_hour":"12","check_out_minute":"0"}'
SMS_PROVIDER = "serwersms"
```

**Secrets (via `wrangler secret put`):**
```
THEKEYS_USERNAME, THEKEYS_PASSWORD, SMOOBU_API_KEY,
ELEVENLABS_WEBHOOK_SECRET, PUSHOVER_USER_KEY, PUSHOVER_API_TOKEN,
SERWERSMS_API_TOKEN, BUDGETSMS_USERNAME, BUDGETSMS_USERID,
BUDGETSMS_HANDLE, WEBHOOK_SECRET
```

## Logging

All logging via `console.log()` / `console.error()`. Visible in:
- `wrangler tail` (live streaming during development)
- Workers dashboard (Cloudflare UI)
- Optionally: Workers Logpush to external service

Log format matches current PHP pattern: `[LEVEL] message`.

## Verification

1. **Unit test each module** — mock `fetch()` to verify The Keys API calls, SMS dispatch, HMAC validation.
2. **Local dev with `wrangler dev`** — test both routes with curl:
   ```bash
   # Test Smoobu webhook
   curl -X POST http://localhost:8787/webhook \
     -H "Content-Type: application/json" \
     -d '{"action":"newReservation","data":{"id":12345,"guest-name":"Test Guest","arrival":"2026-05-01","departure":"2026-05-03","apartment":{"id":"123456"},"language":"en"}}'

   # Test Pushover
   curl -X POST http://localhost:8787/pushover \
     -H "Content-Type: application/json" \
     -d '{"type":"post_call_transcription","data":{"analysis":{"transcript_summary":"Test summary"},"agent_name":"Test Agent","conversation_id":"123","agent_id":"abc"}}'
   ```
3. **Deploy to Workers** and update Smoobu webhook URL + ElevenLabs webhook URL to the new Worker domain.
4. **Tail logs** via `wrangler tail` while triggering a real booking to verify end-to-end flow.
