import {
  apiRequest,
  type ApiResource,
  type PaginationMeta,
  type Paginated,
} from "../support";

export interface ImportBatch {
  id: number;
  original_name: string;
  status: string;
  analysis_version: number;
  counts: { rows?: number } | null;
}

export const importService = {
  list(signal?: AbortSignal) {
    return apiRequest<ImportBatchList>("/inventory/imports", { signal });
  },
  async analyze(file: File) {
    const body = new FormData();
    body.append("file", file);
    return (
      await apiRequest<ApiResource<ImportBatch>>("/inventory/imports/analyze", {
        method: "POST",
        body,
      })
    ).data;
  },
  async detail(id: number, page = 1, signal?: AbortSignal) {
    return apiRequest<{ data: ImportBatch; rows: Paginated<ImportRow> }>(
      `/inventory/imports/${id}`,
      { query: { page: String(page) }, signal },
    );
  },
  async resolve(
    id: number,
    version: number,
    rows: Array<{
      id: number;
      action: "MAPPED" | "SKIPPED";
      reason?: string;
      itemId?: number;
      variantId?: number;
      type?: "ENTRY" | "ISSUE";
    }>,
  ) {
    return apiRequest<{ data: ImportBatch; rows: Paginated<ImportRow> }>(
      `/inventory/imports/${id}/resolutions`,
      { method: "PATCH", body: { version, rows } },
    );
  },
  async commit(batch: ImportBatch) {
    return apiRequest(`/inventory/imports/${batch.id}/commit`, {
      method: "POST",
      headers: { "Idempotency-Key": crypto.randomUUID() },
      body: { version: batch.analysis_version },
    });
  },
};

export interface ImportRow {
  id: number;
  sheet_name: string;
  row_number: number;
  source_payload: Record<string, unknown>;
  corrected_payload: Record<string, unknown> | null;
  errors: string[] | null;
}

export interface ImportBatchList {
  data: {
    data: ImportBatch[];
    links: {
      first: string | null;
      last: string | null;
      prev: string | null;
      next: string | null;
    };
    meta: PaginationMeta;
  };
}
