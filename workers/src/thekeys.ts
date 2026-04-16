import type { TheKeysCode } from "./types";

export interface UpdateCodeOptions {
  name?: string;
  code?: string;
  dateStart?: string;
  dateEnd?: string;
  timeStartHour?: string;
  timeStartMin?: string;
  timeEndHour?: string;
  timeEndMin?: string;
  active?: boolean;
  description?: string;
}

export class TheKeysAPI {
  private username: string;
  private password: string;
  private baseUrl: string;
  private token: string | null = null;

  constructor(username: string, password: string, baseUrl = "https://api.the-keys.fr") {
    this.username = username;
    this.password = password;
    this.baseUrl = baseUrl;
  }

  async login(): Promise<boolean> {
    const res = await fetch(`${this.baseUrl}/api/login_check`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        _username: this.username,
        _password: this.password,
      }).toString(),
    });

    if (res.status === 200) {
      const data = (await res.json()) as { token?: string };
      if (data.token) {
        this.token = data.token;
        return true;
      }
    }
    return false;
  }

  async listCodes(lockId: number): Promise<TheKeysCode[]> {
    const res = await fetch(
      `${this.baseUrl}/fr/api/v2/partage/all/serrure/${lockId}?_format=json`,
      { headers: { Authorization: `Bearer ${this.token}` } }
    );

    if (res.status === 200) {
      const data = (await res.json()) as { data?: { partages_accessoire?: TheKeysCode[] } };
      return data.data?.partages_accessoire ?? [];
    }
    return [];
  }

  async createCode(
    lockId: number, idAccessoire: string, name: string, code: string,
    dateStart: string, dateEnd: string,
    timeStartHour: string, timeStartMin: string,
    timeEndHour: string, timeEndMin: string,
    description: string
  ): Promise<Record<string, unknown> | null> {
    const body = new URLSearchParams({
      "partage_accessoire[nom]": name,
      "partage_accessoire[actif]": "1",
      "partage_accessoire[date_debut]": dateStart,
      "partage_accessoire[date_fin]": dateEnd,
      "partage_accessoire[heure_debut][hour]": timeStartHour,
      "partage_accessoire[heure_debut][minute]": timeStartMin,
      "partage_accessoire[heure_fin][hour]": timeEndHour,
      "partage_accessoire[heure_fin][minute]": timeEndMin,
      "partage_accessoire[code]": code,
      "partage_accessoire[description]": description,
    });

    const res = await fetch(
      `${this.baseUrl}/fr/api/v2/partage/create/${lockId}/accessoire/${idAccessoire}`,
      {
        method: "POST",
        headers: {
          Authorization: `Bearer ${this.token}`,
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: body.toString(),
      }
    );

    if (res.status === 200) {
      const result = (await res.json()) as { status?: number; data?: Record<string, unknown> };
      if (result.status === 200) return result.data ?? null;
    }
    return null;
  }

  async updateCode(codeId: number, options: UpdateCodeOptions): Promise<boolean> {
    const params = new URLSearchParams({
      "partage_accessoire[actif]": options.active !== false ? "1" : "0",
    });

    if (options.name != null) params.set("partage_accessoire[nom]", options.name);
    if (options.code != null) params.set("partage_accessoire[code]", options.code);
    if (options.dateStart != null) params.set("partage_accessoire[date_debut]", options.dateStart);
    if (options.dateEnd != null) params.set("partage_accessoire[date_fin]", options.dateEnd);
    if (options.timeStartHour != null) {
      params.set("partage_accessoire[heure_debut][hour]", options.timeStartHour);
      params.set("partage_accessoire[heure_debut][minute]", options.timeStartMin ?? "0");
    }
    if (options.timeEndHour != null) {
      params.set("partage_accessoire[heure_fin][hour]", options.timeEndHour);
      params.set("partage_accessoire[heure_fin][minute]", options.timeEndMin ?? "0");
    }
    if (options.description != null) params.set("partage_accessoire[description]", options.description);

    const res = await fetch(
      `${this.baseUrl}/fr/api/v2/partage/accessoire/update/${codeId}`,
      {
        method: "POST",
        headers: {
          Authorization: `Bearer ${this.token}`,
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: params.toString(),
      }
    );

    if (res.status === 200) {
      const result = (await res.json()) as { status?: number };
      return result.status === 200;
    }
    return false;
  }

  async deleteCode(codeId: number): Promise<boolean> {
    const res = await fetch(
      `${this.baseUrl}/fr/api/v2/partage/accessoire/delete/${codeId}`,
      {
        method: "POST",
        headers: { Authorization: `Bearer ${this.token}` },
      }
    );

    if (res.status === 200) {
      const result = (await res.json()) as { status?: number };
      return result.status === 200;
    }
    return false;
  }
}
