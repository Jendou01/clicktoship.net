import {customsField,customsHint} from "./customs";
import { shippingCopy } from "./shipping";
import type { Locale } from "./i18n";
const aliases: Record<string, string> = { service_id: "service", goods_origin_country: "manufacture", items: "customs", weight_grams: "weight", length_mm: "length", width_mm: "width", height_mm: "height", declared_value_minor: "declared", value_minor: "value", hs_code: "hs" };
export function shipmentFieldLabel(key: string, locale: Locale) {
  if(key.startsWith("customs."))return customsField(key,locale);
  if (key === "packages_json_or_items_json") return locale === "ar" ? "الطرود والبنود الدولية بصيغة JSON" : "Packages and international items JSON";
  const t: Record<string, string> = shippingCopy[locale];
  return key.replace(/^draft\./, "").split(".").map((p) => /^\d+$/.test(p) ? String(Number(p) + 1) : t[aliases[p] || p] || (locale === "ar" ? "حقل الشحنة" : "Shipment field")).join(" — ");
}
export function shipmentFieldName(key: string) {
  const p = key.replace(/^draft\./, "").split(".");
  if (p[0] === "packages") return `${p[2]}_${p[1]}`;
  if (p[0] === "items" && p.length > 2) return `${({ description: "item", value_minor: "value", hs_code: "hs" } as Record<string, string>)[p[2]] || p[2]}_${p[1]}`;
  if (p.length > 1) return p.join("_");
  return ({ service_id: "service", goods_origin_country: "manufacture", declared_value_minor: "declared" } as Record<string, string>)[p[0]] || p[0];
}
export function shipmentFieldStep(key: string) {
  const name = shipmentFieldName(key);
  return /^(origin|destination|return_address)_/.test(name) ? 0 : name === "service" ? 2 : 1;
}
export function shipmentFieldHint(key: string, locale: Locale) {
  if(key.startsWith("customs."))return customsHint(key,locale);
  const en = locale === "en";
  if (key === "packages_json_or_items_json") return en ? "Enter a valid JSON array matching the package or international item fields." : "أدخل مصفوفة JSON صالحة تطابق حقول الطرود أو البنود الدولية.";
  if (/service/.test(key)) return en ? "Choose a configured service available to this organization." : "اختر خدمة مهيأة ومتاحة لهذه المنشأة.";
  if (/phone/.test(key)) return en ? "Enter an international phone number, starting with + and 7–15 digits." : "أدخل هاتفًا دوليًا يبدأ بعلامة + ويتبعه من ٧ إلى ١٥ رقمًا.";
  if (/email/.test(key)) return en ? "Enter a valid email address or leave this optional field empty." : "أدخل بريدًا إلكترونيًا صالحًا أو اترك الحقل الاختياري فارغًا.";
  if (/country/.test(key)) return en ? "Enter an uppercase two-letter country code." : "أدخل رمز الدولة بحرفين لاتينيين كبيرين.";
  if (/postal/.test(key)) return en ? "Check the postal code for this country (SA: 5 digits; US: 5 or ZIP+4)." : "راجع الرمز البريدي للدولة (السعودية: ٥ أرقام؛ أمريكا: ٥ أرقام أو ZIP+4).";
  if (/weight_grams/.test(key)) return en ? "Enter a whole number of grams from 1 to 1,000,000,000." : "أدخل وزنًا صحيحًا بالغرام من ١ إلى ١٬٠٠٠٬٠٠٠٬٠٠٠.";
  if (/(length|width|height)_mm/.test(key)) return en ? "Enter a whole number of millimetres from 1 to 200,000." : "أدخل بُعدًا صحيحًا بالملليمتر من ١ إلى ٢٠٠٬٠٠٠.";
  if (/items/.test(key)) return en ? "Complete international items: description, positive whole quantity, value in minor units and country; HS code is 6–12 digits if supplied." : "أكمل البنود الدولية: الوصف وكمية صحيحة موجبة والقيمة بالهللة ودولة التصنيع؛ الرمز الجمركي من ٦ إلى ١٢ رقمًا إن أُدخل.";
  return en ? "Complete this field and check its format and permitted length." : "أكمل هذا الحقل وراجع تنسيقه والطول المسموح.";
}
