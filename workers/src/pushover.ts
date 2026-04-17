import type { Env } from "./types";

export async function handlePushover(
  request: Request,
  env: Env
): Promise<Response> {
  if (request.method !== "POST") {
    return Response.json({ error: "Invalid request" }, { status: 400 });
  }

  const rawBody = await request.text();
  let payload: Record<string, unknown>;
  try {
    payload = JSON.parse(rawBody);
  } catch {
    return Response.json({ error: "Invalid JSON payload" }, { status: 400 });
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
      console.error(JSON.stringify({ event: "pushover_auth_failed", reason: "missing_signature_components" }));
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
      console.error(JSON.stringify({ event: "pushover_auth_failed", reason: "signature_mismatch" }));
      return Response.json({ error: "Invalid signature" }, { status: 401 });
    }
  }

  // Check event type
  const type = (payload.type as string) ?? "";
  if (type !== "post_call_transcription") {
    console.log(JSON.stringify({ event: "pushover_ignored", type }));
    return Response.json({ success: true, result: "ignored" });
  }

  // Extract data
  const data = payload.data as Record<string, unknown> | undefined;
  const analysis = (data?.analysis as Record<string, unknown>) ?? {};
  const summary = (analysis.transcript_summary as string) ?? (analysis.summary as string) ?? "";
  const agentId = (data?.agent_id as string) ?? "unknown";
  const agentName = (data?.agent_name as string) ?? "ElevenLabs AI Agent";

  const metadata = data?.metadata as Record<string, unknown> | undefined;
  const phoneCall = metadata?.phone_call as Record<string, unknown> | undefined;
  const clientData = data?.conversation_initiation_client_data as Record<string, unknown> | undefined;
  const dynVars = clientData?.dynamic_variables as Record<string, unknown> | undefined;
  const callerId = (phoneCall?.external_number as string) ?? (dynVars?.system__caller_id as string) ?? "Unknown";

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

  const pushoverRes = await fetch("https://api.pushover.net/1/messages.json", {
    method: "POST",
    body: pushoverBody.toString(),
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
  });

  console.log(JSON.stringify({ event: "pushover_sent", agentName, callerId, httpCode: pushoverRes.status }));

  return Response.json({ success: true });
}
