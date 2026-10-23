import { notFound } from "next/navigation";
import { IdentityScreen } from "@/components/identity-screen";
import { screenNames, type Screen } from "@/lib/auth-copy";
import { isLocale } from "@/lib/i18n";

export function generateStaticParams() { return screenNames.map((screen) => ({ screen })); }
export default async function Page({ params }: { params: Promise<{ locale: string; screen: string }> }) {
  const { locale, screen } = await params;
  if (!isLocale(locale) || !screenNames.includes(screen as Screen)) notFound();
  return <IdentityScreen key={`${locale}:${screen}`} locale={locale} screen={screen as Screen} />;
}
