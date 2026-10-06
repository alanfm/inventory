import { apiDownload, apiRequest, type Paginated } from "../support";

export type ReportType =
  "stock" | "replenishment" | "consumption" | "adjustments";
export type ReportFilters = {
  from?: string;
  to?: string;
  itemId?: string;
  categoryId?: string;
  groupBy?: string;
  page?: number;
  perPage?: number;
};

export type DashboardData = {
  asOf: string;
  period: { from: string; to: string };
  activeItems: number;
  inconsistentItems: number;
  outOfStockItems: number;
  itemsWithoutMinimum: number;
  replenishmentAlerts: number;
  regularItems: number;
  attentionItems: number;
  movementCount: number;
  alerts: {
    id: number;
    code: string;
    name: string;
    unit: string;
    stock: number;
    minimumStock: number | null;
    situation: string;
  }[];
  movementSeries: {
    date: string;
    entries: number;
    issues: number;
    adjustments: number;
    reversals: number;
  }[];
};

export const reportsService = {
  dashboard(filters: Pick<ReportFilters, "from" | "to">, signal?: AbortSignal) {
    return apiRequest<{ data: DashboardData }>("/inventory/dashboard", {
      query: filters,
      signal,
    });
  },
  report(type: ReportType, filters: ReportFilters, signal?: AbortSignal) {
    return apiRequest<Paginated<Record<string, unknown>> & { asOf: string }>(
      `/inventory/reports/${type}`,
      { query: filters, signal },
    );
  },
  async export(
    type: ReportType,
    filters: ReportFilters,
    format: "csv" | "xlsx",
  ) {
    const blob = await apiDownload(`/inventory/exports/${type}`, {
      query: { ...filters, format },
    });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = `inventory-${type}.${format}`;
    anchor.click();
    URL.revokeObjectURL(url);
  },
};
