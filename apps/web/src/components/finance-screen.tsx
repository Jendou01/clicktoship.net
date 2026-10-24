"use client";

import { useEffect, useState, type FormEvent } from "react";
import { requestKey } from "@/lib/request-key";
import { api } from "@/lib/api";
import { financeCopy, formatMinor, parseMinor, type FinanceScreen } from "@/lib/finance";
import type { Locale } from "@/lib/i18n";

type Wallet = { id: string; currency: string; exponent: number; posted: string; reserved: string; available: string; version: string; frozen: boolean };
type Transaction = { id: string; sequence: string; delta: string; posted_after: string; created_at: string };
type Hold = { id: string; original: string; captured: string; released: string; remaining?: string; expires_at: string; protected: boolean; unknown: boolean; events?: { id: string; kind: string; amount: string }[] };
type Grant = { actor_id: string; id: string; merchant_id: string; capabilities: string[]; approved_by: string | null; revoked_at: string | null; expires_at: string; session_id: string };
type Correction = { id: string; version: number; maker_id: string; payload: { kind: string; amount: string; wallet_id: string; reference?: string }; reason: string; approved_at: string | null; revoked_at: string | null; executed_operation: string | null; expires_at: string };
type Run = { id: string; matches: boolean; currency: string; created_at: string };
const button = "rounded bg-teal-800 px-4 py-2 text-white disabled:opacity-50";
const input = "rounded border border-slate-400 p-2 text-slate-900 bg-white";
export function FinanceScreen({ locale, screen }: { locale: Locale; screen: FinanceScreen }) {
  const t = financeCopy[locale]; const admin = screen === "admin-finance";
  const [wallets, setWallets] = useState<Wallet[]>([]); const [walletId, setWalletId] = useState("");
  const [grants, setGrants] = useState<Grant[]>([]); const [grantId, setGrantId] = useState("");
  const [tab, setTab] = useState("transactions"); const [transactions, setTransactions] = useState<Transaction[]>([]); const [holds, setHolds] = useState<Hold[]>([]); const [hold, setHold] = useState<Hold | null>(null);
  const [corrections, setCorrections] = useState<Correction[]>([]); const [runs, setRuns] = useState<Run[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [message, setMessage] = useState(""); const [busy, setBusy] = useState(false); const [enabled, setEnabled] = useState(false);
  const [cutoff, setCutoff] = useState(""); const [opening, setOpening] = useState("0"); const [closing, setClosing] = useState("0"); const [next, setNext] = useState<string | null>(null);
  const [keys, setKeys] = useState<{ business: string; request: string } | null>(null);
  const [kind, setKind] = useState("adjust_credit");
  const wallet = wallets.find((w) => w.id === walletId); const grant = grants.find((g) => g.id === grantId);
  const base = admin && grant ? `/admin/merchants/${grant.merchant_id}` : "";
  const suffix = admin ? `access_grant=${encodeURIComponent(grantId)}` : "";
  async function perform(action: () => Promise<void>) { setBusy(true); setMessage(""); try { await action(); } catch { setMessage(t.error); } finally { setBusy(false); } }
  async function loadWallets(prefix = base, query = suffix) { const data = await api<{ wallets: Wallet[] }>(`${prefix}/wallets${query ? `?${query}` : ""}`); setWallets(data.wallets); setWalletId((current) => data.wallets.some((w) => w.id === current) ? current : data.wallets[0]?.id || ""); }
  useEffect(() => {
    if (admin) { Promise.all([api<{ grants: Grant[] }>("/admin/access-requests"), api<{ user: { id: string } }>("/auth/me")]).then(([d, me]) => { setKeys({ business: requestKey(), request: requestKey() }); setGrants(d.grants.filter((g) => g.actor_id === me.user.id && g.approved_by && !g.revoked_at && new Date(g.expires_at).getTime() > Date.now() && g.capabilities.includes("platform.wallet.view"))); }).catch(() => setMessage(t.error)).finally(() => setLoaded(true)); }
    else { api<{ wallets: Wallet[] }>("/wallets").then((d) => { setWallets(d.wallets); setWalletId(d.wallets[0]?.id || ""); }).catch(() => setMessage(t.error)).finally(() => setLoaded(true)); }
  }, [admin, t.error]);
  async function loadHistory(after?: string) {
    if (!wallet) return;
    const query = new URLSearchParams(suffix); if (after) { query.set(tab === "holds" ? "cursor" : "after", after); if (cutoff && tab !== "holds") query.set("cutoff", cutoff); }
    const path = `${base}/wallets/${wallet.id}/${tab}?${query}`;
    if (tab === "holds") { const d = await api<{ holds: Hold[]; next_cursor: string | null }>(path); setHolds(d.holds); setNext(d.next_cursor); }
    else { const d = await api<{ transactions: Transaction[]; cutoff: string; opening: string; closing: string; next_after: string | null }>(path); setTransactions(d.transactions); setCutoff(d.cutoff); setOpening(d.opening); setClosing(d.closing); setNext(d.next_after); }
  }
  async function loadAdmin() {
    if (!grant) return;
    if (grant.capabilities.includes("platform.finance.correct")) { const d = await api<{ requests: Correction[]; execution_enabled: boolean }>(`${base}/financial-requests?${suffix}`); setCorrections(d.requests); setEnabled(d.execution_enabled); }
    if (grant.capabilities.includes("platform.finance.reconcile")) { const d = await api<{ runs: Run[] }>(`${base}/reconciliation?${suffix}`); setRuns(d.runs); }
  }
  async function requestCorrection(e: FormEvent<HTMLFormElement>) {
    e.preventDefault(); const form = e.currentTarget; const values = new FormData(form);
    await perform(async () => {
      if (!wallet || !keys) throw new Error("No wallet");
      const amount = kind === "reverse" ? "0" : parseMinor(String(values.get("amount")), wallet.exponent);
      await api(`${base}/financial-requests`, "POST", { access_grant: grantId, wallet_id: wallet.id, kind, amount, reference: values.get("reference") || null, reason: values.get("reason"), business_key: keys.business, request_key: keys.request });
      setKeys({ business: requestKey(), request: requestKey() }); form.reset(); setKind("adjust_credit"); await loadAdmin(); setMessage(t.done);
    });
  }
  const money = (value: string) => formatMinor(value, wallet?.currency || "SAR", wallet?.exponent ?? 2);
  return <main dir={locale === "ar" ? "rtl" : "ltr"} className="mx-auto max-w-6xl space-y-6 p-6 text-slate-900">

    <h1 className="text-3xl font-bold">{admin ? t.admin : t.wallet}</h1><p>{t.fundingOff}</p>{admin && <p>{t.accounting}</p>}
    {!loaded && <p>{t.loading}</p>}{message && <p role="status">{message}</p>}
    {admin && <><label htmlFor="finance-grant">{t.grant}</label><select id="finance-grant" className={input} value={grantId} onChange={(e) => { const id = e.target.value; const g = grants.find((v) => v.id === id); setGrantId(id); setWallets([]); setTransactions([]); setHolds([]); setCorrections([]); setRuns([]); setHold(null); if (g) void perform(() => loadWallets(`/admin/merchants/${g.merchant_id}`, `access_grant=${id}`)); }}><option value="">{t.choose}</option>{grants.map((g) => <option key={g.id} value={g.id}>{g.merchant_id} · {g.id}</option>)}</select>{!grant && <p>{t.noGrant}</p>}</>}
    {!admin && loaded && !wallets.length && <button className={button} disabled={busy} onClick={() => void perform(async () => { await api("/wallets", "POST", { currency: "SAR" }); await loadWallets(); })}>{t.open}</button>}
    {wallets.length > 0 && <><label htmlFor="finance-wallet">{t.currency}</label><select id="finance-wallet" className={input} value={walletId} onChange={(e) => { setWalletId(e.target.value); setTransactions([]); setHolds([]); setHold(null); }}>{wallets.map((w) => <option key={w.id} value={w.id}>{w.currency}</option>)}</select></>}
    {wallet && <><dl className="grid gap-4 sm:grid-cols-3">{(["posted", "reserved", "available"] as const).map((field) => <div key={field} className="rounded border p-4"><dt>{t[field]}</dt><dd><bdi dir="ltr">{money(wallet[field])}</bdi></dd></div>)}</dl>{wallet.frozen && <p role="alert">{t.frozen}</p>}
      <div className="flex flex-wrap gap-3">{(["transactions", "holds", "statement"] as const).map((view) => <button key={view} className={button} aria-pressed={tab === view} onClick={() => { setTab(view); setTransactions([]); setHolds([]); setHold(null); setNext(null); }}>{t[view]}</button>)}<button className={button} disabled={busy} onClick={() => void perform(async () => { await loadWallets(); await loadHistory(); if (admin) await loadAdmin(); })}>{t.refresh}</button></div>
      {transactions.length === 0 && tab !== "holds" && <p>{locale === "ar" ? "لا توجد معاملات معروضة. حدّث لتحميل السجل؛ التمويل الفعلي معطل." : "No transactions displayed. Refresh to load history; real funding is disabled."}</p>}{holds.length === 0 && tab === "holds" && <p>{locale === "ar" ? "لا توجد حجوزات معروضة. حدّث لتحميل السجل." : "No reservations displayed. Refresh to load history."}</p>}{tab === "statement" && <p>{t.cutoff}: {cutoff || "—"} · {t.opening}: <bdi dir="ltr">{money(opening)}</bdi> · {t.closing}: <bdi dir="ltr">{money(closing)}</bdi></p>}
      {tab !== "holds" && <table className="w-full text-start"><thead><tr><th>{t.date}</th><th>{t.change}</th><th>{t.posted}</th></tr></thead><tbody>{transactions.map((row) => <tr key={row.id}><td><time>{row.created_at}</time><br/><bdi>{row.id}</bdi></td><td><bdi dir="ltr">{money(row.delta)}</bdi></td><td><bdi dir="ltr">{money(row.posted_after)}</bdi></td></tr>)}</tbody></table>}
      {tab === "holds" && holds.map((h) => <article className="rounded border p-3" key={h.id}><bdi>{h.id}</bdi><p>{t.original}: <bdi dir="ltr">{money(h.original)}</bdi> · {t.captured}: <bdi dir="ltr">{money(h.captured)}</bdi> · {t.released}: <bdi dir="ltr">{money(h.released)}</bdi></p>{h.unknown ? <p>{t.unknown}</p> : h.protected && <p>{t.protected}</p>}<button className={button} disabled={busy} onClick={() => void perform(async () => setHold((await api<{ hold: Hold }>(`${base}/wallets/${wallet.id}/holds/${h.id}?${suffix}`)).hold))}>{t.detail}</button></article>)}
      {hold && <section aria-label={t.timeline}><h2>{t.timeline}</h2><p>{t.remaining}: <bdi dir="ltr">{money(hold.remaining || "0")}</bdi> · {t.expires}: {hold.expires_at}</p>{hold.events?.map((event) => <p key={event.id}>{({ reserve: t.holds, capture: t.captured, release: t.released, expire: t.expires, protect: t.protected, unknown: t.unknown } as Record<string, string>)[event.kind] || event.kind}: <bdi dir="ltr">{money(event.amount)}</bdi></p>)}</section>}
      {next && <button className={button} disabled={busy} onClick={() => void perform(() => loadHistory(next))}>{t.next}</button>}
    </>}
    {admin && grant && wallet && <section className="space-y-4"><p>{t.review}</p>{grant.capabilities.includes("platform.finance.correct") && <><h2 className="text-xl font-bold">{t.request}</h2><form className="grid gap-3" onSubmit={requestCorrection}><label htmlFor="finance-kind">{t.kind}</label><select id="finance-kind" className={input} value={kind} onChange={(e) => setKind(e.target.value)}><option value="adjust_credit">{t.credit}</option><option value="adjust_debit">{t.debit}</option><option value="refund">{t.refund}</option><option value="reverse">{t.reverse}</option></select>{kind !== "reverse" && <><label htmlFor="finance-amount">{t.amount} ({wallet.currency})</label><input id="finance-amount" name="amount" className={input} inputMode="decimal" required /></>}{["refund", "reverse"].includes(kind) && <><label htmlFor="finance-reference">{t.reference}</label><input id="finance-reference" name="reference" className={input} required /></>}<label htmlFor="finance-reason">{t.reason}</label><textarea id="finance-reason" name="reason" className={input} minLength={10} maxLength={500} required/><button className={button} disabled={busy || !keys}>{t.submit}</button></form><h2>{t.requests}</h2>{corrections.map((c) => <article className="space-y-2 rounded border p-3" key={c.id}><bdi>{c.id}</bdi><p>{({ adjust_credit: t.credit, adjust_debit: t.debit, refund: t.refund, reverse: t.reverse } as Record<string, string>)[c.payload.kind]} · <bdi dir="ltr">{money(c.payload.amount)}</bdi></p><p>{c.reason}</p>{c.payload.reference && <p><bdi>{c.payload.reference}</bdi></p>}<p>{c.executed_operation ? t.executed : c.revoked_at ? t.revoked : c.approved_at ? t.approved : t.pending} · {t.expires}: {c.expires_at}</p>{!c.executed_operation && !c.revoked_at && (["approve", "execute", "revoke"] as const).map((action) => <button className={button} key={action} disabled={busy || (action === "execute" && !enabled)} onClick={() => void perform(async () => { await api(`${base}/financial-requests/${c.id}/${action}`, "POST", { access_grant: grantId, version: c.version, request_key: `${c.id}:${action}:${c.version}` }); await loadAdmin(); await loadWallets(); })}>{t[action]}</button>)}</article>)}</>}
      {grant.capabilities.includes("platform.finance.reconcile") && <><button className={button} disabled={busy} onClick={() => void perform(async () => { await api(`${base}/reconciliation`, "POST", { wallet_id: wallet.id, access_grant: grantId }); await loadAdmin(); })}>{t.reconcile}</button><h2>{t.runs}</h2>{runs.map((run) => <p key={run.id}><bdi>{run.id}</bdi> · {run.currency} · {run.matches ? t.matched : t.discrepancy}</p>)}</>}
    </section>}
  </main>;
}
