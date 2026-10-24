import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { FoundationDashboard } from "../src/components/foundation-dashboard";

describe("foundation workspace", () => {
  it("clearly marks unavailable operations and links to Arabic", () => {
    const { container } = render(<FoundationDashboard locale="en" />);
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("One place for every delivery.");
    expect(screen.getByRole("note")).toHaveTextContent("Shipping and payments are not available yet");
    expect(screen.getByRole("link", { name: "Switch to Arabic" })).toHaveAttribute("href", "/ar");
    expect(screen.getAllByText("Planned")).toHaveLength(1);
    expect(screen.getAllByText("Available foundation")).toHaveLength(3);
    expect(screen.getByText("Your merchant workspace")).toBeInTheDocument();
    expect(container.firstChild).toHaveAttribute("dir", "ltr");
  });
  it("renders the Arabic workspace with RTL direction and English navigation", () => {
    const { container } = render(<FoundationDashboard locale="ar" />);
    expect(container.firstChild).toHaveAttribute("dir", "rtl");
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("كل شحناتك في مكان واحد.");
    expect(screen.getByRole("note")).toHaveTextContent("خدمات الشحن والدفع غير متاحة بعد");
    expect(screen.getByRole("link", { name: "Switch to English" })).toHaveAttribute("href", "/en");
  });
});
