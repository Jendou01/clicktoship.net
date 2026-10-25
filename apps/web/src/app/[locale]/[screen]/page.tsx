import { MerchantDashboard } from "@/components/merchant-dashboard";
import { CustomsScreen } from "@/components/customs-screen";
import { customsScreens,type CustomsView } from "@/lib/customs";
import { TrackingScreen } from "@/components/tracking-screen";
import { trackingScreens, type TrackingScreen as TrackingView } from "@/lib/tracking";
import { BulkScreen } from "@/components/bulk-screen";
import { bulkScreens, type BulkView } from "@/lib/bulk";
import { WorkspaceNavigation } from "@/components/workspace-navigation";
import { ShippingScreen } from "@/components/shipping-screen";
import { shippingScreens, type ShippingView } from "@/lib/shipping";
import { CarrierScreen } from "@/components/carrier-screen";
import { carrierScreens, type CarrierScreen as CarrierView } from "@/lib/carriers";
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

export function generateStaticParams() { return [...customsScreens, ...trackingScreens, ...bulkScreens, ...screenNames, ...managementScreens, ...financeScreens, ...pricingScreens, ...carrierScreens, ...shippingScreens].map((screen) => ({ screen })); }
export default async function Page({ params }: { params: Promise<{ locale: string; screen: string }> }) {
  const { locale, screen } = await params;
  if (!isLocale(locale) || ![...customsScreens, ...trackingScreens, ...bulkScreens, ...screenNames, ...managementScreens, ...financeScreens, ...pricingScreens, ...carrierScreens, ...shippingScreens].includes(screen as Screen)) notFound();
  if (screen === "dashboard") return <MerchantDashboard key={locale} locale={locale} />;
  if (customsScreens.includes(screen as CustomsView)) return <CustomsScreen key={`${locale}:${screen}`} locale={locale} screen={screen as CustomsView}/>;
  if (trackingScreens.includes(screen as TrackingView)) return <TrackingScreen key={`${locale}:${screen}`} locale={locale} screen={screen as TrackingView} />;
  if (bulkScreens.includes(screen as BulkView)) return <BulkScreen key={`${locale}:${screen}`} locale={locale} screen={screen as BulkView} />;
  if (shippingScreens.includes(screen as ShippingView)) return <><WorkspaceNavigation locale={locale} screen={screen} /><ShippingScreen key={`${locale}:${screen}`} locale={locale} screen={screen as ShippingView} /></>;
  if (carrierScreens.includes(screen as CarrierView)) return <><WorkspaceNavigation locale={locale} screen={screen} /><CarrierScreen key={`${locale}:${screen}`} locale={locale} screen={screen as CarrierView} /></>;
  if (pricingScreens.includes(screen as PricingView)) return <><WorkspaceNavigation locale={locale} screen={screen} /><PricingScreen key={`${locale}:${screen}`} locale={locale} screen={screen as PricingView} /></>;
  if (financeScreens.includes(screen as FinanceView)) return <><WorkspaceNavigation locale={locale} screen={screen} /><FinanceScreen key={`${locale}:${screen}`} locale={locale} screen={screen as FinanceView} /></>;
  if (managementScreens.includes(screen as ManagementView)) return <><ManagementScreen key={`${locale}:${screen}`} locale={locale} screen={screen as ManagementView} /></>;
  if (["dashboard", "team", "security", "organizations", "onboarding"].includes(screen)) return <><IdentityScreen sharedShell key={`${locale}:${screen}`} locale={locale} screen={screen as Screen} /></>;
  return <IdentityScreen key={`${locale}:${screen}`} locale={locale} screen={screen as Screen} />;
}
