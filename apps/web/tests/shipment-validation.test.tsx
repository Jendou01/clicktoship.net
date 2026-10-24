import { describe, expect, it } from "vitest";
import { shipmentFieldLabel, shipmentFieldName, shipmentFieldStep, shipmentFieldHint } from "@/lib/shipment-validation";
describe("bilingual shipment validation mapping", () => {
 it("maps service and international field names explicitly instead of falling back to customs", () => {
  expect(shipmentFieldLabel("service_id", "ar")).toBe("الخدمة المهيأة");
  expect(shipmentFieldLabel("packages.0.weight_grams", "en")).toBe("Packages and customs — 1 — Weight (grams)");
  expect(shipmentFieldLabel("goods_origin_country", "ar")).toBe("دولة التصنيع");
  expect(shipmentFieldLabel("unrecognized", "ar")).toBe("حقل الشحنة");
 });
 it.each(["origin.phone", "destination.postal", "origin.email", "origin.country", "description", "packages.0.weight_grams", "packages.0.length_mm", "packages.0.width_mm", "packages.0.height_mm", "items.0.hs_code", "items.0.quantity", "service_id", "goods_origin_country"])("localizes constraints for %s", (key) => {
  expect(shipmentFieldHint(key, "ar")).toMatch(/[\u0600-\u06ff]/);
  expect(shipmentFieldHint(key, "en")).toMatch(/[a-z]/i);
 });
 it("maps backend paths to wizard steps and input names", () => {
  expect(shipmentFieldName("draft.origin.phone")).toBe("origin_phone");
  expect(shipmentFieldName("items.2.value_minor")).toBe("value_2");
  expect(shipmentFieldStep("origin.phone")).toBe(0);
  expect(shipmentFieldStep("packages.0.height_mm")).toBe(1);
  expect(shipmentFieldStep("service_id")).toBe(2);
 });
});
