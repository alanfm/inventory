import type { ValidationErrorDetails } from "./api";

export type ApiErrorKind =
  | "unauthorized"
  | "forbidden"
  | "csrf"
  | "validation"
  | "throttled"
  | "notFound"
  | "conflict"
  | "server"
  | "network"
  | "unexpected"
  | "unknown";

function kindForStatus(status: number): ApiErrorKind {
  switch (status) {
    case 401:
      return "unauthorized";
    case 403:
      return "forbidden";
    case 404:
      return "notFound";
    case 409:
      return "conflict";
    case 419:
      return "csrf";
    case 422:
      return "validation";
    case 429:
      return "throttled";
    case 0:
      return "network";
    default:
      return status >= 500 ? "server" : "unexpected";
  }
}

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly details: ValidationErrorDetails | Record<string, unknown> | null;
  readonly requestId: string | null;
  readonly kind: ApiErrorKind;

  constructor(params: {
    status: number;
    code: string;
    message: string;
    details?: ValidationErrorDetails | Record<string, unknown> | null;
    requestId?: string | null;
  }) {
    super(params.message);
    this.name = "ApiError";
    this.status = params.status;
    this.code = params.code;
    this.details = params.details ?? null;
    this.requestId = params.requestId ?? null;
    this.kind = kindForStatus(params.status);
  }

  fieldErrors(): Record<string, string[]> {
    const details = this.details;
    if (details && "fields" in details && details.fields) {
      return details.fields as Record<string, string[]>;
    }

    return {};
  }

  static network(): ApiError {
    return new ApiError({
      status: 0,
      code: "NETWORK_ERROR",
      message:
        "Não foi possível falar com o servidor. Verifique sua conexão e tente novamente.",
    });
  }

  static unexpected(): ApiError {
    return new ApiError({
      status: 500,
      code: "INTERNAL_ERROR",
      message: "Ocorreu um erro inesperado. Tente novamente em instantes.",
    });
  }
}
