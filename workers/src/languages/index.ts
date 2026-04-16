import type { LanguageTemplate } from "../types";
import en from "./en";
import de from "./de";
import pl from "./pl";
import ru from "./ru";
import ua from "./ua";

const templates: Record<string, LanguageTemplate> = { en, de, pl, ru, ua };

interface LoadedLanguage {
  subject: string;
  message: string;
  sms_message: string;
}

export function loadLanguage(
  language: string,
  guestName: string,
  fullPin: string,
  apartmentName: string,
  arrival: string,
  departure: string
): LoadedLanguage {
  const lang = templates[language.toLowerCase()] ?? en;

  const replacements: Record<string, string> = {
    "{guest_name}": guestName,
    "{apartment_name}": apartmentName,
    "{full_pin}": fullPin,
    "{arrival}": arrival,
    "{departure}": departure,
  };

  function applyReplacements(text: string): string {
    let result = text;
    for (const [placeholder, value] of Object.entries(replacements)) {
      result = result.replaceAll(placeholder, value);
    }
    return result;
  }

  return {
    subject: lang.subject,
    message: applyReplacements(lang.message),
    sms_message: applyReplacements(lang.sms_message),
  };
}
