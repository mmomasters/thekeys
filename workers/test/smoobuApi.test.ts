import { describe, it, expect, vi, beforeEach } from "vitest";
import { smoobuFetch } from "../src/smoobuApi";
import { mockEnv } from "./helpers";

// Recompute the expected base64 HMAC-SHA256 the same way the helper should.
async function expectedSignature(secret: string, canonical: string): Promise<string> {
  const key = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"]
  );
  const sig = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(canonical));
  let binary = "";
  for (const b of new Uint8Array(sig)) binary += String.fromCharCode(b);
  return btoa(binary);
}

const EMPTY_BODY_HASH =
  "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";

describe("smoobuFetch", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it("signs a POST with all four headers", async () => {
    const spy = vi
      .spyOn(globalThis, "fetch")
      .mockResolvedValue(new Response("{}", { status: 200 }));
    const env = mockEnv();

    await smoobuFetch(env, "POST", "/api/reservations/42/messages/send-message-to-guest", {
      body: { subject: "Hi", messageBody: "PIN 1234" },
    });

    const [url, init] = spy.mock.calls[0] as [string, RequestInit];
    const headers = init.headers as Record<string, string>;

    expect(url).toBe(
      "https://login.smoobu.com/api/reservations/42/messages/send-message-to-guest"
    );
    expect(init.method).toBe("POST");
    expect(headers["X-API-Key"]).toBe(env.SMOOBU_API_KEY);
    expect(headers["X-Timestamp"]).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
    expect(headers["X-Nonce"]).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/
    );
    expect(headers["Content-Type"]).toBe("application/json");

    const bodyString = JSON.stringify({ subject: "Hi", messageBody: "PIN 1234" });
    expect(init.body).toBe(bodyString);
    const bodyHashBuf = await crypto.subtle.digest(
      "SHA-256",
      new TextEncoder().encode(bodyString)
    );
    const bodyHash = Array.from(new Uint8Array(bodyHashBuf))
      .map((b) => b.toString(16).padStart(2, "0"))
      .join("");
    const canonical = [
      "POST",
      "/api/reservations/42/messages/send-message-to-guest",
      "",
      headers["X-Timestamp"],
      headers["X-Nonce"],
      bodyHash,
      env.SMOOBU_API_KEY,
    ].join("\n");
    expect(headers["X-Signature"]).toBe(
      await expectedSignature(env.SMOOBU_API_SECRET, canonical)
    );
  });

  it("alpha-sorts query params and uses the empty-body hash for GET", async () => {
    const spy = vi
      .spyOn(globalThis, "fetch")
      .mockResolvedValue(new Response("{}", { status: 200 }));
    const env = mockEnv();

    await smoobuFetch(env, "GET", "/api/reservations", {
      query: { pageSize: 100, arrivalFrom: "2026-01-01", page: 1, arrivalTo: "2026-01-31" },
    });

    const [url, init] = spy.mock.calls[0] as [string, RequestInit];
    const headers = init.headers as Record<string, string>;
    const sortedQuery =
      "arrivalFrom=2026-01-01&arrivalTo=2026-01-31&page=1&pageSize=100";

    expect(url).toBe(`https://login.smoobu.com/api/reservations?${sortedQuery}`);
    expect(init.body).toBeUndefined();
    expect(headers["Content-Type"]).toBeUndefined();

    const canonical = [
      "GET",
      "/api/reservations",
      sortedQuery,
      headers["X-Timestamp"],
      headers["X-Nonce"],
      EMPTY_BODY_HASH,
      env.SMOOBU_API_KEY,
    ].join("\n");
    expect(headers["X-Signature"]).toBe(
      await expectedSignature(env.SMOOBU_API_SECRET, canonical)
    );
  });
});
