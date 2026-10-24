import { beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { WorkspaceNavigation, localeHref } from "@/components/workspace-navigation";
import { api } from "@/lib/api";
vi.mock("@/lib/api", () => ({ api: vi.fn() }));
beforeEach(() => { cleanup(); vi.mocked(api).mockReset(); });
describe("permission-aware workspace shell", () => {
 it("shows authorized shipping links and merchant context, without platform links", async () => {
  vi.mocked(api).mockImplementation(async (path) => path === "/auth/mfa" ? { platform_permissions: [] } : path === "/auth/me" ? { active_merchant_id: "synthetic" } : { merchant: { name: "Synthetic organization" }, permissions: ["shipments.read", "shipments.write", "pickups.read", "rates.read"] });
  render(<WorkspaceNavigation locale="en" screen="shipments" />);
  expect(await screen.findByText("Synthetic organization")).toBeInTheDocument();
  for (const name of ["Shipments", "Create shipment", "Pickups", "Shipping rates"]) expect(screen.getByRole("link", { name })).toBeInTheDocument();
  expect(screen.getByRole("link", { name: "Shipments" })).toHaveAttribute("aria-current", "page");
  expect(screen.queryByText("Platform administration")).not.toBeInTheDocument();
  expect(screen.queryByRole("link", { name: "Wallet" })).not.toBeInTheDocument();
 });
 it("fails closed when context or permissions cannot be loaded", async () => {
  vi.mocked(api).mockRejectedValue(new Error("Forbidden")); render(<WorkspaceNavigation locale="ar" screen="wallet" />);
  expect(screen.queryByRole("link", { name: "الشحنات" })).not.toBeInTheDocument();
  expect(screen.queryByText("إدارة المنصة")).not.toBeInTheDocument();
  expect(screen.getByRole("link", { name: "Switch to English" })).toHaveAttribute("href", "/en/wallet");
 });
 it("carries only shipment identity across locales and drops secrets and arbitrary query data", () => {
  const id = "00000000-0000-4000-8000-000000000001";
  expect(localeHref("ar", "shipment-details", `?shipment=${id}&access_grant=secret&token=private`)).toBe(`/ar/shipment-details?shipment=${id}`);
  expect(localeHref("en", "wallet", `?shipment=${id}&token=private`)).toBe("/en/wallet");
  expect(localeHref("ar", "shipment-details", "?shipment=malformed")).toBe("/ar/shipment-details");
 });
});

it("reuses supplied screen context without duplicate auth or tenant requests", async () => {
 render(<WorkspaceNavigation locale="en" screen="business-profile" context={{ merchant: "Already authorized", permissions: ["shipments.read"], platform: [] }} />);
 expect(screen.getByText("Already authorized")).toBeInTheDocument();
 expect(screen.getByRole("link", { name: "Shipments" })).toBeInTheDocument();
 expect(api).not.toHaveBeenCalled();
});
