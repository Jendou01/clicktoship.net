import { PricingScreen } from "@/components/pricing-screen";
import { pricingScreens, type PricingScreen as PricingView } from "@/lib/pricing";
import { FinanceScreen } from "@/components/finance-screen";
import { financeScreens, type FinanceScreen as FinanceView } from "@/lib/finance";
import { ManagementScreen } from "@/components/management-screen";
import { managementScreens, type ManagementScreen as ManagementView } from "@/lib/management-copy";
import { notFound } from "next/navigation";
import { IdentityScreen } from "@/components/identity-screen";
import { screenNames, type Screen } from "@/lib/auth-copy";
import { isLocale } from "@/lib/i18n";

export function generateStaticParams() { return [...screenNames, ...managementScreens, ...financeScreens, ...pricingScreens].map((screen) => ({ screen })); }
export default async function Page({ params }: { params: Promise<{ locale: string; screen: string }> }) {
  const { locale, screen } = await params;
  if (!isLocale(locale) || ![...screenNames, ...managementScreens, ...financeScreens, ...pricingScreens].includes(screen as Screen)) notFound();
  if (pricingScreens.includes(screen as PricingView)) return <PricingScreen key={`${locale}:${screen}`} locale={locale} screen={screen as PricingView} />;
  if (financeScreens.includes(screen as FinanceView)) return <FinanceScreen key={`${locale}:${screen}`} locale={locale} screen={screen as FinanceView} />;
  if (managementScreens.includes(screen as ManagementView)) return <ManagementScreen key={`${locale}:${screen}`} locale={locale} screen={screen as ManagementView} />;
  return <IdentityScreen key={`${locale}:${screen}`} locale={locale} screen={screen as Screen} />;
}
