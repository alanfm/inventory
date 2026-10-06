import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router";
import { afterEach, expect, test, vi } from "vitest";
import { reportsService } from "../services/reportsService";
import ReportsPage from "./ReportsPage";

vi.mock("@starterkit/module-kit", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@starterkit/module-kit")>()),
  useSession: () => ({
    state: { user: { permissions: ["inventory.reports.export"] } },
  }),
}));
vi.mock("../services/reportsService", () => ({
  reportsService: { report: vi.fn(), export: vi.fn() },
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

test("only sends consumption grouping when loading and exporting the selected report", async () => {
  vi.mocked(reportsService.report).mockResolvedValue({
    data: [],
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      currentPage: 1,
      lastPage: 1,
      perPage: 20,
      total: 0,
      from: null,
      to: null,
    },
    asOf: "2026-10-06",
  });
  vi.mocked(reportsService.export).mockResolvedValue(undefined);
  const actor = userEvent.setup();
  render(
    <MemoryRouter initialEntries={["/admin/inventory/reports?groupBy=month"]}>
      <ReportsPage />
    </MemoryRouter>,
  );

  for (const type of [
    "stock",
    "consumption",
    "replenishment",
    "adjustments",
  ] as const) {
    await actor.selectOptions(
      screen.getByRole("combobox", { name: "Relatório" }),
      type,
    );
    await waitFor(() =>
      expect(vi.mocked(reportsService.report).mock.lastCall?.[0]).toBe(type),
    );
    const filters = vi.mocked(reportsService.report).mock.lastCall?.[1];
    if (type === "consumption")
      expect(filters).toHaveProperty("groupBy", "month");
    else expect(filters).not.toHaveProperty("groupBy");

    for (const format of ["csv", "xlsx"] as const) {
      await actor.click(
        screen.getByRole("button", {
          name: `Exportar ${format.toUpperCase()}`,
        }),
      );
      expect(reportsService.export).toHaveBeenLastCalledWith(
        type,
        filters,
        format,
      );
    }
  }
});
