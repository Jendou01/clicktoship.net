import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { IdentityScreen } from "../src/components/identity-screen";
import { api, ApiError } from "../src/lib/api";

const navigation = vi.hoisted(() => ({ push: vi.fn(), refresh: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => navigation }));
vi.mock("../src/lib/api", async (original) => ({ ...(await original<typeof import("../src/lib/api")>()), api: vi.fn() }));
const mockApi = vi.mocked(api);
const profile = { user: { id: "user", name: "Test user", email: "user@example.test", email_verified: true }, active_merchant_id: "organization" };

beforeEach(() => { vi.clearAllMocks(); window.history.replaceState(null, "", "/"); });

describe("identity screens", () => {
  it("renders complete English registration fields and password guidance", () => {
    render(<IdentityScreen locale="en" screen="register" />);
    expect(screen.getByRole("button", { name: "Continue" }).closest("form")).toHaveAttribute("method", "post");
    expect(screen.getByLabelText("Your name")).toHaveAttribute("name", "name");
    expect(screen.getByLabelText("Email address")).toHaveAttribute("type", "email");
    expect(screen.getByLabelText("Password")).toHaveAttribute("minlength", "12");
    expect(screen.getByLabelText("Confirm password")).toHaveAttribute("autocomplete", "new-password");
    expect(screen.getByText(/Use at least 12/)).toBeInTheDocument();
  });
  it("renders Arabic login and recovery navigation in RTL", () => {
    const { container } = render(<IdentityScreen locale="ar" screen="login" />);
    expect(container.firstChild).toHaveAttribute("dir", "rtl");
    expect(screen.getByRole("heading", { name: "تسجيل الدخول" })).toBeInTheDocument();
    expect(screen.getByLabelText("البريد الإلكتروني")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "نسيت كلمة المرور؟" })).toHaveAttribute("href", "/ar/forgot-password");
  });
  it("shows safe localized errors for rejected login", async () => {
    mockApi.mockRejectedValueOnce(new ApiError(422, "VALIDATION_FAILED"));
    render(<IdentityScreen locale="en" screen="login" />);
    fireEvent.change(screen.getByLabelText("Email address"), { target: { value: "user@example.test" } });
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "wrong password" } });
    fireEvent.submit(screen.getByRole("button", { name: "Continue" }).closest("form")!);
    expect(await screen.findByRole("alert")).toHaveTextContent("Review the entered values");
    expect(navigation.push).not.toHaveBeenCalled();
  });
  it("keeps verification links in memory after removing the browser fragment", async () => {
    const verification = "/api/v1/auth/email/verify?signature=synthetic&expires=123";
    window.history.replaceState(null, "", `/en/verify-email#verification=${encodeURIComponent(verification)}`);
    mockApi.mockResolvedValueOnce({ ...profile, user: { ...profile.user, email_verified: false } }).mockResolvedValueOnce({ email_verified: true });
    render(<IdentityScreen locale="en" screen="verify-email" />);
    const button = await screen.findByRole("button", { name: "Verify email" });
    expect(window.location.hash).toBe("");
    fireEvent.click(button);
    await waitFor(() => expect(mockApi).toHaveBeenCalledWith("/auth/email/verify", "POST", { verification }));
    expect(await screen.findByRole("status")).toHaveTextContent("Email verified");
  });
  it("does not enable reset submission without an email link", () => {
    render(<IdentityScreen locale="en" screen="reset-password" />);
    expect(screen.getByRole("button", { name: "Continue" })).toBeDisabled();
    expect(screen.getByText(/Open the complete reset link/)).toBeInTheDocument();
  });
  it("does not show membership write controls to a read-only member", async () => {
    mockApi.mockImplementation(async (path) => {
      if (path === "/auth/me") return profile;
      if (path === "/merchants") return { merchants: [{ id: "organization", name: "Test company", role: "merchant.viewer" }] };
      if (path === "/merchants/current") return { permissions: ["merchant.view", "team.view", "roles.view"] };
      if (path === "/team") return { memberships: [{ id: "member", name: "Viewer", email: "viewer@example.test", role: "merchant.viewer", status: "active", version: 1 }], pagination: { pages: 1 } };
      return { roles: [] };
    });
    render(<IdentityScreen locale="en" screen="team" />);
    expect(await screen.findByText("viewer@example.test")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Add registered member" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Remove membership" })).not.toBeInTheDocument();
  });
});
