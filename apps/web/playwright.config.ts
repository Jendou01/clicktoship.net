import { defineConfig, devices } from "@playwright/test";
const external = process.env.E2E_BASE_URL;
export default defineConfig({
  testDir: "./tests/e2e", fullyParallel: true, workers: 2, retries: process.env.CI ? 1 : 0,
  reporter: "list", use: { baseURL: external || "http://127.0.0.1:3000", trace: "retain-on-failure", launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
  webServer: external ? undefined : { command: "npm run start", url: "http://127.0.0.1:3000/en", reuseExistingServer: !process.env.CI, timeout: 60000 },
});
