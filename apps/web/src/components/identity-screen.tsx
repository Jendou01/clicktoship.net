"use client";

import { useEffect, useState, useSyncExternalStore, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { useEmailLink } from "@/lib/email-link";
import { WorkspaceNavigation, merchantLinks } from "@/components/workspace-navigation";
import { api, ApiError } from "@/lib/api";
import { authCopy, roleLabels, type Screen } from "@/lib/auth-copy";
import type { Locale } from "@/lib/i18n";

type Profile = { user: { id: string; name: string; email: string; email_verified: boolean }; active_merchant_id: string | null };
type Organization = { id: string; name: string; role: string };
type Member = { id: string; name: string; email: string; role: string; status: "active" | "suspended" | "removed"; version: number };
type Session = { id: string; current: boolean; last_seen_at: string; expires_at: string };
type Token = { id: string; name: string; abilities: string[]; expires_at: string };
type Role = { code: string; name: string; permissions: string[] };
const subscribeHydration = () => () => {};
const clientReady = () => true;
const serverReady = () => false;
const inputClass = "mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-950 focus:outline-2 focus:outline-offset-2 focus:outline-teal-700";
const buttonClass = "rounded-xl bg-teal-800 px-5 py-3 text-sm font-semibold text-white hover:bg-teal-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:cursor-wait disabled:opacity-50";
const linkClass = "font-medium text-teal-800 underline underline-offset-4";

export function IdentityScreen({ locale, screen, sharedShell = false }: { locale: Locale; screen: Screen; sharedShell?: boolean }) {
  const t = authCopy[locale];
  const hydrated = useSyncExternalStore(subscribeHydration, clientReady, serverReady);
  const [profile, setProfile] = useState<Profile | null>(null);
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [permissions, setPermissions] = useState<string[]>([]);
  const [members, setMembers] = useState<Member[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [sessions, setSessions] = useState<Session[]>([]);
  const [tokens, setTokens] = useState<Token[]>([]);
  const [newToken, setNewToken] = useState("");
  const { values: secret, clear: clearSecret, read: readEmailLink } = useEmailLink();
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [loading, setLoading] = useState(!["register", "login", "forgot-password", "reset-password"].includes(screen));
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const active = profile?.active_merchant_id || undefined;
  const organization = organizations.find((item) => item.id === active);
  const go = (target: string) => { router.push(`/${locale}/${target}`); router.refresh(); };
  const labelRole = (role: string) => roleLabels[locale][role] || role;
  const handleError = (value: unknown) => {
    const code = value instanceof ApiError ? value.status : 0;
    setError(code === 401 ? t.expired : code === 403 ? t.forbidden : code === 409 ? t.conflict : code === 422 ? t.invalid : code === 429 ? t.limited : code === 419 ? t.expired : code >= 500 ? t.unavailable : t.error);
  };

  useEffect(() => {
    const fragment = readEmailLink();
    if (window.location.hash) window.history.replaceState(null, "", window.location.pathname);
    const publicScreen = ["register", "login", "forgot-password", "reset-password"].includes(screen);
    if (publicScreen) return;
    let cancelled = false;
    (async () => {
      try {
        const me = await api<Profile>("/auth/me");
        if (cancelled) return;
        setProfile(me);
        if (!me.user.email_verified && screen !== "verify-email") { go("verify-email"); return; }
        if (screen === "verify-email") { setLoading(false); return; }
        const orgs = await api<{ merchants: Organization[] }>("/merchants");
        if (cancelled) return;
        setOrganizations(orgs.merchants);
        if (["dashboard", "team", "security"].includes(screen)) {
          if (!me.active_merchant_id || !orgs.merchants.some((o) => o.id === me.active_merchant_id)) { go("organizations"); return; }
          const current = await api<{ permissions: string[] }>("/merchants/current", "GET", undefined, me.active_merchant_id);
          if (cancelled) return;
          setPermissions(current.permissions);
          if (screen === "team" && current.permissions.includes("team.view")) {
            const team = await api<{ memberships: Member[]; pagination: { pages: number } }>("/team", "GET", undefined, me.active_merchant_id);
            setMembers(team.memberships); setPages(team.pagination.pages);
            if (current.permissions.includes("roles.view")) setRoles((await api<{ roles: Role[] }>("/roles", "GET", undefined, me.active_merchant_id)).roles);
          }
          if (screen === "security") {
            setSessions((await api<{ sessions: Session[] }>("/auth/sessions")).sessions);
            if (current.permissions.includes("tokens.manage")) setTokens((await api<{ tokens: Token[] }>("/tokens", "GET", undefined, me.active_merchant_id)).tokens);
          }
        }
      } catch (value) {
        if (value instanceof ApiError && value.status === 401) {
          go("login" + (fragment.verification ? `#verification=${encodeURIComponent(fragment.verification)}` : ""));
        } else handleError(value);
      } finally { if (!cancelled) setLoading(false); }
    })();
    return () => { cancelled = true; };
    // A screen/locale navigation creates a fresh view and server-authorized context.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [locale, screen]);

  const action = async (work: () => Promise<void>) => {
    setPending(true); setError(""); setNotice("");
    try { await work(); } catch (value) { handleError(value); } finally { setPending(false); }
  };
  const form = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget));
    void action(async () => {
      if (screen === "login" || screen === "register") {
        const me = await api<Profile>(`/auth/${screen}`, "POST", { ...data, locale });
        if (secret.verification) go(`verify-email#verification=${encodeURIComponent(secret.verification)}`);
        else go(me.user.email_verified ? "organizations" : "verify-email");
      } else if (screen === "forgot-password") {
        await api("/auth/password/forgot", "POST", data); setNotice(t.checkEmail);
      } else if (screen === "reset-password") {
        if (!secret.token || !secret.email) { setError(t.resetMissing); return; }
        await api("/auth/password/reset", "POST", { ...data, token: secret.token, email: secret.email }); setNotice(t.resetDone); clearSecret();
      } else if (screen === "onboarding") {
        await api("/merchants", "POST", data); go("dashboard");
      }
    });
  };
  const field = (name: string, label: string, type = "text", required = true) => <label className="block text-sm font-medium" key={name}>{label}<input className={inputClass} name={name} type={type} required={required} minLength={name.startsWith("password") && screen !== "login" ? 12 : undefined} maxLength={type === "password" ? 128 : type === "email" ? 254 : 120} autoComplete={name === "password" ? screen === "login" ? "current-password" : "new-password" : name === "password_confirmation" ? "new-password" : name === "current_password" ? "current-password" : name === "email" ? "email" : name === "name" ? "name" : "organization"} /></label>;
  const refreshTeam = async (nextPage = page) => {
    const team = await api<{ memberships: Member[]; pagination: { pages: number } }>(`/team?page=${nextPage}`, "GET", undefined, active);
    setMembers(team.memberships); setPages(team.pagination.pages); setPage(nextPage);
  };
  const changeMember = async (member: Member, change: Record<string, unknown>) => {
    if (!window.confirm(t.confirm)) return;
    await action(async () => { await api(`/team/${member.id}`, "PATCH", { version: member.version, ...change }, active); await refreshTeam(); setNotice(t.saved); });
  };
  const canManage = permissions.includes("team.manage");
  const availableRoles = roles.filter((role) => (role.code !== "merchant.owner" || organization?.role === "merchant.owner") && role.permissions.every((p) => permissions.includes(p)));

  return <div className="min-h-screen bg-slate-50 text-slate-950" dir={locale === "ar" ? "rtl" : "ltr"}>
    {sharedShell && <WorkspaceNavigation locale={locale} screen={screen} context={{ merchant: organization?.name || "", permissions }} />}
    {!sharedShell && <header className="border-b border-slate-200 bg-white"><div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-5 py-5">
      <a href={`/${locale}`} className="text-xl font-bold text-teal-900" dir="ltr">ClickToShip.</a>
      <nav className="flex flex-wrap items-center gap-4 text-sm" aria-label={t.dashboard}>
        {profile && <><a className={linkClass} href={`/${locale}/organizations`}>{t.organizations}</a><button disabled={pending || !hydrated} onClick={() => void action(async () => { await api("/auth/logout", "POST"); go("login"); })} className={linkClass}>{t.logout}</button></>}
        <a className={linkClass} href={`/${locale === "en" ? "ar" : "en"}/${screen}`} hrefLang={locale === "en" ? "ar" : "en"}>{locale === "en" ? "العربية" : "English"}</a>
      </nav>
    </div></header>}
    <main className={`mx-auto px-5 py-10 sm:py-16 ${["team", "dashboard", "security", "organizations"].includes(screen) ? "max-w-6xl" : "max-w-xl"}`}>
      {!sharedShell && organization && <p className="mb-3 text-sm text-teal-800">{t.selected}: {organization.name}</p>}
      <h1 className="text-3xl font-semibold tracking-tight">{t[screen]}</h1>
      {!sharedShell && active && <nav aria-label={t.selected} className="mt-5 flex flex-wrap gap-5 text-sm">{merchantLinks.filter(([route, permission]) => permissions.includes(permission) && ["shipments", "shipment-create", "pickups"].includes(route)).map(([route, , en, ar]) => <a className={linkClass} key={route} href={`/${locale}/${route}`}>{locale === "ar" ? ar : en}</a>)}<a className={linkClass} href={`/${locale}/dashboard`}>{t.dashboard}</a>{permissions.includes("rates.read") && <a className={linkClass} href={`/${locale}/rates`}>{locale === "ar" ? "أسعار الشحن" : "Shipping rates"}</a>}{permissions.includes("wallet.read") && <a className={linkClass} href={`/${locale}/wallet`}>{locale === "ar" ? "المحفظة" : "Wallet"}</a>}{permissions.includes("team.view") && <a className={linkClass} href={`/${locale}/team`}>{t.team}</a>}<a className={linkClass} href={`/${locale}/security`}>{t.security}</a><a className={linkClass} href={`/${locale}/business-profile`}>{locale === "ar" ? "إدارة المنشأة" : "Manage organization"}</a><a className={linkClass} href={`/${locale}/mfa`}>{locale === "ar" ? "المصادقة متعددة العوامل" : "Multi-factor authentication"}</a><a className={linkClass} href={`/${locale}/organizations`}>{t.switch}</a></nav>}
      {sharedShell && profile && <button disabled={pending || !hydrated} className={linkClass} onClick={() => void action(async () => { await api("/auth/logout", "POST"); go("login"); })}>{t.logout}</button>}
      {error && <p role="alert" className="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-red-900">{error}</p>}
      {notice && <p role="status" className="mt-6 rounded-xl border border-teal-200 bg-teal-50 p-4 text-teal-950">{notice}</p>}
      {loading ? <p role="status" className="mt-8">{t.loading}</p> : <>
        {["login", "register", "forgot-password", "reset-password", "onboarding"].includes(screen) && <form method="post" onSubmit={form} className="mt-8 space-y-5 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
          {screen === "register" && field("name", t.name)}
          {["login", "register", "forgot-password"].includes(screen) && field("email", t.email, "email")}
          {["login", "register", "reset-password"].includes(screen) && field("password", t.password, "password")}
          {["register", "reset-password"].includes(screen) && <>{field("password_confirmation", t.password_confirmation, "password")}<p className="text-sm leading-6 text-slate-600">{t.passwordHelp}</p></>}
          {screen === "register" && field("merchant_name", t.merchant_name, "text", false)}
          {screen === "onboarding" && field("name", t.organizationName)}
          {screen === "reset-password" && !secret.token && !notice && <p className="text-sm text-amber-900">{t.resetMissing}</p>}
          <button className={buttonClass} disabled={pending || !hydrated || (screen === "reset-password" && !secret.token)}>{pending ? t.working : screen === "onboarding" ? t.createOrganization : t.submit}</button>
          {screen === "login" && <div className="flex flex-wrap justify-between gap-4 text-sm"><a className={linkClass} href={`/${locale}/forgot-password`}>{t.forgot}</a><a className={linkClass} href={`/${locale}/register`}>{t.noAccount}</a></div>}
          {screen !== "login" && <p><a className={linkClass} href={`/${locale}/login`}>{t.returnLogin}</a></p>}
        </form>}
        {screen === "verify-email" && <section className="mt-8 space-y-5 rounded-2xl border border-slate-200 bg-white p-7"><p className="leading-7">{t.verifyHelp}</p>
          {secret.verification && <button disabled={pending || !hydrated} className={buttonClass} onClick={() => void action(async () => { await api("/auth/email/verify", "POST", { verification: secret.verification }); clearSecret(); setNotice(t.verified); setProfile((p) => p ? { ...p, user: { ...p.user, email_verified: true } } : p); })}>{t.verify}</button>}
          {!profile?.user.email_verified && <button disabled={pending || !hydrated} className={linkClass} onClick={() => void action(async () => { await api("/auth/email/resend", "POST"); setNotice(t.checkEmail); })}>{t.resend}</button>}
          {profile?.user.email_verified && <p><a href={`/${locale}/organizations`} className={linkClass}>{t.viewOrganizations}</a></p>}
        </section>}
        {screen === "organizations" && <section className="mt-8"><div className="grid gap-4 sm:grid-cols-2">{organizations.map((org) => <article key={org.id} className="rounded-2xl border border-slate-200 bg-white p-6"><h2 className="text-xl font-semibold">{org.name}</h2><p className="my-4 text-sm text-slate-600">{labelRole(org.role)}</p><button disabled={pending || !hydrated} className={buttonClass} onClick={() => void action(async () => { await api("/merchants/select", "POST", { merchant_id: org.id }); go("dashboard"); })}>{t.submit}</button></article>)}</div>{!organizations.length && <p className="my-6">{t.emptyOrganizations}</p>}<a href={`/${locale}/onboarding`} className={`mt-8 inline-block ${linkClass}`}>{t.newOrganization}</a></section>}
        {screen === "dashboard" && <section className="mt-8 grid gap-5 sm:grid-cols-2"><article className="rounded-2xl bg-teal-950 p-8 text-white"><h2 className="text-2xl font-semibold">{t.welcome}</h2><p className="mt-5 leading-7 text-teal-100">{t.foundationNotice}</p></article><article className="rounded-2xl border border-slate-200 bg-white p-8"><p className="text-sm text-slate-600">{t.role}</p><p className="mt-3 text-xl font-semibold">{labelRole(organization?.role || "")}</p><p className="mt-5 text-sm text-slate-600">{profile?.user.email}</p></article></section>}
        {screen === "team" && (!permissions.includes("team.view") ? <p className="mt-8">{t.denied}</p> : <section className="mt-8 space-y-6">
          {canManage && <form method="post" className="space-y-4 rounded-2xl border border-slate-200 bg-white p-6" onSubmit={(event) => { event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget)); void action(async () => { await api("/team", "POST", data, active); await refreshTeam(1); setNotice(t.saved); }); }}>
            <h2 className="text-xl font-semibold">{t.addMember}</h2><p className="text-sm text-slate-600">{t.memberHelp}</p>{field("email", t.email, "email")}
            <label className="block text-sm font-medium">{t.role}<select name="role" className={inputClass} defaultValue="merchant.viewer">{availableRoles.map((role) => <option key={role.code} value={role.code}>{labelRole(role.code)}</option>)}</select></label><button disabled={pending || !hydrated} className={buttonClass}>{t.addMember}</button><p className="text-xs text-slate-600">{t.ownerHint}</p>
          </form>}
          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table aria-label={t.team} className="team-table w-full text-start text-sm"><thead><tr className="border-b border-slate-200 bg-slate-100 text-start">{[t.name, t.email, t.role, t.status, t.actions].map((title) => <th key={title} className="p-4 text-start">{title}</th>)}</tr></thead><tbody>{members.map((member) => <tr key={member.id} className="border-b border-slate-100"><td data-label={t.name} className="p-4">{member.name}</td><td data-label={t.email} className="p-4" dir="ltr">{member.email}</td><td data-label={t.role} className="p-4">{canManage && (member.role !== "merchant.owner" || organization?.role === "merchant.owner") ? <select aria-label={`${t.role}: ${member.name}`} value={member.role} disabled={pending || !hydrated} onChange={(event) => void changeMember(member, { role: event.target.value })} className="rounded-lg border border-slate-300 p-2">{availableRoles.map((role) => <option key={role.code} value={role.code}>{labelRole(role.code)}</option>)}</select> : labelRole(member.role)}</td><td data-label={t.status} className="p-4">{t[member.status]}</td><td data-label={t.actions} className="p-4">{canManage && (member.role !== "merchant.owner" || organization?.role === "merchant.owner") && <div className="flex flex-wrap gap-3"><button disabled={pending || !hydrated} className={linkClass} onClick={() => void changeMember(member, { status: member.status === "active" ? "suspended" : "active" })}>{member.status === "active" ? t.suspended : t.active}</button><button disabled={pending || !hydrated} className="text-red-800 underline" onClick={() => void changeMember(member, { status: "removed" })}>{t.remove}</button></div>}</td></tr>)}</tbody></table>{!members.length && <p className="p-6">{t.emptyTeam}</p>}</div>
          <div className="flex gap-4"><button className={linkClass} disabled={pending || page <= 1} onClick={() => void action(() => refreshTeam(page - 1))}>←</button><span>{page} / {pages}</span><button className={linkClass} disabled={pending || page >= pages} onClick={() => void action(() => refreshTeam(page + 1))}>→</button></div>
        </section>)}
        {screen === "security" && <section className="mt-8 space-y-8"><div className="rounded-2xl border border-slate-200 bg-white p-6"><h2 className="mb-5 text-xl font-semibold">{t.sessions}</h2><ul className="divide-y divide-slate-100">{sessions.map((session) => <li key={session.id} className="flex flex-wrap items-center justify-between gap-4 py-4"><div><p>{session.current ? t.current : t.sessions}</p><p className="mt-1 text-xs text-slate-600">{t.lastSeen}: {new Date(session.last_seen_at).toLocaleString(locale)}</p></div><button disabled={pending || !hydrated} className="text-red-800 underline" onClick={() => { if (window.confirm(t.confirm)) void action(async () => { await api(`/auth/sessions/${session.id}`, "DELETE"); if (session.current) go("login"); else setSessions((await api<{ sessions: Session[] }>("/auth/sessions")).sessions); }); }}>{t.revoke}</button></li>)}</ul></div>
          {permissions.includes("tokens.manage") && <div className="space-y-6 rounded-2xl border border-slate-200 bg-white p-6"><h2 className="text-xl font-semibold">{t.tokens}</h2><p className="text-sm leading-6 text-slate-600">{t.tokenHelp}</p>
            {newToken && <div className="rounded-xl border border-amber-300 bg-amber-50 p-4"><p className="mb-3 text-sm">{t.tokenWarning}</p><textarea aria-label={t.tokenWarning} readOnly value={newToken} dir="ltr" className="w-full break-all rounded-lg border border-amber-300 bg-white p-3 font-mono text-sm" /></div>}
            <form method="post" className="space-y-4" onSubmit={(event) => { event.preventDefault(); const data = new FormData(event.currentTarget); void action(async () => { const created = await api<{ token: string }>("/tokens", "POST", { name: data.get("name"), current_password: data.get("current_password"), abilities: data.getAll("abilities") }, active); setNewToken(created.token); setTokens((await api<{ tokens: Token[] }>("/tokens", "GET", undefined, active)).tokens); }); }}>
              {field("name", t.tokenName)}{field("current_password", t.current_password, "password")}<fieldset><legend className="mb-3 text-sm font-medium">{t.scopes}</legend><div className="grid gap-3 sm:grid-cols-2">{["merchant.view", "team.view", "team.manage", "roles.view"].filter((p) => permissions.includes(p)).map((permission) => <label key={permission} className="flex items-center gap-3 text-sm"><input type="checkbox" name="abilities" value={permission} defaultChecked={permission === "merchant.view"} />{permission === "merchant.view" ? t.selected : permission === "team.view" ? t.team : permission === "team.manage" ? t.addMember : t.role}</label>)}</div></fieldset><button disabled={pending || !hydrated} className={buttonClass}>{t.createToken}</button>
            </form><ul className="divide-y divide-slate-100">{tokens.map((token) => <li key={token.id} className="flex flex-wrap items-center justify-between gap-4 py-4"><div><p className="font-medium">{token.name}</p><p className="mt-1 text-xs text-slate-600">{t.expires}: {new Date(token.expires_at).toLocaleDateString(locale)}</p></div><button className="text-red-800 underline" disabled={pending || !hydrated} onClick={() => { if (window.confirm(t.confirm)) void action(async () => { await api(`/tokens/${token.id}`, "DELETE", undefined, active); setNewToken(""); setTokens((await api<{ tokens: Token[] }>("/tokens", "GET", undefined, active)).tokens); }); }}>{t.revoke}</button></li>)}</ul>{!tokens.length && <p className="text-sm text-slate-600">{t.noTokens}</p>}
          </div>}
        </section>}
      </>}
    </main>
  </div>;
}
