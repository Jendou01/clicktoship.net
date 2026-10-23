import { test, expect, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import { randomUUID } from "node:crypto";
type Account = { email: string; password: string; recovery_codes: string[] };
const fixture = JSON.parse(readFileSync(process.env.E2E_PRICING_FIXTURE_FILE || "/tmp/clicktoship-pricing-e2e.json", "utf8")) as { adminE: Account; adminF: Account };
async function login(page: Page, account: Account, retry: number) {
  await page.goto("/en/login"); await page.getByLabel("Email address", { exact: true }).fill(account.email); await page.getByLabel("Password", { exact: true }).fill(account.password); await page.getByRole("button", { name: "Continue", exact: true }).click(); await expect(page).toHaveURL(/\/en\/organizations$/);
  await page.goto("/en/mfa"); const form = page.getByRole("button", { name: "Verify MFA", exact: true }).locator(".."); await form.getByLabel("Current password", { exact: true }).fill(account.password); await form.getByLabel("Authenticator or recovery code", { exact: true }).fill(account.recovery_codes[2 + retry]); await form.getByRole("button", { name: "Verify MFA", exact: true }).click(); await expect(page.getByRole("status")).toContainText("Changes saved");
}
test("real independently approved carrier configuration remains unverified and externally disabled", async ({ page, browser }, testInfo) => {
  const name = `Synthetic carrier ${randomUUID()}`; await login(page, fixture.adminE, testInfo.retry); await page.goto("/en/aramex");
  await page.getByLabel("Stable account code", { exact: true }).fill(`test-${randomUUID()}`); await page.getByLabel("English name", { exact: true }).fill(name); await page.getByLabel("Arabic name", { exact: true }).fill("حساب ناقل تجريبي فقط"); await page.getByLabel("Verified source / configuration review reference", { exact: true }).fill("Synthetic browser review, no real carrier entitlement"); await page.getByLabel("Environment", { exact: true }).selectOption("sandbox");
  await page.getByRole("button", { name: "Save draft", exact: true }).click(); await expect(page.getByRole("status")).toHaveText("Changes saved");
  const article = page.getByRole("article").filter({ has: page.getByRole("heading", { name, exact: true }) }); await article.getByRole("button", { name: "Independently approve configuration", exact: true }).click(); await expect(page.getByRole("status")).toContainText("Request unavailable");
  const context = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || "http://127.0.0.1:8080" }); const other = await context.newPage();
  try { await login(other, fixture.adminF, testInfo.retry); await other.goto("/en/aramex"); const pending = other.getByRole("article").filter({ has: other.getByRole("heading", { name, exact: true }) }); await pending.getByRole("button", { name: "Independently approve configuration", exact: true }).click(); await expect(other.getByRole("status")).toHaveText("Changes saved"); await expect(pending).toContainText("Configuration approved; connection unverified"); await expect(pending).toContainText("Credentials missing");
    const statusResponse = other.waitForResponse((r) => r.url().endsWith("/api/v1/admin/carriers") && r.request().method() === "GET");
    await other.goto("/ar/carrier-capabilities"); await expect(other.getByRole("main")).toHaveAttribute("dir", "rtl"); await expect(other.getByRole("table")).toContainText("عقد الاستجابة غير متحقق منه");
    const response = await statusResponse; expect(response.status()).toBe(200); const body = await response.json(); expect(body.data.production_enabled).toBe(false); expect(body.data.capabilities.every((c: { external_enabled: boolean }) => !c.external_enabled)).toBe(true);
  } finally { await context.close(); }
});
