export const locales = ["en", "ar"] as const;
export type Locale = (typeof locales)[number];
export function isLocale(value: string): value is Locale {
  return locales.some((locale) => locale === value);
}
export const messages = {
  en: {
    home: "Workspace", eyebrow: "SHIPPING, CONNECTED", title: "One place for every delivery.",
    description: "A unified shipping workspace for your business. Built for domestic and international delivery, wherever your carrier account supports it.",
    status: "Development foundation", notice: "Shipping and payments are not available yet. No live carrier account is connected.",
    workspace: "Your shipping workspace", planned: "Planned", available: "Available foundation", next: "Available now", milestone: "Your merchant workspace",
    milestoneText: "Secure accounts, teams, business profiles, configured estimates, wallet statements and shipment drafts. Live shipping and payments remain disabled.",
    modules: [
      ["01", "Shipments", "Prepare shipment and pickup drafts; live dispatch remains disabled."],
      ["02", "Bulk shipping", "Import orders, review rows and follow progress."],
      ["03", "Wallet & billing", "View balances, reservations and statements; real funding remains disabled."],
      ["04", "Store connections", "Bring store orders into your shipping workflow."],
    ],
    footer: "Aramex first. Built to support more carriers.", language: "العربية", languageLabel: "Switch to Arabic", technical: "Foundation status", docs: "API health", safe: "Live shipping disabled",
  },
  ar: {
    home: "مساحة العمل", eyebrow: "شحن متكامل", title: "كل شحناتك في مكان واحد.",
    description: "مساحة شحن موحدة لأعمالك. مصممة للشحن المحلي والدولي، حسب الخدمات المتاحة لحساب شركة النقل.",
    status: "المرحلة التأسيسية", notice: "خدمات الشحن والدفع غير متاحة بعد. لم يتم ربط حساب ناقل فعلي.",
    workspace: "مساحة إدارة الشحن", planned: "مخطط", available: "أساس متاح", next: "متاح الآن", milestone: "مساحة عمل التاجر",
    milestoneText: "حسابات آمنة وفرق وملفات منشآت وتقديرات أسعار وكشوف محفظة ومسودات شحن. الشحن الفعلي والدفع معطلان.",
    modules: [
      ["01", "الشحنات", "إعداد مسودات الشحن والاستلام؛ الإرسال الفعلي معطل."],
      ["02", "الشحن الجماعي", "استيراد الطلبات ومراجعتها ومتابعة تقدمها."],
      ["03", "المحفظة والفواتير", "عرض الأرصدة والحجوزات والكشوف؛ التمويل الفعلي معطل."],
      ["04", "ربط المتاجر", "إضافة طلبات المتاجر إلى سير عمل الشحن."],
    ],
    footer: "أرامكس أولاً. بنية تدعم إضافة المزيد من الناقلين.", language: "English", languageLabel: "Switch to English", technical: "حالة التأسيس", docs: "حالة واجهة البرمجة", safe: "الشحن الفعلي معطل",
  },
} as const;
