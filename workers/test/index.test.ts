import { describe, it, expect, vi, beforeEach } from "vitest";
import worker from "../src/index";
import { mockEnv, makeRequest } from "./helpers";

describe("router", () => {
  beforeEach(() => vi.restoreAllMocks());

  it("returns 404 for unknown paths", async () => {
    const req = new Request("https://test.workers.dev/unknown", { method: "POST" });
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
      type: "conversation_started", data: {},
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
    vi.spyOn(globalThis, "fetch").mockRejectedValueOnce(
      new Error("Network error")
    );

    const req = makeRequest("/webhook", {
      action: "newReservation",
      data: {
        id: 1, "guest-name": "Test",
        arrival: "2026-05-01", departure: "2026-05-03",
        apartment: { id: 123456 },
      },
    });
    const res = await worker.fetch(req, mockEnv());
    expect(res.status).toBe(200);
  });
});
