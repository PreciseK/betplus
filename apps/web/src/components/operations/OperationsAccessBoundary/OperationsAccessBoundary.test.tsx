import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { OperationsAccessBoundary } from "./OperationsAccessBoundary";

describe("OperationsAccessBoundary", () => {
  it("renders permitted content", () => {
    render(<OperationsAccessBoundary role="finance" capability="money.read"><p>Finance workspace</p></OperationsAccessBoundary>);
    expect(screen.getByText("Finance workspace")).toBeInTheDocument();
  });

  it("explains denied access without rendering protected content", () => {
    render(<OperationsAccessBoundary role="support-agent" capability="users.manage"><p>User administration</p></OperationsAccessBoundary>);
    expect(screen.getByRole("heading", { name: "This task is outside your access scope" })).toBeInTheDocument();
    expect(screen.queryByText("User administration")).not.toBeInTheDocument();
  });
});
