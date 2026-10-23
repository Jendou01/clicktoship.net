import { test, expect, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import { randomUUID } from "node:crypto";
type Account = { email: string; password: string; recovery_codes?: string[] };
const fixture = JSON.parse(readFileSync(process.env.E2E_PRICING_FIXTURE_FILE || "/tmp/clicktoship-pricing-e2e.json", "utf8")) as { pricingOwner: Account; adminE: Account; adminF: Account; service: string };
async function login(page: Page, account: Account) {
  await page.goto("/en/login"); await page.getByLabel("Email address", { exact: true }).fill(account.email); await page.getByLabel("Password", { exact: true }).fill(account.password);
  await page.getByRole("button", { name: "Continue", exact: true }).click(); await expect(page).toHaveURL(/\/en\/organizations$/);
}
async function mfa(page: Page, account: Account, retry: number) {
  await login(page, account); await page.goto("/en/mfa"); const form = page.getByRole("button", { name: "Verify MFA", exact: true }).locator("..");
  await form.getByLabel("Current password", { exact: true }).fill(account.password); await form.getByLabel("Authenticator or recovery code", { exact: true }).fill(account.recovery_codes![retry]);
  await form.getByRole("button", { name: "Verify MFA", exact: true }).click(); await expect(page.getByRole("status")).toContainText("Changes saved");
}
test("real static pricing quote, immutable history and bilingual estimate boundaries", async ({ page }) => {
  await login(page, fixture.pricingOwner); await page.goto("/en/onboarding"); await page.getByLabel("Organization name", { exact: true }).fill(`Pricing browser ${randomUUID()}`);
  await page.getByRole("button", { name: "Create organization", exact: true }).click(); await expect(page).toHaveURL(/\/en\/dashboard$/);
  await page.goto("/en/rates"); await page.getByLabel("Service", { exact: true }).selectOption(fixture.service);
  const countries = page.getByLabel("Country (ISO code)", { exact: true }); await countries.nth(0).fill("SA"); await countries.nth(1).fill("SA");
  const cities = page.getByLabel("City", { exact: true }); await cities.nth(0).fill("Synthetic Origin"); await cities.nth(1).fill("Synthetic Destination");
  await page.getByLabel("Weight (grams)", { exact: true }).fill("1000"); for (const name of ["Length (mm)", "Width (mm)", "Height (mm)"]) await page.getByLabel(name, { exact: true }).fill("100");
  const response = page.waitForResponse((r) => r.url().endsWith("/api/v1/rates/quote") && r.request().method() === "POST"); await page.getByRole("button", { name: "Calculate estimate", exact: true }).click();
  const result = await response; expect(result.status()).toBe(201); const body = await result.json(); expect(body.data.total_minor).toBe("2000"); expect(body.data.shipping_enabled).toBe(false); expect(body.data.carrier_availability).toBe("not_live_verified");
  for (const secret of ["carrier_cost_minor", "estimated_margin_minor", "selected_versions", "evidence"]) expect(JSON.stringify(body)).not.toContain(secret);
  await expect(page.getByRole("region", { name: "Quote details" })).toContainText("20.00 SAR");
  await page.getByRole("button", { name: "Check validity", exact: true }).click(); await expect(page.getByRole("status")).toHaveText("Current estimate");
  await page.goto("/en/quote-history"); const article = page.getByRole("article").first(); await expect(article).toContainText("20.00 SAR"); await article.getByRole("button", { name: "Quote details", exact: true }).click();
  await page.getByRole("button", { name: "Recalculate", exact: true }).click(); await expect(page.getByRole("region", { name: "Quote details" })).toContainText("Version 2");
  await page.goto("/ar/quote-history"); await expect(page.getByRole("main")).toHaveAttribute("dir", "rtl"); await expect(page.getByRole("heading", { name: "سجل عروض الأسعار", exact: true })).toBeVisible();
});
test("pricing draft requires real recent MFA and another administrator to publish", async ({ page, browser }, testInfo) => {
  const name = `Synthetic tier ${randomUUID()}`; await mfa(page, fixture.adminE, testInfo.retry); await page.goto("/en/pricing-tiers");
  await page.getByLabel("Stable code", { exact: true }).fill(`tier-${randomUUID()}`); await page.getByLabel("English name", { exact: true }).fill(name); await page.getByLabel("Arabic name", { exact: true }).fill("فئة اختبار فقط");
  await page.getByLabel("Verified source / approval reference", { exact: true }).fill("Synthetic browser approval fixture, no real contract");
  await page.getByLabel("Effective from", { exact: true }).fill(new Date(Date.now() - 3600000).toISOString().slice(0, 16)); await page.getByLabel("Effective to", { exact: true }).fill(new Date(Date.now() + 86400000).toISOString().slice(0, 16));
  await page.getByRole("button", { name: "Save draft", exact: true }).click(); await expect(page.getByRole("status")).toHaveText("Changes saved");
  const article = page.getByRole("article").filter({ has: page.getByRole("heading", { name, exact: true }) }); await article.getByRole("button", { name: "Independently publish", exact: true }).click(); await expect(page.getByRole("status")).toContainText("Request unavailable");
  const context = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || "http://127.0.0.1:8080" }); const other = await context.newPage();
  try {
    await mfa(other, fixture.adminF, testInfo.retry); await other.goto("/en/pricing-tiers"); const pending = other.getByRole("article").filter({ has: other.getByRole("heading", { name, exact: true }) });
    await pending.getByRole("button", { name: "Independently publish", exact: true }).click(); await expect(other.getByRole("status")).toHaveText("Changes saved"); await expect(pending).toContainText("Published");
    await other.goto("/ar/pricing-audit"); await expect(other.getByRole("main")).toHaveAttribute("dir", "rtl"); await expect(other.getByRole("table")).toContainText("نشر بموافقة مستقلة");
  } finally { await context.close(); }
});
