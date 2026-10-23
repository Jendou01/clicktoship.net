import { test, expect } from "@playwright/test";
import { showMerchantCard, selectMerchantOption } from "./merchant-directory";

test("directory helpers accept a first-page target when Next is disabled", async ({ page }) => {
  await page.setContent(`<h1>Merchant administration</h1><article><h2>First-page target</h2></article>
    <label for="merchant">Organization</label><select id="merchant"><option value="target">First-page target</option></select>
    <nav aria-label="Page"><span>1 / 1</span><button disabled>Next page</button></nav>`);
  await showMerchantCard(page, "First-page target");
  await selectMerchantOption(page, "First-page target");
  await expect(page.getByRole("button", { name: "Next page", exact: true })).toBeDisabled();
  await expect(page.getByLabel("Organization", { exact: true })).toHaveValue("target");
});

test("directory helpers traverse loaded pages and stop at the exact target", async ({ page }) => {
  await page.setContent(`<h1>Merchant administration</h1><article><h2 id="merchant-name">Earlier merchant</h2></article>
    <nav aria-label="Page"><span id="position">1 / 2</span><button id="next" onclick="document.getElementById('merchant-name').textContent='Later target'; document.getElementById('position').textContent='2 / 2'; this.disabled=true;">Next page</button></nav>`);
  await showMerchantCard(page, "Later target");
  await expect(page.getByRole("heading", { name: "Later target", exact: true })).toBeVisible();
  await expect(page.getByRole("navigation", { name: "Page", exact: true })).toContainText("2 / 2");
  await expect(page.getByRole("button", { name: "Next page", exact: true })).toBeDisabled();
});
