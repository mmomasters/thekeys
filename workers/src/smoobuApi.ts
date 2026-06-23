import type { Env } from "./types";

// Smoobu HMAC-SHA256 request signing.
// Every outgoing call to Smoobu must carry X-API-Key / X-Timestamp / X-Nonce /
// X-Signature. The signature is base64(HMAC-SHA256(canonical, secret)) over:
//   METHOD\nPATH\nQUERY\nTIMESTAMP\nNONCE\nBODY_HASH\nAPI_KEY
// where QUERY is the alpha-sorted "k=v&..." (no leading "?") and BODY_HASH is
// the lowercase hex sha256 of the raw body (empty-string hash for no body).
// See https://docs.smoobu.com/#hmac-authentication

const SMOOBU_BASE = "https://login.smoobu.com";

function toHex(buf: ArrayBuffer): string {
  return Array.from(new Uint8Array(buf))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

function toBase64(buf: ArrayBuffer): string {
  let binary = "";
  for (const b of new Uint8Array(buf)) binary += String.fromCharCode(b);
  return btoa(binary);
}

async function sha256Hex(text: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text));
  return toHex(digest);
}

// Sorts query params alphabetically by key and joins as "k=v&..." (no "?").
function buildQueryString(query?: Record<string, string | number>): string {
  if (!query) return "";
  const keys = Object.keys(query).sort();
  return keys.map((k) => `${k}=${query[k]}`).join("&");
}

export async function smoobuFetch(
  env: Env,
  method: string,
  path: string,
  opts: { query?: Record<string, string | number>; body?: unknown } = {}
): Promise<Response> {
  const httpMethod = method.toUpperCase();
  const queryString = buildQueryString(opts.query);
  const bodyString = opts.body === undefined ? "" : JSON.stringify(opts.body);
  const bodyHash = await sha256Hex(bodyString);
  const timestamp = new Date().toISOString().replace(/\.\d{3}Z$/, "Z");
  const nonce = crypto.randomUUID();

  const canonical = [
    httpMethod,
    path,
    queryString,
    timestamp,
    nonce,
    bodyHash,
    env.SMOOBU_API_KEY,
  ].join("\n");

  const key = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(env.SMOOBU_API_SECRET),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"]
  );
  const signature = toBase64(
    await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(canonical))
  );

  const headers: Record<string, string> = {
    "X-API-Key": env.SMOOBU_API_KEY,
    "X-Timestamp": timestamp,
    "X-Nonce": nonce,
    "X-Signature": signature,
  };
  const init: RequestInit = { method: httpMethod, headers };
  if (opts.body !== undefined) {
    headers["Content-Type"] = "application/json";
    init.body = bodyString;
  }

  const url = `${SMOOBU_BASE}${path}${queryString ? `?${queryString}` : ""}`;
  return fetch(url, init);
}
