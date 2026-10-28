import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { isLocale, locales } from "@/lib/i18n";
import "../globals.css";

export const metadata: Metadata = { title: "ClickToShip — Shipping workspace", description: "A unified merchant shipping workspace. Development foundation." };
export function generateStaticParams() { return locales.map((locale) => ({ locale })); }
export default async function LocaleLayout({ children, params }: { children: React.ReactNode; params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  return <html lang={locale} dir={locale === "ar" ? "rtl" : "ltr"}><body>{process.env.CTS_DISPOSABLE_DEMO === "true" && <aside role="note" className="bg-amber-100 p-3 text-center text-sm text-black">{locale === "ar" ? "عرض تجريبي — بيانات اصطناعية قابلة للفقدان. لا شحن أو مدفوعات حقيقية." : "TEST / DEMO — disposable synthetic data. No real shipping or payments."}</aside>}{children}</body></html>;
}
