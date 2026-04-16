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

  // Check event type first (before signature validation for efficiency)
  const type = payload.type ?? "";
  if (type !== "post_call_transcription") {
    return Response.json({ success: true, result: "ignored" });
  }

  // Extract data
  const analysis = payload.data?.analysis ?? {};
  const summary = analysis.transcript_summary ?? analysis.summary ?? "";
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
