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
