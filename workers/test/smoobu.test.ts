import { describe, it, expect, vi, beforeEach } from "vitest";
import { handleSmoobuWebhook } from "../src/smoobu";
import { mockEnv, makeRequest } from "./helpers";

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
            id: 42, nom: "Guest", code,
            description: `Smoobu#${bookingId}`,
            date_debut: "2026-05-01", date_fin: "2026-05-03",
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
    const req = new Request("https://test.workers.dev/webhook", { method: "GET" });
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
        id: 500, "guest-name": "Alice Smith",
        arrival: "2026-05-01", departure: "2026-05-03",
        apartment: { id: 123456, name: "Apt 5" },
        language: "en", phone: "+48111222333",
      },
    });

    const res = await handleSmoobuWebhook(req, mockEnv());
    expect(res.status).toBe(200);
    const body = await res.json() as { success: boolean; result: { status: string } };
    expect(body.success).toBe(true);
    expect(body.result.status).toBe("created");

    const createUrl = fetchSpy.mock.calls[2][0] as string;
    expect(createUrl).toContain("/partage/create/3733/accessoire/OXe37UIa");
  });

  it("handles updateReservation: updates existing code dates", async () => {
    vi.spyOn(globalThis, "fetch")
      .mockResolvedValueOnce(mockTheKeysLogin())
      .mockResolvedValueOnce(mockListCodesWithCode(600, "5678"))
      .mockResolvedValueOnce(mockUpdateCodeSuccess())
      .mockResolvedValueOnce(mockSmsSuccess())
      .mockResolvedValueOnce(mockSmoobuMessageSuccess());

    const req = makeRequest("/webhook", {
      action: "updateReservation",
      data: {
        id: 600, "guest-name": "Bob Jones",
        arrival: "2026-06-01", departure: "2026-06-05",
        apartment: { id: 123456, name: "Apt 5" },
        language: "en", phone: "+48111222333",
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
        id: 700, "guest-name": "Charlie",
        arrival: "2026-07-01", departure: "2026-07-03",
        apartment: { id: 123456, name: "Apt 5" },
        language: "en", phone: "+48111222333",
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
        id: 800, "guest-name": "Dave",
        arrival: "2026-08-01", departure: "2026-08-03",
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
        id: 900, "guest-name": "Eve",
        apartment: { id: 123456 },
      },
    });

    const res = await handleSmoobuWebhook(req, mockEnv());
    expect(res.status).toBe(200);
    const body = await res.json() as { success: boolean; result: { status: string } };
    expect(body.result.status).toBe("not_found");
  });
});
