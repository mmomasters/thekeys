import { describe, it, expect, vi, beforeEach } from "vitest";
import { TheKeysAPI } from "../src/thekeys";

describe("TheKeysAPI", () => {
  let api: TheKeysAPI;

  beforeEach(() => {
    vi.restoreAllMocks();
    api = new TheKeysAPI("user", "pass");
  });

  describe("login", () => {
    it("returns true and stores token on success", async () => {
      vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
        new Response(JSON.stringify({ token: "jwt-token-123" }), { status: 200 })
      );

      const result = await api.login();
      expect(result).toBe(true);

      const [url, init] = vi.mocked(fetch).mock.calls[0];
      expect(url).toBe("https://api.the-keys.fr/api/login_check");
      expect(init?.method).toBe("POST");
      const body = new URLSearchParams(init?.body as string);
      expect(body.get("_username")).toBe("user");
      expect(body.get("_password")).toBe("pass");
    });

    it("returns false on failed login", async () => {
      vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
        new Response("Unauthorized", { status: 401 })
      );
      const result = await api.login();
      expect(result).toBe(false);
    });
  });

  describe("listCodes", () => {
    it("returns codes array on success", async () => {
      const codes = [
        { id: 1, nom: "Guest", code: "1234", description: "Smoobu#100" },
      ];

      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(
          new Response(
            JSON.stringify({ data: { partages_accessoire: codes } }),
            { status: 200 }
          )
        );

      await api.login();
      const result = await api.listCodes(3733);

      expect(result).toEqual(codes);
      const [url] = vi.mocked(fetch).mock.calls[1];
      expect(url).toBe(
        "https://api.the-keys.fr/fr/api/v2/partage/all/serrure/3733?_format=json"
      );
    });

    it("returns empty array on failure", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(new Response("Error", { status: 500 }));

      await api.login();
      const result = await api.listCodes(3733);
      expect(result).toEqual([]);
    });
  });

  describe("createCode", () => {
    it("sends form-encoded POST and returns data on success", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(
          new Response(
            JSON.stringify({ status: 200, data: { id: 42 } }),
            { status: 200 }
          )
        );

      await api.login();
      const result = await api.createCode(
        3733, "OXe37UIa", "John Doe", "5678",
        "2026-05-01", "2026-05-03",
        "15", "0", "12", "0",
        "Smoobu#100"
      );

      expect(result).toEqual({ id: 42 });

      const [url, init] = vi.mocked(fetch).mock.calls[1];
      expect(url).toBe(
        "https://api.the-keys.fr/fr/api/v2/partage/create/3733/accessoire/OXe37UIa"
      );
      const body = new URLSearchParams(init?.body as string);
      expect(body.get("partage_accessoire[nom]")).toBe("John Doe");
      expect(body.get("partage_accessoire[code]")).toBe("5678");
      expect(body.get("partage_accessoire[date_debut]")).toBe("2026-05-01");
      expect(body.get("partage_accessoire[date_fin]")).toBe("2026-05-03");
      expect(body.get("partage_accessoire[description]")).toBe("Smoobu#100");
    });

    it("returns null on failure", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(new Response("Error", { status: 500 }));

      await api.login();
      const result = await api.createCode(
        3733, "OXe37UIa", "Guest", "1234",
        "2026-05-01", "2026-05-03",
        "15", "0", "12", "0", ""
      );
      expect(result).toBeNull();
    });
  });

  describe("updateCode", () => {
    it("sends update and returns true on success", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ status: 200 }), { status: 200 })
        );

      await api.login();
      const result = await api.updateCode(42, {
        name: "Jane Doe",
        code: "5678",
        dateStart: "2026-05-01",
        dateEnd: "2026-05-05",
        timeStartHour: "15",
        timeStartMin: "0",
        timeEndHour: "12",
        timeEndMin: "0",
        active: true,
        description: "Smoobu#100",
      });

      expect(result).toBe(true);
      const [url] = vi.mocked(fetch).mock.calls[1];
      expect(url).toBe(
        "https://api.the-keys.fr/fr/api/v2/partage/accessoire/update/42"
      );
    });

    it("returns false on failure", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(new Response("Error", { status: 500 }));

      await api.login();
      const result = await api.updateCode(42, { active: true });
      expect(result).toBe(false);
    });
  });

  describe("deleteCode", () => {
    it("returns true on success", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ status: 200 }), { status: 200 })
        );

      await api.login();
      const result = await api.deleteCode(42);
      expect(result).toBe(true);

      const [url, init] = vi.mocked(fetch).mock.calls[1];
      expect(url).toBe(
        "https://api.the-keys.fr/fr/api/v2/partage/accessoire/delete/42"
      );
      expect(init?.method).toBe("POST");
    });

    it("returns false on failure", async () => {
      vi.spyOn(globalThis, "fetch")
        .mockResolvedValueOnce(
          new Response(JSON.stringify({ token: "jwt" }), { status: 200 })
        )
        .mockResolvedValueOnce(new Response("Error", { status: 500 }));

      await api.login();
      const result = await api.deleteCode(42);
      expect(result).toBe(false);
    });
  });
});
