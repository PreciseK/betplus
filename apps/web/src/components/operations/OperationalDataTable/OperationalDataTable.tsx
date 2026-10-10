"use client";

import { useMemo, useState, type ReactNode } from "react";
import { Button } from "@/components/ui/Button/Button";
import { Dialog } from "@/components/ui/feedback/Dialog/Dialog";
import { Icon } from "@/components/ui/Icon/Icon";
import { EmptyState } from "@/components/operations/EmptyState/EmptyState";
import styles from "@/components/operations/OperationsConsole.module.css";

export type OperationalDataState = "ready" | "loading" | "partial" | "stale" | "error";

export interface OperationalColumn<Row> {
  id: string;
  label: string;
  cell: (row: Row) => ReactNode;
  sortValue?: (row: Row) => string | number;
  align?: "start" | "end";
}

interface OperationalDataTableProps<Row> {
  caption: string;
  columns: readonly OperationalColumn<Row>[];
  rows: readonly Row[];
  getRowId: (row: Row) => string;
  defaultSort?: { id: string; direction: "ascending" | "descending" };
  dataState?: OperationalDataState;
  stateMessage?: string;
  emptyTitle?: string;
  emptyMessage?: string;
  onRetry?: () => void;
  selectable?: boolean;
  bulkActionLabel?: string;
  onBulkAction?: (rows: readonly Row[]) => void;
}

export function OperationalDataTable<Row>({
  caption,
  columns,
  rows,
  getRowId,
  defaultSort,
  dataState = "ready",
  stateMessage,
  emptyTitle = "No records in this scope",
  emptyMessage = "Adjust the active filters or choose another bounded date range.",
  onRetry,
  selectable = false,
  bulkActionLabel = "Process selected",
  onBulkAction,
}: OperationalDataTableProps<Row>) {
  const [sort, setSort] = useState(defaultSort);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [pageSize, setPageSize] = useState(10);
  const [currentPage, setCurrentPage] = useState(1);
  const [pageJump, setPageJump] = useState("");

  const sortedRows = useMemo(() => {
    if (!sort) return [...rows];
    const column = columns.find((candidate) => candidate.id === sort.id);
    if (!column?.sortValue) return [...rows];
    return [...rows].sort((left, right) => {
      const leftValue = column.sortValue?.(left) ?? "";
      const rightValue = column.sortValue?.(right) ?? "";
      const result = typeof leftValue === "number" && typeof rightValue === "number"
        ? leftValue - rightValue
        : String(leftValue).localeCompare(String(rightValue));
      return sort.direction === "ascending" ? result : -result;
    });
  }, [columns, rows, sort]);

  const selectedRows = sortedRows.filter((row) => selected.has(getRowId(row)));
  const allSelected = sortedRows.length > 0 && selectedRows.length === sortedRows.length;

  const totalPages = Math.max(1, Math.ceil(sortedRows.length / pageSize));
  const safePage = Math.min(Math.max(1, currentPage), totalPages);
  const pagedRows = sortedRows.length > pageSize
    ? sortedRows.slice((safePage - 1) * pageSize, safePage * pageSize)
    : sortedRows;

  function toggleSort(column: OperationalColumn<Row>) {
    if (!column.sortValue) return;
    setSort((current) => current?.id === column.id
      ? { id: column.id, direction: current.direction === "ascending" ? "descending" : "ascending" }
      : { id: column.id, direction: "ascending" });
  }

  function toggleRow(id: string) {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }

  function confirmBulkAction() {
    onBulkAction?.(selectedRows);
    setSelected(new Set());
    setConfirmOpen(false);
  }

  function handlePageJump(e: React.FormEvent) {
    e.preventDefault();
    const parsed = parseInt(pageJump, 10);
    if (!isNaN(parsed) && parsed >= 1 && parsed <= totalPages) {
      setCurrentPage(parsed);
      setPageJump("");
    }
  }

  if (dataState === "loading") {
    return (
      <div className={styles.tableState} data-state="loading" role="status" aria-live="polite">
        <Icon name="loading" size="control" />
        <div><strong>Loading bounded records</strong><p>{stateMessage ?? "The scoped result set is being prepared."}</p></div>
        <span className={styles.skeletonLines} aria-hidden="true" />
      </div>
    );
  }

  if (dataState === "error") {
    return (
      <div className={styles.tableState} data-state="error" role="alert">
        <Icon name="error" size="control" />
        <div><strong>Records could not be loaded</strong><p>{stateMessage ?? "The current filters are preserved. Retry when the service is available."}</p></div>
        {onRetry && <Button variant="secondary" onClick={onRetry}>Retry</Button>}
      </div>
    );
  }

  if (rows.length === 0) {
    return <EmptyState icon="info" title={emptyTitle} description={emptyMessage} />;
  }

  return (
    <div className={styles.tableStack}>
      {dataState !== "ready" && (
        <div className={styles.tableState} data-state={dataState} role="status">
          <Icon name={dataState === "stale" ? "warning" : "info"} />
          <div>
            <strong>{dataState === "stale" ? "Stale snapshot" : "Partial result set"}</strong>
            <p>{stateMessage}</p>
          </div>
        </div>
      )}

      <p className="sr-only" aria-live="polite">
        {sort ? `Sorted by ${columns.find((column) => column.id === sort.id)?.label}, ${sort.direction}.` : "Table is not sorted."}
      </p>

      <div className={styles.tableContainer}>
        <div className={styles.tableWrap}>
          <table className={styles.table}>
            <caption className="sr-only">{caption}</caption>
            <thead>
              <tr>
                {selectable && (
                  <th scope="col" className={styles.selectColumn}>
                    <input
                      type="checkbox"
                      aria-label={`Select all ${sortedRows.length} rows`}
                      checked={allSelected}
                      onChange={() => setSelected(allSelected ? new Set() : new Set(sortedRows.map(getRowId)))}
                    />
                  </th>
                )}
                {columns.map((column) => (
                  <th
                    key={column.id}
                    scope="col"
                    className={column.align === "end" ? styles.money : undefined}
                    aria-sort={sort?.id === column.id ? sort.direction : column.sortValue ? "none" : undefined}
                  >
                    {column.sortValue ? (
                      <button className={styles.sortButton} type="button" onClick={() => toggleSort(column)}>
                        {column.label}<span aria-hidden="true">{sort?.id === column.id ? sort.direction === "ascending" ? "↑" : "↓" : "↕"}</span>
                      </button>
                    ) : column.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {pagedRows.map((row) => {
                const rowId = getRowId(row);
                const isSelected = selected.has(rowId);
                return (
                  <tr key={rowId} aria-selected={selectable ? isSelected : undefined} className={isSelected ? styles.selectedRow : undefined}>
                    {selectable && (
                      <td data-label="Select" className={styles.selectColumn}>
                        <input type="checkbox" aria-label={`Select ${rowId}`} checked={isSelected} onChange={() => toggleRow(rowId)} />
                      </td>
                    )}
                    {columns.map((column, index) => {
                      const Cell = index === 0 ? "th" : "td";
                      return (
                        <Cell
                          key={column.id}
                          scope={index === 0 ? "row" : undefined}
                          data-label={column.label}
                          className={column.align === "end" ? styles.money : undefined}
                        >
                          {column.cell(row)}
                        </Cell>
                      );
                    })}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        {/* Template-inspired Pagination Footer */}
        <div className={styles.paginationFooter} aria-label="Table pagination">
          <div className={styles.pageSizeControl}>
            <span>Showing per page</span>
            <select
              value={pageSize}
              aria-label="Records per page"
              onChange={(e) => {
                setPageSize(Number(e.target.value));
                setCurrentPage(1);
              }}
            >
              <option value="10">10</option>
              <option value="25">25</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
          </div>

          <nav className={styles.paginationNav} aria-label="Page navigation">
            <button
              type="button"
              className={styles.pageArrow}
              disabled={safePage <= 1}
              onClick={() => setCurrentPage(1)}
              aria-label="First page"
            >
              «
            </button>
            <button
              type="button"
              className={styles.pageArrow}
              disabled={safePage <= 1}
              onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
              aria-label="Previous page"
            >
              ‹
            </button>
            {Array.from({ length: Math.min(5, totalPages) }, (_, idx) => {
              let pageNum: number;
              if (totalPages <= 5) {
                pageNum = idx + 1;
              } else if (safePage <= 3) {
                pageNum = idx + 1;
              } else if (safePage >= totalPages - 2) {
                pageNum = totalPages - 4 + idx;
              } else {
                pageNum = safePage - 2 + idx;
              }
              const isActive = pageNum === safePage;
              return (
                <button
                  key={pageNum}
                  type="button"
                  className={isActive ? styles.pageNumberActive : styles.pageNumber}
                  aria-current={isActive ? "page" : undefined}
                  onClick={() => setCurrentPage(pageNum)}
                >
                  {pageNum}
                </button>
              );
            })}
            <button
              type="button"
              className={styles.pageArrow}
              disabled={safePage >= totalPages}
              onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
              aria-label="Next page"
            >
              ›
            </button>
            <button
              type="button"
              className={styles.pageArrow}
              disabled={safePage >= totalPages}
              onClick={() => setCurrentPage(totalPages)}
              aria-label="Last page"
            >
              »
            </button>
          </nav>

          <form className={styles.pageJumpForm} onSubmit={handlePageJump}>
            <label htmlFor="jump-page">Go to page</label>
            <input
              id="jump-page"
              type="number"
              min="1"
              max={totalPages}
              value={pageJump}
              onChange={(e) => setPageJump(e.target.value)}
              placeholder={`${safePage}`}
            />
            <button type="submit">Go ›</button>
          </form>
        </div>
      </div>

      {/* Floating Action Dock (Uxerflow template style) */}
      {selectedRows.length > 0 && (
        <div className={styles.floatingBulkDock} role="status" aria-live="polite">
          <div className={styles.dockCount}>
            <span className={styles.dockCountBadge}>{selectedRows.length}</span>
            <span>Selected</span>
          </div>
          <div className={styles.dockActions}>
            <Button variant="secondary" onClick={() => setConfirmOpen(true)}>
              {bulkActionLabel}
            </Button>
            <button
              className={styles.dockClearBtn}
              type="button"
              onClick={() => setSelected(new Set())}
              aria-label="Clear selection"
            >
              ✕
            </button>
          </div>
        </div>
      )}

      <Dialog
        open={confirmOpen}
        title={`${bulkActionLabel} for ${selectedRows.length} records?`}
        confirmLabel={bulkActionLabel}
        onClose={() => setConfirmOpen(false)}
        onConfirm={confirmBulkAction}
      >
        <p>The action is restricted to the {selectedRows.length} explicitly selected records in this bounded result set.</p>
      </Dialog>
    </div>
  );
}
