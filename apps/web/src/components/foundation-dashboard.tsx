import { messages, type Locale } from "@/lib/i18n";

export function FoundationDashboard({ locale }: { locale: Locale }) {
  const t = messages[locale];
  return (
    <div dir={locale === "ar" ? "rtl" : "ltr"} className="min-h-screen bg-slate-50 text-slate-900">
      <header className="border-b border-slate-200 bg-white">
        <nav aria-label={t.home} className="mx-auto flex max-w-6xl items-center justify-between gap-6 px-6 py-5">
          <a href={`/${locale}`} className="flex items-center gap-3 font-bold tracking-tight" aria-label="ClickToShip">
            <span aria-hidden="true" className="grid size-9 place-items-center rounded-xl bg-teal-800 text-white">↗</span>
            <span dir="ltr">ClickToShip<span className="text-teal-700">.</span></span>
          </a>
          <a href={locale === "en" ? "/ar" : "/en"} hrefLang={locale === "en" ? "ar" : "en"} aria-label={t.languageLabel} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-700">{t.language}</a>
        </nav>
      </header>
      <main className="mx-auto max-w-6xl px-6 py-12 sm:py-20">
        <section className="grid gap-10 lg:grid-cols-[1.5fr_1fr] lg:items-center" aria-labelledby="welcome">
          <div>
            <p className="mb-5 text-xs font-bold tracking-widest text-teal-800">{t.eyebrow}</p>
            <h1 id="welcome" className="max-w-2xl text-4xl leading-tight font-semibold tracking-tight sm:text-6xl">{t.title}</h1>
            <p className="mt-6 max-w-xl text-lg leading-8 text-slate-600">{t.description}</p>
            <p className="mt-8 inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900"><span aria-hidden="true" className="size-2 rounded-full bg-amber-600" />{t.status}</p>
            <div className="mt-6 flex flex-wrap gap-4 text-sm font-semibold"><a className="rounded-xl bg-teal-800 px-5 py-3 text-white" href={`/${locale}/login`}>{locale === "en" ? "Sign in" : "تسجيل الدخول"}</a><a className="rounded-xl border border-teal-800 px-5 py-3 text-teal-900" href={`/${locale}/register`}>{locale === "en" ? "Create account" : "إنشاء حساب"}</a></div>
            <p role="note" className="mt-4 max-w-xl text-sm leading-6 text-slate-600">{t.notice}</p>
          </div>
          <aside className="rounded-3xl bg-teal-950 p-8 text-white">
            <p className="mb-8 text-xs font-medium tracking-widest text-teal-200">{t.next}</p>
            <div aria-hidden="true" className="mb-6 text-4xl text-teal-300">⌘</div>
            <h2 className="text-2xl font-semibold">{t.milestone}</h2>
            <p className="mt-4 leading-7 text-teal-100">{t.milestoneText}</p>
          </aside>
        </section>
        <section aria-labelledby="workspace" className="mt-20">
          <h2 id="workspace" className="mb-6 text-xl font-semibold">{t.workspace}</h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {t.modules.map(([number, title, description]) => (
              <article key={number} className="rounded-2xl border border-slate-200 bg-white p-6">
                <div className="mb-8 flex items-center justify-between gap-3"><span aria-hidden="true" className="text-sm font-medium text-teal-700">{number}</span><span className="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">{t.planned}</span></div>
                <h3 className="font-semibold">{title}</h3><p className="mt-3 text-sm leading-6 text-slate-600">{description}</p>
              </article>
            ))}
          </div>
        </section>
      </main>
      <footer className="mx-auto flex max-w-6xl flex-wrap justify-between gap-4 border-t border-slate-200 px-6 py-6 text-xs leading-6 text-slate-500"><p>{t.footer}</p><p>{t.safe}</p></footer>
    </div>
  );
}
