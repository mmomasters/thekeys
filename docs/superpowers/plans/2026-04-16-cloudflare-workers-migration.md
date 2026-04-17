# Cloudflare Workers Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the Smoobu booking webhook and ElevenLabs/Pushover forwarder from PHP to a single Cloudflare Worker in TypeScript.

**Architecture:** Single Worker with route-based dispatch (`/webhook` and `/pushover`). No database — idempotency and audit logging are dropped. All external API calls use `fetch()`. Config via wrangler vars and secrets.

**Tech Stack:** TypeScript, Cloudflare Workers, Wrangler, Vitest

**Spec:** `docs/superpowers/specs/2026-04-16-cloudflare-workers-migration-design.md`

---

## File Structure

```
workers/
  src/
    index.ts              # Router: dispatches to /webhook and /pushover handlers
    types.ts              # Env interface, booking/code type definitions
    pushover.ts           # ElevenLabs HMAC validation + Pushover forwarding
    thekeys.ts            # The Keys Cloud API client (JWT auth, CRUD on codes)
    sms.ts                # SerwerSMS + BudgetSMS dispatch
    smoobu.ts             # Smoobu webhook handler (booking lifecycle)
    languages/
      index.ts            # loadLanguage() with placeholder replacement
      en.ts               # English templates
      de.ts               # German templates
      pl.ts               # Polish templates
      ru.ts               # Russian templates
      ua.ts               # Ukrainian templates
  test/
    pushover.test.ts      # Tests for pushover handler
    thekeys.test.ts       # Tests for The Keys API client
    sms.test.ts           # Tests for SMS dispatch
    smoobu.test.ts        # Tests for Smoobu webhook handler
    index.test.ts         # Tests for router
    helpers.ts            # Shared test utilities (mock env, mock fetch)
  wrangler.toml
  package.json
  tsconfig.json
```

---

### Task 1: Project Scaffold

**Files:**
- Create: `workers/package.json`
- Create: `workers/tsconfig.json`
- Create: `workers/wrangler.toml`
- Create: `workers/src/types.ts`

- [ ] **Step 1: Create `workers/package.json`**

```json
{
  "name": "thekeys-workers",
  "version": "1.0.0",
  "private": true,
  "scripts": {
    "dev": "wrangler dev",
    "deploy": "wrangler deploy",
    "test": "vitest run",
    "test:watch": "vitest"
  },
  "devDependencies": {
    "@cloudflare/vitest-pool-workers": "^0.8.0",
    "@cloudflare/workers-types": "^4.20250410.0",
    "typescript": "^5.8.0",
    "vitest": "^3.1.0",
    "wrangler": "^4.14.0"
  }
}
```

- [ ] **Step 2: Create `workers/tsconfig.json`**

```json
{
  "compilerOptions": {
    "target": "ESNext",
    "module": "ESNext",
    "moduleResolution": "bundler",
    "lib": ["ESNext"],
    "types": ["@cloudflare/workers-types", "@cloudflare/vitest-pool-workers"],
    "strict": true,
    "noEmit": true,
    "skipLibCheck": true,
    "esModuleInterop": true,
    "forceConsistentCasingInImports": true
  },
  "include": ["src/**/*.ts", "test/**/*.ts"]
}
```

- [ ] **Step 3: Create `workers/wrangler.toml`**

```toml
name = "thekeys"
main = "src/index.ts"
compatibility_date = "2025-04-01"

[vars]
APARTMENT_LOCKS = '{"123456":3733,"123457":3723}'
LOCK_ACCESSOIRES = '{"3733":"OXe37UIa","3723":"SLORUV6s"}'
DIGICODE_PREFIXES = '{"3733":"28","3723":"18"}'
DEFAULT_TIMES = '{"check_in_hour":"15","check_in_minute":"0","check_out_hour":"12","check_out_minute":"0"}'
SMS_PROVIDER = "serwersms"
PIN_LENGTH = "4"

# Secrets (set via `wrangler secret put <NAME>`):
# THEKEYS_USERNAME, THEKEYS_PASSWORD, SMOOBU_API_KEY,
# ELEVENLABS_WEBHOOK_SECRET, PUSHOVER_USER_KEY, PUSHOVER_API_TOKEN,
# SERWERSMS_API_TOKEN, BUDGETSMS_USERNAME, BUDGETSMS_USERID,
# BUDGETSMS_HANDLE, WEBHOOK_SECRET (optional)
```

- [ ] **Step 4: Create `workers/src/types.ts`**

```typescript
export interface Env {
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
  IP_WHITELIST?: string;

  // Vars (JSON strings parsed at runtime)
  APARTMENT_LOCKS: string;
  LOCK_ACCESSOIRES: string;
  DIGICODE_PREFIXES: string;
  DEFAULT_TIMES: string;
  SMS_PROVIDER: string;
  PIN_LENGTH: string;
}

export interface SmoobuBooking {
  id: number;
  'guest-name'?: string;
  arrival?: string;
  departure?: string;
  apartment?: { id: number | string; name?: string };
  language?: string;
  phone?: string;
  type?: string;
}

export interface SmoobuWebhookPayload {
  action: string;
  data?: SmoobuBooking;
  booking?: SmoobuBooking;
}

export interface DefaultTimes {
  check_in_hour: string;
  check_in_minute: string;
  check_out_hour: string;
  check_out_minute: string;
}

export interface TheKeysCode {
  id: number;
  nom: string;
  code: string;
  description: string;
  date_debut: string;
  date_fin: string;
  heure_debut?: { hour: string; minute: string };
  heure_fin?: { hour: string; minute: string };
  accessoire?: { id_accessoire: string };
}

export interface LanguageTemplate {
  subject: string;
  message: string;
  sms_message: string;
}
```

- [ ] **Step 5: Install dependencies and verify TypeScript**

Run: `cd workers && npm install`
Expected: Installs all devDependencies successfully.

Run: `cd workers && npx tsc --noEmit`
Expected: No errors (only `types.ts` exists, no code to check yet).

- [ ] **Step 6: Commit**

```bash
git add workers/package.json workers/tsconfig.json workers/wrangler.toml workers/src/types.ts workers/package-lock.json
git commit -m "feat(workers): scaffold project with types, wrangler config, and dependencies"
```

---

### Task 2: Test Helpers

**Files:**
- Create: `workers/test/helpers.ts`
- Create: `workers/vitest.config.ts`

- [ ] **Step 1: Create `workers/vitest.config.ts`**

```typescript
import { defineWorkersConfig } from "@cloudflare/vitest-pool-workers/config";

export default defineWorkersConfig({
  test: {
    poolOptions: {
      workers: {
        wrangler: { configPath: "./wrangler.toml" },
      },
    },
  },
});
```

- [ ] **Step 2: Create `workers/test/helpers.ts`**

Shared utilities for building mock `Env` objects and intercepting `fetch()` calls.

```typescript
import type { Env } from "../src/types";

export function mockEnv(overrides: Partial<Env> = {}): Env {
  return {
    THEKEYS_USERNAME: "test_user",
    THEKEYS_PASSWORD: "test_pass",
    SMOOBU_API_KEY: "test_smoobu_key",
    ELEVENLABS_WEBHOOK_SECRET: "test_elevenlabs_secret",
    PUSHOVER_USER_KEY: "test_pushover_user",
    PUSHOVER_API_TOKEN: "test_pushover_token",
    SERWERSMS_API_TOKEN: "test_serwersms_token",
    BUDGETSMS_USERNAME: "test_budget_user",
    BUDGETSMS_USERID: "test_budget_id",
    BUDGETSMS_HANDLE: "test_budget_handle",
    APARTMENT_LOCKS: '{"123456":3733}',
    LOCK_ACCESSOIRES: '{"3733":"OXe37UIa"}',
    DIGICODE_PREFIXES: '{"3733":"28"}',
    DEFAULT_TIMES: '{"check_in_hour":"15","check_in_minute":"0","check_out_hour":"12","check_out_minute":"0"}',
    SMS_PROVIDER: "serwersms",
    PIN_LENGTH: "4",
    ...overrides,
  };
}

export function makeRequest(
  path: string,
  body: unknown,
  method = "POST",
  headers: Record<string, string> = {}
): Request {
  return new Request(`https://test.workers.dev${path}`, {
    method,
    headers: { "Content-Type": "application/json", ...headers },
    body: JSON.stringify(body),
  });
}
```

- [ ] **Step 3: Verify vitest config works**

We need a minimal src/index.ts for the workers pool to load. Create a placeholder:

```typescript
// workers/src/index.ts
export default {
  async fetch(): Promise<Response> {
    return new Response("placeholder");
  },
};
```

Run: `cd workers && npx vitest run`
Expected: `No test files found` (no `.test.ts` files yet) — but no config errors.

- [ ] **Step 4: Commit**

```bash
git add workers/vitest.config.ts workers/test/helpers.ts workers/src/index.ts
git commit -m "feat(workers): add vitest config and test helpers"
```

---

### Task 3: Language Templates

**Files:**
- Create: `workers/src/languages/en.ts`
- Create: `workers/src/languages/de.ts`
- Create: `workers/src/languages/pl.ts`
- Create: `workers/src/languages/ru.ts`
- Create: `workers/src/languages/ua.ts`
- Create: `workers/src/languages/index.ts`

- [ ] **Step 1: Create `workers/src/languages/en.ts`**

```typescript
import type { LanguageTemplate } from "../types";

const en: LanguageTemplate = {
  subject: "Kolna Apartments access codes and information",

  sms_message: `Dear {guest_name},

- Main building "Jana z Kolna 19" code is 1 + KEY + 5687
- Lobby door code is 3256 + ENTER
- Apartment {apartment_name} door code is {full_pin} + BLUE BUTTON

Your apartment code will ONLY work between the check in and check out date and time.
Your check in: {arrival} from 15.00
Your check out: {departure} until 12.00

PARKING : A lot of parking spaces are located on the street near Kolna Apartments. Parking is free from 5pm to 8am and during weekends and holidays, pricing: https://spp.szczecin.pl/informacja/paid-parking-zone-pricing

In case of any issue, please feel free to call us +48 91 819 99 65

We wish you a very pleasant stay,
Kolna Apartments`,

  message: `Dear {guest_name},

- Main building "Jana z Kolna 19" code is 1 + \u{1F511} + 5687
- Lobby door code is 3256 + ENTER
- Apartment {apartment_name} door code is {full_pin} + \u{1F7E6}

Your apartment code will ONLY work between the check in and check out date and time.
Your check in: {arrival} from 15.00
Your check out: {departure} until 12.00

\u{1F17F}\u{FE0F} PARKING : A lot of parking spaces are located on the street near Kolna Apartments. Parking is free from 5pm to 8am and during weekends and holidays, pricing: https://spp.szczecin.pl/informacja/paid-parking-zone-pricing

In case of any issue, please feel free to call us +48 91 819 99 65

We wish you a very pleasant stay,
Kolna Apartments`,
};

export default en;
```

- [ ] **Step 2: Create `workers/src/languages/de.ts`**

```typescript
import type { LanguageTemplate } from "../types";

const de: LanguageTemplate = {
  subject: "Zugangscodes f\u00FCr die Kolna Apartments",

  sms_message: `Lieber, Herr {guest_name},

- Hauptgeb\u00E4ude "Jana z Kolna 19" Code ist 1 + SCHL\u00DCSSEL + 5687
- Lobby-T\u00FCrcode ist 3256 + ENTER
- Der T\u00FCrcode f\u00FCr das Apartment {apartment_name} lautet {full_pin} + BLAUE TASTE

Ihr Apartmentcode funktioniert NUR zwischen Check-in- und Check-out-Datum und -Uhrzeit.
Ihr Check-in: {arrival} ab 15.00 Uhr.
Ihr Check-out: {departure} bis 12.00 Uhr.

PARKING : Viele Parkpl\u00E4tze befinden sich auf der Stra\u00DFe in der N\u00E4he der Kolna Apartments. Das Parken ist von 17:00 bis 08:00 Uhr sowie an Wochenenden und Feiertagen kostenlos, Preisliste: https://spp.szczecin.pl/informacja/SPP-Preisliste

Bei Problemen k\u00F6nnen Sie uns gerne unter +48 91 819 99 65 anrufen.

Wir w\u00FCnschen Ihnen einen sehr angenehmen Aufenthalt,
Kolna Apartments`,

  message: `Lieber, Herr {guest_name},

- Hauptgeb\u00E4ude "Jana z Kolna 19" Code ist 1 + \u{1F511} + 5687
- Lobby-T\u00FCrcode ist 3256 + ENTER
- Der T\u00FCrcode f\u00FCr das Apartment {apartment_name} lautet {full_pin} + \u{1F7E6}

Ihr Apartmentcode funktioniert NUR zwischen Check-in- und Check-out-Datum und -Uhrzeit.
Ihr Check-in: {arrival} ab 15.00 Uhr.
Ihr Check-out: {departure} bis 12.00 Uhr.

\u{1F17F}\u{FE0F} PARKING : Viele Parkpl\u00E4tze befinden sich auf der Stra\u00DFe in der N\u00E4he der Kolna Apartments. Das Parken ist von 17:00 bis 08:00 Uhr sowie an Wochenenden und Feiertagen kostenlos, Preisliste: https://spp.szczecin.pl/informacja/SPP-Preisliste

Bei Problemen k\u00F6nnen Sie uns gerne unter +48 91 819 99 65 anrufen.

Wir w\u00FCnschen Ihnen einen sehr angenehmen Aufenthalt,
Kolna Apartments`,
};

export default de;
```

- [ ] **Step 3: Create `workers/src/languages/pl.ts`**

```typescript
import type { LanguageTemplate } from "../types";

const pl: LanguageTemplate = {
  subject: "Kody dost\u0119pu do Kolna Apartments",

  sms_message: `Pan, Pani {guest_name},

- Kod budynku g\u0142\u00F3wnego "Jana z Kolna 19" to 1 + KLUCZ + 5687
- Kod do recepcji to 3256 + ENTER
- Kod apartamentu {apartment_name} to {full_pin} + NIEBIESKI PRZYCISK

Tw\u00F3j kod apartamentu b\u0119dzie dzia\u0142a\u0142 TYLKO pomi\u0119dzy dat\u0105 i godzin\u0105 zameldowania i wymeldowania.
Twoje zameldowanie: {arrival} od 15.00
Twoje wymeldowanie: {departure} do 12.00

PARKING : Du\u017Co miejsc parkingowych znajduje si\u0119 przy ulicy pod Kolna Apartments. Parking jest bezp\u0142atny od 17:00 do 8:00 oraz w weekendy i \u015Bwi\u0119ta, cennik: https://spp.szczecin.pl/informacja/cennik-strefy-platnego-parkowania

W przypadku jakichkolwiek problem\u00F3w prosimy o kontakt telefoniczny +48 91 819 99 65

\u017Byczymy mi\u0142ego pobytu,
Kolna Apartments`,

  message: `Pan, Pani {guest_name},

- Kod budynku g\u0142\u00F3wnego "Jana z Kolna 19" to 1 + \u{1F511} + 5687
- Kod do recepcji to 3256 + ENTER
- Kod apartamentu {apartment_name} to {full_pin} + \u{1F7E6}

Tw\u00F3j kod apartamentu b\u0119dzie dzia\u0142a\u0142 TYLKO pomi\u0119dzy dat\u0105 i godzin\u0105 zameldowania i wymeldowania.
Twoje zameldowanie: {arrival} od 15.00
Twoje wymeldowanie: {departure} do 12.00

\u{1F17F}\u{FE0F} PARKING : Du\u017Co miejsc parkingowych znajduje si\u0119 przy ulicy pod Kolna Apartments. Parking jest bezp\u0142atny od 17:00 do 8:00 oraz w weekendy i \u015Bwi\u0119ta, cennik: https://spp.szczecin.pl/informacja/cennik-strefy-platnego-parkowania

W przypadku jakichkolwiek problem\u00F3w prosimy o kontakt telefoniczny +48 91 819 99 65

\u017Byczymy mi\u0142ego pobytu,
Kolna Apartments`,
};

export default pl;
```

- [ ] **Step 4: Create `workers/src/languages/ru.ts`**

```typescript
import type { LanguageTemplate } from "../types";

const ru: LanguageTemplate = {
  subject: "\u041A\u043E\u0434\u044B \u0434\u043E\u0441\u0442\u0443\u043F\u0430 Kolna Apartments",

  sms_message: `\u0423\u0432\u0430\u0436\u0430\u0435\u043C\u044B\u0439 {guest_name},

- \u041A\u043E\u0434 \u0433\u043B\u0430\u0432\u043D\u043E\u0433\u043E \u0437\u0434\u0430\u043D\u0438\u044F "Jana z Kolna 19" - 1 + \u{1F511} + 5687
- \u041A\u043E\u0434 \u0434\u0432\u0435\u0440\u0438 \u0432 \u0445\u043E\u043B\u043B - 3256 + ENTER
- \u041A\u043E\u0434 \u0434\u0432\u0435\u0440\u0438 \u0430\u043F\u0430\u0440\u0442\u0430\u043C\u0435\u043D\u0442\u043E\u0432 {apartment_name} - {full_pin} + \u{1F7E6}

\u041A\u043E\u0434 \u0434\u0435\u0439\u0441\u0442\u0432\u0443\u0435\u0442 \u0442\u043E\u043B\u044C\u043A\u043E \u0432\u043E \u0432\u0440\u0435\u043C\u044F \u0432\u0430\u0448\u0435\u0433\u043E \u043F\u0440\u0435\u0431\u044B\u0432\u0430\u043D\u0438\u044F.
\u0412\u0430\u0448 \u0437\u0430\u0435\u0437\u0434: {arrival} \u0441 15.00
\u0412\u0430\u0448 \u0432\u044B\u0435\u0437\u0434: {departure} \u0434\u043E 12.00

\u0412 \u0441\u043B\u0443\u0447\u0430\u0435 \u043F\u0440\u043E\u0431\u043B\u0435\u043C \u0437\u0432\u043E\u043D\u0438\u0442\u0435 \u043D\u0430\u043C +48 91 819 99 65

\u041F\u0440\u0438\u044F\u0442\u043D\u043E\u0433\u043E \u043F\u0440\u0435\u0431\u044B\u0432\u0430\u043D\u0438\u044F!
Kolna Apartments`,

  message: `\u0423\u0432\u0430\u0436\u0430\u0435\u043C\u044B\u0439 {guest_name},

- \u041A\u043E\u0434 \u0433\u043B\u0430\u0432\u043D\u043E\u0433\u043E \u0437\u0434\u0430\u043D\u0438\u044F "Jana z Kolna 19" - 1 + \u{1F511} + 5687
- \u041A\u043E\u0434 \u0434\u0432\u0435\u0440\u0438 \u0432 \u0445\u043E\u043B\u043B - 3256 + ENTER
- \u041A\u043E\u0434 \u0434\u0432\u0435\u0440\u0438 \u0430\u043F\u0430\u0440\u0442\u0430\u043C\u0435\u043D\u0442\u043E\u0432 {apartment_name} - {full_pin} + \u{1F7E6}

\u0412\u0430\u0448 \u043A\u043E\u0434 \u0430\u043F\u0430\u0440\u0442\u0430\u043C\u0435\u043D\u0442\u043E\u0432 \u0431\u0443\u0434\u0435\u0442 \u0440\u0430\u0431\u043E\u0442\u0430\u0442\u044C \u0422\u041E\u041B\u042C\u041A\u041E \u043C\u0435\u0436\u0434\u0443 \u0434\u0430\u0442\u043E\u0439 \u0438 \u0432\u0440\u0435\u043C\u0435\u043D\u0435\u043C \u0437\u0430\u0435\u0437\u0434\u0430 \u0438 \u0432\u044B\u0435\u0437\u0434\u0430.
\u0412\u0430\u0448 \u0437\u0430\u0435\u0437\u0434: {arrival} \u0441 15.00
\u0412\u0430\u0448 \u0432\u044B\u0435\u0437\u0434: {departure} \u0434\u043E 12.00

\u{1F17F}\u{FE0F} \u041C\u043D\u043E\u0433\u043E \u043F\u0430\u0440\u043A\u043E\u0432\u043E\u0447\u043D\u044B\u0445 \u043C\u0435\u0441\u0442 \u0440\u0430\u0441\u043F\u043E\u043B\u043E\u0436\u0435\u043D\u043E \u043D\u0430 \u0443\u043B\u0438\u0446\u0435 \u0432\u043E\u0437\u043B\u0435 Kolna Apartments. \u041F\u0430\u0440\u043A\u043E\u0432\u043A\u0430 \u0431\u0435\u0441\u043F\u043B\u0430\u0442\u043D\u0430 \u0441 17:00 \u0434\u043E 08:00, \u0430 \u0442\u0430\u043A\u0436\u0435 \u0432 \u0432\u044B\u0445\u043E\u0434\u043D\u044B\u0435 \u0438 \u043F\u0440\u0430\u0437\u0434\u043D\u0438\u0447\u043D\u044B\u0435 \u0434\u043D\u0438, \u0446\u0435\u043D\u044B: https://spp.szczecin.pl/informacja/paid-parking-zone-pricing

\u0412 \u0441\u043B\u0443\u0447\u0430\u0435 \u0432\u043E\u0437\u043D\u0438\u043A\u043D\u043E\u0432\u0435\u043D\u0438\u044F \u043F\u0440\u043E\u0431\u043B\u0435\u043C \u0437\u0432\u043E\u043D\u0438\u0442\u0435 \u043D\u0430\u043C +48 91 819 99 65

\u0416\u0435\u043B\u0430\u0435\u043C \u0432\u0430\u043C \u043F\u0440\u0438\u044F\u0442\u043D\u043E\u0433\u043E \u043E\u0442\u0434\u044B\u0445\u0430,
Kolna Apartments`,
};

export default ru;
```

- [ ] **Step 5: Create `workers/src/languages/ua.ts`**

```typescript
import type { LanguageTemplate } from "../types";

const ua: LanguageTemplate = {
  subject: "\u041A\u043E\u0434\u0438 \u0434\u043E\u0441\u0442\u0443\u043F\u0443 Kolna Apartments",

  sms_message: `\u0428\u0430\u043D\u043E\u0432\u043D\u0438\u0439 {guest_name},

- \u041A\u043E\u0434 \u0433\u043E\u043B\u043E\u0432\u043D\u043E\u0457 \u0431\u0443\u0434\u0456\u0432\u043B\u0456 "Jana z Kolna 19" - 1 + \u{1F511} + 5687
- \u041A\u043E\u0434 \u0434\u0432\u0435\u0440\u0435\u0439 \u0443 \u0445\u043E\u043B\u043B - 3256 + ENTER
- \u041A\u043E\u0434 \u0434\u0432\u0435\u0440\u0435\u0439 \u0430\u043F\u0430\u0440\u0442\u0430\u043C\u0435\u043D\u0442\u0456\u0432 {apartment_name} - {full_pin} + \u{1F7E6}

\u041A\u043E\u0434 \u0434\u0456\u0454 \u043B\u0438\u0448\u0435 \u043F\u0456\u0434 \u0447\u0430\u0441 \u0432\u0430\u0448\u043E\u0433\u043E \u043F\u0435\u0440\u0435\u0431\u0443\u0432\u0430\u043D\u043D\u044F.
\u0412\u0430\u0448 \u0437\u0430\u0457\u0437\u0434: {arrival} \u0437 15.00
\u0412\u0430\u0448 \u0432\u0456\u0434'\u0457\u0437\u0434: {departure} \u0434\u043E 12.00

\u0423 \u0440\u0430\u0437\u0456 \u043F\u0440\u043E\u0431\u043B\u0435\u043C \u0442\u0435\u043B\u0435\u0444\u043E\u043D\u0443\u0439\u0442\u0435 \u043D\u0430\u043C +48 91 819 99 65

\u041F\u0440\u0438\u0454\u043C\u043D\u043E\u0433\u043E \u043F\u0435\u0440\u0435\u0431\u0443\u0432\u0430\u043D\u043D\u044F!
Kolna Apartments`,

  message: `\u0428\u0430\u043D\u043E\u0432\u043D\u0438\u0439 {guest_name},

- \u041A\u043E\u0434 \u0433\u043E\u043B\u043E\u0432\u043D\u043E\u0457 \u0431\u0443\u0434\u0456\u0432\u043B\u0456 "Jana z Kolna 19" - 1 + \u{1F511} + 5687
- \u041A\u043E\u0434 \u0434\u0432\u0435\u0440\u0435\u0439 \u0443 \u0445\u043E\u043B\u043B - 3256 + ENTER
- \u041A\u043E\u0434 \u0434\u0432\u0435\u0440\u0435\u0439 \u0430\u043F\u0430\u0440\u0442\u0430\u043C\u0435\u043D\u0442\u0456\u0432 {apartment_name} - {full_pin} + \u{1F7E6}

\u0412\u0430\u0448 \u043A\u043E\u0434 \u0430\u043F\u0430\u0440\u0442\u0430\u043C\u0435\u043D\u0442\u0456\u0432 \u0431\u0443\u0434\u0435 \u043F\u0440\u0430\u0446\u044E\u0432\u0430\u0442\u0438 \u041B\u0418\u0428\u0415 \u043C\u0456\u0436 \u0434\u0430\u0442\u043E\u044E \u0442\u0430 \u0447\u0430\u0441\u043E\u043C \u0437\u0430\u0457\u0437\u0434\u0443 \u0442\u0430 \u0432\u0456\u0434'\u0457\u0437\u0434\u0443.
\u0412\u0430\u0448 \u0437\u0430\u0457\u0437\u0434: {arrival} \u0437 15.00
\u0412\u0430\u0448 \u0432\u0456\u0434'\u0457\u0437\u0434: {departure} \u0434\u043E 12.00

\u{1F17F}\u{FE0F} \u0411\u0430\u0433\u0430\u0442\u043E \u043F\u0430\u0440\u043A\u0443\u0432\u0430\u043B\u044C\u043D\u0438\u0445 \u043C\u0456\u0441\u0446\u044C \u0440\u043E\u0437\u0442\u0430\u0448\u043E\u0432\u0430\u043D\u043E \u043D\u0430 \u0432\u0443\u043B\u0438\u0446\u0456 \u0431\u0456\u043B\u044F Kolna Apartments. \u041F\u0430\u0440\u043A\u043E\u0432\u043A\u0430 \u0431\u0435\u0437\u043A\u043E\u0448\u0442\u043E\u0432\u043D\u0430 \u0437 17:00 \u0434\u043E 08:00, \u0430 \u0442\u0430\u043A\u043E\u0436 \u0443 \u0432\u0438\u0445\u0456\u0434\u043D\u0456 \u0442\u0430 \u0441\u0432\u044F\u0442\u043A\u043E\u0432\u0456 \u0434\u043D\u0456, \u0446\u0456\u043D\u0438: https://spp.szczecin.pl/informacja/paid-parking-zone-pricing

\u0423 \u0440\u0430\u0437\u0456 \u0432\u0438\u043D\u0438\u043A\u043D\u0435\u043D\u043D\u044F \u043F\u0440\u043E\u0431\u043B\u0435\u043C \u0442\u0435\u043B\u0435\u0444\u043E\u043D\u0443\u0439\u0442\u0435 \u043D\u0430\u043C +48 91 819 99 65

\u0411\u0430\u0436\u0430\u0454\u043C\u043E \u0432\u0430\u043C \u043F\u0440\u0438\u0454\u043C\u043D\u043E\u0433\u043E \u0432\u0456\u0434\u043F\u043E\u0447\u0438\u043D\u043A\u0443,
Kolna Apartments`,
};

export default ua;
```

- [ ] **Step 6: Create `workers/src/languages/index.ts`**

```typescript
import type { LanguageTemplate } from "../types";
import en from "./en";
import de from "./de";
import pl from "./pl";
import ru from "./ru";
import ua from "./ua";

const templates: Record<string, LanguageTemplate> = { en, de, pl, ru, ua };

interface LoadedLanguage {
  subject: string;
  message: string;
  sms_message: string;
}

export function loadLanguage(
  language: string,
  guestName: string,
  fullPin: string,
  apartmentName: string,
  arrival: string,
  departure: string
): LoadedLanguage {
  const lang = templates[language.toLowerCase()] ?? en;

  const replacements: Record<string, string> = {
    "{guest_name}": guestName,
    "{apartment_name}": apartmentName,
    "{full_pin}": fullPin,
    "{arrival}": arrival,
    "{departure}": departure,
  };

  function applyReplacements(text: string): string {
    let result = text;
    for (const [placeholder, value] of Object.entries(replacements)) {
      result = result.replaceAll(placeholder, value);
    }
    return result;
  }

  return {
    subject: lang.subject,
    message: applyReplacements(lang.message),
    sms_message: applyReplacements(lang.sms_message),
  };
}
```

- [ ] **Step 7: Commit**

```bash
git add workers/src/languages/
git commit -m "feat(workers): add language templates and loader"
```

---

### Task 4: Pushover Handler (TDD)

**Files:**
- Create: `workers/src/pushover.ts`
- Create: `workers/test/pushover.test.ts`

- [ ] **Step 1: Write the failing tests**

Create `workers/test/pushover.test.ts`:

```typescript
import { describe, it, expect, vi, beforeEach } from "vitest";
import { handlePushover } from "../src/pushover";
import { mockEnv, makeRequest } from "./helpers";

describe("handlePushover", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it("returns 400 for non-POST requests", async () => {
    const req = new Request("https://test.workers.dev/pushover", {
      method: "GET",
    });
    const res = await handlePushover(req, mockEnv());
    expect(res.status).toBe(400);
  });

  it("ignores non-post_call_transcription events", async () => {
    const req = makeRequest("/pushover", {
      type: "conversation_started",
      data: {},
    });
    const res = await handlePushover(req, mockEnv());
    expect(res.status).toBe(200);
    const body = await res.json() as { result: string };
    expect(body.result).toBe("ignored");
  });

  it("returns 200 with no_summary when summary is empty", async () => {
    const req = makeRequest("/pushover", {
      type: "post_call_transcription",
      data: {
        analysis: { transcript_summary: "" },
        agent_name: "Test Agent",
        conversation_id: "123",
        agent_id: "abc",
      },
    });
    const res = await handlePushover(req, mockEnv());
    expect(res.status).toBe(200);
    const body = await res.json() as { result: string };
    expect(body.result).toBe("no_summary");
  });

  it("sends to Pushover and returns success", async () => {
    const fetchSpy = vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response(JSON.stringify({ status: 1 }), { status: 200 })
    );

    const req = makeRequest("/pushover", {
      type: "post_call_transcription",
      data: {
        analysis: { transcript_summary: "Guest asked about check-in" },
        agent_name: "Kolna Agent",
        conversation_id: "conv-123",
        agent_id: "agent-abc",
        metadata: {
          phone_call: { external_number: "+48123456789" },
        },
      },
    });

    const env = mockEnv({ ELEVENLABS_WEBHOOK_SECRET: "" });
    const res = await handlePushover(req, env);

    expect(res.status).toBe(200);
    const body = await res.json() as { success: boolean };
    expect(body.success).toBe(true);

    expect(fetchSpy).toHaveBeenCalledOnce();
    const [url, init] = fetchSpy.mock.calls[0];
    expect(url).toBe("https://api.pushover.net/1/messages.json");
    expect(init?.method).toBe("POST");

    const sentBody = new URLSearchParams(init?.body as string);
    expect(sentBody.get("token")).toBe("test_pushover_token");
    expect(sentBody.get("user")).toBe("test_pushover_user");
    expect(sentBody.get("title")).toBe("Kolna Agent");
    expect(sentBody.get("message")).toContain("+48123456789");
    expect(sentBody.get("message")).toContain("Guest asked about check-in");
  });

  it("extracts caller_id from dynamic_variables fallback", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response(JSON.stringify({ status: 1 }), { status: 200 })
    );

    const req = makeRequest("/pushover", {
      type: "post_call_transcription",
      data: {
        analysis: { transcript_summary: "Test summary" },
        agent_name: "Agent",
        conversation_id: "123",
        agent_id: "abc",
        conversation_initiation_client_data: {
          dynamic_variables: { system__caller_id: "+48999888777" },
        },
      },
    });

    const env = mockEnv({ ELEVENLABS_WEBHOOK_SECRET: "" });
    const res = await handlePushover(req, env);
    expect(res.status).toBe(200);

    const sentBody = new URLSearchParams(
      (vi.mocked(fetch).mock.calls[0][1] as RequestInit).body as string
    );
    expect(sentBody.get("message")).toContain("+48999888777");
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd workers && npx vitest run test/pushover.test.ts`
Expected: FAIL — `handlePushover` does not exist.

- [ ] **Step 3: Implement `workers/src/pushover.ts`**

```typescript
import type { Env } from "./types";

export async function handlePushover(
  request: Request,
  env: Env
): Promise<Response> {
  if (request.method !== "POST") {
    return Response.json({ error: "Invalid request" }, { status: 400 });
  }

  const rawBody = await request.text();
  const payload = JSON.parse(rawBody);

  // Validate HMAC signature if secret is configured
  if (env.ELEVENLABS_WEBHOOK_SECRET) {
    const allHeaders = Object.fromEntries(request.headers.entries());
    const signatureHeader =
      allHeaders["x-elevenlabs-signature"] ??
      allHeaders["elevenlabs-signature"] ??
      "";

    const parts = signatureHeader.split(",");
    let timestamp = "";
    let signature = "";

    for (const part of parts) {
      const eqIndex = part.indexOf("=");
      if (eqIndex === -1) continue;
      const key = part.slice(0, eqIndex).trim();
      const val = part.slice(eqIndex + 1).trim();
      if (key === "t") timestamp = val;
      if (key === "v1" || key === "v0") signature = val;
    }

    if (!timestamp || !signature) {
      return Response.json(
        { error: "Missing signature components" },
        { status: 401 }
      );
    }

    const signedPayload = `${timestamp}.${rawBody}`;
    const encoder = new TextEncoder();
    const key = await crypto.subtle.importKey(
      "raw",
      encoder.encode(env.ELEVENLABS_WEBHOOK_SECRET),
      { name: "HMAC", hash: "SHA-256" },
      false,
      ["sign"]
    );
    const sig = await crypto.subtle.sign(
      "HMAC",
      key,
      encoder.encode(signedPayload)
    );
    const expectedSignature = Array.from(new Uint8Array(sig))
      .map((b) => b.toString(16).padStart(2, "0"))
      .join("");

    if (expectedSignature !== signature) {
      return Response.json({ error: "Invalid signature" }, { status: 401 });
    }
  }

  // Check event type
  const type = payload.type ?? "";
  if (type !== "post_call_transcription") {
    return Response.json({ success: true, result: "ignored" });
  }

  // Extract data
  const analysis = payload.data?.analysis ?? {};
  const summary =
    analysis.transcript_summary ?? analysis.summary ?? "";
  const agentId = payload.data?.agent_id ?? "unknown";
  const agentName = payload.data?.agent_name ?? "ElevenLabs AI Agent";

  const callerId =
    payload.data?.metadata?.phone_call?.external_number ??
    payload.data?.conversation_initiation_client_data?.dynamic_variables
      ?.system__caller_id ??
    "Unknown";

  if (!summary) {
    return Response.json({ success: true, result: "no_summary" });
  }

  // Send to Pushover
  const message = `Caller: ${callerId}\n\nSummary:\n${summary}`;

  const pushoverBody = new URLSearchParams({
    token: env.PUSHOVER_API_TOKEN,
    user: env.PUSHOVER_USER_KEY,
    message,
    title: agentName,
    url: `googlechrome://elevenlabs.io/app/agents/agents/${agentId}?tab=analysis`,
    url_title: "Open in Chrome",
  });

  await fetch("https://api.pushover.net/1/messages.json", {
    method: "POST",
    body: pushoverBody.toString(),
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
  });

  return Response.json({ success: true });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd workers && npx vitest run test/pushover.test.ts`
Expected: All 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add workers/src/pushover.ts workers/test/pushover.test.ts
git commit -m "feat(workers): add pushover handler with tests"
```

---

### Task 5: The Keys API Client (TDD)

**Files:**
- Create: `workers/src/thekeys.ts`
- Create: `workers/test/thekeys.test.ts`

- [ ] **Step 1: Write the failing tests**

Create `workers/test/thekeys.test.ts`:

```typescript
import { describe, it, expect, vi, beforeEach } from "vitest";
import { TheKeysAPI } from "../src/thekeys";

describe("TheKeysAPI", () => {
  let api: TheKeysAPI;

  beforeEach(() => {
    vi.restoreAllMocks();
    api = new TheKeysAPI("user", "pass");
  });

  describe("login", () => {
    it("returns true and stores token on success", async () => {
      vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
        new Response(JSON.stringify({ token: "jwt-token-123" }), {
          status: 200,
        })
      );

      const result = await api.login();
      expect(result).toBe(true);

      const [url, init] = vi.mocked(fetch).mock.calls[0];
      expect(url).toBe("https://api.the-keys.fr/api/login_check");
      expect(init?.method).toBe("POST");
      const body = new URLSearchParams(init?.body as string);
      expect(body.get("_username")).toBe("user");
      expect(body.get("_password")).toBe("pass");
    });

    it("returns false on failed login", async () => {
      vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
        new Response("Unauthorized", { status: 401 })
      );

      const result = await api.login();
      expect(result).toBe(false);
    });
  });

  describe("listCodes", () => {
    it("returns codes array on success", async () => {
      const codes = [
        { id: 1, nom: "Guest", code: "1234", description: "Smoobu#100" },
      ];

      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(
          new Response(
            JSON.stringify({ data: { partages_accessoire: codes } }),
            { status: 200 }
          )
        );

      await api.login();
      const result = await api.listCodes(3733);

      expect(result).toEqual(codes);
      const [url] = vi.mocked(fetch).mock.calls[1];
      expect(url).toBe(
        "https://api.the-keys.fr/fr/api/v2/partage/all/serrure/3733?_format=json"
      );
    });

    it("returns empty array on failure", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(new Response("Error", { status: 500 }));

      await api.login();
      const result = await api.listCodes(3733);
      expect(result).toEqual([]);
    });
  });

  describe("createCode", () => {
    it("sends form-encoded POST and returns data on success", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(
          new Response(
            JSON.stringify({ status: 200, data: { id: 42 } }),
            { status: 200 }
          )
        );

      await api.login();
      const result = await api.createCode(
        3733,
        "OXe37UIa",
        "John Doe",
        "5678",
        "2026-05-01",
        "2026-05-03",
        "15", "0", "12", "0",
        "Smoobu#100"
      );

      expect(result).toEqual({ id: 42 });

      const [url, init] = vi.mocked(fetch).mock.calls[1];
      expect(url).toBe(
        "https://api.the-keys.fr/fr/api/v2/partage/create/3733/accessoire/OXe37UIa"
      );
      const body = new URLSearchParams(init?.body as string);
      expect(body.get("partage_accessoire[nom]")).toBe("John Doe");
      expect(body.get("partage_accessoire[code]")).toBe("5678");
      expect(body.get("partage_accessoire[date_debut]")).toBe("2026-05-01");
      expect(body.get("partage_accessoire[date_fin]")).toBe("2026-05-03");
      expect(body.get("partage_accessoire[description]")).toBe("Smoobu#100");
    });

    it("returns null on failure", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(new Response("Error", { status: 500 }));

      await api.login();
      const result = await api.createCode(
        3733, "OXe37UIa", "Guest", "1234",
        "2026-05-01", "2026-05-03",
        "15", "0", "12", "0", ""
      );
      expect(result).toBeNull();
    });
  });

  describe("updateCode", () => {
    it("sends update and returns true on success", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ status: 200 }), { status: 200 })
        );

      await api.login();
      const result = await api.updateCode(42, {
        name: "Jane Doe",
        code: "5678",
        dateStart: "2026-05-01",
        dateEnd: "2026-05-05",
        timeStartHour: "15",
        timeStartMin: "0",
        timeEndHour: "12",
        timeEndMin: "0",
        active: true,
        description: "Smoobu#100",
      });

      expect(result).toBe(true);
      const [url] = vi.mocked(fetch).mock.calls[1];
      expect(url).toBe(
        "https://api.the-keys.fr/fr/api/v2/partage/accessoire/update/42"
      );
    });

    it("returns false on failure", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(new Response("Error", { status: 500 }));

      await api.login();
      const result = await api.updateCode(42, { active: true });
      expect(result).toBe(false);
    });
  });

  describe("deleteCode", () => {
    it("returns true on success", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ status: 200 }), { status: 200 })
        );

      await api.login();
      const result = await api.deleteCode(42);
      expect(result).toBe(true);

      const [url, init] = vi.mocked(fetch).mock.calls[1];
      expect(url).toBe(
        "https://api.the-keys.fr/fr/api/v2/partage/accessoire/delete/42"
      );
      expect(init?.method).toBe("POST");
    });

    it("returns false on failure", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(new Response("Error", { status: 500 }));

      await api.login();
      const result = await api.deleteCode(42);
      expect(result).toBe(false);
    });
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd workers && npx vitest run test/thekeys.test.ts`
Expected: FAIL — `TheKeysAPI` does not exist.

- [ ] **Step 3: Implement `workers/src/thekeys.ts`**

```typescript
import type { TheKeysCode } from "./types";

export interface UpdateCodeOptions {
  name?: string;
  code?: string;
  dateStart?: string;
  dateEnd?: string;
  timeStartHour?: string;
  timeStartMin?: string;
  timeEndHour?: string;
  timeEndMin?: string;
  active?: boolean;
  description?: string;
}

export class TheKeysAPI {
  private username: string;
  private password: string;
  private baseUrl: string;
  private token: string | null = null;

  constructor(
    username: string,
    password: string,
    baseUrl = "https://api.the-keys.fr"
  ) {
    this.username = username;
    this.password = password;
    this.baseUrl = baseUrl;
  }

  async login(): Promise<boolean> {
    const res = await fetch(`${this.baseUrl}/api/login_check`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        _username: this.username,
        _password: this.password,
      }).toString(),
    });

    if (res.status === 200) {
      const data = (await res.json()) as { token?: string };
      if (data.token) {
        this.token = data.token;
        return true;
      }
    }
    return false;
  }

  async listCodes(lockId: number): Promise<TheKeysCode[]> {
    const res = await fetch(
      `${this.baseUrl}/fr/api/v2/partage/all/serrure/${lockId}?_format=json`,
      {
        headers: { Authorization: `Bearer ${this.token}` },
      }
    );

    if (res.status === 200) {
      const data = (await res.json()) as {
        data?: { partages_accessoire?: TheKeysCode[] };
      };
      return data.data?.partages_accessoire ?? [];
    }
    return [];
  }

  async createCode(
    lockId: number,
    idAccessoire: string,
    name: string,
    code: string,
    dateStart: string,
    dateEnd: string,
    timeStartHour: string,
    timeStartMin: string,
    timeEndHour: string,
    timeEndMin: string,
    description: string
  ): Promise<Record<string, unknown> | null> {
    const body = new URLSearchParams({
      "partage_accessoire[nom]": name,
      "partage_accessoire[actif]": "1",
      "partage_accessoire[date_debut]": dateStart,
      "partage_accessoire[date_fin]": dateEnd,
      "partage_accessoire[heure_debut][hour]": timeStartHour,
      "partage_accessoire[heure_debut][minute]": timeStartMin,
      "partage_accessoire[heure_fin][hour]": timeEndHour,
      "partage_accessoire[heure_fin][minute]": timeEndMin,
      "partage_accessoire[code]": code,
      "partage_accessoire[description]": description,
    });

    const res = await fetch(
      `${this.baseUrl}/fr/api/v2/partage/create/${lockId}/accessoire/${idAccessoire}`,
      {
        method: "POST",
        headers: {
          Authorization: `Bearer ${this.token}`,
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: body.toString(),
      }
    );

    if (res.status === 200) {
      const result = (await res.json()) as {
        status?: number;
        data?: Record<string, unknown>;
      };
      if (result.status === 200) {
        return result.data ?? null;
      }
    }
    return null;
  }

  async updateCode(
    codeId: number,
    options: UpdateCodeOptions
  ): Promise<boolean> {
    const params = new URLSearchParams({
      "partage_accessoire[actif]": options.active !== false ? "1" : "0",
    });

    if (options.name != null)
      params.set("partage_accessoire[nom]", options.name);
    if (options.code != null)
      params.set("partage_accessoire[code]", options.code);
    if (options.dateStart != null)
      params.set("partage_accessoire[date_debut]", options.dateStart);
    if (options.dateEnd != null)
      params.set("partage_accessoire[date_fin]", options.dateEnd);
    if (options.timeStartHour != null) {
      params.set(
        "partage_accessoire[heure_debut][hour]",
        options.timeStartHour
      );
      params.set(
        "partage_accessoire[heure_debut][minute]",
        options.timeStartMin ?? "0"
      );
    }
    if (options.timeEndHour != null) {
      params.set(
        "partage_accessoire[heure_fin][hour]",
        options.timeEndHour
      );
      params.set(
        "partage_accessoire[heure_fin][minute]",
        options.timeEndMin ?? "0"
      );
    }
    if (options.description != null)
      params.set("partage_accessoire[description]", options.description);

    const res = await fetch(
      `${this.baseUrl}/fr/api/v2/partage/accessoire/update/${codeId}`,
      {
        method: "POST",
        headers: {
          Authorization: `Bearer ${this.token}`,
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: params.toString(),
      }
    );

    if (res.status === 200) {
      const result = (await res.json()) as { status?: number };
      return result.status === 200;
    }
    return false;
  }

  async deleteCode(codeId: number): Promise<boolean> {
    const res = await fetch(
      `${this.baseUrl}/fr/api/v2/partage/accessoire/delete/${codeId}`,
      {
        method: "POST",
        headers: { Authorization: `Bearer ${this.token}` },
      }
    );

    if (res.status === 200) {
      const result = (await res.json()) as { status?: number };
      return result.status === 200;
    }
    return false;
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd workers && npx vitest run test/thekeys.test.ts`
Expected: All 8 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add workers/src/thekeys.ts workers/test/thekeys.test.ts
git commit -m "feat(workers): add The Keys API client with tests"
```

---

### Task 6: SMS Module (TDD)

**Files:**
- Create: `workers/src/sms.ts`
- Create: `workers/test/sms.test.ts`

- [ ] **Step 1: Write the failing tests**

Create `workers/test/sms.test.ts`:

```typescript
import { describe, it, expect, vi, beforeEach } from "vitest";
import {
  sendViaSerwersms,
  sendViaBudgetSMS,
  sendSMSNotification,
} from "../src/sms";
import { mockEnv } from "./helpers";

describe("sendViaSerwersms", () => {
  beforeEach(() => vi.restoreAllMocks());

  it("sends POST with Bearer auth and returns true on success", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true }), { status: 200 })
    );

    const result = await sendViaSerwersms(
      "+48123456789",
      "Your code is 281234",
      "en",
      "api-token-123"
    );
    expect(result).toBe(true);

    const [url, init] = vi.mocked(fetch).mock.calls[0];
    expect(url).toBe("https://api2.serwersms.pl/messages/send_sms");
    const headers = init?.headers as Record<string, string>;
    expect(headers["Authorization"]).toBe("Bearer api-token-123");

    const body = new URLSearchParams(init?.body as string);
    expect(body.get("phone")).toBe("+48123456789");
    expect(body.get("sender")).toBe("KOLNA");
    expect(body.has("utf")).toBe(false);
  });

  it("sets utf=true for Russian", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true }), { status: 200 })
    );

    await sendViaSerwersms("+48123", "msg", "ru", "token");
    const body = new URLSearchParams(
      (vi.mocked(fetch).mock.calls[0][1] as RequestInit).body as string
    );
    expect(body.get("utf")).toBe("true");
  });

  it("sets utf=true for Ukrainian", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true }), { status: 200 })
    );

    await sendViaSerwersms("+48123", "msg", "ua", "token");
    const body = new URLSearchParams(
      (vi.mocked(fetch).mock.calls[0][1] as RequestInit).body as string
    );
    expect(body.get("utf")).toBe("true");
  });

  it("returns false on failure", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response(JSON.stringify({ error: "bad" }), { status: 400 })
    );

    const result = await sendViaSerwersms("+48123", "msg", "en", "token");
    expect(result).toBe(false);
  });
});

describe("sendViaBudgetSMS", () => {
  beforeEach(() => vi.restoreAllMocks());

  it("sends GET with query params and strips leading +", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response("OK 12345", { status: 200 })
    );

    const result = await sendViaBudgetSMS("+48123456789", "Hello", {
      username: "u",
      userid: "uid",
      handle: "h",
      sender: "KOLNA",
    });
    expect(result).toBe(true);

    const url = new URL(vi.mocked(fetch).mock.calls[0][0] as string);
    expect(url.searchParams.get("to")).toBe("48123456789");
    expect(url.searchParams.get("username")).toBe("u");
    expect(url.searchParams.get("msg")).toBe("Hello");
  });

  it("strips leading 00 from phone numbers", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response("OK 12345", { status: 200 })
    );

    await sendViaBudgetSMS("0048123456789", "Hello", {
      username: "u",
      userid: "uid",
      handle: "h",
      sender: "KOLNA",
    });

    const url = new URL(vi.mocked(fetch).mock.calls[0][0] as string);
    expect(url.searchParams.get("to")).toBe("48123456789");
  });

  it("returns false on failure", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response("ERR", { status: 200 })
    );

    const result = await sendViaBudgetSMS("+48123", "msg", {
      username: "u",
      userid: "uid",
      handle: "h",
      sender: "KOLNA",
    });
    expect(result).toBe(false);
  });
});

describe("sendSMSNotification", () => {
  beforeEach(() => vi.restoreAllMocks());

  it("returns false when guest has no phone", async () => {
    const result = await sendSMSNotification(
      { id: 1, "guest-name": "Guest", arrival: "2026-05-01", departure: "2026-05-03" },
      "281234",
      "Apt 1",
      "new",
      mockEnv()
    );
    expect(result).toBe(false);
  });

  it("cleans phone number and sends via configured provider", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true }), { status: 200 })
    );

    const result = await sendSMSNotification(
      {
        id: 1,
        "guest-name": "John",
        arrival: "2026-05-01",
        departure: "2026-05-03",
        phone: "+48 (123) 456-789",
        language: "en",
      },
      "281234",
      "Apt 1",
      "new",
      mockEnv()
    );
    expect(result).toBe(true);

    const body = new URLSearchParams(
      (vi.mocked(fetch).mock.calls[0][1] as RequestInit).body as string
    );
    expect(body.get("phone")).toBe("+48123456789");
  });

  it("transliterates Polish diacritics in SMS", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true }), { status: 200 })
    );

    await sendSMSNotification(
      {
        id: 1,
        "guest-name": "Jan",
        arrival: "2026-05-01",
        departure: "2026-05-03",
        phone: "+48123456789",
        language: "pl",
      },
      "281234",
      "Apt 1",
      "new",
      mockEnv()
    );

    const body = new URLSearchParams(
      (vi.mocked(fetch).mock.calls[0][1] as RequestInit).body as string
    );
    const text = body.get("text") ?? "";
    // Polish template has diacritics like ę, ą etc. — they should be transliterated
    expect(text).not.toMatch(/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/);
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd workers && npx vitest run test/sms.test.ts`
Expected: FAIL — module `../src/sms` does not exist.

- [ ] **Step 3: Implement `workers/src/sms.ts`**

```typescript
import type { Env, SmoobuBooking } from "./types";
import { loadLanguage } from "./languages";

const POLISH_TRANSLITERATION: Record<string, string> = {
  "\u0105": "a", "\u0107": "c", "\u0119": "e", "\u0142": "l",
  "\u0144": "n", "\u00F3": "o", "\u015B": "s", "\u017A": "z",
  "\u017C": "z", "\u0104": "A", "\u0106": "C", "\u0118": "E",
  "\u0141": "L", "\u0143": "N", "\u00D3": "O", "\u015A": "S",
  "\u0179": "Z", "\u017B": "Z",
};

function transliteratePl(text: string): string {
  let result = text;
  for (const [from, to] of Object.entries(POLISH_TRANSLITERATION)) {
    result = result.replaceAll(from, to);
  }
  return result;
}

function cleanPhone(phone: string): string {
  return phone.replace(/[\s()\-]/g, "");
}

export async function sendViaSerwersms(
  recipient: string,
  message: string,
  language: string,
  apiToken: string
): Promise<boolean> {
  const params = new URLSearchParams({
    phone: recipient,
    text: message,
    sender: "KOLNA",
  });

  if (language === "ru" || language === "ua") {
    params.set("utf", "true");
  }

  const res = await fetch("https://api2.serwersms.pl/messages/send_sms", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${apiToken}`,
      Accept: "application/json",
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: params.toString(),
  });

  if (res.status === 200) {
    const data = (await res.json()) as { success?: boolean };
    return !!data.success;
  }
  return false;
}

export interface BudgetSMSConfig {
  username: string;
  userid: string;
  handle: string;
  sender: string;
}

export async function sendViaBudgetSMS(
  recipient: string,
  message: string,
  config: BudgetSMSConfig
): Promise<boolean> {
  let to = recipient.replace(/^\+/, "");
  if (to.startsWith("00")) {
    to = to.slice(2);
  }

  const params = new URLSearchParams({
    username: config.username,
    userid: config.userid,
    handle: config.handle,
    msg: message,
    from: config.sender,
    to,
  });

  const res = await fetch(
    `https://api.budgetsms.net/sendsms/?${params.toString()}`
  );

  if (res.status === 200) {
    const text = await res.text();
    return text.startsWith("OK");
  }
  return false;
}

export async function sendSMSNotification(
  booking: SmoobuBooking,
  fullPin: string,
  apartmentName: string,
  action: string,
  env: Env
): Promise<boolean> {
  const guestPhone = cleanPhone(booking.phone ?? "");
  if (!guestPhone) {
    return false;
  }

  const language = (booking.language ?? "en").toLowerCase();

  let message: string;
  if (action === "cancel") {
    message = `CANCELLED: Kolna Apartments reservation ${apartmentName} (${booking.arrival} to ${booking.departure}) has been cancelled.`;
  } else {
    const lang = loadLanguage(
      language,
      booking["guest-name"] ?? "Guest",
      fullPin,
      apartmentName,
      booking.arrival ?? "",
      booking.departure ?? ""
    );
    message = lang.sms_message;
    if (language === "pl") {
      message = transliteratePl(message);
    }
  }

  if (env.SMS_PROVIDER === "budgetsms") {
    return sendViaBudgetSMS(guestPhone, message, {
      username: env.BUDGETSMS_USERNAME,
      userid: env.BUDGETSMS_USERID,
      handle: env.BUDGETSMS_HANDLE,
      sender: "KOLNA",
    });
  }

  return sendViaSerwersms(guestPhone, message, language, env.SERWERSMS_API_TOKEN);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd workers && npx vitest run test/sms.test.ts`
Expected: All 9 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add workers/src/sms.ts workers/test/sms.test.ts
git commit -m "feat(workers): add SMS module with SerwerSMS and BudgetSMS support"
```

---

### Task 7: Smoobu Webhook Handler (TDD)

**Files:**
- Create: `workers/src/smoobu.ts`
- Create: `workers/test/smoobu.test.ts`

- [ ] **Step 1: Write the failing tests**

Create `workers/test/smoobu.test.ts`:

```typescript
import { describe, it, expect, vi, beforeEach } from "vitest";
import { handleSmoobuWebhook } from "../src/smoobu";
import { mockEnv, makeRequest } from "./helpers";

// Mock fetch to handle TheKeysAPI login + all subsequent calls
function mockTheKeysLogin() {
  return new Response(JSON.stringify({ token: "jwt-token" }), { status: 200 });
}

function mockListCodesEmpty() {
  return new Response(
    JSON.stringify({ data: { partages_accessoire: [] } }),
    { status: 200 }
  );
}

function mockListCodesWithCode(bookingId: number, code = "1234") {
  return new Response(
    JSON.stringify({
      data: {
        partages_accessoire: [
          {
            id: 42,
            nom: "Guest",
            code,
            description: `Smoobu#${bookingId}`,
            date_debut: "2026-05-01",
            date_fin: "2026-05-03",
          },
        ],
      },
    }),
    { status: 200 }
  );
}

function mockCreateCodeSuccess() {
  return new Response(
    JSON.stringify({ status: 200, data: { id: 99 } }),
    { status: 200 }
  );
}

function mockUpdateCodeSuccess() {
  return new Response(JSON.stringify({ status: 200 }), { status: 200 });
}

function mockDeleteCodeSuccess() {
  return new Response(JSON.stringify({ status: 200 }), { status: 200 });
}

function mockSmoobuMessageSuccess() {
  return new Response("", { status: 201 });
}

function mockSmsSuccess() {
  return new Response(JSON.stringify({ success: true }), { status: 200 });
}

describe("handleSmoobuWebhook", () => {
  beforeEach(() => vi.restoreAllMocks());

  it("returns 400 for non-POST", async () => {
    const req = new Request("https://test.workers.dev/webhook", {
      method: "GET",
    });
    const res = await handleSmoobuWebhook(req, mockEnv());
    expect(res.status).toBe(400);
  });

  it("returns 400 for invalid JSON", async () => {
    const req = new Request("https://test.workers.dev/webhook", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: "not json",
    });
    const res = await handleSmoobuWebhook(req, mockEnv());
    expect(res.status).toBe(400);
  });

  it("ignores non-reservation actions", async () => {
    const req = makeRequest("/webhook", { action: "newMessage" });
    const res = await handleSmoobuWebhook(req, mockEnv());
    expect(res.status).toBe(200);
    const body = await res.json() as { result: string };
    expect(body.result).toBe("ignored");
  });

  it("handles newReservation: creates code and sends notifications", async () => {
    const fetchSpy = vi.spyOn(globalThis, "fetch")
      .mockResolvedValueOnce(mockTheKeysLogin())       // login
      .mockResolvedValueOnce(mockListCodesEmpty())      // listCodes (check existing)
      .mockResolvedValueOnce(mockCreateCodeSuccess())   // createCode
      .mockResolvedValueOnce(mockSmsSuccess())          // SMS
      .mockResolvedValueOnce(mockSmoobuMessageSuccess()); // guest message

    const req = makeRequest("/webhook", {
      action: "newReservation",
      data: {
        id: 500,
        "guest-name": "Alice Smith",
        arrival: "2026-05-01",
        departure: "2026-05-03",
        apartment: { id: 123456, name: "Apt 5" },
        language: "en",
        phone: "+48111222333",
      },
    });

    const res = await handleSmoobuWebhook(req, mockEnv());
    expect(res.status).toBe(200);
    const body = await res.json() as { success: boolean; result: { status: string } };
    expect(body.success).toBe(true);
    expect(body.result.status).toBe("created");

    // Verify createCode was called (3rd fetch call)
    const createUrl = fetchSpy.mock.calls[2][0] as string;
    expect(createUrl).toContain("/partage/create/3733/accessoire/OXe37UIa");
  });

  it("handles updateReservation: updates existing code dates", async () => {
    vi.spyOn(globalThis, "fetch")
      .mockResolvedValueOnce(mockTheKeysLogin())
      .mockResolvedValueOnce(mockListCodesWithCode(600, "5678")) // found on first lock
      .mockResolvedValueOnce(mockUpdateCodeSuccess())
      .mockResolvedValueOnce(mockSmsSuccess())
      .mockResolvedValueOnce(mockSmoobuMessageSuccess());

    const req = makeRequest("/webhook", {
      action: "updateReservation",
      data: {
        id: 600,
        "guest-name": "Bob Jones",
        arrival: "2026-06-01",
        departure: "2026-06-05",
        apartment: { id: 123456, name: "Apt 5" },
        language: "en",
        phone: "+48111222333",
      },
    });

    const res = await handleSmoobuWebhook(req, mockEnv());
    expect(res.status).toBe(200);
    const body = await res.json() as { success: boolean; result: { status: string } };
    expect(body.result.status).toBe("updated");
  });

  it("handles updateReservation: creates code when not found", async () => {
    vi.spyOn(globalThis, "fetch")
      .mockResolvedValueOnce(mockTheKeysLogin())
      .mockResolvedValueOnce(mockListCodesEmpty())      // not found on lock 3733
      .mockResolvedValueOnce(mockListCodesEmpty())      // listCodes for new creation check
      .mockResolvedValueOnce(mockCreateCodeSuccess())
      .mockResolvedValueOnce(mockSmsSuccess())
      .mockResolvedValueOnce(mockSmoobuMessageSuccess());

    const req = makeRequest("/webhook", {
      action: "updateReservation",
      data: {
        id: 700,
        "guest-name": "Charlie",
        arrival: "2026-07-01",
        departure: "2026-07-03",
        apartment: { id: 123456, name: "Apt 5" },
        language: "en",
        phone: "+48111222333",
      },
    });

    const res = await handleSmoobuWebhook(req, mockEnv());
    expect(res.status).toBe(200);
    const body = await res.json() as { success: boolean; result: { status: string } };
    expect(body.result.status).toBe("created");
  });

  it("handles cancelReservation: deletes existing code", async () => {
    vi.spyOn(globalThis, "fetch")
      .mockResolvedValueOnce(mockTheKeysLogin())
      .mockResolvedValueOnce(mockListCodesWithCode(800))
      .mockResolvedValueOnce(mockDeleteCodeSuccess());

    const req = makeRequest("/webhook", {
      action: "cancelReservation",
      data: {
        id: 800,
        "guest-name": "Dave",
        arrival: "2026-08-01",
        departure: "2026-08-03",
        apartment: { id: 123456 },
      },
    });

    const res = await handleSmoobuWebhook(req, mockEnv());
    expect(res.status).toBe(200);
    const body = await res.json() as { success: boolean; result: { status: string } };
    expect(body.result.status).toBe("deleted");
  });

  it("handles cancelReservation: returns not_found when no code exists", async () => {
    vi.spyOn(globalThis, "fetch")
      .mockResolvedValueOnce(mockTheKeysLogin())
      .mockResolvedValueOnce(mockListCodesEmpty());

    const req = makeRequest("/webhook", {
      action: "cancelReservation",
      data: {
        id: 900,
        "guest-name": "Eve",
        apartment: { id: 123456 },
      },
    });

    const res = await handleSmoobuWebhook(req, mockEnv());
    expect(res.status).toBe(200);
    const body = await res.json() as { success: boolean; result: { status: string } };
    expect(body.result.status).toBe("not_found");
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd workers && npx vitest run test/smoobu.test.ts`
Expected: FAIL — `handleSmoobuWebhook` does not exist.

- [ ] **Step 3: Implement `workers/src/smoobu.ts`**

```typescript
import type { Env, SmoobuBooking, SmoobuWebhookPayload, DefaultTimes, TheKeysCode } from "./types";
import { TheKeysAPI } from "./thekeys";
import { sendSMSNotification } from "./sms";
import { loadLanguage } from "./languages";

const ACTION_MAP: Record<string, string> = {
  newReservation: "reservation.new",
  cancelReservation: "reservation.cancelled",
  updateReservation: "reservation.updated",
  newMessage: "ignore",
  updateRates: "ignore",
  newTimelineEvent: "ignore",
  deleteTimelineEvent: "ignore",
};

function generatePIN(length: number): string {
  let pin = "";
  for (let i = 0; i < length; i++) {
    pin += Math.floor(Math.random() * 10).toString();
  }
  return pin;
}

function parseJsonVar<T>(value: string): T {
  return JSON.parse(value) as T;
}

async function findExistingCode(
  api: TheKeysAPI,
  lockId: number,
  bookingId: number
): Promise<TheKeysCode | null> {
  const codes = await api.listCodes(lockId);
  for (const code of codes) {
    if ((code.description ?? "").includes(`Smoobu#${bookingId}`)) {
      return code;
    }
  }
  return null;
}

async function sendGuestMessage(
  booking: SmoobuBooking,
  fullPin: string,
  apartmentName: string,
  smoobuApiKey: string
): Promise<boolean> {
  const language = (booking.language ?? "en").toLowerCase();
  const lang = loadLanguage(
    language,
    booking["guest-name"] ?? "Guest",
    fullPin,
    apartmentName,
    booking.arrival ?? "",
    booking.departure ?? ""
  );

  const res = await fetch(
    `https://login.smoobu.com/api/reservations/${booking.id}/messages/send-message-to-guest`,
    {
      method: "POST",
      headers: {
        "Api-Key": smoobuApiKey,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        subject: lang.subject,
        messageBody: lang.message,
      }),
    }
  );

  return res.status === 200 || res.status === 201;
}

async function handleNewReservation(
  booking: SmoobuBooking,
  env: Env,
  api: TheKeysAPI
): Promise<Record<string, unknown>> {
  const apartmentId = String(booking.apartment?.id ?? "");
  const apartmentLocks = parseJsonVar<Record<string, number>>(env.APARTMENT_LOCKS);
  const lockId = apartmentLocks[apartmentId];
  if (!lockId) {
    return { status: "skipped", message: "No lock mapping" };
  }

  const lockAccessoires = parseJsonVar<Record<string, string>>(env.LOCK_ACCESSOIRES);
  const idAccessoire = lockAccessoires[String(lockId)];
  if (!idAccessoire) {
    return { status: "skipped", message: "No accessoire mapping" };
  }

  const existing = await findExistingCode(api, lockId, booking.id);
  if (existing) {
    return { status: "exists", message: "Code already exists" };
  }

  const pinLength = parseInt(env.PIN_LENGTH, 10) || 4;
  const pinCode = generatePIN(pinLength);
  const prefixes = parseJsonVar<Record<string, string>>(env.DIGICODE_PREFIXES);
  const prefix = prefixes[String(lockId)] ?? "";
  const fullPin = prefix + pinCode;

  const guestName = booking["guest-name"] ?? "Guest";
  const arrival = booking.arrival;
  const departure = booking.departure;
  if (!arrival || !departure) {
    return { status: "error", message: "Missing arrival or departure dates" };
  }

  const times = parseJsonVar<DefaultTimes>(env.DEFAULT_TIMES);
  const result = await api.createCode(
    lockId,
    idAccessoire,
    guestName,
    pinCode,
    arrival,
    departure,
    times.check_in_hour,
    times.check_in_minute,
    times.check_out_hour,
    times.check_out_minute,
    `Smoobu#${booking.id}`
  );

  if (!result) {
    return { status: "error", message: "Failed to create code" };
  }

  const apartmentName = booking.apartment?.name ?? "your apartment";

  // Send SMS (fire-and-forget style, don't fail the webhook)
  await sendSMSNotification(booking, fullPin, apartmentName, "new", env);

  // Send guest message via Smoobu if arrival is today or future
  const today = new Date().toISOString().slice(0, 10);
  if (today <= arrival) {
    await sendGuestMessage(booking, fullPin, apartmentName, env.SMOOBU_API_KEY);
  }

  return { status: "created", code_id: result.id, pin: pinCode };
}

async function handleUpdatedReservation(
  booking: SmoobuBooking,
  env: Env,
  api: TheKeysAPI
): Promise<Record<string, unknown>> {
  const apartmentId = String(booking.apartment?.id ?? "");
  const apartmentLocks = parseJsonVar<Record<string, number>>(env.APARTMENT_LOCKS);
  const lockId = apartmentLocks[apartmentId];
  if (!lockId) {
    return { status: "skipped", message: "No lock mapping" };
  }

  const lockAccessoires = parseJsonVar<Record<string, string>>(env.LOCK_ACCESSOIRES);

  // Search across ALL locks
  let existingCode: TheKeysCode | null = null;
  let existingLockId: number | null = null;
  for (const searchLockId of Object.keys(lockAccessoires).map(Number)) {
    const code = await findExistingCode(api, searchLockId, booking.id);
    if (code) {
      existingCode = code;
      existingLockId = searchLockId;
      break;
    }
  }

  // Apartment changed — delete old, create new
  if (existingCode && existingLockId !== lockId) {
    await api.deleteCode(existingCode.id);
    return handleNewReservation(booking, env, api);
  }

  // Not found — create new
  if (!existingCode) {
    return handleNewReservation(booking, env, api);
  }

  // Update existing code
  const arrival = booking.arrival;
  const departure = booking.departure;
  const times = parseJsonVar<DefaultTimes>(env.DEFAULT_TIMES);

  const success = await api.updateCode(existingCode.id, {
    name: booking["guest-name"] ?? "Guest",
    code: existingCode.code,
    dateStart: arrival,
    dateEnd: departure,
    timeStartHour: times.check_in_hour,
    timeStartMin: times.check_in_minute,
    timeEndHour: times.check_out_hour,
    timeEndMin: times.check_out_minute,
    active: true,
    description: `Smoobu#${booking.id}`,
  });

  if (!success) {
    return { status: "error", message: "Failed to update code" };
  }

  const apartmentName = booking.apartment?.name ?? "your apartment";
  const prefixes = parseJsonVar<Record<string, string>>(env.DIGICODE_PREFIXES);
  const prefix = prefixes[String(lockId)] ?? "";
  const fullPin = prefix + existingCode.code;

  await sendSMSNotification(booking, fullPin, apartmentName, "update", env);
  await sendGuestMessage(booking, fullPin, apartmentName, env.SMOOBU_API_KEY);

  return { status: "updated", code_id: existingCode.id };
}

async function handleCancelledReservation(
  booking: SmoobuBooking,
  env: Env,
  api: TheKeysAPI
): Promise<Record<string, unknown>> {
  const lockAccessoires = parseJsonVar<Record<string, string>>(env.LOCK_ACCESSOIRES);

  let existingCode: TheKeysCode | null = null;
  for (const searchLockId of Object.keys(lockAccessoires).map(Number)) {
    const code = await findExistingCode(api, searchLockId, booking.id);
    if (code) {
      existingCode = code;
      break;
    }
  }

  if (!existingCode) {
    return { status: "not_found", message: "Code not found" };
  }

  const success = await api.deleteCode(existingCode.id);
  if (!success) {
    return { status: "error", message: "Failed to delete code" };
  }

  return { status: "deleted", code_id: existingCode.id };
}

export async function handleSmoobuWebhook(
  request: Request,
  env: Env
): Promise<Response> {
  if (request.method !== "POST") {
    return Response.json({ error: "Method not allowed" }, { status: 400 });
  }

  let payload: SmoobuWebhookPayload;
  try {
    payload = (await request.json()) as SmoobuWebhookPayload;
  } catch {
    return Response.json({ error: "Invalid JSON payload" }, { status: 400 });
  }

  const action = payload.action ?? "";
  const eventType = ACTION_MAP[action] ?? "ignore";

  if (eventType === "ignore") {
    return Response.json({ success: true, result: "ignored", action });
  }

  const bookingData: SmoobuBooking =
    payload.data ?? payload.booking ?? (payload as unknown as SmoobuBooking);

  // Handle cancellation type field
  if (bookingData.type === "cancellation") {
    const api = new TheKeysAPI(env.THEKEYS_USERNAME, env.THEKEYS_PASSWORD);
    if (!(await api.login())) {
      return Response.json({
        success: false,
        error: "Failed to login to The Keys API",
      });
    }
    const result = await handleCancelledReservation(bookingData, env, api);
    return Response.json({ success: true, result, event: "reservation.cancelled", booking_id: bookingData.id });
  }

  // Optional IP whitelist
  if (env.IP_WHITELIST) {
    const whitelist = parseJsonVar<string[]>(env.IP_WHITELIST);
    const clientIp = request.headers.get("cf-connecting-ip") ?? "";
    if (whitelist.length > 0 && !whitelist.includes(clientIp)) {
      return Response.json({ error: "IP not whitelisted" }, { status: 403 });
    }
  }

  // Optional HMAC signature check
  if (env.WEBHOOK_SECRET) {
    const rawBody = JSON.stringify(payload);
    const signature = request.headers.get("x-smoobu-signature") ?? "";
    const encoder = new TextEncoder();
    const key = await crypto.subtle.importKey(
      "raw",
      encoder.encode(env.WEBHOOK_SECRET),
      { name: "HMAC", hash: "SHA-256" },
      false,
      ["sign"]
    );
    const sig = await crypto.subtle.sign("HMAC", key, encoder.encode(rawBody));
    const expected = Array.from(new Uint8Array(sig))
      .map((b) => b.toString(16).padStart(2, "0"))
      .join("");
    if (expected !== signature) {
      return Response.json({ error: "Invalid signature" }, { status: 401 });
    }
  }

  const api = new TheKeysAPI(env.THEKEYS_USERNAME, env.THEKEYS_PASSWORD);
  if (!(await api.login())) {
    return Response.json({
      success: false,
      error: "Failed to login to The Keys API",
    });
  }

  let result: Record<string, unknown>;
  try {
    switch (eventType) {
      case "reservation.new":
        result = await handleNewReservation(bookingData, env, api);
        break;
      case "reservation.updated":
        result = await handleUpdatedReservation(bookingData, env, api);
        break;
      case "reservation.cancelled":
        result = await handleCancelledReservation(bookingData, env, api);
        break;
      default:
        result = { status: "ignored", message: "Unknown event type" };
    }
  } catch (e) {
    const message = e instanceof Error ? e.message : "Unknown error";
    console.error(`Error processing webhook: ${message}`);
    return Response.json({ success: false, error: message });
  }

  return Response.json({
    success: true,
    result,
    event: eventType,
    booking_id: bookingData.id ?? null,
  });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd workers && npx vitest run test/smoobu.test.ts`
Expected: All 7 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add workers/src/smoobu.ts workers/test/smoobu.test.ts
git commit -m "feat(workers): add Smoobu webhook handler with tests"
```

---

### Task 8: Router and Integration (TDD)

**Files:**
- Modify: `workers/src/index.ts` (replace placeholder)
- Create: `workers/test/index.test.ts`

- [ ] **Step 1: Write the failing tests**

Create `workers/test/index.test.ts`:

```typescript
import { describe, it, expect, vi, beforeEach } from "vitest";
import worker from "../src/index";
import { mockEnv, makeRequest } from "./helpers";

describe("router", () => {
  beforeEach(() => vi.restoreAllMocks());

  it("returns 404 for unknown paths", async () => {
    const req = new Request("https://test.workers.dev/unknown", {
      method: "POST",
    });
    const res = await worker.fetch(req, mockEnv());
    expect(res.status).toBe(404);
  });

  it("returns 404 for GET on root", async () => {
    const req = new Request("https://test.workers.dev/", { method: "GET" });
    const res = await worker.fetch(req, mockEnv());
    expect(res.status).toBe(404);
  });

  it("routes POST /pushover to pushover handler", async () => {
    const req = makeRequest("/pushover", {
      type: "conversation_started",
      data: {},
    });
    const env = mockEnv({ ELEVENLABS_WEBHOOK_SECRET: "" });
    const res = await worker.fetch(req, env);
    expect(res.status).toBe(200);
    const body = await res.json() as { result: string };
    expect(body.result).toBe("ignored");
  });

  it("routes POST /webhook to smoobu handler for ignored actions", async () => {
    const req = makeRequest("/webhook", { action: "newMessage" });
    const res = await worker.fetch(req, mockEnv());
    expect(res.status).toBe(200);
    const body = await res.json() as { result: string };
    expect(body.result).toBe("ignored");
  });

  it("catches errors and returns 200 with error message", async () => {
    // Send invalid reservation to trigger login failure
    vi.spyOn(globalThis, "fetch").mockRejectedValueOnce(
      new Error("Network error")
    );

    const req = makeRequest("/webhook", {
      action: "newReservation",
      data: {
        id: 1,
        "guest-name": "Test",
        arrival: "2026-05-01",
        departure: "2026-05-03",
        apartment: { id: 123456 },
      },
    });
    const res = await worker.fetch(req, mockEnv());
    // Should return 200 (not 500) to prevent Smoobu retries
    expect(res.status).toBe(200);
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd workers && npx vitest run test/index.test.ts`
Expected: FAIL — placeholder `index.ts` doesn't route.

- [ ] **Step 3: Implement `workers/src/index.ts`**

Replace the placeholder with the full router:

```typescript
import type { Env } from "./types";
import { handlePushover } from "./pushover";
import { handleSmoobuWebhook } from "./smoobu";

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const url = new URL(request.url);
    const path = url.pathname;

    try {
      switch (path) {
        case "/webhook":
          return await handleSmoobuWebhook(request, env);
        case "/pushover":
          return await handlePushover(request, env);
        default:
          return Response.json({ error: "Not found" }, { status: 404 });
      }
    } catch (e) {
      const message = e instanceof Error ? e.message : "Unknown error";
      console.error(`Unhandled error: ${message}`);
      // Return 200 to prevent webhook retries
      return Response.json({ success: false, error: message });
    }
  },
};
```

- [ ] **Step 4: Run ALL tests to verify everything passes**

Run: `cd workers && npx vitest run`
Expected: All tests across all files PASS.

- [ ] **Step 5: Run TypeScript type checking**

Run: `cd workers && npx tsc --noEmit`
Expected: No type errors.

- [ ] **Step 6: Commit**

```bash
git add workers/src/index.ts workers/test/index.test.ts
git commit -m "feat(workers): add router and integration tests"
```

---

### Task 9: Local Smoke Test with Wrangler

**Files:** None (verification only)

- [ ] **Step 1: Start wrangler dev server**

Run: `cd workers && npx wrangler dev --port 8787`
Expected: Server starts on `http://localhost:8787`.

- [ ] **Step 2: Test pushover route (ignored event)**

Run in a separate terminal:
```bash
curl -s -X POST http://localhost:8787/pushover \
  -H "Content-Type: application/json" \
  -d '{"type":"conversation_started","data":{}}' | jq .
```
Expected: `{"success":true,"result":"ignored"}`

- [ ] **Step 3: Test webhook route (ignored action)**

```bash
curl -s -X POST http://localhost:8787/webhook \
  -H "Content-Type: application/json" \
  -d '{"action":"newMessage"}' | jq .
```
Expected: `{"success":true,"result":"ignored","action":"newMessage"}`

- [ ] **Step 4: Test 404**

```bash
curl -s -X GET http://localhost:8787/unknown | jq .
```
Expected: `{"error":"Not found"}` with status 404.

- [ ] **Step 5: Stop dev server and commit any fixes**

Stop the dev server (Ctrl+C). If any fixes were needed, commit them:
```bash
git add -A workers/
git commit -m "fix(workers): address issues found during smoke test"
```

---

### Task 10: Final Verification

**Files:** None (verification only)

- [ ] **Step 1: Run full test suite one final time**

Run: `cd workers && npx vitest run`
Expected: All tests PASS.

- [ ] **Step 2: Run type check**

Run: `cd workers && npx tsc --noEmit`
Expected: No errors.

- [ ] **Step 3: Verify project structure matches spec**

Run: `find workers/src -name '*.ts' | sort`
Expected output:
```
workers/src/index.ts
workers/src/languages/de.ts
workers/src/languages/en.ts
workers/src/languages/index.ts
workers/src/languages/pl.ts
workers/src/languages/ru.ts
workers/src/languages/ua.ts
workers/src/pushover.ts
workers/src/smoobu.ts
workers/src/sms.ts
workers/src/thekeys.ts
workers/src/types.ts
```

- [ ] **Step 4: Add workers/ to .gitignore for node_modules**

Create or update `.gitignore` at repo root to include:
```
workers/node_modules/
```

- [ ] **Step 5: Final commit**

```bash
git add .gitignore
git commit -m "chore: add workers/node_modules to gitignore"
```

## Deployment Notes (Post-Implementation)

After all tasks pass, deploy to Cloudflare:

1. `cd workers && npx wrangler login`
2. Set secrets: `npx wrangler secret put THEKEYS_USERNAME` (repeat for each secret)
3. `npx wrangler deploy`
4. Update Smoobu webhook URL to `https://thekeys.<your-subdomain>.workers.dev/webhook`
5. Update ElevenLabs webhook URL to `https://thekeys.<your-subdomain>.workers.dev/pushover`
6. Monitor with `npx wrangler tail`
