import { test, expect, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import { randomUUID } from "node:crypto";
import { createHmac } from "node:crypto";

type Account = { id: string; email: string; password: string; recovery_codes?: string[] };
const accounts = JSON.parse(readFileSync(process.env.E2E_FIXTURE_FILE || "/tmp/clicktoship-e2e-credentials.json", "utf8")) as Record<"adminA" | "adminB" | "owner" | "member", Account>;
async function login(page: Page, account: Account) {
  await page.goto("/en/login");
  await page.getByLabel("Email address", { exact: true }).fill(account.email);
  await page.getByLabel("Password", { exact: true }).fill(account.password);
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await expect(page).toHaveURL(/\/en\/organizations$/);
}
async function createMerchant(page: Page, name: string) {
  await page.goto("/en/onboarding"); await page.getByLabel("Organization name", { exact: true }).fill(name);
  await page.getByRole("button", { name: "Create organization" }).click(); await expect(page).toHaveURL(/\/en\/dashboard$/);
}
// Independent RFC 6238 test helper only; production uses the reviewed OTPHP library.
function totp(secret: string) {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  const bits = [...secret].map((c) => alphabet.indexOf(c).toString(2).padStart(5, "0")).join("");
  const key = Buffer.from(bits.match(/.{8}/g)!.map((byte) => parseInt(byte, 2)));
  const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const hash = createHmac("sha1", key).update(counter).digest(); const offset = hash[19] & 15;
  return ((hash.readUInt32BE(offset) & 0x7fffffff) % 1000000).toString().padStart(6, "0");
}

test("business profile, country-aware addresses, defaults, immutable submission and MFA enrollment", async ({ page }) => {
  await login(page, accounts.owner); await createMerchant(page, `Management browser ${randomUUID()}`);
  await page.goto("/en/business-profile");
  await page.getByLabel("Legal business name", { exact: true }).fill("Synthetic Verified Business");
  await page.getByLabel("Country code", { exact: true }).fill("SA");
  await page.getByLabel("Contact name", { exact: true }).fill("Test contact");
  await page.getByLabel("Contact email", { exact: true }).fill("contact@example.test");
  await page.getByLabel("Contact phone (international format)", { exact: true }).fill("+966501234567");
  await page.getByRole("button", { name: "Save changes", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("Changes saved");
  await page.goto("/en/addresses");
  for (const [label, value] of [["Address label", "Main warehouse"], ["Contact name", "Test contact"], ["Contact phone (international format)", "+966501234567"], ["Country code", "SA"], ["City", "Riyadh"], ["Postal code", "12345"], ["Address line 1", "Synthetic street 20"]]) await page.getByLabel(label, { exact: true }).fill(value);
  await page.getByRole("button", { name: "Create", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Main warehouse" })).toBeVisible();
  await page.goto("/en/organization-settings");
  await page.getByLabel("Default origin address", { exact: true }).selectOption({ label: "Main warehouse" });
  await page.getByLabel("Default return address", { exact: true }).selectOption({ label: "Main warehouse" });
  await page.getByRole("button", { name: "Save changes", exact: true }).click(); await expect(page.getByRole("status")).toContainText("Changes saved");
  await page.goto("/ar/business-verification");
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await page.getByRole("button", { name: "إرسال للمراجعة", exact: true }).click();
  await expect(page.getByRole("button", { name: "إرسال للمراجعة", exact: true })).toHaveCount(0);
  await expect(page.getByText("Synthetic Verified Business", { exact: true })).toBeVisible();
  await page.goto("/en/dashboard"); await expect(page.getByRole("heading", { name: "Your team workspace is ready." })).toBeVisible();
  await page.goto("/en/mfa");
  await page.getByLabel("Current password", { exact: true }).first().fill(accounts.owner.password);
  await page.getByRole("button", { name: "Start enrollment", exact: true }).click();
  const secret = await page.getByLabel("Authenticator setup key", { exact: true }).inputValue();
  const form = page.getByRole("button", { name: "Confirm authenticator", exact: true }).locator("..");
  await form.getByLabel("Current password", { exact: true }).fill(accounts.owner.password);
  await form.getByLabel("Authenticator or recovery code", { exact: true }).fill(totp(secret));
  await form.getByRole("button", { name: "Confirm authenticator", exact: true }).click();
  await expect(page.getByLabel("Recovery codes — copy and store securely now", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Clear displayed secrets", exact: true }).click();
  await expect(page.getByLabel("Recovery codes — copy and store securely now", { exact: true })).toHaveCount(0);
});

test("real invitation email acceptance and independent platform approval before merchant access", async ({ page, browser, request }) => {
  const name = `Access browser ${randomUUID()}`;
  await login(page, accounts.owner); await createMerchant(page, name);
  const mailbox = process.env.MAILPIT_URL || "http://127.0.0.1:8025";
  const previous = await (await request.get(`${mailbox}/api/v1/messages?limit=200`)).json() as { messages: { ID: string }[] };
  const previousIds = new Set(previous.messages.map((m) => m.ID));
  await page.goto("/en/invitations");
  await page.getByLabel("Email address", { exact: true }).fill(accounts.member.email);
  await page.getByRole("button", { name: "Create", exact: true }).click(); await expect(page.getByRole("status")).toContainText("Changes saved");
  let invitation = "";
  await expect.poll(async () => {
    const data = await (await request.get(`${mailbox}/api/v1/messages?limit=200`)).json() as { messages: { ID: string; Subject: string; To: { Address: string }[] }[] };
    const message = data.messages.find((m) => !previousIds.has(m.ID) && m.Subject.includes("team invitation") && m.To.some((to) => to.Address === accounts.member.email));
    if (!message) return "";
    const body = await (await request.get(`${mailbox}/api/v1/message/${message.ID}`)).json() as { Text: string };
    invitation = body.Text.match(/\/en\/invitation#invitation=([a-f0-9]{64})/)?.[1] || ""; return invitation;
  }, { timeout: 30000 }).not.toBe("");
  const second = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || "http://127.0.0.1:8080" }); const member = await second.newPage();
  await login(member, accounts.member); await member.goto(`/en/invitation#invitation=${invitation}`);
  await member.getByRole("button", { name: "Accept invitation", exact: true }).click(); await expect(member).toHaveURL(/\/en\/organizations$/);
  await expect(member.getByRole("heading", { name, exact: true })).toBeVisible(); await second.close();
  await login(page, accounts.adminA); await page.goto("/en/admin-merchants"); await expect(page.getByRole("alert").filter({ hasText: "Access denied" })).toBeVisible();
  await page.goto("/en/mfa");
  const challenge = page.getByRole("button", { name: "Verify MFA", exact: true }).locator("..");
  await challenge.getByLabel("Current password", { exact: true }).fill(accounts.adminA.password);
  await challenge.getByLabel("Authenticator or recovery code", { exact: true }).fill(accounts.adminA.recovery_codes!.shift()!);
  await challenge.getByRole("button", { name: "Verify MFA", exact: true }).click(); await expect(page.getByRole("status")).toContainText("Changes saved");
  await page.goto("/en/admin-access"); await page.getByLabel("Organization", { exact: true }).selectOption({ label: name });
  await page.getByLabel("Reason (at least 10 characters)", { exact: true }).fill(`Review ${name}`);
  await page.getByRole("button", { name: "Request merchant access", exact: true }).click(); await expect(page.getByRole("status")).toContainText("Changes saved");
  const approval = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || "http://127.0.0.1:8080" }); const admin = await approval.newPage();
  await login(admin, accounts.adminB); await admin.goto("/en/mfa");
  const verify = admin.getByRole("button", { name: "Verify MFA", exact: true }).locator("..");
  await verify.getByLabel("Current password", { exact: true }).fill(accounts.adminB.password); await verify.getByLabel("Authenticator or recovery code", { exact: true }).fill(accounts.adminB.recovery_codes!.shift()!);
  await verify.getByRole("button", { name: "Verify MFA", exact: true }).click(); await expect(admin.getByRole("status")).toContainText("Changes saved");
  await admin.goto("/en/admin-access");
  await admin.getByRole("article").filter({ has: admin.getByRole("heading", { name, exact: true }) }).getByRole("button", { name: "Approve access", exact: true }).click(); await expect(admin.getByRole("status")).toContainText("Changes saved");
  await page.goto("/en/admin-merchants"); await page.getByRole("article").filter({ has: page.getByRole("heading", { name, exact: true }) }).getByRole("button", { name: "Open", exact: true }).click();
  await expect(page.getByRole("heading", { level: 2, name, exact: true })).toHaveCount(2);
  await expect(page.getByText("Shipping is unavailable. Email or business verification alone does not enable shipping.")).toBeVisible(); await approval.close();
});
