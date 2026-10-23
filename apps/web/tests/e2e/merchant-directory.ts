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
  while (!(await select.getByRole("option", { name, exact: true }).count())) await nextDirectoryPage(page);
  await select.selectOption({ label: name });
}
export async function showMerchantCard(page: Page, name: string) {
  await expect(page.getByRole("heading", { name: "Merchant administration", exact: true })).toBeVisible();
  while (!(await page.getByRole("heading", { name, exact: true }).count())) await nextDirectoryPage(page);
}
