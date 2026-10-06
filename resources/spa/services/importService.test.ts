import { afterEach, describe, expect, it, vi } from "vitest";
import { importService } from "./importService";

describe("importService", () => {
  afterEach(() => vi.restoreAllMocks());

  it("preserves the Laravel paginated resource envelope for batch lists", async () => {
    const payload = {
      data: {
        data: [{ id: 1, original_name: "legacy.xlsx", status: "READY" }],
        links: { first: null, last: null, prev: null, next: null },
        meta: {
          currentPage: 1,
          from: 1,
          lastPage: 1,
          perPage: 20,
          to: 1,
          total: 1,
        },
      },
    };
    vi.spyOn(globalThis, "fetch").mockResolvedValue(
      new Response(JSON.stringify(payload), { status: 200 }),
    );

    const result = await importService.list();

    expect(result.data.data).toEqual(payload.data.data);
  });
});
