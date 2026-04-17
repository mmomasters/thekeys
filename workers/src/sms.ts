import type { Env, SmoobuBooking } from "./types";
import { loadLanguage } from "./languages";

const POLISH_TRANSLITERATION: Record<string, string> = {
  "ą": "a", "ć": "c", "ę": "e", "ł": "l",
  "ń": "n", "ó": "o", "ś": "s", "ź": "z",
  "ż": "z", "Ą": "A", "Ć": "C", "Ę": "E",
  "Ł": "L", "Ń": "N", "Ó": "O", "Ś": "S",
  "Ź": "Z", "Ż": "Z",
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
  recipient: string, message: string, language: string, apiToken: string
): Promise<boolean> {
  const params = new URLSearchParams({
    phone: recipient, text: message, sender: "KOLNA",
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
  recipient: string, message: string, config: BudgetSMSConfig
): Promise<boolean> {
  let to = recipient.replace(/^\+/, "");
  if (to.startsWith("00")) {
    to = to.slice(2);
  }

  const params = new URLSearchParams({
    username: config.username, userid: config.userid,
    handle: config.handle, msg: message,
    from: config.sender, to,
  });

  const res = await fetch(`https://api.budgetsms.net/sendsms/?${params.toString()}`);

  if (res.status === 200) {
    const text = await res.text();
    return text.startsWith("OK");
  }
  return false;
}

export async function sendSMSNotification(
  booking: SmoobuBooking, fullPin: string, apartmentName: string,
  action: string, env: Env
): Promise<boolean> {
  const guestPhone = cleanPhone(booking.phone ?? "");
  if (!guestPhone) return false;

  const language = (booking.language ?? "en").toLowerCase();

  let message: string;
  if (action === "cancel") {
    message = `CANCELLED: Kolna Apartments reservation ${apartmentName} (${booking.arrival} to ${booking.departure}) has been cancelled.`;
  } else {
    const lang = loadLanguage(
      language,
      booking["guest-name"] ?? "Guest",
      fullPin, apartmentName,
      booking.arrival ?? "", booking.departure ?? ""
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
