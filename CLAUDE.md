# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Smoobu (rental booking platform) → The Keys Cloud (smart locks) integration. When a guest books, the system generates a keypad PIN, creates a time-bounded access code on the apartment's lock, and notifies the guest by SMS and by a Smoobu guest message. Codes are updated on booking changes and deleted after checkout on cancellation.

## Two parallel implementations (read this first)

The same booking→PIN logic exists twice, against the same external APIs:

- **Cloudflare Worker (`workers/`, TypeScript) — this is the LIVE webhook path.** Smoobu and ElevenLabs POST here. Auto-deploys from a push to `main` (Cloudflare build, root directory `workers/`). `smoobu.ts` is the handler, `thekeys.ts` the API client.
- **PHP (repo root) — admin/recovery tools + a legacy webhook handler.** `webhook.php`/`SmoobuWebhook.php` mirror the worker logic but are not the active endpoint. `SmoobuWebhook.php` is still actively used by `manual_sync.php` and `lock_migration.php` to send guest notifications. PHP runs on a VPS and is updated by `git pull` there (NOT auto-deployed).

When changing booking behavior, consider whether both sides need the change. They do not share code.

## Commands

Workers (`cd workers`):
- `npm test` — Vitest suite (`@cloudflare/vitest-pool-workers`). Single test: `npm test -- -t "name substring"` or `npm test src/../test/smoobu.test.ts`.
- `npx tsc --noEmit` — type check.
- `npx wrangler dev` — local server on :8787. `npx wrangler tail` — live prod logs.

PHP (repo root) — there is no test harness and **must not** be a build system:
- `php -l <file>.php` — lint. This is the only check available for PHP.

## Architecture invariants & gotchas

- **Code↔booking linking:** the PIN code's `description` field stores `Smoobu#{bookingId}`. Matching is by that ID first, then a normalized guest-name fallback (names may carry a stripped `smoobu ` prefix). Get this wrong and you create duplicates or mis-link a returning guest's code.
- **Accessoire IDs are STRINGS** (e.g. `"OXe37UIa"`, from API field `accessoire.id_accessoire`), not numeric. Numeric IDs silently break code creation.
- **PIN = prefix + random code.** `digicode_prefixes[lockId]` (2 digits) + a random `PIN_LENGTH`-digit code. Existing PINs are preserved across updates — never regenerate a code on a date change.
- **Worker returns HTTP 200 even on internal errors** (`index.ts`) to stop Smoobu from retrying. Failures surface in logs, not status codes.
- **The Keys cloud API intermittently resets connections** ("Connection reset by peer"). `TheKeysAPI::listCodes()` (PHP) retries and reports success via a `&$ok` out-param; callers must treat `$ok===false` as "could not fetch" (NOT "no codes") and never create from a failed scan — otherwise a transient failure produces duplicate codes for an entire lock. The worker's `thekeys.ts listCodes()` still has the un-retried `return [] on failure` pattern (known latent risk).
- **Messages are only sent for future arrivals** (`today <= arrival`). Tests must use future dates or the send path is skipped.
- **SMS delivery cannot be verified**, so recovery/re-send flows must never re-send SMS — only the Smoobu guest message.
- **Smoobu's public API cannot see Airbnb-channel messages** — `GET /reservations/{id}/messages` returns empty for Airbnb bookings even when the message is visible in Smoobu's inbox. Do not build delivery verification on it.

## Conventions

- `config.php` is gitignored (real credentials); copy from `config.example.php`. Worker secrets live in the Cloudflare dashboard, non-secret maps in `wrangler.toml [vars]`.
- Commit and push only when explicitly asked.
- Message templates are per-language files under `languages/` (PHP) and `workers/src/languages/` (TS): `en/de/pl/ru/ua`.
