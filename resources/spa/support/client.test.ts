import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { apiRequest } from "./client";
import { ApiError } from "./errors";

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

beforeEach(() => {
  document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT";
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("cliente HTTP do inventário", () => {
  test("não permite usar o transporte do módulo para acessar APIs do host", async () => {
    const fetch = vi.fn();
    vi.stubGlobal("fetch", fetch);
    await expect(apiRequest("/auth/user")).rejects.toThrow(TypeError);
    expect(fetch).not.toHaveBeenCalled();
  });
  test("retorna o corpo em sucesso", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockImplementation(() =>
          Promise.resolve(jsonResponse({ data: { status: "ok" } })),
        ),
    );

    await expect(apiRequest("/inventory/items")).resolves.toEqual({
      data: { status: "ok" },
    });
  });

  test("retorna undefined em 204", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockImplementation(() =>
          Promise.resolve(new Response(null, { status: 204 })),
        ),
    );

    await expect(
      apiRequest("/inventory/movements/1", { method: "POST" }),
    ).resolves.toBeUndefined();
  });

  test("normaliza erro 422 com campos", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockImplementation(() =>
        Promise.resolve(
          jsonResponse(
            {
              error: {
                code: "VALIDATION_FAILED",
                message: "Os dados informados são inválidos.",
                details: { fields: { email: ["Informe um e-mail válido."] } },
                requestId: "01K",
              },
            },
            422,
          ),
        ),
      ),
    );

    await expect(
      apiRequest("/inventory/movements", { method: "POST" }),
    ).rejects.toMatchObject({
      kind: "validation",
      status: 422,
      requestId: "01K",
    });

    try {
      await apiRequest("/inventory/movements", { method: "POST" });
    } catch (error) {
      expect((error as ApiError).fieldErrors()).toEqual({
        email: ["Informe um e-mail válido."],
      });
    }
  });

  test("normaliza erro 500 sem envelope", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockImplementation(() =>
          Promise.resolve(new Response("erro interno", { status: 500 })),
        ),
    );

    await expect(apiRequest("/inventory/items")).rejects.toMatchObject({
      kind: "server",
      code: "INTERNAL_ERROR",
    });
  });

  test("envia X-XSRF-TOKEN em requisições de escrita", async () => {
    document.cookie = "XSRF-TOKEN=token-value";
    const fetchMock = vi
      .fn()
      .mockImplementation(() => Promise.resolve(jsonResponse({ data: {} })));
    vi.stubGlobal("fetch", fetchMock);

    await apiRequest("/inventory/movements", {
      method: "POST",
      body: { email: "a@b.c" },
    });

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/v1/inventory/movements");
    expect(init.credentials).toBe("same-origin");
    expect(new Headers(init.headers).get("X-XSRF-TOKEN")).toBe("token-value");
  });

  test("preserva headers customizados sem permitir substituir headers protegidos", async () => {
    document.cookie = "XSRF-TOKEN=token-value";
    const fetchMock = vi
      .fn()
      .mockImplementation(() => Promise.resolve(jsonResponse({ data: {} })));
    vi.stubGlobal("fetch", fetchMock);

    await apiRequest("/inventory/items", {
      method: "POST",
      headers: { "Idempotency-Key": "op-123", "X-Trace-Id": "trace-1" },
      body: { name: "SSD" },
    });

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = new Headers(init.headers);
    expect(headers.get("Idempotency-Key")).toBe("op-123");
    expect(headers.get("X-Trace-Id")).toBe("trace-1");
    expect(headers.get("X-XSRF-TOKEN")).toBe("token-value");

    await expect(
      apiRequest("/inventory/items", {
        method: "POST",
        headers: { "x-xsrf-token": "forged" },
      }),
    ).rejects.toThrow(/controlado pelo cliente da API/);
  });

  test("envia FormData sem definir Content-Type manualmente", async () => {
    document.cookie = "XSRF-TOKEN=token-value";
    const fetchMock = vi
      .fn()
      .mockImplementation(() => Promise.resolve(jsonResponse({ data: {} })));
    vi.stubGlobal("fetch", fetchMock);
    const form = new FormData();
    form.append("file", "workbook");

    await apiRequest("/inventory/imports/analyze", {
      method: "POST",
      body: form,
    });

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.body).toBe(form);
    expect(new Headers(init.headers).has("Content-Type")).toBe(false);
  });

  test("renova CSRF e repete escrita idempotente preservando chave e corpo", async () => {
    document.cookie = "XSRF-TOKEN=old-token";
    let writes = 0;
    const fetchMock = vi.fn().mockImplementation((input: RequestInfo | URL) => {
      if (String(input) === "/sanctum/csrf-cookie") {
        document.cookie = "XSRF-TOKEN=new-token";
        return Promise.resolve(new Response(null, { status: 204 }));
      }

      writes += 1;
      return Promise.resolve(
        writes === 1
          ? jsonResponse({ error: { code: "SESSION_EXPIRED" } }, 419)
          : jsonResponse({ data: { id: "1" } }),
      );
    });
    vi.stubGlobal("fetch", fetchMock);

    await expect(
      apiRequest("/inventory/movements", {
        method: "POST",
        headers: { "Idempotency-Key": "movement-1" },
        body: { occurredOn: "2026-09-28", quantity: 2 },
      }),
    ).resolves.toEqual({ data: { id: "1" } });

    const mutationCalls = fetchMock.mock.calls.filter(
      ([url]) => String(url) === "/api/v1/inventory/movements",
    );
    expect(mutationCalls).toHaveLength(2);
    const [, first] = mutationCalls[0] as [string, RequestInit];
    const [, retry] = mutationCalls[1] as [string, RequestInit];
    expect(first.body).toBe(retry.body);
    expect(new Headers(first.headers).get("Idempotency-Key")).toBe(
      "movement-1",
    );
    expect(new Headers(retry.headers).get("Idempotency-Key")).toBe(
      "movement-1",
    );
    expect(new Headers(retry.headers).get("X-XSRF-TOKEN")).toBe("new-token");
  });

  test("não repete escrita sem Idempotency-Key após 419", async () => {
    document.cookie = "XSRF-TOKEN=token-value";
    const fetchMock = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(
          jsonResponse({ error: { code: "SESSION_EXPIRED" } }, 419),
        ),
      );
    vi.stubGlobal("fetch", fetchMock);

    await expect(
      apiRequest("/inventory/movements", {
        method: "POST",
        body: { quantity: 1 },
      }),
    ).rejects.toMatchObject({ status: 419 });
    expect(fetchMock).toHaveBeenCalledOnce();
  });

  test("apiDownload retorna Blob e normaliza envelope de erro", async () => {
    const { apiDownload } = await import("./client");
    const file = new Blob(["inventory report"], { type: "text/csv" });
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockImplementationOnce(() => Promise.resolve(new Response(file)))
        .mockImplementationOnce(() =>
          Promise.resolve(
            jsonResponse(
              {
                error: {
                  code: "FORBIDDEN",
                  message: "Acesso negado.",
                  details: null,
                  requestId: "01K",
                },
              },
              403,
            ),
          ),
        ),
    );

    await expect(
      apiDownload("/inventory/reports/stock.csv"),
    ).resolves.toBeInstanceOf(Blob);
    await expect(
      apiDownload("/inventory/reports/stock.csv"),
    ).rejects.toMatchObject({
      kind: "forbidden",
      requestId: "01K",
    });
  });

  test("emite evento em 401", async () => {
    const listener = vi.fn();
    window.addEventListener("auth:unauthorized", listener);
    vi.stubGlobal(
      "fetch",
      vi.fn().mockImplementation(() =>
        Promise.resolve(
          jsonResponse(
            {
              error: {
                code: "UNAUTHENTICATED",
                message: "Sessão expirada.",
                details: null,
                requestId: "01K",
              },
            },
            401,
          ),
        ),
      ),
    );

    await expect(apiRequest("/inventory/items")).rejects.toMatchObject({
      kind: "unauthorized",
    });
    expect(listener).toHaveBeenCalledOnce();

    window.removeEventListener("auth:unauthorized", listener);
  });
});
