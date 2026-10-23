import { selectMerchantOption } from "./merchant-directory";
import { test, expect, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import { randomUUID } from "node:crypto";
type Account = { email: string; password: string; recovery_codes?: string[] };
const accounts = JSON.parse(readFileSync(process.env.E2E_FIXTURE_FILE || "/tmp/clicktoship-e2e-credentials.json", "utf8")) as Record<"financeOwner" | "adminC" | "adminD", Account>;
async function login(page: Page, account: Account) {
  await page.goto("/en/login"); await page.getByLabel("Email address", { exact: true }).fill(account.email); await page.getByLabel("Password", { exact: true }).fill(account.password);
  await page.getByRole("button", { name: "Continue", exact: true }).click(); await expect(page).toHaveURL(/\/en\/organizations$/);
}
async function merchant(page: Page, name: string) {
  await login(page, accounts.financeOwner); await page.goto("/en/onboarding"); await page.getByLabel("Organization name", { exact: true }).fill(name);
  await page.getByRole("button", { name: "Create organization", exact: true }).click(); await expect(page).toHaveURL(/\/en\/dashboard$/);
  await page.goto("/en/wallet"); await page.getByRole("button", { name: "Open SAR wallet", exact: true }).click(); await expect(page.getByText("0.00 SAR")).toHaveCount(3);
}
async function mfa(page: Page, account: Account, retry: number) {
  await login(page, account); await page.goto("/en/mfa"); const form = page.getByRole("button", { name: "Verify MFA", exact: true }).locator("..");
  await form.getByLabel("Current password", { exact: true }).fill(account.password); await form.getByLabel("Authenticator or recovery code", { exact: true }).fill(account.recovery_codes![6 + retry]);
  await form.getByRole("button", { name: "Verify MFA", exact: true }).click(); await expect(page.getByRole("status")).toContainText("Changes saved");
}
async function requestGrant(page: Page, name: string) {
  await page.goto("/en/admin-access"); await selectMerchantOption(page, name);
  await page.getByLabel("Reason (at least 10 characters)", { exact: true }).fill(`Financial review ${name}`);
  await page.getByLabel("Requested access", { exact: true }).selectOption("platform.finance.correct");
  const response = page.waitForResponse((r) => r.url().endsWith("/api/v1/admin/access-requests") && r.request().method() === "POST");
  await page.getByRole("button", { name: "Request merchant access", exact: true }).click();
  const body = await (await response).json(); await expect(page.getByRole("status")).toContainText("Changes saved"); return body.data.id as string;
}
async function approveGrant(page: Page, name: string) {
  await page.goto("/en/admin-access"); await selectMerchantOption(page, name); const article = page.getByRole("article").filter({ has: page.getByRole("heading", { name, exact: true }) });
  await article.getByRole("button", { name: "Approve access", exact: true }).click(); await expect(page.getByRole("status")).toContainText("Changes saved");
}
test("empty SAR wallet, statement and Arabic balances keep funding unavailable", async ({ page }) => {
  await merchant(page, `Wallet browser ${randomUUID()}`);
  await page.getByRole("button", { name: "Statement", exact: true }).click(); await page.getByRole("button", { name: "Refresh", exact: true }).click();
  await expect(page.getByText(/Statement cutoff: 0/)).toBeVisible();
  await page.goto("/ar/wallet"); await expect(page.getByRole("main")).toHaveAttribute("dir", "rtl"); await expect(page.getByText("الرصيد المتاح", { exact: true })).toBeVisible();
  await expect(page.getByText("تمويل المحفظة والسحب ورسوم الشحن غير متاحة.")).toBeVisible(); await expect(page.getByText("0.00 SAR")).toHaveCount(3);
});
test("two administrators independently review a correction while execution stays disabled", async ({ page, browser }, testInfo) => {
  const name = `Finance approvals ${randomUUID()}`; await merchant(page, name); await mfa(page, accounts.adminC, testInfo.retry); const grantA = await requestGrant(page, name);
  const context = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || "http://127.0.0.1:8080" }); const other = await context.newPage();
  try {
    await mfa(other, accounts.adminD, testInfo.retry); await approveGrant(other, name); const grantB = await requestGrant(other, name);
    await approveGrant(page, name);
    await page.goto("/en/admin-finance"); await page.getByLabel("Approved merchant access", { exact: true }).selectOption(grantA);
    await page.getByLabel("Amount (SAR)", { exact: true }).fill("5.00"); await page.getByLabel("Reason (at least 10 characters)", { exact: true }).fill(`Reviewed correction ${name}`);
    await page.getByRole("button", { name: "Submit for independent approval", exact: true }).click(); await expect(page.getByText("Pending approval", { exact: false })).toBeVisible();
    await other.goto("/en/admin-finance"); await other.getByLabel("Approved merchant access", { exact: true }).selectOption(grantB); await other.getByRole("button", { name: "Refresh", exact: true }).click();
    const correction = other.getByRole("article").filter({ hasText: `Reviewed correction ${name}` }); await correction.getByRole("button", { name: "Approve correction", exact: true }).click();
    await expect(correction.getByText(/^Approved ·/)).toBeVisible(); await expect(correction.getByRole("button", { name: "Execute approved correction", exact: true })).toBeDisabled();
    await expect(other.getByText("0.00 SAR")).toHaveCount(3);
  } finally { await context.close(); }
});
