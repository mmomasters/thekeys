import type { LanguageTemplate } from "../types";

const ru: LanguageTemplate = {
  subject: "Коды доступа Kolna Apartments",

  sms_message: `Уважаемый {guest_name},

- Код главного здания "Jana z Kolna 19" - 1 + 🔑 + 5687
- Код двери в холл - 3256 + ENTER
- Код двери апартаментов {apartment_name} - {full_pin} + 🟦

Код действует только во время вашего пребывания.
Ваш заезд: {arrival} с 15.00
Ваш выезд: {departure} до 12.00

В случае проблем звоните нам +48 91 819 99 65

Приятного пребывания!
Kolna Apartments`,

  message: `Уважаемый {guest_name},

- Код главного здания "Jana z Kolna 19" - 1 + 🔑 + 5687
- Код двери в холл - 3256 + ENTER
- Код двери апартаментов {apartment_name} - {full_pin} + 🟦

Ваш код апартаментов будет работать ТОЛЬКО между датой и временем заезда и выезда.
Ваш заезд: {arrival} с 15.00
Ваш выезд: {departure} до 12.00

🅿️ Много парковочных мест расположено на улице возле Kolna Apartments. Парковка бесплатна с 17:00 до 08:00, а также в выходные и праздничные дни, цены: https://spp.szczecin.pl/informacja/paid-parking-zone-pricing

В случае возникновения проблем звоните нам +48 91 819 99 65

Желаем вам приятного отдыха,
Kolna Apartments`,
};

export default ru;
