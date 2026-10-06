import { apiRequest, type ApiResource, type Paginated } from "../support";

export interface MovementLine {
  variantId: string;
  quantity: number;
  unitCost?: string | null;
}
export interface MovementDraft {
  id: number;
  type: "ENTRY" | "ISSUE" | "ADJUSTMENT" | "REVERSAL";
  status: string;
  location_id: number;
  version: number;
  occurred_on: string | null;
  origin: string | null;
  description: string | null;
  observations: string | null;
  service_order_number: string | null;
  document_number: string | null;
  lines: Array<{
    id: number;
    variant_id: number;
    quantity: number | null;
    unit_cost?: string | null;
    counted_quantity?: number | null;
    snapshot: Record<string, string>;
  }>;
}
export interface DraftInput {
  type: "ENTRY" | "ISSUE";
  locationId?: string;
  occurredOn?: string | null;
  origin?: string;
  serviceOrderNumber?: string | null;
  documentNumber?: string | null;
  description?: string | null;
  observations?: string | null;
  lines: MovementLine[];
}

export const movementService = {
  list(signal?: AbortSignal) {
    return apiRequest<Paginated<MovementDraft>>("/inventory/movements", {
      query: { perPage: "50" },
      signal,
    });
  },
  async get(id: string, signal?: AbortSignal) {
    return (
      await apiRequest<ApiResource<MovementDraft>>(
        `/inventory/movements/${id}`,
        { signal },
      )
    ).data;
  },
  async createDraft(input: DraftInput) {
    const key = crypto.randomUUID();
    return (
      await apiRequest<ApiResource<MovementDraft>>("/inventory/movements", {
        method: "POST",
        headers: { "Idempotency-Key": key },
        body: input,
      })
    ).data;
  },
  async cancel(id: number) {
    return (
      await apiRequest<ApiResource<MovementDraft>>(
        `/inventory/movements/${id}/cancel`,
        { method: "POST", body: {} },
      )
    ).data;
  },
  async post(id: number, version: number) {
    return (
      await apiRequest<ApiResource<Record<string, number | string>>>(
        `/inventory/movements/${id}/post`,
        {
          method: "POST",
          headers: { "Idempotency-Key": crypto.randomUUID() },
          body: { version },
        },
      )
    ).data;
  },
  async updateDraft(
    id: number,
    version: number,
    input: Omit<DraftInput, "type" | "locationId">,
  ) {
    return (
      await apiRequest<ApiResource<MovementDraft>>(
        `/inventory/movements/${id}`,
        {
          method: "PATCH",
          body: { ...input, version },
        },
      )
    ).data;
  },
  async adjust(input: {
    locationId: string;
    variantId: string;
    countedQuantity: number;
    expectedBalanceVersion: number;
    reason: string;
  }) {
    return (
      await apiRequest<ApiResource<Record<string, number>>>(
        "/inventory/adjustments",
        {
          method: "POST",
          headers: { "Idempotency-Key": crypto.randomUUID() },
          body: input,
        },
      )
    ).data;
  },
  async reverse(id: string, reason: string) {
    return (
      await apiRequest<
        ApiResource<{ movementId: number; reversalId: number; status: string }>
      >(`/inventory/movements/${id}/reverse`, {
        method: "POST",
        headers: { "Idempotency-Key": crypto.randomUUID() },
        body: { reason },
      })
    ).data;
  },
};
