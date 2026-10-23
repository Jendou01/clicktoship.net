import { beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { PricingScreen } from "@/components/pricing-screen";
import { api } from "@/lib/api";
import { pricingFields } from "@/lib/pricing";
vi.mock("@/lib/api", () => ({ api: vi.fn() }));
const mocked = vi.mocked(api);
beforeEach(() => { cleanup(); mocked.mockReset(); });
describe("pricing merchant boundaries", () => {
  it("shows explicit unavailable state with no invented Aramex offers", async () => {
    mocked.mockResolvedValue({ services: [] }); render(<PricingScreen locale="en" screen="rates" />);
    expect(await screen.findByText("No approved configuration or quotes.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Calculate estimate" })).toBeDisabled();
    expect(screen.getByText(/Carrier availability is unverified/)).toBeInTheDocument();
    expect(screen.queryByText(/14.00 SAR/)).not.toBeInTheDocument();
  });
  it("renders Arabic RTL and complete controls without privileged cost fields", async () => {
    mocked.mockResolvedValue({ services: [] }); render(<PricingScreen locale="ar" screen="rates" />);
    expect(screen.getByRole("main")).toHaveAttribute("dir", "rtl");
    expect(await screen.findByText("لا توجد إعدادات معتمدة أو عروض أسعار.")).toBeInTheDocument();
    expect(screen.getByLabelText("الوزن (غرام)")).toBeInTheDocument();
    expect(screen.queryByText(/تكاليف العقود/)).not.toBeInTheDocument();
  });
  it("submits integer dimensions and preserves the request key across an ambiguous failure", async () => {
    mocked.mockImplementation(async (path) => { if (path === "/rates") return { services: [{ id: "synthetic-service", name_en: "Synthetic test only", name_ar: "اختبار فقط" }] }; throw new Error("Network failure"); });
    render(<PricingScreen locale="en" screen="rates" />);
    await screen.findByRole("option", { name: "Synthetic test only" }); fireEvent.change(screen.getByLabelText("Service"), { target: { value: "synthetic-service" } });
    const countries = screen.getAllByLabelText("Country (ISO code)"); countries.forEach((field) => fireEvent.change(field, { target: { value: "SA" } }));
    screen.getAllByLabelText("City").forEach((field) => fireEvent.change(field, { target: { value: "Riyadh" } }));
    for (const label of ["Weight (grams)", "Length (mm)", "Width (mm)", "Height (mm)"]) fireEvent.change(screen.getByLabelText(label), { target: { value: "100" } });
    fireEvent.click(screen.getByRole("button", { name: "Calculate estimate" })); await screen.findByRole("status");
    const first = mocked.mock.calls.find((c) => c[0] === "/rates/quote")![2] as { idempotency_key: string; packages: { weight_grams: number }[] };
    expect(first.packages[0].weight_grams).toBe(100); expect(first.idempotency_key).toMatch(/^[a-f0-9-]{36}$/);
    fireEvent.click(screen.getByRole("button", { name: "Calculate estimate" }));
    await waitFor(() => expect(mocked.mock.calls.filter((c) => c[0] === "/rates/quote")).toHaveLength(2));
    expect(mocked.mock.calls.filter((c) => c[0] === "/rates/quote")[1][2]).toEqual(first);
  });
  it("fails closed for administrative views without MFA", async () => {
    mocked.mockRejectedValue(new Error("Forbidden")); render(<PricingScreen locale="en" screen="pricing-audit" />);
    expect(await screen.findByRole("status")).toHaveTextContent("Request unavailable");
  });
  it("keeps complete bilingual field names for every definition schema", () => {
    for (const fields of Object.values(pricingFields)) for (const field of fields) { expect(field.en.length).toBeGreaterThan(0); expect(field.ar).toMatch(/[\u0600-\u06ff]/); }
  });
});
