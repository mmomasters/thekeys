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
  api: TheKeysAPI, lockId: number, bookingId: number
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
  booking: SmoobuBooking, fullPin: string, apartmentName: string, smoobuApiKey: string
): Promise<boolean> {
  const language = (booking.language ?? "en").toLowerCase();
  const lang = loadLanguage(
    language, booking["guest-name"] ?? "Guest",
    fullPin, apartmentName,
    booking.arrival ?? "", booking.departure ?? ""
  );

  const res = await fetch(
    `https://login.smoobu.com/api/reservations/${booking.id}/messages/send-message-to-guest`,
    {
      method: "POST",
      headers: { "Api-Key": smoobuApiKey, "Content-Type": "application/json" },
      body: JSON.stringify({ subject: lang.subject, messageBody: lang.message }),
    }
  );

  return res.status === 200 || res.status === 201;
}

async function handleNewReservation(
  booking: SmoobuBooking, env: Env, api: TheKeysAPI
): Promise<Record<string, unknown>> {
  const apartmentId = String(booking.apartment?.id ?? "");
  const apartmentLocks = parseJsonVar<Record<string, number>>(env.APARTMENT_LOCKS);
  const lockId = apartmentLocks[apartmentId];
  if (!lockId) return { status: "skipped", message: "No lock mapping" };

  const lockAccessoires = parseJsonVar<Record<string, string>>(env.LOCK_ACCESSOIRES);
  const idAccessoire = lockAccessoires[String(lockId)];
  if (!idAccessoire) return { status: "skipped", message: "No accessoire mapping" };

  const existing = await findExistingCode(api, lockId, booking.id);
  if (existing) return { status: "exists", message: "Code already exists" };

  const pinLength = parseInt(env.PIN_LENGTH, 10) || 4;
  const pinCode = generatePIN(pinLength);
  const prefixes = parseJsonVar<Record<string, string>>(env.DIGICODE_PREFIXES);
  const prefix = prefixes[String(lockId)] ?? "";
  const fullPin = prefix + pinCode;

  const guestName = booking["guest-name"] ?? "Guest";
  const arrival = booking.arrival;
  const departure = booking.departure;
  if (!arrival || !departure) return { status: "error", message: "Missing arrival or departure dates" };

  const times = parseJsonVar<DefaultTimes>(env.DEFAULT_TIMES);
  const result = await api.createCode(
    lockId, idAccessoire, guestName, pinCode,
    arrival, departure,
    times.check_in_hour, times.check_in_minute,
    times.check_out_hour, times.check_out_minute,
    `Smoobu#${booking.id}`
  );

  if (!result) return { status: "error", message: "Failed to create code" };

  const apartmentName = booking.apartment?.name ?? "your apartment";
  await sendSMSNotification(booking, fullPin, apartmentName, "new", env);

  const today = new Date().toISOString().slice(0, 10);
  if (today <= arrival) {
    await sendGuestMessage(booking, fullPin, apartmentName, env.SMOOBU_API_KEY);
  }

  return { status: "created", code_id: result.id, pin: pinCode };
}

async function handleUpdatedReservation(
  booking: SmoobuBooking, env: Env, api: TheKeysAPI
): Promise<Record<string, unknown>> {
  const apartmentId = String(booking.apartment?.id ?? "");
  const apartmentLocks = parseJsonVar<Record<string, number>>(env.APARTMENT_LOCKS);
  const lockId = apartmentLocks[apartmentId];
  if (!lockId) return { status: "skipped", message: "No lock mapping" };

  const lockAccessoires = parseJsonVar<Record<string, string>>(env.LOCK_ACCESSOIRES);
  const idAccessoire = lockAccessoires[String(lockId)];
  if (!idAccessoire) return { status: "skipped", message: "No accessoire mapping" };

  // Search for existing code on the target lock
  const existingCode = await findExistingCode(api, lockId, booking.id);

  if (!existingCode) {
    // Not found on current lock - check other locks and migrate if needed
    for (const searchLockIdStr of Object.keys(lockAccessoires)) {
      const searchLockId = Number(searchLockIdStr);
      if (searchLockId === lockId) continue;
      const code = await findExistingCode(api, searchLockId, booking.id);
      if (code) {
        await api.deleteCode(code.id);
        break;
      }
    }
    return handleNewReservation(booking, env, api);
  }

  const arrival = booking.arrival;
  const departure = booking.departure;
  const times = parseJsonVar<DefaultTimes>(env.DEFAULT_TIMES);

  const success = await api.updateCode(existingCode.id, {
    name: booking["guest-name"] ?? "Guest",
    code: existingCode.code,
    dateStart: arrival, dateEnd: departure,
    timeStartHour: times.check_in_hour, timeStartMin: times.check_in_minute,
    timeEndHour: times.check_out_hour, timeEndMin: times.check_out_minute,
    active: true, description: `Smoobu#${booking.id}`,
  });

  if (!success) return { status: "error", message: "Failed to update code" };

  const apartmentName = booking.apartment?.name ?? "your apartment";
  const prefixes = parseJsonVar<Record<string, string>>(env.DIGICODE_PREFIXES);
  const prefix = prefixes[String(lockId)] ?? "";
  const fullPin = prefix + existingCode.code;

  await sendSMSNotification(booking, fullPin, apartmentName, "update", env);
  await sendGuestMessage(booking, fullPin, apartmentName, env.SMOOBU_API_KEY);

  return { status: "updated", code_id: existingCode.id };
}

async function handleCancelledReservation(
  booking: SmoobuBooking, env: Env, api: TheKeysAPI
): Promise<Record<string, unknown>> {
  const lockAccessoires = parseJsonVar<Record<string, string>>(env.LOCK_ACCESSOIRES);

  let existingCode: TheKeysCode | null = null;
  for (const searchLockId of Object.keys(lockAccessoires).map(Number)) {
    const code = await findExistingCode(api, searchLockId, booking.id);
    if (code) { existingCode = code; break; }
  }

  if (!existingCode) return { status: "not_found", message: "Code not found" };

  const success = await api.deleteCode(existingCode.id);
  if (!success) return { status: "error", message: "Failed to delete code" };

  return { status: "deleted", code_id: existingCode.id };
}

export async function handleSmoobuWebhook(
  request: Request, env: Env
): Promise<Response> {
  if (request.method !== "POST") {
    return Response.json({ error: "Method not allowed" }, { status: 400 });
  }

  const rawBody = await request.text();
  let payload: SmoobuWebhookPayload;
  try {
    payload = JSON.parse(rawBody) as SmoobuWebhookPayload;
  } catch {
    return Response.json({ error: "Invalid JSON payload" }, { status: 400 });
  }

  const action = payload.action ?? "";
  const eventType = ACTION_MAP[action] ?? "ignore";

  if (eventType === "ignore") {
    return Response.json({ success: true, result: "ignored", action });
  }

  if (env.IP_WHITELIST) {
    const whitelist = parseJsonVar<string[]>(env.IP_WHITELIST);
    const clientIp = request.headers.get("cf-connecting-ip") ?? "";
    if (whitelist.length > 0 && !whitelist.includes(clientIp)) {
      return Response.json({ error: "IP not whitelisted" }, { status: 403 });
    }
  }

  if (env.WEBHOOK_SECRET) {
    const signature = request.headers.get("x-smoobu-signature") ?? "";
    const encoder = new TextEncoder();
    const key = await crypto.subtle.importKey(
      "raw", encoder.encode(env.WEBHOOK_SECRET),
      { name: "HMAC", hash: "SHA-256" }, false, ["sign"]
    );
    const sig = await crypto.subtle.sign("HMAC", key, encoder.encode(rawBody));
    const expected = Array.from(new Uint8Array(sig))
      .map((b) => b.toString(16).padStart(2, "0")).join("");
    if (expected !== signature) {
      return Response.json({ error: "Invalid signature" }, { status: 401 });
    }
  }

  const bookingData: SmoobuBooking =
    payload.data ?? payload.booking ?? (payload as unknown as SmoobuBooking);

  if (bookingData.type === "cancellation") {
    const api = new TheKeysAPI(env.THEKEYS_USERNAME, env.THEKEYS_PASSWORD);
    if (!(await api.login())) {
      return Response.json({ success: false, error: "Failed to login to The Keys API" });
    }
    const result = await handleCancelledReservation(bookingData, env, api);
    return Response.json({ success: true, result, event: "reservation.cancelled", booking_id: bookingData.id });
  }

  const api = new TheKeysAPI(env.THEKEYS_USERNAME, env.THEKEYS_PASSWORD);
  if (!(await api.login())) {
    return Response.json({ success: false, error: "Failed to login to The Keys API" });
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
    success: true, result, event: eventType,
    booking_id: bookingData.id ?? null,
  });
}
