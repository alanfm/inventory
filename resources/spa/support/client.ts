import type { ApiErrorBody } from "./api";
import { ApiError } from "./errors";

const API_BASE = "/api/v1";
const CSRF_COOKIE = "XSRF-TOKEN";
const CSRF_ENDPOINT = "/sanctum/csrf-cookie";

let csrfRequest: Promise<void> | null = null;

export type QueryValue = string | number | boolean | null | undefined;

export interface RequestOptions {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  headers?: HeadersInit;
  query?: Record<string, QueryValue>;
  signal?: AbortSignal;
}

const managedHeaders = new Set([
  "accept",
  "authorization",
  "content-type",
  "cookie",
  "x-csrf-token",
  "x-xsrf-token",
]);

function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.match(
    new RegExp(
      `(?:^|;\\s*)${name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}=([^;]*)`,
    ),
  );
  return match ? decodeURIComponent(match[1]) : null;
}

function buildQuery(query?: RequestOptions["query"]): string {
  if (!query) return "";

  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === "") continue;
    params.append(key, String(value));
  }

  const serialized = params.toString();
  return serialized ? `?${serialized}` : "";
}

export async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie(CSRF_COOKIE)) return;

  csrfRequest ??= fetch(CSRF_ENDPOINT, {
    method: "GET",
    credentials: "same-origin",
    headers: { Accept: "application/json" },
  })
    .then(() => undefined)
    .finally(() => {
      csrfRequest = null;
    });

  await csrfRequest;
}

function safeParse(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

function defaultCode(status: number): string {
  switch (status) {
    case 401:
      return "UNAUTHENTICATED";
    case 403:
      return "FORBIDDEN";
    case 404:
      return "NOT_FOUND";
    case 419:
      return "CSRF_TOKEN_EXPIRED";
    case 429:
      return "TOO_MANY_REQUESTS";
    default:
      return status >= 500 ? "INTERNAL_ERROR" : "REQUEST_FAILED";
  }
}

function defaultMessage(status: number): string {
  switch (status) {
    case 401:
      return "Sua sessão expirou. Entre novamente.";
    case 403:
      return "Você não tem permissão para executar esta ação.";
    case 404:
      return "O recurso solicitado não foi encontrado.";
    case 419:
      return "Sua sessão expirou. Entre novamente.";
    case 429:
      return "Muitas tentativas. Aguarde alguns instantes e tente novamente.";
    default:
      return status >= 500
        ? "Ocorreu um erro inesperado. Tente novamente em instantes."
        : "Não foi possível concluir a solicitação.";
  }
}

function toApiError(response: Response, json: unknown): ApiError {
  const body = json as ApiErrorBody | null;

  if (body && typeof body === "object" && body.error) {
    return new ApiError({
      status: response.status,
      code: body.error.code,
      message: body.error.message,
      details: body.error.details,
      requestId: body.error.requestId,
    });
  }

  return new ApiError({
    status: response.status,
    code: defaultCode(response.status),
    message: defaultMessage(response.status),
    requestId: response.headers.get("X-Request-Id"),
  });
}

function emitAuthEvent(status: number): void {
  if (typeof window === "undefined") return;

  const type = status === 419 ? "auth:csrf-expired" : "auth:unauthorized";
  window.dispatchEvent(new CustomEvent(type));
}

async function parseResponse<T>(response: Response): Promise<T> {
  if (response.status === 204) {
    return undefined as T;
  }

  const text = await response.text();
  const json = text ? safeParse(text) : null;

  if (response.ok) {
    return json as T;
  }

  if (response.status === 401 || response.status === 419) {
    emitAuthEvent(response.status);
  }

  throw toApiError(response, json);
}

function isFormData(body: unknown): body is FormData {
  return typeof FormData !== "undefined" && body instanceof FormData;
}

function createHeaders(options: RequestOptions, method: string): Headers {
  const headers = new Headers(options.headers);

  for (const name of managedHeaders) {
    if (headers.has(name)) {
      throw new TypeError(`O header ${name} é controlado pelo cliente da API.`);
    }
  }

  headers.set("Accept", "application/json");
  if (options.body !== undefined && !isFormData(options.body)) {
    headers.set("Content-Type", "application/json");
  }

  if (method !== "GET") {
    const token = readCookie(CSRF_COOKIE);
    if (token) headers.set("X-XSRF-TOKEN", token);
  }

  return headers;
}

function serializeBody(body: unknown): BodyInit | undefined {
  if (body === undefined) return undefined;
  if (isFormData(body)) return body;
  return JSON.stringify(body);
}

function canRetryCsrf(method: string, headers: Headers): boolean {
  if (method === "GET") return true;

  return Boolean(headers.get("Idempotency-Key")?.trim());
}

async function sendRequest(
  path: string,
  options: RequestOptions = {},
  retriedAfterCsrf = false,
): Promise<Response> {
  if (!path.startsWith("/inventory/")) {
    throw new TypeError("O cliente do inventário aceita somente /inventory/.");
  }
  const method = options.method ?? "GET";
  const mutating = method !== "GET";

  if (mutating) {
    await ensureCsrfCookie();
  }

  const headers = createHeaders(options, method);

  let response: Response;
  try {
    response = await fetch(`${API_BASE}${path}${buildQuery(options.query)}`, {
      method,
      headers,
      credentials: "same-origin",
      body: serializeBody(options.body),
      signal: options.signal,
    });
  } catch (error) {
    if (error instanceof Error && error.name === "AbortError") throw error;
    throw ApiError.network();
  }

  if (
    response.status === 419 &&
    !retriedAfterCsrf &&
    canRetryCsrf(method, headers)
  ) {
    await ensureCsrfCookie(true);
    return sendRequest(path, options, true);
  }

  return response;
}

/** Writes are retried after CSRF renewal only when protected by Idempotency-Key. */
export async function apiRequest<T>(
  path: string,
  options: RequestOptions = {},
): Promise<T> {
  return parseResponse<T>(await sendRequest(path, options));
}

/** Fetches a same-origin API download and normalizes JSON errors like apiRequest. */
export async function apiDownload(
  path: string,
  options: RequestOptions = {},
): Promise<Blob> {
  const response = await sendRequest(path, options);
  if (!response.ok) await parseResponse<never>(response);
  if (response.status === 204) return new Blob();

  return response.blob();
}
