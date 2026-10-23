import { defineConfig, devices } from "@playwright/test";
export default defineConfig({
  testDir: "./tests/e2e", fullyParallel: false, workers: 1, retries: process.env.CI ? 1 : 0,
  reporter: "list", use: { baseURL: process.env.E2E_BASE_URL || "http://127.0.0.1:8080", trace: "retain-on-failure", launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
