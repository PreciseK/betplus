import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeAll, describe, expect, it, vi } from "vitest";
import { OperationalDataTable, type OperationalColumn } from "./OperationalDataTable";

interface Row { id: string; label: string; amount: number }
const rows: readonly Row[] = [{ id: "B", label: "Beta", amount: 200 }, { id: "A", label: "Alpha", amount: 100 }];
const columns: readonly OperationalColumn<Row>[] = [
  { id: "label", label: "Label", cell: (row) => row.label, sortValue: (row) => row.label },
  { id: "amount", label: "Amount", cell: (row) => row.amount, sortValue: (row) => row.amount, align: "end" },
];

beforeAll(() => {
  Object.defineProperty(HTMLDialogElement.prototype, "showModal", { configurable: true, value() { this.setAttribute("open", ""); } });
  Object.defineProperty(HTMLDialogElement.prototype, "close", { configurable: true, value() { this.removeAttribute("open"); } });
});

describe("OperationalDataTable", () => {
  it("sorts with an announced direction and explicitly confirms counted bulk actions", () => {
    const onBulkAction = vi.fn();
    render(<OperationalDataTable caption="Test records" columns={columns} rows={rows} getRowId={(row) => row.id} selectable bulkActionLabel="Export selected" onBulkAction={onBulkAction} />);
    fireEvent.click(screen.getByRole("button", { name: /Label/ }));
    expect(screen.getByRole("columnheader", { name: /Label/ })).toHaveAttribute("aria-sort", "ascending");
    expect(within(screen.getByRole("table", { name: "Test records" })).getAllByRole("rowheader")[0]).toHaveTextContent("Alpha");

    fireEvent.click(screen.getByRole("checkbox", { name: "Select A" }));
    fireEvent.click(screen.getByRole("button", { name: "Export selected" }));
    const dialog = screen.getByRole("dialog", { name: "Export selected for 1 records?" });
    expect(within(dialog).getByText(/restricted to the 1 explicitly selected records/)).toBeInTheDocument();
    fireEvent.click(within(dialog).getByRole("button", { name: "Export selected" }));
    expect(onBulkAction).toHaveBeenCalledTimes(1);
    expect(onBulkAction.mock.calls[0][0]).toHaveLength(1);
  });

  it("gives loading, partial, stale, error and empty states distinct text", () => {
    const common = { caption: "States", columns, getRowId: (row: Row) => row.id };
    const { rerender } = render(<OperationalDataTable {...common} rows={rows} dataState="loading" />);
    expect(screen.getByRole("status")).toHaveTextContent("Loading bounded records");
    rerender(<OperationalDataTable {...common} rows={rows} dataState="partial" stateMessage="Some rows are pending." />);
    expect(screen.getByRole("status")).toHaveTextContent("Partial result set");
    rerender(<OperationalDataTable {...common} rows={rows} dataState="stale" stateMessage="Snapshot is old." />);
    expect(screen.getByRole("status")).toHaveTextContent("Stale snapshot");
    rerender(<OperationalDataTable {...common} rows={rows} dataState="error" />);
    expect(screen.getByRole("alert")).toHaveTextContent("Records could not be loaded");
    rerender(<OperationalDataTable {...common} rows={[]} />);
    expect(screen.getByText("No records in this scope")).toBeInTheDocument();
  });
});
