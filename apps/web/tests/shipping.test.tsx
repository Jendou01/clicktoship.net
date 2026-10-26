import { beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { ShippingScreen } from "@/components/shipping-screen";
import { api, ApiError } from "@/lib/api";
vi.mock("@/lib/api", async (original) => ({ ...await original<typeof import("@/lib/api")>(), api: vi.fn() }));
const mocked = vi.mocked(api);
beforeEach(() => { cleanup(); mocked.mockReset(); });
describe("shipment workflow safety", () => {
 it("shows truthful shipping gates and bounded pagination in English", async () => {
  mocked.mockResolvedValue({ shipments: [], page: 1, last_page: 1 }); render(<ShippingScreen locale="en" screen="shipments" />);
  expect(await screen.findByText("No records.")).toBeInTheDocument(); expect(screen.getByRole("note")).toHaveTextContent("remain disabled"); expect(screen.getByRole("button", { name: "Next page" })).toBeDisabled();
 });
 it("supports Arabic RTL draft navigation without defaulting origin or manufacture", async () => {
  mocked.mockImplementation(async (path) => path === "/rates" ? { services: [] } : { shipments: [], page: 1, last_page: 1 }); render(<ShippingScreen locale="ar" screen="shipment-create" />);
  expect(screen.getByRole("main")).toHaveAttribute("dir", "rtl"); await screen.findByText("لا توجد سجلات."); expect(screen.getByLabelText("المرسل — رمز الدولة")).toHaveValue(""); fireEvent.click(screen.getByRole("button", { name: /^التالي$/ })); expect(screen.getByLabelText("دولة التصنيع")).toHaveValue("");
 });
 it("keeps unknown outcomes visible and prohibits another submission", async () => {
  const shipment = { id: "synthetic", state: "unknown", financial_state: "review", document_state: "not_requested", version: 3, revision: 1 };
  mocked.mockImplementation(async (path) => path.includes("/events") ? { events: [] } : path === "/shipments/synthetic" ? { shipment, draft: { packages: [{}], items: [] }, quote: null, validation: { valid: true, missing: [] } } : { shipments: [shipment], page: 1, last_page: 1 });
  render(<ShippingScreen locale="en" screen="shipment-details" />); fireEvent.click(await screen.findByRole("button", { name: "Open" })); expect(await screen.findByRole("alert")).toHaveTextContent("reservation retained"); expect(screen.getByRole("button", { name: "Submit shipment (disabled)" })).toBeDisabled(); expect(screen.getByRole("button", { name: "Cancel before submission" })).toBeDisabled();
 });
 it("requires an explicit merchant and grant before querying administrative exceptions", async () => {
  render(<ShippingScreen locale="en" screen="admin-shipping" />); expect(screen.getByRole("button", { name: "Load exceptions" })).toBeDisabled(); expect(mocked).not.toHaveBeenCalled(); expect(screen.getByText(/independent approver/)).toBeInTheDocument();
 });
});

 it.each(["en", "ar"] as const)("renders localized field errors and focuses the failing step in %s", async (locale) => {
  mocked.mockImplementation(async (path, method) => {
   if (method === "POST") throw new ApiError(422, "VALIDATION_FAILED", { "origin.phone": ["Invalid"], "packages.0.weight_grams": ["Invalid"], "items.0.country": ["Invalid"], service_id: ["Invalid"] });
   return path === "/rates" ? { services: [] } : { shipments: [], page: 1, last_page: 1 };
  });
  render(<ShippingScreen locale={locale} screen="shipment-create" />);
  await screen.findByText(locale === "en" ? "No records." : "لا توجد سجلات.");
  const next = locale === "en" ? "Next" : "التالي";
  fireEvent.click(screen.getByRole("button", { name: next })); fireEvent.click(screen.getByRole("button", { name: next }));
  fireEvent.click(screen.getByRole("button", { name: locale === "en" ? "Save draft" : "حفظ المسودة" }));
  expect(await screen.findByRole("alert")).toHaveTextContent(locale === "en" ? "international phone" : "هاتفًا دوليًا");
  const phone = screen.getByLabelText(locale === "en" ? "Sender — International phone" : "المرسل — الهاتف الدولي");
  expect(phone).toHaveAttribute("aria-invalid", "true"); await waitFor(() => expect(phone).toHaveFocus());
  expect(screen.getByRole("alert")).toHaveTextContent(locale === "en" ? "Configured service" : "الخدمة المهيأة");
 });

it('preserves every accepted quote digit without floating point conversion',async()=>{const shipment={id:'precision-fixture',state:'draft',financial_state:'none',document_state:'not_requested',version:1,revision:1};mocked.mockImplementation(async path=>path==='/shipments/precision-fixture'?{shipment,draft:{packages:[{}],items:[]},quote:{id:'quote',total_minor:'100000000000000001',currency:'SAR',expires_at:'2026-12-01T00:00:00Z'},validation:{valid:false,missing:[]}}:path.includes('/events')?{events:[]}:{shipments:[shipment],page:1,last_page:1});render(<ShippingScreen locale="en" screen="shipment-details"/>);fireEvent.click(await screen.findByRole('button',{name:'Open'}));expect(await screen.findByText('1,000,000,000,000,000.01 SAR',{exact:true})).toBeVisible();});
