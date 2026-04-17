export interface Env {
  // Secrets
  THEKEYS_USERNAME: string;
  THEKEYS_PASSWORD: string;
  SMOOBU_API_KEY: string;
  ELEVENLABS_WEBHOOK_SECRET: string;
  PUSHOVER_USER_KEY: string;
  PUSHOVER_API_TOKEN: string;
  SERWERSMS_API_TOKEN: string;
  BUDGETSMS_USERNAME: string;
  BUDGETSMS_USERID: string;
  BUDGETSMS_HANDLE: string;
  WEBHOOK_SECRET?: string;
  IP_WHITELIST?: string;

  // Vars (JSON strings parsed at runtime)
  APARTMENT_LOCKS: string;
  LOCK_ACCESSOIRES: string;
  DIGICODE_PREFIXES: string;
  DEFAULT_TIMES: string;
  SMS_PROVIDER: string;
  PIN_LENGTH: string;
}

export interface SmoobuBooking {
  id: number;
  'guest-name'?: string;
  arrival?: string;
  departure?: string;
  apartment?: { id: number | string; name?: string };
  language?: string;
  phone?: string;
  type?: string;
}

export interface SmoobuWebhookPayload {
  action: string;
  data?: SmoobuBooking;
  booking?: SmoobuBooking;
}

export interface DefaultTimes {
  check_in_hour: string;
  check_in_minute: string;
  check_out_hour: string;
  check_out_minute: string;
}

export interface TheKeysCode {
  id: number;
  nom: string;
  code: string;
  description: string;
  date_debut: string;
  date_fin: string;
  heure_debut?: { hour: string; minute: string };
  heure_fin?: { hour: string; minute: string };
  accessoire?: { id_accessoire: string };
}

export interface LanguageTemplate {
  subject: string;
  message: string;
  sms_message: string;
}
