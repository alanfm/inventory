import {
  ApiError,
  Table,
  apiDownload,
  apiRequest,
  can,
  useAsync,
  type ApiRequestOptions,
  type ApiResource,
} from "./support";
import { describe, expect, test } from "vitest";

describe("recursos privados do inventário", () => {
  test("fornece HTTP e UI sem extensões do module-kit", () => {
    const options: ApiRequestOptions = {
      headers: { "Idempotency-Key": "op-1" },
    };
    const resource: ApiResource<{ id: string }> = { data: { id: "1" } };

    expect(options.headers).toBeDefined();
    expect(resource.data.id).toBe("1");
    expect(apiRequest).toBeTypeOf("function");
    expect(apiDownload).toBeTypeOf("function");
    expect(ApiError).toBeTypeOf("function");
    expect(Table).toBeTypeOf("function");
    expect(useAsync).toBeTypeOf("function");
    expect(can).toBeTypeOf("function");
  });
});
