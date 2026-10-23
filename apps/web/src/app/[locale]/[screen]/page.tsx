import { ManagementScreen } from "@/components/management-screen";
import { managementScreens, type ManagementScreen as ManagementView } from "@/lib/management-copy";
import { notFound } from "next/navigation";
import { IdentityScreen } from "@/components/identity-screen";
import { screenNames, type Screen } from "@/lib/auth-copy";
import { isLocale } from "@/lib/i18n";

export function generateStaticParams() { return [...screenNames, ...managementScreens].map((screen) => ({ screen })); }
export default async function Page({ params }: { params: Promise<{ locale: string; screen: string }> }) {
  const { locale, screen } = await params;
  if (!isLocale(locale) || ![...screenNames, ...managementScreens].includes(screen as Screen)) notFound();
  if (managementScreens.includes(screen as ManagementView)) return <ManagementScreen key={`${locale}:${screen}`} locale={locale} screen={screen as ManagementView} />;
  return <IdentityScreen key={`${locale}:${screen}`} locale={locale} screen={screen as Screen} />;
}
