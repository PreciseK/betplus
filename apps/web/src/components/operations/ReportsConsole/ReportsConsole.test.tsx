import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ReportsConsole } from "./ReportsConsole";

const requestFinancialReport = vi.fn();
const reportStatus = vi.fn();

vi.mock("@betplus/api-client", async () => {
  const actual = await vi.importActual<typeof import("@betplus/api-client")>("@betplus/api-client");
  return {
    ...actual,
    backOfficeGateway: {
      ...actual.backOfficeGateway,
      hasSession: () => true,
      requestFinancialReport: (...args: unknown[]) => requestFinancialReport(...args),
      reportStatus: (...args: unknown[]) => reportStatus(...args),
    },
  };
});

beforeEach(() => {
  window.history.replaceState({}, "", "/back-office/reports");
  vi.clearAllMocks();
});

describe("ReportsConsole", () => {
  it("has no live financial or remittance table — that data has no read endpoint, only export", () => {
    render(<ReportsConsole />);
    expect(screen.queryByRole("table", { name: "Financial report sliced by game and attributed state" })).not.toBeInTheDocument();
    expect(screen.queryByRole("table", { name: /remittance reconciliation/ })).not.toBeInTheDocument();
  });

  it("queues a real export with the real request payload and starts tracking it", async () => {
    requestFinancialReport.mockResolvedValueOnce({ id: 7, status: "queued" });
    reportStatus.mockResolvedValue({ id: 7, report_type: "financial", status: "processing", row_count: null, failure_reason: null, download_url: null });

    render(<ReportsConsole />);
    fireEvent.click(screen.getByRole("button", { name: "Queue export" }));

    expect(await screen.findByText("#7")).toBeInTheDocument();
    expect(requestFinancialReport).toHaveBeenCalledWith(expect.objectContaining({ game_code: undefined, state_code: undefined }));
  });

  it("shows a real signed download link once the export completes, not a fabricated one", async () => {
    requestFinancialReport.mockResolvedValueOnce({ id: 8, status: "queued" });
    reportStatus.mockResolvedValueOnce({ id: 8, report_type: "financial", status: "completed", row_count: 42, failure_reason: null, download_url: "https://api.example/backoffice/v1/reports/exports/8/download?signature=abc" });

    render(<ReportsConsole />);
    fireEvent.click(screen.getByRole("button", { name: "Queue export" }));

    const link = await screen.findByRole("link", { name: /Download \(expires in 1 hour\)/ });
    expect(link).toHaveAttribute("href", "https://api.example/backoffice/v1/reports/exports/8/download?signature=abc");
    expect(screen.getByText("42")).toBeInTheDocument();
  });

  it("rejects an invalid date range before requesting anything", () => {
    render(<ReportsConsole />);
    fireEvent.change(screen.getByLabelText("From"), { target: { value: "2026-08-20" } });
    fireEvent.change(screen.getByLabelText("To"), { target: { value: "2026-08-01" } });

    fireEvent.click(screen.getByRole("button", { name: "Queue export" }));

    expect(screen.getByRole("alert")).toHaveTextContent("valid bounded date range");
    expect(requestFinancialReport).not.toHaveBeenCalled();
  });
});
