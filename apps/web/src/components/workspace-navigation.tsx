"use client";

import { useEffect, useState, useRef } from "react";
import { carrierCopy, carrierScreens } from "@/lib/carriers";
import { pricingCopy, pricingScreens } from "@/lib/pricing";
import { api } from "@/lib/api";
import { dashboardCopy } from "@/lib/dashboard";
import { PlatformNavigation } from "@/components/platform-navigation";
import { localeHref } from "@/lib/navigation-context";
export { localeHref } from "@/lib/navigation-context";
import type { Locale } from "@/lib/i18n";

export const merchantLinks = [
  ["store-integrations", "integrations.read", "Store integrations", "تكامل المتاجر"],
  ["store-orders", "orders.read", "Connected-store orders", "طلبات المتاجر المرتبطة"],
  ["international", "customs.read", "International customs", "الجمارك الدولية"],
  ["tracking", "tracking.read", "Shipment tracking", "تتبع الشحنات"],
  ["bulk", "bulk.read", "Bulk shipping", "الشحن الجماعي"],
  ["shipments", "shipments.read", "Shipments", "الشحنات"],
  ["shipment-create", "shipments.write", "Create shipment", "إنشاء شحنة"],
  ["pickups", "pickups.read", "Pickups", "طلبات الاستلام"],
  ["rates", "rates.read", "Shipping rates", "أسعار الشحن"],
  ["available-rates", "rates.read", "Configured services", "الخدمات المهيأة"],
  ["quote-history", "rates.read", "Quote history", "سجل تقديرات الأسعار"],
  ["addresses", "addresses.view", "Address book", "دفتر العناوين"],
  ["wallet", "wallet.read", "Wallet", "المحفظة"],
  ["team", "team.view", "Team", "الفريق"],
  ["invitations", "team.manage", "Invitations", "الدعوات"],
  ["organization-settings", "settings.manage", "Organization settings", "إعدادات المنشأة"],
  ["business-verification", "profile.view", "Business verification", "التحقق من المنشأة"],
  ["shipment-documents", "shipments.documents", "Shipping documents", "وثائق الشحن"],
  ["business-profile", "profile.view", "Organization", "المنشأة"],
] as const;
const platformLinks = [
  ["platform-overview", "platform.overview", "Platform overview", "نظرة عامة على المنصة"],
  ["admin-customs", "platform.customs.view", "Customs administration", "إدارة الجمارك"],
  ["admin-tracking", "platform.tracking.health", "Tracking administration", "إدارة التتبع"],
  ["admin-merchants", "platform.directory", "Merchant administration", "إدارة التجار"],
  ["admin-access", "platform.access.request", "Access requests", "طلبات الوصول"],
  ["admin-finance", "platform.wallet.view", "Finance administration", "الإدارة المالية"],
  ["admin-pricing", "platform.pricing.view", "Pricing administration", "إدارة التسعير"],
  ["carriers", "platform.carriers.view", "Carrier configuration", "إعدادات الناقل"],
  ["admin-shipping", "platform.shipments.view", "Shipping administration", "إدارة الشحن"],
] as const;

type Context = { merchant: string; permissions: string[]; platform?: string[] };
export function WorkspaceNavigation(props: {locale: Locale; screen: string; context?: Context}) {
 const platform=props.screen.startsWith("admin-")||props.screen.startsWith("platform-")||carrierScreens.includes(props.screen as typeof carrierScreens[number])||(pricingScreens.includes(props.screen as typeof pricingScreens[number])&&!["rates","available-rates","quote-history"].includes(props.screen));
 return platform ? <PlatformNavigation locale={props.locale} screen={props.screen} permissions={props.context?.platform}/> : <MerchantNavigation {...props}/>;
}
function MerchantNavigation({ locale, screen, context }: { locale: Locale; screen: string; context?: Context }) {
  const [permissions, setPermissions] = useState<string[]>([]);
  const [platform, setPlatform] = useState<string[]>([]);
  const [merchant, setMerchant] = useState("");
  const [languageHref, setLanguageHref] = useState(localeHref(locale === "en" ? "ar" : "en", screen));
  const [query, setQuery] = useState("");
  const menu = useRef<HTMLDetailsElement>(null);
  const supplied = context !== undefined;
  const suppliedPlatform = context?.platform !== undefined;
  useEffect(() => {
    let live = true;
    if (window.matchMedia?.("(max-width: 639px)").matches && menu.current) menu.current.open = false;
    // Identity/management screens supply their already-authorized context. Operational screens
    // first check selection; administrators without a tenant must never probe a merchant API.
    if (!supplied) void api<{ active_merchant_id: string | null }>("/auth/me").then(async (me) => {
      if (!me.active_merchant_id || !live) return;
      const d = await api<{ merchant: { name: string }; permissions: string[] }>("/merchants/current", "GET", undefined, me.active_merchant_id);
      if (live) { setPermissions(d.permissions); setMerchant(d.merchant.name); }
    }).catch(() => {});
    if (!suppliedPlatform) void api<{ platform_permissions: string[] }>("/auth/mfa").then((d) => { if (live) setPlatform(d.platform_permissions); }).catch(() => {});
    const update = () => setLanguageHref(localeHref(locale === "en" ? "ar" : "en", screen, window.location.search));
    update(); window.addEventListener("shipment-context", update); window.addEventListener("popstate", update);
    return () => { live = false; window.removeEventListener("shipment-context", update); window.removeEventListener("popstate", update); };
  }, [locale, screen, supplied, suppliedPlatform]);
  const label = (en: string, ar: string) => locale === "en" ? en : ar;
  const currentPermissions = context?.permissions ?? permissions;
  const currentPlatform = context?.platform ?? platform;
  const currentMerchant = context?.merchant ?? merchant;
  const order = ["shipments", "shipment-create", "bulk", "tracking", "international", "pickups", "rates", "wallet", "team", "addresses", "organization-settings"];
  const links = merchantLinks.filter(([, p, en, ar]) => currentPermissions.includes(p) && `${en} ${ar}`.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase())).sort(([a], [b]) => { const rank = (v: string) => order.includes(v) ? order.indexOf(v) : 99; return rank(a) - rank(b); });
  return <header dir={locale === "ar" ? "rtl" : "ltr"} className="workspace-header border-b bg-white p-5 text-slate-900"><div className="mx-auto max-w-6xl space-y-4">
    <div className="flex flex-wrap items-center justify-between gap-3"><a className="text-xl font-bold text-teal-900" href={`/${locale}/dashboard`}><bdi dir="ltr">ClickToShip.</bdi></a><a aria-label={label("Switch to Arabic", "Switch to English")} href={languageHref} hrefLang={locale === "en" ? "ar" : "en"}>{locale === "en" ? "العربية" : "English"}</a></div>
    <p>{label("Selected organization", "المنشأة المحددة")}: <bdi>{currentMerchant || "—"}</bdi> · <a href={`/${locale}/organizations`}>{label("Switch organization", "تبديل المنشأة")}</a></p>
    <details ref={menu} className="workspace-menu" open><summary>{label("Workspace navigation", "التنقل في مساحة العمل")}</summary><label className="navigation-search">{dashboardCopy[locale].menuSearch}<input type="search" value={query} onChange={e=>setQuery(e.target.value)} /></label><nav aria-label={label("Workspace navigation", "التنقل في مساحة العمل")} className="mt-3 flex flex-wrap gap-3"><a aria-current={screen === "dashboard" ? "page" : undefined} href={`/${locale}/dashboard`}>{label("Dashboard", "لوحة التحكم")}</a>{links.map(([route, , en, ar]) => <a key={route} aria-current={screen === route ? "page" : undefined} href={`/${locale}/${route}`}>{label(en, ar)}</a>)}<a href={`/${locale}/mfa`}>{label("MFA", "المصادقة متعددة العوامل")}</a><a href={`/${locale}/security`}>{label("Account security", "أمان الحساب")}</a></nav></details>
    {platformLinks.some(([, p]) => currentPlatform.includes(p)) && <details><summary>{label("Platform administration", "إدارة المنصة")}</summary><nav className="mt-3 flex flex-wrap gap-3">{platformLinks.filter(([, p]) => currentPlatform.includes(p)).map(([route, , en, ar]) => <a key={route} href={`/${locale}/${route}`}>{label(en, ar)}</a>)}</nav>{currentPlatform.includes("platform.carriers.view") && <nav aria-label={label("Carrier configuration", "إعدادات الناقل")} className="flex flex-wrap gap-3">{carrierScreens.map((route) => <a key={route} href={`/${locale}/${route}`}>{carrierCopy[locale][route]}</a>)}</nav>}{currentPlatform.includes("platform.pricing.view") && <nav aria-label={label("Pricing configuration", "إعدادات التسعير")} className="flex flex-wrap gap-3">{pricingScreens.filter((route) => !["rates", "available-rates", "quote-history"].includes(route)).map((route) => <a key={route} href={`/${locale}/${route}`}>{(pricingCopy[locale] as Record<string, string>)[({ "admin-pricing": "admin", "carrier-costs": "contracts", "merchant-pricing": "merchant", "pricing-tiers": "tiers", "shipping-zones": "zones", "pricing-fees": "fees", "pricing-rules": "rules", "pricing-audit": "audit" } as Record<string, string>)[route]]}</a>)}</nav>}</details>}
  </div></header>;
}
