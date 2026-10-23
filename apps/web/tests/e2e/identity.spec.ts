import { test, expect, type APIRequestContext, type Page } from "@playwright/test";
import { randomUUID } from "node:crypto";

const password = "Development-Test-Password-123!";
const mailbox = process.env.MAILPIT_URL || "http://127.0.0.1:8025";
type Message = { ID: string; Subject: string; To: { Address: string }[] };

async function emailLink(request: APIRequestContext, email: string, subject: string, screen: string) {
  let id = "";
  await expect.poll(async () => {
    const response = await request.get(`${mailbox}/api/v1/messages?limit=200`);
    expect(response.ok()).toBe(true);
    const data = await response.json() as { messages: Message[] };
    id = data.messages.find((message) => message.Subject.includes(subject) && message.To.some((recipient) => recipient.Address === email))?.ID || "";
    return id;
  }, { timeout: 30000, intervals: [200, 500, 1000] }).not.toBe("");
  const message = await (await request.get(`${mailbox}/api/v1/message/${id}`)).json() as { Text: string; HTML: string };
  const match = `${message.Text}\n${message.HTML}`.match(new RegExp(`https?:\\/\\/[^\\s<>"']+\\/en\\/${screen}#[^\\s<>"']+`));
  expect(match, "Development mail must contain the expected authentication link").not.toBeNull();
  return new URL(match![0].replaceAll("&amp;", "&"));
}

async function register(page: Page, request: APIRequestContext, email: string, name: string) {
  await page.goto("/en/register");
  await page.getByLabel("Your name", { exact: true }).fill(name);
  await page.getByLabel("Email address", { exact: true }).fill(email);
  await page.getByLabel("Password", { exact: true }).fill(password);
  await page.getByLabel("Confirm password", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await expect(page).toHaveURL(/\/en\/verify-email$/);
  const link = await emailLink(request, email, "Verify your", "verify-email");
  await page.goto(`/en/verify-email${link.hash}`);
  await page.getByRole("button", { name: "Verify email", exact: true }).click();
  await expect(page.getByRole("status")).toContainText("Email verified");
  await expect(page).toHaveURL(/\/en\/verify-email$/);
}

test("registration, verification, organization switching, team and scoped tokens", async ({ page, request, browser }) => {
  const email = `owner-${randomUUID()}@example.test`;
  await register(page, request, email, "Owner browser test");
  await page.goto("/en/onboarding");
  await page.getByLabel("Organization name", { exact: true }).fill("Browser First Company");
  await page.getByRole("button", { name: "Create organization" }).click();
  await expect(page).toHaveURL(/\/en\/dashboard$/);
  await expect(page.getByRole("heading", { name: "Your team workspace is ready." })).toBeVisible();
  await page.goto("/en/onboarding");
  await page.getByLabel("Organization name", { exact: true }).fill("Browser Second Company");
  await page.getByRole("button", { name: "Create organization" }).click();
  await expect(page).toHaveURL(/\/en\/dashboard$/);
  await page.goto("/en/organizations");
  await page.getByRole("article").filter({ has: page.getByRole("heading", { name: "Browser First Company" }) }).getByRole("button", { name: "Continue" }).click();
  await expect(page).toHaveURL(/\/en\/dashboard$/);
  await expect(page.getByText("Selected organization: Browser First Company")).toBeVisible();

  const memberEmail = `viewer-${randomUUID()}@example.test`;
  const second = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || "http://127.0.0.1:8080" });
  const member = await second.newPage();
  await register(member, request, memberEmail, "Viewer browser test");
  await page.goto("/en/team");
  await page.getByLabel("Email address", { exact: true }).fill(memberEmail);
  await page.getByRole("button", { name: "Add registered member", exact: true }).click();
  await expect(page.getByRole("cell", { name: memberEmail, exact: true })).toBeVisible();
  await member.goto("/en/organizations");
  await member.getByRole("article").filter({ has: member.getByRole("heading", { name: "Browser First Company" }) }).getByRole("button", { name: "Continue" }).click();
  await member.goto("/en/team");
  await expect(member.getByRole("cell", { name: memberEmail, exact: true })).toBeVisible();
  await expect(member.getByRole("button", { name: "Add registered member" })).toHaveCount(0);
  const csrfCookie = (await second.cookies()).find((cookie) => cookie.name === "XSRF-TOKEN")!;
  const forbidden = await second.request.post("/api/v1/team", { data: { email, role: "merchant.owner" }, headers: { Accept: "application/json", Origin: new URL(member.url()).origin, "X-XSRF-TOKEN": decodeURIComponent(csrfCookie.value) } });
  expect(forbidden.status()).toBe(403);
  await second.close();

  await page.goto("/en/security");
  await page.getByLabel("Token name", { exact: true }).fill("Browser test token");
  await page.getByLabel("Current password", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Create token", exact: true }).click();
  const tokenField = page.getByRole("textbox", { name: "Copy this token now. It will not be shown again. Store it securely." });
  await expect(tokenField).toBeVisible();
  const token = await tokenField.inputValue();
  const current = await request.get("/api/v1/merchants/current", { headers: { Authorization: `Bearer ${token}` } });
  expect(current.status()).toBe(200);
  expect((await current.json()).data.merchant.name).toBe("Browser First Company");
  const denied = await request.get("/api/v1/team", { headers: { Authorization: `Bearer ${token}` } });
  expect(denied.status()).toBe(403);
  page.on("dialog", (dialog) => dialog.accept());
  await page.locator("li").filter({ hasText: "Browser test token" }).getByRole("button", { name: "Revoke", exact: true }).click();
  await expect(page.getByText("No API tokens for this organization.")).toBeVisible();
  expect((await request.get("/api/v1/merchants/current", { headers: { Authorization: `Bearer ${token}` } })).status()).toBe(401);
});

test("password recovery resets the password and invalidates an existing browser session", async ({ page, request, browser }) => {
  const email = `reset-${randomUUID()}@example.test`;
  await register(page, request, email, "Password recovery test");
  const recoveryContext = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || "http://127.0.0.1:8080" });
  const recovery = await recoveryContext.newPage();
  await recovery.goto("/en/forgot-password");
  await recovery.getByLabel("Email address", { exact: true }).fill(email);
  await recovery.getByRole("button", { name: "Continue", exact: true }).click();
  await expect(recovery.getByRole("status")).toContainText("If the account is eligible");
  const link = await emailLink(request, email, "Reset your", "reset-password");
  await recovery.goto(`/en/reset-password${link.hash}`);
  await expect(recovery.getByRole("button", { name: "Continue", exact: true })).toBeEnabled();
  await expect(recovery).toHaveURL(/\/en\/reset-password$/);
  const changed = "New-Development-Password-123!";
  await recovery.getByLabel("Password", { exact: true }).fill(changed);
  await recovery.getByLabel("Confirm password", { exact: true }).fill(changed);
  await recovery.getByRole("button", { name: "Continue", exact: true }).click();
  await expect(recovery.getByRole("status")).toContainText("Your password has been reset");
  await page.goto("/en/organizations");
  await expect(page).toHaveURL(/\/en\/login$/);
  await recovery.goto("/en/login");
  await recovery.getByLabel("Email address", { exact: true }).fill(email);
  await recovery.getByLabel("Password", { exact: true }).fill(changed);
  await recovery.getByRole("button", { name: "Continue", exact: true }).click();
  await expect(recovery).toHaveURL(/\/en\/organizations$/);
  await recoveryContext.close();
});

test("Arabic forms use RTL, anonymous pages are protected and CSRF is enforced", async ({ page }) => {
  await page.goto("/ar/login");
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await expect(page.getByRole("heading", { name: "تسجيل الدخول" })).toBeVisible();
  await expect(page.getByLabel("البريد الإلكتروني")).toBeVisible();
  await page.goto("/ar/dashboard");
  await expect(page).toHaveURL(/\/ar\/login$/);
  const response = await page.request.post("/api/v1/auth/login", { data: { email: "unknown@example.test", password }, headers: { Accept: "application/json", Origin: new URL(page.url()).origin } });
  expect(response.status()).toBe(419);
});
