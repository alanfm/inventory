export interface ApiResource<T> {
  data: T;
}

export interface PaginationLinks {
  first: string | null;
  last: string | null;
  prev: string | null;
  next: string | null;
}

export interface PaginationMeta {
  currentPage: number;
  from: number | null;
  lastPage: number;
  perPage: number;
  to: number | null;
  total: number;
}

export interface Paginated<T> {
  data: T[];
  links: PaginationLinks;
  meta: PaginationMeta;
}

export interface ValidationErrorDetails {
  fields: Record<string, string[]>;
}

export interface ApiErrorBody {
  error: {
    code: string;
    message: string;
    details: ValidationErrorDetails | Record<string, unknown> | null;
    requestId: string | null;
  };
}

export interface ListQuery {
  page?: number;
  perPage?: number;
  sort?: string;
  filter?: Record<string, string | undefined>;
}
