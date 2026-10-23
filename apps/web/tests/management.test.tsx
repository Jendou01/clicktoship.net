import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { beforeEach, expect, it, vi } from "vitest";
import { ManagementScreen } from "../src/components/management-screen";
import { api, ApiError } from "../src/lib/api";
const navigation = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => navigation }));
vi.mock("../src/lib/api", async (original) => ({ ...(await original<typeof import("../src/lib/api")>()), api: vi.fn() }));
const mockApi = vi.mocked(api);
const profile = { user: { id: "user", name: "Test", locale: "en", email_verified: true }, active_merchant_id: "merchant" };
beforeEach(() => { vi.resetAllMocks(); window.history.replaceState(null, "", "/"); });
function configure(extra: Record<string, unknown> = {}) {
  mockApi.mockImplementation(async (path) => {
    if (path === "/auth/me") return profile;
    if (path === "/auth/mfa") return { enabled: false, fresh: false, platform_permissions: [] };
    if (path === "/merchants/current") return { merchant: { id: "merchant", name: "Test merchant" }, permissions: ["profile.view", "profile.manage", "addresses.view", "addresses.manage", "verification.submit"] };
    if (path in extra) return extra[path];
    throw new ApiError(403, "FORBIDDEN");
  });
}
it("loads the actual business profile into a POST form and sends expected version", async () => {
  configure({ "/merchants/current/profile": { profile: { id: "p", version: 2, legal_name: "Business", country: "SA", contact_name: "Contact", contact_email: "c@example.test", contact_phone: "+966501234567" } } });
  render(<ManagementScreen locale="en" screen="business-profile" />);
  expect(await screen.findByLabelText("Legal business name")).toHaveValue("Business");
  expect(screen.getByRole("button", { name: "Save changes" }).closest("form")).toHaveAttribute("method", "post");
  fireEvent.submit(screen.getByRole("button", { name: "Save changes" }).closest("form")!);
  await waitFor(() => expect(mockApi).toHaveBeenCalledWith("/merchants/current/profile", "PATCH", expect.objectContaining({ version: 2, country: "SA" }), "merchant"));
});
it("renders Arabic address guidance and RTL", async () => {
  configure({ "/addresses?page=1": { addresses: [], pagination: { pages: 1 } } });
  const { container } = render(<ManagementScreen locale="ar" screen="addresses" />);
  expect(await screen.findByLabelText("اسم العنوان")).toBeInTheDocument();
  expect(container.firstChild).toHaveAttribute("dir", "rtl");
  expect(screen.getByText(/العناوين المحفوظة غير معتمدة/)).toBeInTheDocument();
});
it("keeps verification separate from activation and presents immutable history", async () => {
  configure({ "/merchants/current/verification": { status: "pending", submissions: [{ id: "s", created_at: "2026-10-23T12:00:00Z", snapshot: { legal_name: "Snapshot business" }, outcome: null }] } });
  render(<ManagementScreen locale="en" screen="business-verification" />);
  expect(await screen.findByText("Snapshot business")).toBeInTheDocument();
  expect(screen.getByText(/Shipping is unavailable/)).toBeInTheDocument();
  expect(screen.queryByRole("button", { name: "Submit for review" })).not.toBeInTheDocument();
});
it("shows MFA setup secrets only after an authenticated request", async () => {
  configure({ "/auth/mfa/enrollment": { secret: "SYNTHETIC-SETUP-KEY" } });
  render(<ManagementScreen locale="en" screen="mfa" />);
  const start = await screen.findByRole("button", { name: "Start enrollment" });
  expect(screen.queryByLabelText("Authenticator setup key")).toBeNull();
  fireEvent.change(screen.getByLabelText("Current password"), { target: { value: "Synthetic-Password-123!" } });
  fireEvent.submit(start.closest("form")!);
  expect(await screen.findByLabelText("Authenticator setup key")).toHaveValue("SYNTHETIC-SETUP-KEY");
  fireEvent.click(screen.getByRole("button", { name: "Clear displayed secrets" }));
  expect(screen.queryByLabelText("Authenticator setup key")).toBeNull();
});
it("redirects unauthenticated management requests to sign in", async () => {
  mockApi.mockRejectedValue(new ApiError(401, "UNAUTHENTICATED"));
  render(<ManagementScreen locale="en" screen="admin-merchants" />);
  await waitFor(() => expect(navigation.push).toHaveBeenCalledWith("/en/login"));
});
it("requires fresh MFA for platform pages and shows a safe error", async () => {
  configure();
  render(<ManagementScreen locale="ar" screen="admin-merchants" />);
  expect(await screen.findByRole("alert")).toHaveTextContent("الوصول مرفوض");
  expect(screen.queryByText("SYNTHETIC-SETUP-KEY")).toBeNull();
});
it("associates select labels separately from their option text", async () => {
  configure({ "/addresses?page=1": { addresses: [{ id: "address", version: 1, label: "Warehouse" }], pagination: { pages: 1 } }, "/merchants/current/settings": { settings: { version: 0, locale: "en", timezone: "UTC", origin_address_id: null, return_address_id: null } } });
  render(<ManagementScreen locale="en" screen="organization-settings" />);
  expect(await screen.findByLabelText("Default origin address", { exact: true })).toHaveRole("combobox");
  expect(screen.getByLabelText("Default return address", { exact: true })).toHaveRole("combobox");
});
it("keeps the directory navigation hidden until authorized first-page data is loaded", async () => {
  let finishDirectory!: (data: unknown) => void;
  const directory = new Promise((resolve) => { finishDirectory = resolve; });
  configure({ "/admin/merchants?page=1": directory, "/admin/access-requests": { grants: [] } });
  render(<ManagementScreen locale="en" screen="admin-merchants" />);
  expect(screen.getByRole("heading", { name: "Merchant administration" })).toBeVisible();
  await waitFor(() => expect(mockApi).toHaveBeenCalledWith("/admin/merchants?page=1"));
  expect(screen.queryByRole("navigation", { name: "Page" })).not.toBeInTheDocument();
  finishDirectory({ merchants: [{ id: "merchant", name: "First-page target", status: "active", verification_status: "unverified", version: 1 }], pagination: { pages: 1 } });
  expect(await screen.findByRole("heading", { name: "First-page target" })).toBeVisible();
  expect(screen.getByRole("navigation", { name: "Page" })).toBeVisible();
  expect(screen.getByRole("button", { name: "Next page" })).toBeDisabled();
});
