import { beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { CarrierScreen } from "@/components/carrier-screen";
import { api } from "@/lib/api";
vi.mock("@/lib/api", () => ({ api: vi.fn() }));
const mocked = vi.mocked(api);
beforeEach(() => { cleanup(); mocked.mockReset(); });
describe("carrier administration boundaries", () => {
  it("shows explicit unverified capabilities rather than live carrier success", async () => {
    mocked.mockResolvedValue({ accounts: [], capabilities: [{ operation: "CalculateRate", request_documented: true, external_enabled: false }] });
    render(<CarrierScreen locale="en" screen="carrier-capabilities" />);
    expect(await screen.findByText("CalculateRate")).toBeInTheDocument(); expect(screen.getByText("Request documented · Response contract unverified")).toBeInTheDocument(); expect(screen.getByText(/No live sandbox contract tests/)).toBeInTheDocument();
  });
  it("renders Arabic RTL and keeps external webhook authentication unavailable", async () => {
    mocked.mockResolvedValue({ accounts: [], capabilities: [] }); render(<CarrierScreen locale="ar" screen="carrier-webhooks" />);
    expect(screen.getByRole("main")).toHaveAttribute("dir", "rtl"); expect(screen.getByText(/الاستقبال معطل/)).toBeInTheDocument(); expect(await screen.findByText("لا توجد سجلات.")).toBeInTheDocument();
  });
  it("does not invent credentials or route mappings when creating a disabled draft", async () => {
    mocked.mockResolvedValue({ accounts: [], capabilities: [] }); render(<CarrierScreen locale="en" screen="aramex" />); await screen.findByText("No records.");
    for (const [label, value] of [["Stable account code", "synthetic-test"], ["English name", "Synthetic test only"], ["Arabic name", "حساب اختبار"], ["Verified source / configuration review reference", "Synthetic review only"]]) fireEvent.change(screen.getByLabelText(label), { target: { value } });
    fireEvent.change(screen.getByLabelText("Environment"), { target: { value: "sandbox" } }); fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await waitFor(() => expect(mocked).toHaveBeenCalledWith("/admin/carriers/accounts", "POST", expect.objectContaining({ services: [], environment: "sandbox", version: 0 })));
    const payload = mocked.mock.calls.find((c) => c[1] === "POST")![2] as Record<string, unknown>; expect(payload).not.toHaveProperty("credentials"); expect(payload).not.toHaveProperty("enabled");
  });
  it("fails closed without administrative authorization", async () => {
    mocked.mockRejectedValue(new Error("Forbidden")); render(<CarrierScreen locale="en" screen="carrier-health" />); expect(await screen.findByRole("status")).toHaveTextContent("Request unavailable");
  });
});
