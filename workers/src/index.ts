import type { Env } from "./types";
import { handlePushover } from "./pushover";
import { handleSmoobuWebhook } from "./smoobu";

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const url = new URL(request.url);
    const path = url.pathname;

    try {
      switch (path) {
        case "/webhook":
          return await handleSmoobuWebhook(request, env);
        case "/pushover":
          return await handlePushover(request, env);
        default:
          return Response.json({ error: "Not found" }, { status: 404 });
      }
    } catch (e) {
      const message = e instanceof Error ? e.message : "Unknown error";
      console.error(`Unhandled error: ${message}`);
      return Response.json({ success: false, error: message });
    }
  },
};
