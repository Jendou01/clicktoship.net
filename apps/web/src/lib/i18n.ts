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
    workspace: "Your shipping workspace", planned: "Planned", next: "Next milestone", milestone: "Secure merchant access",
    milestoneText: "Authentication, verified memberships and tenant isolation come before shipping and financial operations.",
    modules: [
      ["01", "Shipments", "Create, manage and track deliveries in one place."],
      ["02", "Bulk shipping", "Import orders, review rows and follow progress."],
      ["03", "Wallet & billing", "Clear balances, reservations and transaction history."],
      ["04", "Store connections", "Bring store orders into your shipping workflow."],
    ],
    footer: "Aramex first. Built to support more carriers.", language: "العربية", languageLabel: "Switch to Arabic", technical: "Foundation status", docs: "API health", safe: "Live shipping disabled",
  },
  ar: {
    home: "مساحة العمل", eyebrow: "شحن متكامل", title: "كل شحناتك في مكان واحد.",
    description: "مساحة شحن موحدة لأعمالك. مصممة للشحن المحلي والدولي، حسب الخدمات المتاحة لحساب شركة النقل.",
    status: "المرحلة التأسيسية", notice: "خدمات الشحن والدفع غير متاحة بعد. لم يتم ربط حساب ناقل فعلي.",
    workspace: "مساحة إدارة الشحن", planned: "مخطط", next: "الخطوة التالية", milestone: "وصول آمن للتجار",
    milestoneText: "تأتي المصادقة والتحقق من العضويات وعزل بيانات التجار قبل تفعيل الشحن والعمليات المالية.",
    modules: [
      ["01", "الشحنات", "إنشاء الشحنات وإدارتها وتتبعها من مكان واحد."],
      ["02", "الشحن الجماعي", "استيراد الطلبات ومراجعتها ومتابعة تقدمها."],
      ["03", "المحفظة والفواتير", "وضوح الأرصدة والحجوزات وسجل المعاملات."],
      ["04", "ربط المتاجر", "إضافة طلبات المتاجر إلى سير عمل الشحن."],
    ],
    footer: "أرامكس أولاً. بنية تدعم إضافة المزيد من الناقلين.", language: "English", languageLabel: "Switch to English", technical: "حالة التأسيس", docs: "حالة واجهة البرمجة", safe: "الشحن الفعلي معطل",
  },
} as const;
