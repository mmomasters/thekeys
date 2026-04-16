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
      "+48123456789", "Your code is 281234", "en", "api-token-123"
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
      username: "u", userid: "uid", handle: "h", sender: "KOLNA",
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
      username: "u", userid: "uid", handle: "h", sender: "KOLNA",
    });
    const url = new URL(vi.mocked(fetch).mock.calls[0][0] as string);
    expect(url.searchParams.get("to")).toBe("48123456789");
  });

  it("returns false on failure", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response("ERR", { status: 200 })
    );
    const result = await sendViaBudgetSMS("+48123", "msg", {
      username: "u", userid: "uid", handle: "h", sender: "KOLNA",
    });
    expect(result).toBe(false);
  });
});

describe("sendSMSNotification", () => {
  beforeEach(() => vi.restoreAllMocks());

  it("returns false when guest has no phone", async () => {
    const result = await sendSMSNotification(
      { id: 1, "guest-name": "Guest", arrival: "2026-05-01", departure: "2026-05-03" },
      "281234", "Apt 1", "new", mockEnv()
    );
    expect(result).toBe(false);
  });

  it("cleans phone number and sends via configured provider", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true }), { status: 200 })
    );

    const result = await sendSMSNotification(
      {
        id: 1, "guest-name": "John", arrival: "2026-05-01",
        departure: "2026-05-03", phone: "+48 (123) 456-789", language: "en",
      },
      "281234", "Apt 1", "new", mockEnv()
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
        id: 1, "guest-name": "Jan", arrival: "2026-05-01",
        departure: "2026-05-03", phone: "+48123456789", language: "pl",
      },
      "281234", "Apt 1", "new", mockEnv()
    );

    const body = new URLSearchParams(
      (vi.mocked(fetch).mock.calls[0][1] as RequestInit).body as string
    );
    const text = body.get("text") ?? "";
    expect(text).not.toMatch(/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/);
  });
});
