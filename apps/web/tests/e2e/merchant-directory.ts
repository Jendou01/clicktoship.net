import { expect, type Page } from "@playwright/test";

async function nextDirectoryPage(page: Page) {
  const navigation = page.getByRole("navigation", { name: "Page", exact: true });
  const before = await navigation.textContent();
  const next = navigation.getByRole("button", { name: "Next page", exact: true });
  await expect(next).toBeEnabled(); await next.click();
  await expect(navigation).not.toHaveText(before || "");
}
/** Follow the actual paginated directory; fixtures are retained across test runs. */
export async function selectMerchantOption(page: Page, name: string) {
  const select = page.getByLabel("Organization", { exact: true }); await expect(select).toBeVisible();
  // Retained fixture directories grow between runs; use the real bounded search
  // before traversing results, rather than making test cost depend on every prior tenant.
  const search = page.getByLabel("Search merchant directory", { exact: true });
  if (await search.count()) {
    await search.fill(name);
    const response = page.waitForResponse(r => {
      const url = new URL(r.url());
      return url.pathname === "/api/v1/admin/merchants" && url.searchParams.get("search") === name;
    });
    await page.getByRole("button", { name: "Apply filters", exact: true }).click();
    expect((await response).status()).toBe(200);
    await expect(select.getByRole("option", { name, exact: true })).toHaveCount(1);
  }
  while (!(await select.getByRole("option", { name, exact: true }).count())) await nextDirectoryPage(page);
  await select.selectOption({ label: name });
}
export async function showMerchantCard(page: Page, name: string) {
  await expect(page.getByRole("heading", { name: "Merchant administration", exact: true })).toBeVisible();
  // The heading is server-rendered before the authorized directory finishes loading.
  // On a fresh single-page CI database, checking cards early incorrectly requests a disabled Next button.
  await expect(page.getByRole("navigation", { name: "Page", exact: true })).toBeVisible();
  while (!(await page.getByRole("heading", { name, exact: true }).count())) await nextDirectoryPage(page);
}
