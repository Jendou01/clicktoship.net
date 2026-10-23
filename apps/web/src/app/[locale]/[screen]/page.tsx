import { FinanceScreen } from "@/components/finance-screen";
import { financeScreens, type FinanceScreen as FinanceView } from "@/lib/finance";
import { ManagementScreen } from "@/components/management-screen";
import { managementScreens, type ManagementScreen as ManagementView } from "@/lib/management-copy";
import { notFound } from "next/navigation";
import { IdentityScreen } from "@/components/identity-screen";
import { screenNames, type Screen } from "@/lib/auth-copy";
import { isLocale } from "@/lib/i18n";

export function generateStaticParams() { return [...screenNames, ...managementScreens, ...financeScreens].map((screen) => ({ screen })); }
export default async function Page({ params }: { params: Promise<{ locale: string; screen: string }> }) {
  const { locale, screen } = await params;
  if (!isLocale(locale) || ![...screenNames, ...managementScreens, ...financeScreens].includes(screen as Screen)) notFound();
  if (financeScreens.includes(screen as FinanceView)) return <FinanceScreen key={`${locale}:${screen}`} locale={locale} screen={screen as FinanceView} />;
  if (managementScreens.includes(screen as ManagementView)) return <ManagementScreen key={`${locale}:${screen}`} locale={locale} screen={screen as ManagementView} />;
  return <IdentityScreen key={`${locale}:${screen}`} locale={locale} screen={screen as Screen} />;
}
