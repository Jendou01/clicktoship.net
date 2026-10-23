import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, fireEvent, cleanup } from "@testing-library/react";
import { FinanceScreen } from "@/components/finance-screen";
import { formatMinor, parseMinor } from "@/lib/finance";
import { api } from "@/lib/api";
vi.mock("@/lib/api", () => ({ api: vi.fn() }));
const mocked = vi.mocked(api);
beforeEach(() => { cleanup(); mocked.mockReset(); });
describe("exact financial amounts", () => {
  it("formats without Number precision loss across exponents", () => {
    expect(formatMinor("9007199254740993", "SAR", 2)).toBe("90,071,992,547,409.93 SAR");
    expect(formatMinor("123", "JPY", 0)).toBe("123 JPY");
    expect(formatMinor("-5", "KWD", 3)).toBe("−0.005 KWD");
  });
  it("parses exact decimals and rejects excessive precision and limits", () => {
    expect(parseMinor("12.50", 2)).toBe("1250");
    expect(parseMinor("0.001", 3)).toBe("1");
    for (const value of ["0", "1.001", "1e2", "-1", "1,000", "10000000001.00"]) expect(() => parseMinor(value, 2)).toThrow();
    expect(() => parseMinor("1.0", 0)).toThrow();
  });
});
describe("wallet screens", () => {
  it("shows Arabic RTL balances and unavailable funding", async () => {
    mocked.mockResolvedValue({ wallets: [{ id: "wallet", currency: "SAR", exponent: 2, posted: "10000", reserved: "6000", available: "4000", version: "2", frozen: false }] });
    render(<FinanceScreen locale="ar" screen="wallet" />);
    expect(screen.getByRole("main")).toHaveAttribute("dir", "rtl");
    expect(await screen.findByText("100.00 SAR")).toBeInTheDocument();
    expect(screen.getByText("40.00 SAR")).toBeInTheDocument();
    expect(screen.getByText("تمويل المحفظة والسحب ورسوم الشحن غير متاحة.")).toBeInTheDocument();
  });
  it("opens only an empty SAR wallet", async () => {
    mocked.mockResolvedValue({ wallets: [] });
    render(<FinanceScreen locale="en" screen="wallet" />);
    fireEvent.click(await screen.findByRole("button", { name: "Open SAR wallet" }));
    await waitFor(() => expect(mocked).toHaveBeenCalledWith("/wallets", "POST", { currency: "SAR" }));
  });
  it("fails closed when private admin access cannot be loaded", async () => {
    mocked.mockRejectedValue(new Error("Forbidden"));
    render(<FinanceScreen locale="en" screen="admin-finance" />);
    expect(await screen.findByRole("status")).toHaveTextContent("Unable to complete");
    expect(screen.queryByRole("button", { name: "Execute approved correction" })).not.toBeInTheDocument();
  });
});
