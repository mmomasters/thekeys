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

  it("returns 400 for malformed JSON body", async () => {
    const req = new Request("https://test.workers.dev/pushover", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: "not json",
    });
    const res = await handlePushover(req, mockEnv());
    expect(res.status).toBe(400);
    const body = (await res.json()) as { error: string };
    expect(body.error).toBe("Invalid JSON payload");
  });

  it("returns 400 for empty POST body", async () => {
    const req = new Request("https://test.workers.dev/pushover", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: "",
    });
    const res = await handlePushover(req, mockEnv());
    expect(res.status).toBe(400);
  });

  it("ignores non-post_call_transcription events", async () => {
    const req = makeRequest("/pushover", {
      type: "conversation_started",
      data: {},
    });
    const res = await handlePushover(req, mockEnv({ ELEVENLABS_WEBHOOK_SECRET: "" }));
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
    const res = await handlePushover(req, mockEnv({ ELEVENLABS_WEBHOOK_SECRET: "" }));
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
